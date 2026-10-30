<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** Role-filtered navigation. Route middleware enforces the same rules server-side. */
final class Menu
{
    public const STAFF = ['super_admin', 'admin'];
    public const ALL = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech', 'reporter'];
    public const RESELLER_UP = ['super_admin', 'admin', 'reseller'];
    public const SIP_USERS = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech'];
    public const FINANCE = ['super_admin', 'admin', 'reseller', 'customer_owner', 'reporter'];
    public const LEDGER_READ = ['super_admin', 'admin', 'reseller', 'customer_owner', 'reporter'];

    /** @return list<array{label:string,items:list<array{label:string,path:string}>}> */
    public static function for(string $role): array
    {
        $sections = [
            ['Overview', [['Dashboard', '/', self::ALL]]],
            ['Customers', [
                ['Customers', '/customers', self::RESELLER_UP],
                ['Resellers', '/resellers', self::STAFF],
                ['SIP Users', '/sip-users', self::SIP_USERS],
                ['IP Authentication', '/ip-auth', self::SIP_USERS],
            ]],
            ['Rates', [
                ['Rate Plans', '/rate-plans', self::STAFF],
                ['Customer Tariffs', '/tariffs', self::STAFF],
                ['Carrier Tariffs', '/carrier-tariffs', self::STAFF],
                ['Tariff CSV', '/tariffs/import', self::STAFF],
            ]],
            ['Carriers & Routing', [
                ['Carriers', '/carriers', self::STAFF],
                ['Trunks', '/trunks', self::STAFF],
                ['Routing Plans', '/routing/plans', self::STAFF],
                ['Routing Rules', '/routing', self::STAFF],
                ['Route Simulator', '/routing/simulate', self::STAFF],
            ]],
            ['CDR & Reports', [
                ['CDR Search', '/cdrs', self::ALL],
                ['ASR / ACD', '/reports/asr-acd', self::ALL],
                ['Financial Report', '/reports/financial', self::FINANCE],
            ]],
            ['Billing', [
                ['Billing Preview', '/billing/preview', self::STAFF],
                ['Top-up / Adjust', '/billing/topup', self::STAFF],
                ['Ledger', '/ledger', self::LEDGER_READ],
                ['Invoices', '/invoices', ['super_admin', 'admin', 'reseller', 'customer_owner']],
            ]],
            ['Media & Security', [
                ['Recordings', '/recordings', ['super_admin', 'admin', 'reseller', 'customer_owner']],
                ['Fraud Alerts', '/fraud', self::RESELLER_UP],
            ]],
            ['System', [
                ['System Status', '/system/status', self::STAFF],
                ['Settings', '/settings', self::STAFF],
            ]],
        ];
        $out = [];
        foreach ($sections as [$label, $items]) {
            $visible = [];
            foreach ($items as [$l, $p, $roles]) {
                if (in_array($role, $roles, true)) {
                    $visible[] = ['label' => $l, 'path' => $p];
                }
            }
            if ($visible) {
                $out[] = ['label' => $label, 'items' => $visible];
            }
        }
        return $out;
    }
}
