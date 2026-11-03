<?php

declare(strict_types=1);

namespace Ownswitch\Integrations\OpenSIPS;

use Ownswitch\Support\Crypto;
use PDO;

/**
 * Projects Ownswitch SIP accounts, IP peers and carrier trunks into the OpenSIPS DB
 * (subscriber, address, dr_gateways, dr_rules, uac_registrant) and reloads via MI.
 *
 * Address groups (must match opensips.cfg.tpl):
 *   1 = customer IP auth
 *   2 = provider / carrier IPs (inbound)
 */
final class OpensipsProvisioner
{
    public const GRP_CUSTOMER = 1;
    public const GRP_CARRIER = 2;
    public const DR_GROUP = '1';

    public function __construct(
        private ?PDO $osDb,
        private string $miUrl = '',
        private string $sipDomain = '',
        private string $initError = '',
    ) {
    }

    public static function fromConfig(): self
    {
        $cfg = (array) \Ownswitch\App::config('app.integrations.opensips', []);
        if (empty($cfg['enabled'])) {
            return new self(null, '', '', 'OPENSIPS_ENABLED is off');
        }
        $db = (array) ($cfg['db'] ?? []);
        if (trim((string) ($db['username'] ?? '')) === '' || (string) ($db['password'] ?? '') === '') {
            return new self(null, '', '', 'OpenSIPS DB username/password missing in /etc/ownswitch/ownswitch.env');
        }
        try {
            $pdo = \Ownswitch\Database\Connection::make($db + ['charset' => 'utf8mb4']);
        } catch (\Throwable $e) {
            error_log('[ownswitch] OpenSIPS DB connect failed: ' . $e->getMessage());
            return new self(null, '', '', 'Cannot connect to OpenSIPS DB: ' . $e->getMessage());
        }
        return new self($pdo, (string) ($cfg['mi_url'] ?? ''), (string) ($cfg['sip_domain'] ?? ''));
    }

    public function enabled(): bool
    {
        return $this->osDb !== null;
    }

    public function initError(): string
    {
        return $this->initError;
    }

    public function hasMi(): bool
    {
        return $this->miUrl !== '';
    }

    /** OpenSIPS MySQL handle (null when provisioning disabled). */
    public function pdo(): ?PDO
    {
        return $this->osDb;
    }

    public static function ha1(string $username, string $realm, string $password): array
    {
        return [md5("{$username}:{$realm}:{$password}"), md5("{$username}@{$realm}:{$realm}:{$password}")];
    }

