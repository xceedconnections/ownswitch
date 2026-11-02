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

    public static function ha1(string $username, string $realm, string $password): array
    {
        return [md5("{$username}:{$realm}:{$password}"), md5("{$username}@{$realm}:{$realm}:{$password}")];
    }

    /**
     * @param array<string,mixed>|null $account Ownswitch sip_accounts row (CallerID → rpid, etc.)
     */
    public function upsertSubscriber(string $username, string $domain, string $ha1, string $ha1b, ?array $account = null): void
    {
        $db = $this->db();
        if (!$this->tableExists('subscriber')) {
            throw new \RuntimeException('opensips.subscriber table missing — re-run deploy stage 50 / OpenSIPS schema');
        }
        $cols = $this->columns('subscriber');
        $st = $db->prepare('SELECT id FROM subscriber WHERE username = ? AND domain = ?');
        $st->execute([$username, $domain]);
        $id = $st->fetchColumn();

        $set = ['ha1' => $ha1];
        if (in_array('ha1b', $cols, true)) {
            $set['ha1b'] = $ha1b;
        }
        if (in_array('password', $cols, true)) {
            $set['password'] = '';
        }
        if ($account !== null && in_array('rpid', $cols, true)) {
            $cid = trim((string) ($account['caller_id'] ?? ''));
            $set['rpid'] = $cid !== '' ? $cid : '';
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

        // Fixed-host SIP users → permissions address (customer group)
        if ($account !== null && $this->tableExists('address')) {
            $host = trim((string) ($account['sip_host'] ?? 'dynamic'));
            $ctx = 'sip:' . $username;
            $db->prepare('DELETE FROM address WHERE grp = ? AND context_info = ?')->execute([self::GRP_CUSTOMER, $ctx]);
            if ($host !== '' && strtolower($host) !== 'dynamic' && filter_var($host, FILTER_VALIDATE_IP)) {
                $this->upsertAddress(self::GRP_CUSTOMER, $host, str_contains($host, ':') ? 128 : 32, 0, 'any', $ctx);
            }
        }
    }

    public function removeSubscriber(string $username, string $domain): void
    {
        $db = $this->db();
        if ($this->tableExists('subscriber')) {
            $db->prepare('DELETE FROM subscriber WHERE username = ? AND domain = ?')->execute([$username, $domain]);
        }
        if ($this->tableExists('address')) {
            $db->prepare('DELETE FROM address WHERE grp = ? AND context_info = ?')->execute([self::GRP_CUSTOMER, 'sip:' . $username]);
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

        $strip = self::stripDigits((string) ($trunk['remove_prefix'] ?? ''));
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

    /** Digits to strip from RURI = length of remove_prefix (Magnus). */
    public static function stripDigits(string $removePrefix): int
    {
        $p = preg_replace('/\D+/', '', $removePrefix) ?? '';
        return min(32, strlen($p));
    }

    /** @param array<string,mixed> $trunk */
    public static function trunkAttrs(array $trunk): string
    {
        $parts = [];
        $map = [
            'fu' => 'from_user',
            'fd' => 'from_domain',
            'cidadd' => 'cid_add_prefix',
            'cidrem' => 'cid_remove_prefix',
            'codecs' => 'codecs',
            'param' => 'addparameter',
            'dm' => 'directmedia',
            'dtmf' => 'dtmfmode',
            'ctx' => 'context',
        ];
        foreach ($map as $k => $col) {
            $v = trim((string) ($trunk[$col] ?? ''));
            if ($v !== '') {
                $parts[] = $k . '=' . rawurlencode($v);
            }
        }
        $extra = trim((string) ($trunk['asterisk_extra'] ?? ''));
        if ($extra !== '') {
            $parts[] = 'extra=' . rawurlencode(mb_substr(preg_replace('/\s+/', ' ', $extra) ?? $extra, 0, 120));
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

    /** @param list<array{prefix:string,carrier_trunk_id:int,priority:int,status?:string}> $rules */
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
            foreach ($list as $r) {
                $gws[] = self::gwid((int) $r['carrier_trunk_id']);
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
                    ->execute([self::DR_GROUP, $drPrefix, '', $prio++, $gwlist, 'N', $desc]);
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
        if ($this->miUrl === '') {
            return false;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => 'Content-Type: application/json', 'timeout' => 3, 'ignore_errors' => true,
            'content' => json_encode(['jsonrpc' => '2.0', 'method' => $command, 'id' => 1], JSON_THROW_ON_ERROR),
        ]]);
        $raw = @file_get_contents($this->miUrl, false, $ctx);
        $r = $raw === false ? null : json_decode($raw, true);
        return is_array($r) && !isset($r['error']);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        static $cache = [];
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
