<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** Role-filtered navigation. MagnusBilling-style section order for day-to-day ops. */
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
        // Order mirrors MagnusBilling daily workflow:
        // Customers → SIP Users → Providers → Trunks → Plans → Prefixes → Routes → CDRs → Billing
        $sections = [
            ['Overview', [
                ['Dashboard', '/', self::ALL],
            ]],
            ['Customers', [
                ['Customers', '/customers', self::RESELLER_UP],
                ['Resellers', '/resellers', self::STAFF],
                ['SIP Users', '/sip-users', self::SIP_USERS],
                ['IP Auth', '/ip-auth', self::SIP_USERS],
            ]],
            ['Providers', [
                ['Providers', '/carriers', self::STAFF],
                ['Trunks', '/trunks', self::STAFF],
                ['Provider Rates', '/carrier-tariffs', self::STAFF],
            ]],
            ['Rates & Plans', [
                ['Plans', '/rate-plans', self::STAFF],
                ['Prefixes', '/tariffs', self::STAFF],
                ['Import CSV', '/tariffs/import', self::STAFF],
            ]],
            ['Routing', [
                ['Route Groups', '/routing/plans', self::STAFF],
                ['Routes', '/routing', self::STAFF],
                ['Simulator', '/routing/simulate', self::STAFF],
            ]],
            ['CDR & Reports', [
                ['CDRs', '/cdrs', self::ALL],
                ['ASR / ACD', '/reports/asr-acd', self::ALL],
                ['Financial', '/reports/financial', self::FINANCE],
            ]],
            ['Billing', [
                ['Refill / Adjust', '/billing/topup', self::STAFF],
                ['Billing Preview', '/billing/preview', self::STAFF],
                ['Ledger', '/ledger', self::LEDGER_READ],
                ['Invoices', '/invoices', ['super_admin', 'admin', 'reseller', 'customer_owner']],
            ]],
            ['Security', [
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