    /**
     * @param array<string,mixed>|null $account Ownswitch sip_accounts row.
     *        Pass `_plain_password` so OpenSIPS calculate_ha1=1 can auth any Domain (FQDN/IP).
     */
    public function upsertSubscriber(string $username, string $domain, string $ha1, string $ha1b, ?array $account = null): void
    {
        $db = $this->db();
        if (!$this->tableExists('subscriber')) {
            throw new \RuntimeException('opensips.subscriber table missing — re-run deploy stage 50 / OpenSIPS schema');
        }
        $cols = $this->columns('subscriber');

        // use_domain=0 → one subscriber row per username. Prefer exact (user,domain),
        // then drop any other domain rows for the same user to avoid account_idx dupes.
        $stExact = $db->prepare('SELECT id FROM subscriber WHERE username = ? AND domain = ? LIMIT 1');
        $stExact->execute([$username, $domain]);
        $id = $stExact->fetchColumn();
        if ($id) {
            $db->prepare('DELETE FROM subscriber WHERE username = ? AND id <> ?')->execute([$username, $id]);
        } else {
            $stAny = $db->prepare('SELECT id FROM subscriber WHERE username = ? ORDER BY id ASC LIMIT 1');
            $stAny->execute([$username]);
            $id = $stAny->fetchColumn();
            if ($id) {
                // Reuse oldest row; remove siblings first so domain UPDATE cannot collide
                $db->prepare('DELETE FROM subscriber WHERE username = ? AND id <> ?')->execute([$username, $id]);
            }
        }

        $set = [
            'domain' => $domain,
            'ha1' => $ha1,
        ];
        if (in_array('ha1b', $cols, true)) {
            $set['ha1b'] = $ha1b;
        }
        if (in_array('password', $cols, true)) {
            $plain = '';
            if ($account !== null) {
                $plain = (string) ($account['_plain_password'] ?? '');
                if ($plain === '' && !empty($account['password_enc'])) {
                    try {
                        $plain = Crypto::decrypt((string) $account['password_enc']);
                    } catch (\Throwable) {
                        $plain = '';
                    }
                }
            }
            if ($plain !== '') {
                $set['password'] = $plain;
            } elseif (!$id) {
                $set['password'] = '';
            }
        }
        if ($account !== null && in_array('rpid', $cols, true)) {
            $cid = trim((string) ($account['caller_id'] ?? ''));
            $set['rpid'] = $cid !== '' ? $cid : '';
        }
        if ($account !== null) {
            $this->ensureSubscriberExtraColumns();
            $cols = $this->columns('subscriber');
            if (in_array('media_policy', $cols, true)) {
                $mp = strtolower(trim((string) ($account['_effective_media'] ?? $account['media_policy'] ?? 'relay')));
                if ($mp === '' || $mp === 'default' || $mp === 'inherit') {
                    $mp = 'relay';
                }
                $set['media_policy'] = in_array($mp, ['direct', 'relay', 'record'], true) ? $mp : 'relay';
            }
            if (in_array('dtmf_pass', $cols, true)) {
                $dp = strtolower(trim((string) ($account['dtmf_pass'] ?? 'yes')));
                $set['dtmf_pass'] = $dp === 'no' ? 'no' : 'yes';
            }
            if (in_array('cid_mode', $cols, true)) {
                $mode = strtolower(trim((string) ($account['caller_id_mode'] ?? '')));
                $cid = trim((string) ($account['caller_id'] ?? ''));
                if ($mode === '' || $mode === 'auto') {
                    $mode = $cid !== '' ? 'fixed' : 'passthrough';
                }
                $set['cid_mode'] = in_array($mode, ['passthrough', 'fixed', 'display_name'], true) ? $mode : 'passthrough';
            }
            if (in_array('cid_min_len', $cols, true)) {
                $set['cid_min_len'] = max(0, (int) ($account['cid_min_len'] ?? 0));
            }
            if (in_array('cid_max_len', $cols, true)) {
                $set['cid_max_len'] = max(0, (int) ($account['cid_max_len'] ?? 0));
            }
            if (in_array('allow_calls', $cols, true)) {
                $set['allow_calls'] = $this->customerAllowsCalls($account) ? 1 : 0;
            }
        }

        if ($id) {
            $parts = [];
            $vals = [];
            foreach ($set as $c => $v) {
                $parts[] = "`{$c}` = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $db->prepare('UPDATE subscriber SET ' . implode(',', $parts) . ' WHERE id = ?')->execute($vals);
        } else {
            $insert = ['username' => $username, 'domain' => $domain] + $set;
            foreach (['email_address', 'rpid'] as $opt) {
                if (in_array($opt, $cols, true) && !array_key_exists($opt, $insert)) {
                    $insert[$opt] = '';
                }
            }
            $cnames = array_keys($insert);
            $sql = 'INSERT INTO subscriber (' . implode(',', array_map(static fn ($c) => "`{$c}`", $cnames)) . ') VALUES ('
                . implode(',', array_fill(0, count($cnames), '?')) . ')';
            $db->prepare($sql)->execute(array_values($insert));
        }

        // Fixed-host SIP users → permissions address (customer group); honour sip_port
        if ($account !== null && $this->tableExists('address')) {
            $host = trim((string) ($account['sip_host'] ?? 'dynamic'));
            $ctx = 'sip:' . $username;
            $db->prepare('DELETE FROM address WHERE grp = ? AND context_info = ?')->execute([self::GRP_CUSTOMER, $ctx]);
            if ($host !== '' && strtolower($host) !== 'dynamic' && filter_var($host, FILTER_VALIDATE_IP)) {
                $port = (int) ($account['sip_port'] ?? 5060);
                if ($port < 0 || $port > 65535) {
                    $port = 5060;
                }
                // 0 = any port (OpenSIPS address semantics)
                $this->upsertAddress(
                    self::GRP_CUSTOMER,
                    $host,
                    str_contains($host, ':') ? 128 : 32,
                    $port,
                    'any',
                    $ctx
                );
            }
        }
    }

    public function removeSubscriber(string $username, string $domain): void
    {
        $db = $this->db();
        if ($this->tableExists('subscriber')) {
            // use_domain=0 — remove all domain rows for this username
            $db->prepare('DELETE FROM subscriber WHERE username = ?')->execute([$username]);
        }
        if ($this->tableExists('address')) {
            $db->prepare('DELETE FROM address WHERE grp = ? AND context_info = ?')->execute([self::GRP_CUSTOMER, 'sip:' . $username]);
        }
        // Drop live registrations so disabled SIP cannot place calls
        if ($this->tableExists('location')) {
            try {
                $db->prepare('DELETE FROM location WHERE username = ?')->execute([$username]);
            } catch (\Throwable) {
            }
        }
        if ($this->hasMi()) {
            $this->miCall('ul_rm', ['AOR' => $username]);
            $this->miCall('ul_rm', [$username]);
        }
        unset($domain);
    }

    /** Disabled SIP / inactive customer / prepaid with no funds → allow_calls=0 → 402. */
    private function customerAllowsCalls(array $account): bool
    {
        if (strtolower(trim((string) ($account['status'] ?? 'active'))) !== 'active') {
            return false;
        }
        $cid = isset($account['customer_id']) ? (int) $account['customer_id'] : 0;
        if ($cid <= 0) {
            return true;
        }
        try {
            $portal = \Ownswitch\App::db();
            $st = $portal->prepare('SELECT billing_mode, credit_limit, status FROM customers WHERE id = ? LIMIT 1');
            $st->execute([$cid]);
            $c = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$c) {
                return true;
            }
            if (strtolower((string) ($c['status'] ?? '')) !== 'active') {
                return false;
            }
            if (strtolower((string) ($c['billing_mode'] ?? '')) !== 'prepaid') {
                return true;
            }
            $bst = $portal->prepare('SELECT balance, reserved FROM balances WHERE customer_id = ? LIMIT 1');
            $bst->execute([$cid]);
            $b = $bst->fetch(\PDO::FETCH_ASSOC) ?: ['balance' => '0', 'reserved' => '0'];
            $avail = (float) ($b['balance'] ?? 0) - (float) ($b['reserved'] ?? 0);
            return $avail > 0.000001;
        } catch (\Throwable) {
            return true;
        }
    }

    /** Re-push allow_calls for every active SIP account of a customer (after top-up / spend). */
    public function refreshAllowCallsForCustomer(int $customerId): void
    {
        if ($customerId <= 0 || !$this->enabled()) {
            return;
        }
        try {
            $portal = \Ownswitch\App::db();
            $st = $portal->prepare("SELECT * FROM sip_accounts WHERE customer_id = ? AND status = 'active'");
            $st->execute([$customerId]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $effective = \Ownswitch\Support\MediaPolicy::resolve(
                    isset($row['media_policy']) ? (string) $row['media_policy'] : null,
                    $portal
                );
                $row['_effective_media'] = $effective;
                $this->upsertSubscriber(
                    (string) $row['username'],
                    (string) $row['domain'],
                    (string) $row['ha1'],
                    (string) ($row['ha1b'] ?? ''),
                    $row
                );
            }
        } catch (\Throwable $e) {
            error_log('[ownswitch] refreshAllowCallsForCustomer: ' . $e->getMessage());
        }
    }

