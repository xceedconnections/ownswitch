<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Navigation — only items with a live operational engine (no empty Magnus scaffolds).
 */
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
            ['Clients', [
                ['Users', '/users', self::RESELLER_UP],
                ['Resellers', '/resellers', self::STAFF],
                ['SIP Users', '/sip-users', self::SIP_USERS],
                ['CallerID', '/ip-auth', self::SIP_USERS],
            ]],
            ['Billing', [
                ['Refills', '/billing/refill', self::STAFF],
                ['Ledger', '/ledger', self::LEDGER_READ],
                ['Invoices', '/invoices', ['super_admin', 'admin', 'reseller', 'customer_owner']],
                ['Billing Preview', '/billing/preview', self::STAFF],
            ]],
            ['Rates', [
                ['Plans', '/rate-plans', self::STAFF],
                ['Tariffs', '/tariffs', self::STAFF],
                ['Prefixes', '/prefixes', self::STAFF],
                ['User Custom Rates', '/custom-rates', self::STAFF],
                ['Import CSV', '/tariffs/import', self::STAFF],
            ]],
            ['Reports', [
                ['CDR', '/cdrs', self::ALL],
                ['Summary per Day', '/reports/summary-day', self::ALL],
                ['Financial', '/reports/financial', self::FINANCE],
                ['Recordings', '/recordings', ['super_admin', 'admin', 'reseller', 'customer_owner']],
                ['Fraud Alerts', '/fraud', self::RESELLER_UP],
            ]],
            ['Routes', [
                ['Providers', '/providers', self::STAFF],
                ['Trunks', '/trunks', self::STAFF],
                ['Trunk Groups', '/trunk-groups', self::STAFF],
                ['Provider Rates', '/provider-rates', self::STAFF],
                ['Simulator', '/routing/simulate', self::STAFF],
            ]],
            ['Settings', [
                ['Settings', '/settings', self::STAFF],
                ['System Status', '/system/status', self::STAFF],
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

    /** @return array<string,array{title:string,blurb:string,columns:list<string>}> */
    public static function scaffolds(): array
    {
        return [];
    }
}
