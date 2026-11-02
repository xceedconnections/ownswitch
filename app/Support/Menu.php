<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * MagnusBilling-parity navigation (section order + item names).
 * Working modules point at real Ownswitch pages; others hit /modules/{slug} scaffolds.
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
                ['Calls Online', '/modules/calls-online', self::STAFF],
                ['CallerID', '/ip-auth', self::SIP_USERS],
                ['Restricted Number', '/modules/restricted-numbers', self::STAFF],
                ['Callback', '/modules/callback', self::STAFF],
                ['IAX', '/modules/iax', self::STAFF],
                ['User History', '/modules/user-history', self::STAFF],
            ]],
            ['Billing', [
                ['Refills', '/billing/topup', self::STAFF],
                ['Payment Methods', '/modules/payment-methods', self::STAFF],
                ['Voucher', '/modules/vouchers', self::STAFF],
                ['Refill Providers', '/modules/refill-providers', self::STAFF],
                ['Ledger', '/ledger', self::LEDGER_READ],
                ['Invoices', '/invoices', ['super_admin', 'admin', 'reseller', 'customer_owner']],
                ['Billing Preview', '/billing/preview', self::STAFF],
            ]],
            ['DIDs', [
                ['DIDs', '/modules/dids', self::STAFF],
                ['DID Destination', '/modules/did-destination', self::STAFF],
                ['DIDs Use', '/modules/dids-use', self::STAFF],
                ['IVRs', '/modules/ivrs', self::STAFF],
                ['Queues', '/modules/queues', self::STAFF],
                ['Queue Members', '/modules/queue-members', self::STAFF],
                ['Holidays', '/modules/holidays', self::STAFF],
                ['DID History', '/modules/did-history', self::STAFF],
            ]],
            ['Rates', [
                ['Plans', '/rate-plans', self::STAFF],
                ['Tariffs', '/tariffs', self::STAFF],
                ['Prefixes', '/prefixes', self::STAFF],
                ['User Custom Rates', '/custom-rates', self::STAFF],
                ['Offers', '/modules/offers', self::STAFF],
                ['Offer CDR', '/modules/offer-cdr', self::STAFF],
                ['Offer Use', '/modules/offer-use', self::STAFF],
                ['Import CSV', '/tariffs/import', self::STAFF],
            ]],
            ['Reports', [
                ['CDR', '/cdrs', self::ALL],
                ['CDR Failed', '/modules/cdr-failed', self::STAFF],
                ['Summary per Day', '/reports/asr-acd', self::ALL],
                ['Summary Day User', '/modules/summary-day-user', self::STAFF],
                ['Summary Day Trunk', '/modules/summary-day-trunk', self::STAFF],
                ['Summary Day Agent', '/modules/summary-day-agent', self::STAFF],
                ['Summary per Month', '/modules/summary-month', self::STAFF],
                ['Summary Month User', '/modules/summary-month-user', self::STAFF],
                ['Summary Month Trunk', '/modules/summary-month-trunk', self::STAFF],
                ['Summary per User', '/modules/summary-user', self::STAFF],
                ['Summary per Trunk', '/modules/summary-trunk', self::STAFF],
                ['Call Archive', '/modules/call-archive', self::STAFF],
                ['Financial', '/reports/financial', self::FINANCE],
                ['Recordings', '/recordings', ['super_admin', 'admin', 'reseller', 'customer_owner']],
                ['Fraud Alerts', '/fraud', self::RESELLER_UP],
            ]],
            ['Routes', [
                ['Providers', '/carriers', self::STAFF],
                ['Trunks', '/trunks', self::STAFF],
                ['Trunk Groups', '/routing/plans', self::STAFF],
                ['Provider Rates', '/carrier-tariffs', self::STAFF],
                ['Routes', '/routing', self::STAFF],
                ['Simulator', '/routing/simulate', self::STAFF],
                ['Servers', '/modules/servers', self::STAFF],
                ['Trunk Errors', '/modules/trunk-errors', self::STAFF],
                ['Provider CNL', '/modules/provider-cnl', self::STAFF],
                ['SIP Trace', '/modules/sip-trace', self::STAFF],
            ]],
            ['Services', [
                ['Services', '/modules/services', self::STAFF],
                ['Services Use', '/modules/services-use', self::STAFF],
            ]],
            ['Settings', [
                ['Configuration', '/settings', self::STAFF],
                ['System Status', '/system/status', self::STAFF],
                ['Menus', '/modules/menus', self::STAFF],
                ['Group Users', '/modules/group-users', self::STAFF],
                ['Emails Templates', '/modules/email-templates', self::STAFF],
                ['Log Users', '/modules/log-users', self::STAFF],
                ['SMTP', '/modules/smtp', self::STAFF],
                ['Fail2ban', '/modules/fail2ban', self::STAFF],
                ['API', '/modules/api', self::STAFF],
                ['Backup', '/modules/backup', self::STAFF],
                ['Alarms', '/modules/alarms', self::STAFF],
            ]],
            ['Voice Broadcasting', [
                ['Campaigns', '/modules/campaigns', self::STAFF],
                ['Phonebooks', '/modules/phonebooks', self::STAFF],
                ['Phonenumbers', '/modules/phonenumbers', self::STAFF],
                ['Polls', '/modules/polls', self::STAFF],
                ['Polls Reports', '/modules/polls-reports', self::STAFF],
                ['Restrict Phone', '/modules/restrict-phone', self::STAFF],
                ['SMS', '/modules/sms', self::STAFF],
                ['Quick Campaign', '/modules/quick-campaign', self::STAFF],
                ['Campaigns Dashboard', '/modules/campaigns-dashboard', self::STAFF],
                ['Campaign Report', '/modules/campaign-report', self::STAFF],
            ]],
            ['CallShop', [
                ['Booths', '/modules/booths', self::STAFF],
                ['Booths Report', '/modules/booths-report', self::STAFF],
                ['Booths Tariffs', '/modules/booths-tariffs', self::STAFF],
                ['Summary per Day', '/modules/callshop-summary-day', self::STAFF],
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
        return [
            'calls-online' => ['title' => 'Calls Online', 'blurb' => 'Live channels currently up (Magnus Calls Online).', 'columns' => ['User', 'SIP', 'Destination', 'Duration', 'Trunk']],
            'restricted-numbers' => ['title' => 'Restricted Number', 'blurb' => 'Block dialled numbers / patterns per user.', 'columns' => ['User', 'Number', 'Direction', 'Status']],
            'callback' => ['title' => 'Callback', 'blurb' => 'Callback DID / trigger configuration.', 'columns' => ['User', 'DID', 'Status']],
            'iax' => ['title' => 'IAX', 'blurb' => 'IAX peers (Magnus IAX accounts).', 'columns' => ['User', 'IAX user', 'Host', 'Status']],
            'user-history' => ['title' => 'User History', 'blurb' => 'Audit trail of user credit / plan changes.', 'columns' => ['User', 'Event', 'Detail', 'Date']],
            'payment-methods' => ['title' => 'Payment Methods', 'blurb' => 'Online payment gateways (PayPal, Stripe, …).', 'columns' => ['Name', 'Method', 'Active']],
            'vouchers' => ['title' => 'Voucher', 'blurb' => 'Prepaid voucher codes.', 'columns' => ['Voucher', 'Credit', 'Plan', 'Used']],
            'refill-providers' => ['title' => 'Refill Providers', 'blurb' => 'Credit adjustments against providers.', 'columns' => ['Provider', 'Credit', 'Description', 'Date']],
            'dids' => ['title' => 'DIDs', 'blurb' => 'Inbound numbers inventory (setup / monthly prices).', 'columns' => ['DID', 'Country', 'Monthly', 'Status']],
            'did-destination' => ['title' => 'DID Destination', 'blurb' => 'Where each DID routes (SIP, IVR, Queue).', 'columns' => ['DID', 'User', 'Type', 'Destination', 'Priority']],
            'dids-use' => ['title' => 'DIDs Use', 'blurb' => 'DIDs reserved / assigned to users.', 'columns' => ['DID', 'User', 'Months paid', 'Reserved']],
            'ivrs' => ['title' => 'IVRs', 'blurb' => 'Interactive voice menus.', 'columns' => ['Name', 'User', 'Work audio', 'Status']],
            'queues' => ['title' => 'Queues', 'blurb' => 'Call queues / ACD.', 'columns' => ['Name', 'User', 'Strategy', 'Max wait']],
            'queue-members' => ['title' => 'Queue Members', 'blurb' => 'Agents belonging to queues.', 'columns' => ['Queue', 'Member', 'Penalty']],
            'holidays' => ['title' => 'Holidays', 'blurb' => 'Calendar days treated as out-of-hours for IVR.', 'columns' => ['Name', 'Date']],
            'did-history' => ['title' => 'DID History', 'blurb' => 'Historical DID assignments and payments.', 'columns' => ['DID', 'User', 'Months', 'Date']],
            'offers' => ['title' => 'Offers', 'blurb' => 'Free packages (unlimited / free calls / free seconds).', 'columns' => ['Name', 'Type', 'Quantity', 'Status']],
            'offer-cdr' => ['title' => 'Offer CDR', 'blurb' => 'CDRs that consumed offer packages.', 'columns' => ['User', 'Offer', 'Destination', 'Seconds']],
            'offer-use' => ['title' => 'Offer Use', 'blurb' => 'Offer activation / remaining balance.', 'columns' => ['User', 'Offer', 'Used', 'Remaining']],
            'cdr-failed' => ['title' => 'CDR Failed', 'blurb' => 'Failed / unanswered attempt CDRs.', 'columns' => ['Date', 'User', 'Destination', 'Cause']],
            'summary-day-user' => ['title' => 'Summary Day User', 'blurb' => 'Per-user daily ASR/ACD/cost.', 'columns' => ['Day', 'User', 'Calls', 'ASR', 'Sell']],
            'summary-day-trunk' => ['title' => 'Summary Day Trunk', 'blurb' => 'Per-trunk daily totals.', 'columns' => ['Day', 'Trunk', 'Calls', 'Buy']],
            'summary-day-agent' => ['title' => 'Summary Day Agent', 'blurb' => 'Per-reseller / agent daily totals.', 'columns' => ['Day', 'Agent', 'Calls', 'Profit']],
            'summary-month' => ['title' => 'Summary per Month', 'blurb' => 'Platform monthly rollup.', 'columns' => ['Month', 'Calls', 'ASR', 'Sell', 'Buy']],
            'summary-month-user' => ['title' => 'Summary Month User', 'blurb' => 'Per-user monthly totals.', 'columns' => ['Month', 'User', 'Calls', 'Sell']],
            'summary-month-trunk' => ['title' => 'Summary Month Trunk', 'blurb' => 'Per-trunk monthly totals.', 'columns' => ['Month', 'Trunk', 'Calls', 'Buy']],
            'summary-user' => ['title' => 'Summary per User', 'blurb' => 'Lifetime user aggregates.', 'columns' => ['User', 'Calls', 'ASR', 'Sell']],
            'summary-trunk' => ['title' => 'Summary per Trunk', 'blurb' => 'Lifetime trunk aggregates.', 'columns' => ['Trunk', 'Calls', 'ASR', 'Buy']],
            'call-archive' => ['title' => 'Call Archive', 'blurb' => 'Archived CDRs beyond hot retention.', 'columns' => ['Date', 'User', 'Destination', 'Duration']],
            'servers' => ['title' => 'Servers', 'blurb' => 'Magnus multi-server / slave nodes.', 'columns' => ['Name', 'Host', 'Type', 'Status']],
            'trunk-errors' => ['title' => 'Trunk Errors', 'blurb' => 'Recent trunk hangup causes / failures.', 'columns' => ['Trunk', 'Code', 'Count', 'Last seen']],
            'provider-cnl' => ['title' => 'Provider CNL', 'blurb' => 'Brazil CNL zone mapping per provider.', 'columns' => ['Provider', 'CNL', 'Zone']],
            'sip-trace' => ['title' => 'SIP Trace', 'blurb' => 'Captured SIP ladders for debugging.', 'columns' => ['Time', 'Call-ID', 'Method', 'From']],
            'services' => ['title' => 'Services', 'blurb' => 'Recurring billable services catalogue.', 'columns' => ['Name', 'Price', 'Type', 'Status']],
            'services-use' => ['title' => 'Services Use', 'blurb' => 'Services activated on users.', 'columns' => ['User', 'Service', 'Price', 'Reserved']],
            'menus' => ['title' => 'Menus', 'blurb' => 'Custom menu visibility (Magnus Menus).', 'columns' => ['Menu', 'Module', 'Show']],
            'group-users' => ['title' => 'Group Users', 'blurb' => 'Permission groups (admin / agent / client).', 'columns' => ['Name', 'Type', 'Users']],
            'email-templates' => ['title' => 'Emails Templates', 'blurb' => 'Transactional email bodies.', 'columns' => ['Key', 'Subject', 'Language']],
            'log-users' => ['title' => 'Log Users', 'blurb' => 'Panel login / action logs.', 'columns' => ['User', 'IP', 'Action', 'Date']],
            'smtp' => ['title' => 'SMTP', 'blurb' => 'Outbound mail server settings.', 'columns' => ['Host', 'Port', 'User', 'Active']],
            'fail2ban' => ['title' => 'Fail2ban', 'blurb' => 'Banned IPs from SIP / SSH abuse.', 'columns' => ['IP', 'Jail', 'Banned at']],
            'api' => ['title' => 'API', 'blurb' => 'API keys for external integrations.', 'columns' => ['Name', 'User', 'Status', 'Created']],
            'backup' => ['title' => 'Backup', 'blurb' => 'Database / config backup jobs.', 'columns' => ['File', 'Size', 'Created']],
            'alarms' => ['title' => 'Alarms', 'blurb' => 'Threshold alarms (ASR, balance, CPS).', 'columns' => ['Name', 'Type', 'Threshold', 'Active']],
            'campaigns' => ['title' => 'Campaigns', 'blurb' => 'Outbound voice broadcast campaigns.', 'columns' => ['Name', 'Phonebook', 'Status']],
            'phonebooks' => ['title' => 'Phonebooks', 'blurb' => 'Campaign number lists.', 'columns' => ['Name', 'Numbers', 'Status']],
            'phonenumbers' => ['title' => 'Phonenumbers', 'blurb' => 'Numbers inside phonebooks.', 'columns' => ['Phonebook', 'Number', 'Name', 'Status']],
            'polls' => ['title' => 'Polls', 'blurb' => 'IVR poll definitions.', 'columns' => ['Name', 'Campaign', 'Status']],
            'polls-reports' => ['title' => 'Polls Reports', 'blurb' => 'Poll answer aggregates.', 'columns' => ['Poll', 'Option', 'Votes']],
            'restrict-phone' => ['title' => 'Restrict Phone', 'blurb' => 'Numbers blocked from campaigns.', 'columns' => ['Number', 'Description']],
            'sms' => ['title' => 'SMS', 'blurb' => 'SMS gateway / outbound SMS.', 'columns' => ['To', 'Text', 'Status', 'Date']],
            'quick-campaign' => ['title' => 'Quick Campaign', 'blurb' => 'One-shot campaign wizard.', 'columns' => ['Name', 'Numbers', 'Status']],
            'campaigns-dashboard' => ['title' => 'Campaigns Dashboard', 'blurb' => 'Live campaign metrics.', 'columns' => ['Campaign', 'Dialled', 'Answered', 'ASR']],
            'campaign-report' => ['title' => 'Campaign Report', 'blurb' => 'Historical campaign results.', 'columns' => ['Campaign', 'Date', 'Calls', 'Cost']],
            'booths' => ['title' => 'Booths', 'blurb' => 'CallShop booths / cabins.', 'columns' => ['Name', 'User', 'Status']],
            'booths-report' => ['title' => 'Booths Report', 'blurb' => 'Booth session / revenue report.', 'columns' => ['Booth', 'Calls', 'Duration', 'Sell']],
            'booths-tariffs' => ['title' => 'Booths Tariffs', 'blurb' => 'Per-booth sell rates.', 'columns' => ['Booth', 'Prefix', 'Rate']],
            'callshop-summary-day' => ['title' => 'CallShop Summary per Day', 'blurb' => 'Daily CallShop totals.', 'columns' => ['Day', 'Booths', 'Calls', 'Sell']],
        ];
    }
}