    public function upsertAddress(int $group, string $ip, int $mask, int $port, string $proto, string $context = ''): void
    {
        $db = $this->db();
        $st = $db->prepare('SELECT id FROM address WHERE grp = ? AND ip = ? AND mask = ? AND port = ?');
        $st->execute([$group, $ip, $mask, $port]);
        if ($id = $st->fetchColumn()) {
            if ($context !== '') {
                $db->prepare('UPDATE address SET proto = ?, context_info = ? WHERE id = ?')->execute([$proto, $context, $id]);
            }
            return;
        }
        $db->prepare('INSERT INTO address (grp, ip, mask, port, proto, pattern, context_info) VALUES (?,?,?,?,?,NULL,?)')
            ->execute([$group, $ip, $mask, $port, $proto, $context !== '' ? $context : null]);
    }

    public function removeAddress(int $group, string $ip, int $mask, int $port): void
    {
        $this->db()->prepare('DELETE FROM address WHERE grp = ? AND ip = ? AND mask = ? AND port = ?')->execute([$group, $ip, $mask, $port]);
    }

    public function reloadAddresses(): bool
    {
        return $this->mi('address_reload');
    }

    public function reloadRouting(): bool
    {
        return $this->mi('dr_reload');
    }

    public function reloadRegistrant(): bool
    {
        return $this->mi('reg_reload') || $this->mi('uac_reg_reload');
    }

    /** @param array<string,mixed> $trunk */
    public function syncTrunk(array $trunk): void
    {
        $id = (int) $trunk['id'];
        $gwid = self::gwid($id);
        $active = ($trunk['status'] ?? '') === 'active';
        $host = (string) $trunk['host'];
        $port = (int) ($trunk['port'] ?? 5060);
        $transport = strtolower((string) ($trunk['transport'] ?? 'udp'));
        $address = $host . ($port > 0 && $port !== 5060 ? ':' . $port : '');
        if (in_array($transport, ['tcp', 'tls'], true)) {
            $address .= ';transport=' . $transport;
        }

        if (!$active) {
            $this->removeTrunk($id);
            return;
        }

        if (!$this->tableExists('dr_gateways')) {
            throw new \RuntimeException('opensips.dr_gateways missing — run: sudo bash /opt/ownswitch-src/deploy/fix-drouting-schema.sh');
        }

        // Multi remove-prefix is applied in OpenSIPS script via attrs rstrip=; keep gateway.strip=0.
        $strip = 0;
        $pri = trim((string) ($trunk['add_prefix'] ?? ''));
        $probe = strtolower((string) ($trunk['qualify'] ?? 'yes')) === 'yes' ? 1 : 0;
        $attrs = self::trunkAttrs($trunk);
        $this->upsertGateway($gwid, $address, substr((string) $trunk['name'], 0, 120), $strip, $pri !== '' ? $pri : null, $probe, $attrs);

        if (filter_var($host, FILTER_VALIDATE_IP) && $this->tableExists('address')) {
            try {
                $this->upsertAddress(self::GRP_CARRIER, $host, str_contains($host, ':') ? 128 : 32, 0, 'any', 'trunk:' . $id);
            } catch (\Throwable $e) {
                error_log('[ownswitch] address upsert trunk ' . $id . ': ' . $e->getMessage());
            }
        }

        $mode = (string) ($trunk['auth_mode'] ?? 'ip');
        if ($mode === 'register') {
            try {
                $this->upsertRegistrant($trunk);
            } catch (\Throwable $e) {
                // Still keep the gateway; REGISTER is optional enhancement.
                throw new \RuntimeException(
                    'Gateway saved, but outbound REGISTER failed: ' . $e->getMessage()
                    . ' (For IP peering use Auth mode = IP peer instead.)',
                    0,
                    $e
                );
            }
        } else {
            $this->removeRegistrant($id);
        }
    }

    private function upsertGateway(
        string $gwid,
        string $address,
        string $desc,
        int $strip = 0,
        ?string $priPrefix = null,
        int $probeMode = 0,
        ?string $attrs = null,
    ): void {
        $db = $this->db();
        $cols = $this->columns('dr_gateways');
        if (!in_array('gwid', $cols, true) || !in_array('address', $cols, true)) {
            throw new \RuntimeException('dr_gateways schema unexpected (need gwid+address columns)');
        }
        $st = $db->prepare('SELECT id FROM dr_gateways WHERE gwid = ?');
        $st->execute([$gwid]);
        $rowId = $st->fetchColumn();

        $fields = ['type' => 0, 'address' => $address];
        if (in_array('strip', $cols, true)) {
            $fields['strip'] = max(0, $strip);
        }
        if (in_array('pri_prefix', $cols, true)) {
            $fields['pri_prefix'] = $priPrefix;
        }
        if (in_array('state', $cols, true)) {
            $fields['state'] = 0;
        }
        if (in_array('description', $cols, true)) {
            $fields['description'] = $desc;
        }
        if (in_array('probe_mode', $cols, true)) {
            $fields['probe_mode'] = $probeMode > 0 ? 1 : 0;
        }
        if (in_array('attrs', $cols, true)) {
            $fields['attrs'] = $attrs !== null && $attrs !== '' ? mb_substr($attrs, 0, 250) : null;
        }

        if ($rowId) {
            $parts = [];
            $vals = [];
            foreach ($fields as $c => $v) {
                $parts[] = "`{$c}` = ?";
                $vals[] = $v;
            }
            $vals[] = $rowId;
            $db->prepare('UPDATE dr_gateways SET ' . implode(',', $parts) . ' WHERE id = ?')->execute($vals);
            return;
        }

        $insert = ['gwid' => $gwid] + $fields;
        if (in_array('socket', $cols, true) && !array_key_exists('socket', $insert)) {
            $insert['socket'] = null;
        }
        $names = array_keys($insert);
        $sql = 'INSERT INTO dr_gateways (' . implode(',', array_map(static fn ($c) => "`{$c}`", $names)) . ') VALUES ('
            . implode(',', array_fill(0, count($names), '?')) . ')';
        $db->prepare($sql)->execute(array_values($insert));
    }

