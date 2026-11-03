<?php

declare(strict_types=1);

/**
 * Backfill customer / SIP account on unattributed CDRs and rate answered ones.
 *
 *   php bin/reattribute_cdrs.php
 *   php bin/reattribute_cdrs.php --limit=500
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\PrepaidService;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Services\Settings\SettingsService;

$limit = 500;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d{1,5})$/', $a, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

$pdo = App::db();
$settings = new SettingsService($pdo);
$rating = new RatingService();
$resolver = new RateResolver($pdo, $settings);
$ledger = new LedgerService($pdo);

$sole = $pdo->query("SELECT id, customer_id, username FROM sip_accounts WHERE status = 'active' LIMIT 2")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$soleSip = count($sole) === 1 ? $sole[0] : null;

$st = $pdo->prepare(
    "SELECT id, src, dst_normalized, duration_actual, disposition, customer_id, sip_account_id,
            carrier_trunk_id, settlement_status, sip_call_id, external_cdr_key, currency
     FROM cdrs
     WHERE customer_id IS NULL OR sip_account_id IS NULL OR settlement_status IN ('unattributed','unrated','no_charge')
     ORDER BY id DESC
     LIMIT {$limit}"
);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$fixed = 0;
$charged = 0;

foreach ($rows as $cdr) {
    $sipId = $cdr['sip_account_id'] !== null ? (int) $cdr['sip_account_id'] : null;
    $customerId = $cdr['customer_id'] !== null ? (int) $cdr['customer_id'] : null;

    if ($sipId === null && $soleSip !== null) {
        $sipId = (int) $soleSip['id'];
        $customerId = (int) $soleSip['customer_id'];
    }
    if ($sipId === null && !empty($cdr['src'])) {
        $q = $pdo->prepare('SELECT id, customer_id FROM sip_accounts WHERE username = ? LIMIT 1');
        $q->execute([(string) $cdr['src']]);
        $hit = $q->fetch(PDO::FETCH_ASSOC);
        if ($hit) {
            $sipId = (int) $hit['id'];
            $customerId = (int) $hit['customer_id'];
        }
    }
    if ($customerId === null) {
        continue;
    }

    $cust = $pdo->prepare('SELECT id, reseller_id, currency, billing_mode, credit_limit FROM customers WHERE id = ?');
    $cust->execute([$customerId]);
    $customer = $cust->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        continue;
    }

    $pdo->prepare(
        'UPDATE cdrs SET customer_id = ?, reseller_id = ?, sip_account_id = COALESCE(sip_account_id, ?), currency = COALESCE(currency, ?)
         WHERE id = ?'
    )->execute([
        $customerId,
        $customer['reseller_id'],
        $sipId,
        $customer['currency'],
        (int) $cdr['id'],
    ]);
    $fixed++;

    $answered = ($cdr['disposition'] === 'ANSWERED' && (int) $cdr['duration_actual'] > 0);
    if (!$answered) {
        if (($cdr['settlement_status'] ?? '') === 'unattributed') {
            $pdo->prepare("UPDATE cdrs SET settlement_status = 'no_charge' WHERE id = ?")->execute([(int) $cdr['id']]);
        }
        continue;
    }

    // Already has a positive charge — skip
    $chk = $pdo->prepare('SELECT customer_charge, settlement_status, external_cdr_key FROM cdrs WHERE id = ?');
    $chk->execute([(int) $cdr['id']]);
    $cur = $chk->fetch(PDO::FETCH_ASSOC) ?: [];
    if (bccomp((string) ($cur['customer_charge'] ?? '0'), '0', 6) > 0) {
        continue;
    }

    $dst = (string) $cdr['dst_normalized'];
    $crate = $resolver->customer($customerId, $dst);
    if ($crate === null) {
        $pdo->prepare("UPDATE cdrs SET settlement_status = 'unrated' WHERE id = ?")->execute([(int) $cdr['id']]);
        echo "CDR #{$cdr['id']}: attributed but unrated (no tariff match for {$dst})\n";
        continue;
    }

    $duration = (int) $cdr['duration_actual'];
    $c = $rating->rate($crate['rate'], $crate['initial'], $crate['subsequent'], $duration, $crate['connect_fee'], $crate['charge_connect_on_zero'], $crate['min_billable']);
    $carrierTotal = '0.000000';
    $carrierId = null;
    $carrierFields = [
        'carrier_rate' => null,
        'carrier_initial' => null,
        'carrier_subsequent' => null,
        'duration_billable_carrier' => 0,
        'carrier_cost' => '0.000000',
        'carrier_connect_fee' => '0.000000',
    ];
    $trunkId = $cdr['carrier_trunk_id'] !== null ? (int) $cdr['carrier_trunk_id'] : null;
    if ($trunkId !== null && ($k = $resolver->carrier($trunkId, $dst)) !== null) {
        $kr = $rating->rate($k['rate'], $k['initial'], $k['subsequent'], $duration, $k['connect_fee']);
        $carrierTotal = $kr['total'];
        $carrierId = $k['carrier_id'];
        $carrierFields = [
            'carrier_rate' => $k['rate'],
            'carrier_initial' => $k['initial'],
            'carrier_subsequent' => $k['subsequent'],
            'duration_billable_carrier' => $kr['billable_seconds'],
            'carrier_cost' => $kr['total'],
            'carrier_connect_fee' => $kr['connect_fee'],
        ];
    } elseif ($trunkId !== null) {
        $t = $pdo->prepare('SELECT carrier_id FROM carrier_trunks WHERE id = ?');
        $t->execute([$trunkId]);
        $carrierId = ($v = $t->fetchColumn()) !== false ? (int) $v : null;
    }

    $margin = $rating->margin($c['total'], $carrierTotal);
    $key = (string) ($cur['external_cdr_key'] ?? ('cdr:' . $cdr['id']));

    $ledger->tx(function () use ($pdo, $cdr, $customer, $customerId, $c, $crate, $carrierId, $carrierFields, $margin, $key, $ledger): void {
        $pdo->prepare(
            "UPDATE cdrs SET
                dst_prefix = ?,
                customer_rate = ?, customer_initial = ?, customer_subsequent = ?,
                duration_billable_customer = ?, customer_charge = ?, customer_connect_fee = ?,
                customer_rate_plan_version_id = ?, rating_source = ?, rated_at = UTC_TIMESTAMP(3),
                carrier_id = ?, carrier_rate = ?, carrier_initial = ?, carrier_subsequent = ?,
                duration_billable_carrier = ?, carrier_cost = ?, carrier_connect_fee = ?,
                gross_margin = ?, settlement_status = 'settled', currency = ?
             WHERE id = ?"
        )->execute([
            $crate['prefix'] !== '' ? $crate['prefix'] : null,
            $crate['rate'], $crate['initial'], $crate['subsequent'],
            $c['billable_seconds'], $c['total'], $c['connect_fee'],
            $crate['version_id'], 'portal:reattribute:' . $crate['source'],
            $carrierId, $carrierFields['carrier_rate'], $carrierFields['carrier_initial'], $carrierFields['carrier_subsequent'],
            $carrierFields['duration_billable_carrier'], $carrierFields['carrier_cost'], $carrierFields['carrier_connect_fee'],
            $margin, $crate['currency'],
            (int) $cdr['id'],
        ]);

        if (bccomp($c['total'], '0', 6) > 0) {
            $ledger->post([
                'customer_id' => $customerId,
                'reseller_id' => $customer['reseller_id'] !== null ? (int) $customer['reseller_id'] : null,
                'entry_type' => 'call_charge',
                'amount' => bcmul($c['total'], '-1', 6),
                'currency' => (string) $crate['currency'],
                'cdr_id' => (int) $cdr['id'],
                'source_system' => 'reattribute',
                'external_ref' => $key,
                'run_type' => 'customer',
                'idempotency_key' => 'cdr:' . $key . ':customer',
                'description' => 'Call ' . $cdr['dst_normalized'],
            ]);
        }
    });
    $charged++;
    echo "CDR #{$cdr['id']}: charged {$c['total']} (billable {$c['billable_seconds']}s)\n";
}

echo gmdate('c') . " attributed={$fixed} charged={$charged} scanned=" . count($rows) . "\n";