    /** Digits to strip from RURI = length of a single remove_prefix (legacy). */
    public static function stripDigits(string $removePrefix): int
    {
        $first = explode(',', $removePrefix)[0] ?? '';
        $p = preg_replace('/\D+/', '', $first) ?? '';
        return min(32, strlen($p));
    }

    /** @param array<string,mixed> $trunk OpenSIPS-applied attrs only (From / CID rewrite / multi-strip / tid). */
    public static function trunkAttrs(array $trunk): string
    {
        $parts = [];
        $tid = (int) ($trunk['id'] ?? 0);
        if ($tid > 0) {
            $parts[] = 'tid=' . $tid;
        }
        foreach (['fu' => 'from_user', 'fd' => 'from_domain', 'cidadd' => 'cid_add_prefix', 'cidrem' => 'cid_remove_prefix'] as $k => $col) {
            $v = trim((string) ($trunk[$col] ?? ''));
            if ($v !== '') {
                $parts[] = $k . '=' . rawurlencode($v);
            }
        }
        $cmin = (int) ($trunk['cid_min_len'] ?? 0);
        $cmax = (int) ($trunk['cid_max_len'] ?? 0);
        if ($cmin > 0) {
            $parts[] = 'cidmin=' . $cmin;
        }
        if ($cmax > 0) {
            $parts[] = 'cidmax=' . $cmax;
        }
        $rawRem = trim((string) ($trunk['remove_prefix'] ?? ''));
        if ($rawRem !== '') {
            $list = [];
            foreach (preg_split('/[\s,;|]+/', $rawRem) ?: [] as $p) {
                $p = preg_replace('/\D+/', '', $p) ?? '';
                if ($p !== '' && !in_array($p, $list, true)) {
                    $list[] = $p;
                }
            }
            usort($list, static fn ($a, $b) => strlen($b) <=> strlen($a));
            if ($list !== []) {
                $parts[] = 'rstrip=' . rawurlencode(implode(',', $list));
            }
        }
        return implode(';', $parts);
    }

    public function removeTrunk(int $trunkId): void
    {
        $gwid = self::gwid($trunkId);
        $db = $this->db();
        if ($this->tableExists('dr_gateways')) {
            $db->prepare('DELETE FROM dr_gateways WHERE gwid = ?')->execute([$gwid]);
        }
        if ($this->tableExists('address')) {
            $db->prepare('DELETE FROM address WHERE grp = ? AND context_info = ?')->execute([self::GRP_CARRIER, 'trunk:' . $trunkId]);
        }
        $this->removeRegistrant($trunkId);
    }

    /**
     * @param list<array{prefix:string,carrier_trunk_id:int,priority:int,status?:string,sort_alg?:string,strategy?:string}> $rules
     */
    public function syncRoutingRules(array $rules): void
    {
        if (!$this->tableExists('dr_rules')) {
            throw new \RuntimeException('opensips.dr_rules missing — apply drouting schema');
        }
        $db = $this->db();
        $byPrefix = [];
        foreach ($rules as $r) {
            if (($r['status'] ?? 'active') !== 'active') {
                continue;
            }
            $byPrefix[(string) $r['prefix']][] = $r;
        }
        foreach ($byPrefix as &$list) {
            usort($list, static fn ($a, $b) => ((int) $a['priority']) <=> ((int) $b['priority']));
        }
        unset($list);

        $db->prepare('DELETE FROM dr_rules WHERE groupid = ?')->execute([self::DR_GROUP]);
        $prio = 0;
        $hasSort = in_array('sort_alg', $this->columns('dr_rules'), true);
        foreach ($byPrefix as $prefix => $list) {
            $gws = [];
            $sortAlg = 'N';
            foreach ($list as $r) {
                $gws[] = self::gwid((int) $r['carrier_trunk_id']);
                if (($r['sort_alg'] ?? '') === 'R' || ($r['strategy'] ?? '') === 'random') {
                    $sortAlg = 'R'; // equal random pick among gateways
                }
            }
            $gwlist = implode(',', array_unique($gws));
            if ($gwlist === '') {
                continue;
            }
            // Trunk-group catch-all (*) → empty OpenSIPS prefix (default / longest-match fallback)
            $drPrefix = $prefix === '*' ? '' : $prefix;
            $desc = 'ownswitch:' . ($prefix === '*' ? 'default' : $prefix);
            if ($hasSort) {
                $db->prepare('INSERT INTO dr_rules (groupid, prefix, timerec, priority, routeid, gwlist, sort_alg, description) VALUES (?,?,?,?,NULL,?,?,?)')
                    ->execute([self::DR_GROUP, $drPrefix, '', $prio++, $gwlist, $sortAlg, $desc]);
            } else {
                $db->prepare('INSERT INTO dr_rules (groupid, prefix, timerec, priority, routeid, gwlist, description) VALUES (?,?,?,?,NULL,?,?)')
                    ->execute([self::DR_GROUP, $drPrefix, '', $prio++, $gwlist, $desc]);
            }
        }
    }

    /** @param array<string,mixed> $trunk */
    private function upsertRegistrant(array $trunk): void
    {
        if (!$this->tableExists('uac_registrant')) {
            throw new \RuntimeException('uac_registrant table missing — install opensips-uac-module');
        }
        $id = (int) $trunk['id'];
        $user = trim((string) ($trunk['auth_username'] ?? ''));
        $enc = (string) ($trunk['auth_secret_enc'] ?? '');
        if ($user === '' || $enc === '') {
            throw new \DomainException('Register mode needs Username and Password on the trunk.');
        }
        $pass = Crypto::decrypt($enc);
        $host = (string) $trunk['host'];
        $port = (int) ($trunk['port'] ?? 5060);
        $proxyHost = trim((string) ($trunk['outbound_proxy'] ?? ''));
        if ($proxyHost === '') {
            $proxyHost = $host;
        }
        $authProxy = 'sip:' . $proxyHost . ($port && $port !== 5060 ? ':' . $port : '');
        $domain = $this->sipDomain !== '' ? $this->sipDomain : $host;
        $uuid = self::regUuid($id);

        $db = $this->db();
        $st = $db->prepare('SELECT id FROM uac_registrant WHERE l_uuid = ?');
        $st->execute([$uuid]);
        if ($rowId = $st->fetchColumn()) {
            $db->prepare('UPDATE uac_registrant SET l_username=?, l_domain=?, r_username=?, r_domain=?, realm=?, auth_username=?, auth_password=?, auth_proxy=?, expires=? WHERE id=?')
                ->execute([$user, $domain, $user, $host, $host, $user, $pass, $authProxy, 300, $rowId]);
        } else {
            $db->prepare('INSERT INTO uac_registrant (l_uuid, l_username, l_domain, r_username, r_domain, realm, auth_username, auth_password, auth_proxy, expires) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$uuid, $user, $domain, $user, $host, $host, $user, $pass, $authProxy, 300]);
        }
    }

    private function removeRegistrant(int $trunkId): void
    {
        if (!$this->tableExists('uac_registrant')) {
            return;
        }
        $this->db()->prepare('DELETE FROM uac_registrant WHERE l_uuid = ?')->execute([self::regUuid($trunkId)]);
    }

    public static function gwid(int $trunkId): string
    {
        return 'T' . $trunkId;
    }

    public static function regUuid(int $trunkId): string
    {
        return 'trunk-' . $trunkId;
    }

    public function mi(string $command): bool
    {
        $r = $this->miCall($command);
        return $r !== null && !isset($r['error']);
    }

    /**
     * JSON-RPC MI call returning decoded body (null on transport failure).
     *
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function miCall(string $method, array $params = []): ?array
    {
        if ($this->miUrl === '') {
            return null;
        }
        $body = ['jsonrpc' => '2.0', 'method' => $method, 'id' => 1];
        if ($params !== []) {
            $body['params'] = $params;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'timeout' => 5,
            'ignore_errors' => true,
            'content' => json_encode($body, JSON_THROW_ON_ERROR),
        ]]);
        $raw = @file_get_contents($this->miUrl, false, $ctx);
        if ($raw === false) {
            return null;
        }
        $r = json_decode($raw, true);
        return is_array($r) ? $r : null;
    }

    /**
     * Live dialogs from OpenSIPS dlg_list (MI), with dialog table fallback.
     *
     * @return list<array<string,mixed>>
     */
    public function listDialogs(): array
    {
        $fromMi = $this->listDialogsMi();
        $fromDb = $this->listDialogsDb();
        if ($fromMi === []) {
            return $fromDb;
        }
        if ($fromDb === []) {
            return $fromMi;
        }
        // Merge by Call-ID so early/trying dialogs missing from one source still appear
        $byId = [];
        foreach (array_merge($fromDb, $fromMi) as $d) {
            if (!is_array($d)) {
                continue;
            }
            $cid = (string) ($d['callid'] ?? $d['Call-ID'] ?? $d['call_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $byId[$cid] = isset($byId[$cid]) ? array_merge($byId[$cid], $d) : $d;
        }
        return array_values($byId);
    }

    /** @return list<array<string,mixed>> */
    private function listDialogsMi(): array
    {
        foreach (['dlg_list', 'dlg_list_ctx'] as $method) {
            $r = $this->miCall($method);
            if ($r === null || isset($r['error'])) {
                continue;
            }
            $result = $r['result'] ?? null;
            if (!is_array($result)) {
                continue;
            }
            $list = $this->extractDialogList($result);
            if ($list !== []) {
                return $list;
            }
        }
        return [];
    }

    /**
     * @param array<string,mixed> $result
     * @return list<array<string,mixed>>
     */
    private function extractDialogList(array $result): array
    {
        foreach (['Dialogs', 'Dialog', 'dialogs', 'dialog'] as $k) {
            if (isset($result[$k]) && is_array($result[$k])) {
                $v = $result[$k];
                if ($v !== [] && !array_is_list($v) && isset($v['callid'])) {
                    return [$v];
                }
                if (array_is_list($v)) {
                    return array_values(array_filter($v, 'is_array'));
                }
            }
        }
        if (array_is_list($result)) {
            return array_values(array_filter($result, 'is_array'));
        }
        // Sometimes MI wraps as { "0": {...}, "1": {...} }
        $vals = array_values($result);
        if ($vals !== [] && is_array($vals[0] ?? null) && isset($vals[0]['callid'])) {
            return array_values(array_filter($vals, 'is_array'));
        }
        foreach ($result as $v) {
            if (is_array($v) && array_is_list($v)) {
                return array_values(array_filter($v, 'is_array'));
            }
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function listDialogsDb(): array
    {
        try {
            $db = $this->db();
            if (!$this->tableExists('dialog')) {
                return [];
            }
            $cols = $this->columns('dialog');
            $need = ['callid', 'from_uri', 'to_uri'];
            foreach ($need as $c) {
                if (!in_array($c, $cols, true)) {
                    return [];
                }
            }
            $stateCol = in_array('state', $cols, true) ? 'state' : (in_array('dlg_state', $cols, true) ? 'dlg_state' : null);
            $startCol = in_array('start_time', $cols, true) ? 'start_time' : null;
            $sql = 'SELECT callid, from_uri, to_uri'
                . ($stateCol ? ", `{$stateCol}` AS state" : '')
                . ($startCol ? ", `{$startCol}` AS start_time" : '')
                . (in_array('from_tag', $cols, true) ? ', from_tag' : '')
                . ' FROM dialog ORDER BY ' . ($startCol ? "`{$startCol}`" : 'callid') . ' DESC LIMIT 500';
            return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[ownswitch] listDialogsDb: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Sync SIP peer profile into OpenSIPS for dialplan / media lookups.
     *
     * @param array<string,mixed>|null $account optional codecs, dtmf, nat, etc.
     */
    public function syncSipPeerProfile(string $username, string $mediaPolicy, int $maxChannels, ?array $account = null): void
    {
        $db = $this->db();
        // MySQL 8 reserves QUALIFY — always backtick that identifier.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ownswitch_sip (
                username VARCHAR(64) NOT NULL PRIMARY KEY,
                media_policy VARCHAR(16) NOT NULL DEFAULT \'relay\',
                max_channels INT NOT NULL DEFAULT 2,
                sip_port INT UNSIGNED NOT NULL DEFAULT 5060,
                codecs VARCHAR(190) NULL,
                dtmfmode VARCHAR(32) NULL,
                dtmf_pass VARCHAR(8) NULL,
                nat VARCHAR(64) NULL,
                insecure VARCHAR(64) NULL,
                peer_type VARCHAR(16) NULL,
                `qualify` VARCHAR(8) NULL,
                allowtransfer VARCHAR(8) NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        foreach ([
            ['sip_port', 'INT UNSIGNED NOT NULL DEFAULT 5060'],
            ['codecs', 'VARCHAR(190) NULL'],
            ['dtmfmode', 'VARCHAR(32) NULL'],
            ['dtmf_pass', 'VARCHAR(8) NULL'],
            ['nat', 'VARCHAR(64) NULL'],
            ['insecure', 'VARCHAR(64) NULL'],
            ['peer_type', 'VARCHAR(16) NULL'],
            ['qualify', 'VARCHAR(8) NULL'],
            ['allowtransfer', 'VARCHAR(8) NULL'],
            ['max_cps', 'INT NOT NULL DEFAULT 1'],
        ] as [$col, $def]) {
            try {
                $db->exec("ALTER TABLE ownswitch_sip ADD COLUMN `{$col}` {$def}");
            } catch (\Throwable) {
                // column already exists
            }
        }
        try {
            $db->exec('ALTER TABLE ownswitch_sip MODIFY max_channels INT NOT NULL DEFAULT 2');
        } catch (\Throwable) {
        }

        $port = (int) ($account['sip_port'] ?? 5060);
        if ($port < 0 || $port > 65535) {
            $port = 5060;
        }
        $maxCps = (int) ($account['max_cps'] ?? 1);
        if ($maxCps < -1) {
            $maxCps = -1;
        }
        if ($maxCps === 0) {
            $maxCps = 1;
        }
        $st = $db->prepare(
            'INSERT INTO ownswitch_sip
                (username, media_policy, max_channels, max_cps, sip_port, codecs, dtmfmode, dtmf_pass, nat, insecure, peer_type, `qualify`, allowtransfer)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                media_policy = VALUES(media_policy),
                max_channels = VALUES(max_channels),
                max_cps = VALUES(max_cps),
                sip_port = VALUES(sip_port),
                codecs = VALUES(codecs),
                dtmfmode = VALUES(dtmfmode),
                dtmf_pass = VALUES(dtmf_pass),
                nat = VALUES(nat),
                insecure = VALUES(insecure),
                peer_type = VALUES(peer_type),
                `qualify` = VALUES(`qualify`),
                allowtransfer = VALUES(allowtransfer)'
        );
        $st->execute([
            $username,
            $mediaPolicy,
            (int) $maxChannels,
            $maxCps,
            $port,
            isset($account['codecs']) ? mb_substr((string) $account['codecs'], 0, 190) : null,
            isset($account['dtmfmode']) ? mb_substr((string) $account['dtmfmode'], 0, 32) : null,
            isset($account['dtmf_pass']) ? mb_substr((string) $account['dtmf_pass'], 0, 8) : 'yes',
            isset($account['nat']) ? mb_substr((string) $account['nat'], 0, 64) : null,
            isset($account['insecure']) ? mb_substr((string) $account['insecure'], 0, 64) : null,
            isset($account['peer_type']) ? mb_substr((string) $account['peer_type'], 0, 16) : null,
            isset($account['qualify']) ? mb_substr((string) $account['qualify'], 0, 8) : null,
            isset($account['allowtransfer']) ? mb_substr((string) $account['allowtransfer'], 0, 8) : null,
        ]);
    }

    public function removeSipPeerProfile(string $username): void
    {
        if ($this->tableExists('ownswitch_sip')) {
            $this->db()->prepare('DELETE FROM ownswitch_sip WHERE username = ?')->execute([$username]);
        }
    }

    /** Map DID digits → SIP AOR via OpenSIPS dbaliases (alias_db module). */
    public function syncDidAlias(string $didNorm, string $sipUsername, string $domain): void
    {
        $db = $this->db();
        $this->ensureDbAliases();
        $aliasUser = preg_replace('/\D+/', '', $didNorm) ?? '';
        if ($aliasUser === '' || $sipUsername === '') {
            throw new \InvalidArgumentException('DID alias requires digits and SIP username');
        }
        $db->prepare('DELETE FROM dbaliases WHERE alias_username = ?')->execute([$aliasUser]);
        // Also store +prefixed form if different
        $db->prepare('DELETE FROM dbaliases WHERE alias_username = ?')->execute(['+' . $aliasUser]);
        $ins = $db->prepare('INSERT INTO dbaliases (alias_username, alias_domain, username, domain) VALUES (?,?,?,?)');
        $ins->execute([$aliasUser, $domain, $sipUsername, $domain]);
        $ins->execute(['+' . $aliasUser, $domain, $sipUsername, $domain]);
        $this->mi('alias_db_reload') || $this->mi('domain_reload');
    }

    public function removeDidAlias(string $didNorm): void
    {
        if (!$this->tableExists('dbaliases')) {
            return;
        }
        $aliasUser = preg_replace('/\D+/', '', $didNorm) ?? '';
        if ($aliasUser === '') {
            return;
        }
        $this->db()->prepare('DELETE FROM dbaliases WHERE alias_username IN (?,?)')->execute([$aliasUser, '+' . $aliasUser]);
        $this->mi('alias_db_reload');
    }

    /**
     * Hang up a live dialog. Tries dlg_end_dlg with callid (OpenSIPS 3.x).
     *
     * @return array{ok:bool,detail:string}
     */
    public function endDialog(string $callId, ?string $fromTag = null, ?string $toTag = null): array
    {
        if ($callId === '') {
            return ['ok' => false, 'detail' => 'Missing Call-ID'];
        }
        // OpenSIPS 3.6: dlg_end_dlg params often { callid, from_tag } or dialog hash
        $attempts = [];
        if ($fromTag !== null && $fromTag !== '') {
            $attempts[] = ['dlg_end_dlg', ['callid' => $callId, 'fhtag' => $fromTag]];
            $attempts[] = ['dlg_end_dlg', [$callId, $fromTag]];
        }
        $attempts[] = ['dlg_end_dlg', ['callid' => $callId]];
        $attempts[] = ['dlg_end_dlg', [$callId]];
        foreach ($attempts as [$method, $params]) {
            $r = $this->miCall($method, is_array($params) ? $params : [$params]);
            if ($r !== null && !isset($r['error'])) {
                return ['ok' => true, 'detail' => 'Dialog terminate requested'];
            }
            $detail = is_array($r['error'] ?? null) ? (string) ($r['error']['message'] ?? json_encode($r['error'])) : (string) ($r['error'] ?? 'no response');
            $last = $detail;
        }
        return ['ok' => false, 'detail' => $last ?? 'dlg_end_dlg failed'];
    }

    private function ensureDbAliases(): void
    {
        if ($this->tableExists('dbaliases')) {
            return;
        }
        $this->db()->exec(
            'CREATE TABLE IF NOT EXISTS dbaliases (
                id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
                alias_username VARCHAR(64) NOT NULL DEFAULT \'\',
                alias_domain VARCHAR(64) NOT NULL DEFAULT \'\',
                username VARCHAR(64) NOT NULL DEFAULT \'\',
                domain VARCHAR(64) NOT NULL DEFAULT \'\',
                KEY alias_user_idx (alias_username, alias_domain),
                KEY user_idx (username, domain)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function ensureSubscriberExtraColumns(): void
    {
        static $done = false;
        if ($done || !$this->tableExists('subscriber')) {
            return;
        }
        foreach ([
            'media_policy VARCHAR(16) NOT NULL DEFAULT \'relay\'',
            'dtmf_pass VARCHAR(8) NOT NULL DEFAULT \'yes\'',
            'cid_mode VARCHAR(16) NOT NULL DEFAULT \'passthrough\'',
            'cid_min_len INT UNSIGNED NOT NULL DEFAULT 0',
            'cid_max_len INT UNSIGNED NOT NULL DEFAULT 0',
            'allow_calls TINYINT(1) NOT NULL DEFAULT 1',
        ] as $colDef) {
            $col = explode(' ', $colDef, 2)[0];
            $cols = $this->columns('subscriber');
            if (in_array($col, $cols, true)) {
                continue;
            }
            try {
                $this->db()->exec("ALTER TABLE subscriber ADD COLUMN {$colDef}");
                $this->columns('subscriber', true);
            } catch (\Throwable) {
                // concurrent alter or no DDL rights
            }
        }
        $done = true;
    }

    /** @deprecated use ensureSubscriberExtraColumns */
    private function ensureSubscriberMediaColumn(): void
    {
        $this->ensureSubscriberExtraColumns();
    }

    /**
     * Registered contacts keyed by username (best contact prefers `received` for NAT).
     * Prefers live MI usrloc dump (memory); falls back to SQL location table.
     *
     * @return array<string, array{contact:string, received:string, expires:int}>
     */
    public function registeredContacts(): array
    {
        $fromMi = $this->registeredContactsFromMi();
        $fromSql = $this->registeredContactsFromSql();
        // SQL write-through is authoritative; MI only fills gaps (avoids stale MI-only ghosts)
        foreach ($fromMi as $u => $info) {
            if (!isset($fromSql[$u])) {
                $fromSql[$u] = $info;
            }
        }
        return $fromSql;
    }

    /** Drop a dead/stale registration so the portal stops showing green. */
    public function purgeRegistration(string $username): void
    {
        $username = trim($username);
        if ($username === '' || !$this->enabled()) {
            return;
        }
        try {
            if ($this->tableExists('location')) {
                $this->db()->prepare('DELETE FROM location WHERE username = ?')->execute([$username]);
            }
        } catch (\Throwable) {
        }
        if ($this->hasMi()) {
            $this->miCall('ul_rm', ['AOR' => $username]);
            $this->miCall('ul_rm', [$username]);
        }
    }

    /**
     * @return array<string, array{contact:string, received:string, expires:int}>
     */
    private function registeredContactsFromMi(): array
    {
        if (!$this->hasMi()) {
            return [];
        }
        foreach (['ul_dump', 'reg_list', 'usrloc:dump'] as $method) {
            $r = $this->miCall($method);
            if ($r === null || isset($r['error'])) {
                continue;
            }
            $out = [];
            $walk = static function ($node) use (&$walk, &$out): void {
                if (!is_array($node)) {
                    return;
                }
                $user = $node['username'] ?? $node['AOR'] ?? $node['aor'] ?? $node['User'] ?? null;
                if (is_string($user) && str_contains($user, '@')) {
                    $user = explode('@', $user, 2)[0];
                }
                $contact = $node['Contact'] ?? $node['contact'] ?? $node['address'] ?? null;
                $received = $node['Received'] ?? $node['received'] ?? '';
                $expires = $node['Expires'] ?? $node['expires'] ?? $node['Expiry'] ?? null;
                if (is_string($user) && $user !== '' && (is_string($contact) || is_array($contact))) {
                    $u = strtolower($user);
                    if (is_array($contact)) {
                        $received = (string) ($contact['received'] ?? $contact['Received'] ?? $received);
                        $expires = $contact['Expires'] ?? $contact['expires'] ?? $expires;
                        $contact = (string) ($contact['Contact'] ?? $contact['contact'] ?? $contact['address'] ?? '');
                    }
                    // Require a parseable future expiry — never treat missing/ambiguous as registered
                    $expTs = null;
                    if (is_numeric($expires)) {
                        $expTs = (int) $expires;
                        // Remaining TTL (seconds) vs absolute unix timestamp
                        if ($expTs > 0 && $expTs < 86400 * 10) {
                            $expTs = time() + $expTs;
                        }
                    } elseif (is_string($expires) && $expires !== '') {
                        $ts = strtotime($expires);
                        if ($ts !== false) {
                            $expTs = $ts;
                        }
                    }
                    if ($expTs === null || $expTs <= time()) {
                        // expired / unknown — do not mark registered
                    } elseif (!isset($out[$u]) && (string) $contact !== '') {
                        $out[$u] = [
                            'contact' => (string) $contact,
                            'received' => is_string($received) ? $received : '',
                            'expires' => $expTs,
                        ];
                    }
                }
                foreach ($node as $v) {
                    if (is_array($v)) {
                        $walk($v);
                    }
                }
            };
            $walk($r['result'] ?? $r);
            if ($out !== []) {
                return $out;
            }
        }
        return [];
    }

    /**
     * @return array<string, array{contact:string, received:string, expires:int}>
     */
    private function registeredContactsFromSql(): array
    {
        if (!$this->enabled() || !$this->tableExists('location')) {
            return [];
        }
        $cols = $this->columns('location');
        $need = ['username', 'contact'];
        foreach ($need as $c) {
            if (!in_array($c, $cols, true)) {
                return [];
            }
        }
        $hasRecv = in_array('received', $cols, true);
        $hasExp = in_array('expires', $cols, true);
        // OpenSIPS 3.6 location.expires is INT unix timestamp (not DATETIME).
        // Only live contacts: expires must be in the future. Never fall back to unfiltered SELECT.
        $sql = 'SELECT username, contact'
            . ($hasRecv ? ', received' : '')
            . ($hasExp ? ', expires' : '')
            . ' FROM location';
        if ($hasExp) {
            $sql .= ' WHERE expires IS NOT NULL AND expires > UNIX_TIMESTAMP()';
        } else {
            // No expires column → cannot prove registration is live
            return [];
        }
        $out = [];
        try {
            $rows = $this->db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $now = time();
        foreach ($rows as $row) {
            $u = strtolower((string) ($row['username'] ?? ''));
            if ($u === '' || isset($out[$u])) {
                continue;
            }
            $contact = (string) ($row['contact'] ?? '');
            if ($contact === '') {
                continue;
            }
            $raw = $row['expires'] ?? null;
            if (!is_numeric($raw)) {
                continue;
            }
            $expires = (int) $raw;
            if ($expires <= $now) {
                continue;
            }
            $out[$u] = [
                'contact' => $contact,
                'received' => $hasRecv ? (string) ($row['received'] ?? '') : '',
                'expires' => $expires,
            ];
        }
        return $out;
    }

    /**
     * Gateway probe state from MI dr_gw_status (gwid => Active|Inactive|…).
     *
     * @return array<string, string>
     */
    public function gatewayStatuses(): array
    {
        $r = $this->miCall('dr_gw_status');
        if ($r === null || isset($r['error'])) {
            $r = $this->miCall('drouting:gw_status');
        }
        if ($r === null || isset($r['error']) || !is_array($r['result'] ?? null)) {
            return [];
        }
        $out = [];
        $walk = static function ($node) use (&$walk, &$out): void {
            if (!is_array($node)) {
                return;
            }
            $gwid = $node['gwid'] ?? $node['GW_ID'] ?? $node['ID'] ?? null;
            $state = $node['State'] ?? $node['state'] ?? $node['Enabled'] ?? $node['enabled'] ?? $node['Status'] ?? $node['status'] ?? null;
            if (is_string($gwid) && $gwid !== '' && $state !== null && $state !== '') {
                $out[$gwid] = is_string($state) ? $state : (string) $state;
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($r['result']);
        return $out;
    }

    /** @return list<string> */
    private function columns(string $table, bool $fresh = false): array
    {
        static $cache = [];
        if ($fresh) {
            unset($cache[$table]);
        }
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $rows = $this->db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $cache[$table] = array_values(array_map(static fn ($r) => (string) $r['Field'], $rows));
    }

    private function tableExists(string $table): bool
    {
        $st = $this->db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    }

    private function db(): PDO
    {
        if ($this->osDb === null) {
            throw new \RuntimeException('OpenSIPS database is not configured');
        }
        return $this->osDb;
    }
}
