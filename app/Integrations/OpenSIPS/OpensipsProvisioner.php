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

    public function upsertSubscriber(string $username, string $domain, string $ha1, string $ha1b): void
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

        if ($id) {
            $parts = [];
            $vals = [];
            foreach ($set as $c => $v) {
                $parts[] = "`{$c}` = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $db->prepare('UPDATE subscriber SET ' . implode(',', $parts) . ' WHERE id = ?')->execute($vals);
            return;
        }

        $insert = ['username' => $username, 'domain' => $domain] + $set;
        // Optional NOT NULL columns with empty defaults on some schemas
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

    public function removeSubscriber(string $username, string $domain): void
    {
        if (!$this->tableExists('subscriber')) {
            return;
        }
        $this->db()->prepare('DELETE FROM subscriber WHERE username = ? AND domain = ?')->execute([$username, $domain]);
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
            throw new \RuntimeException('opensips.dr_gateways missing — apply drouting schema');
        }

        $db = $this->db();
        $desc = substr((string) $trunk['name'], 0, 120);
        $st = $db->prepare('SELECT id FROM dr_gateways WHERE gwid = ?');
        $st->execute([$gwid]);
        if ($rowId = $st->fetchColumn()) {
            $db->prepare('UPDATE dr_gateways SET type = 0, address = ?, strip = 0, state = 0, description = ? WHERE id = ?')
                ->execute([$address, $desc, $rowId]);
        } else {
            $db->prepare('INSERT INTO dr_gateways (gwid, type, address, strip, pri_prefix, attrs, probe_mode, state, socket, description) VALUES (?,0,?,0,NULL,NULL,0,0,NULL,?)')
                ->execute([$gwid, $address, $desc]);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->upsertAddress(self::GRP_CARRIER, $host, str_contains($host, ':') ? 128 : 32, 0, 'any', 'trunk:' . $id);
        }

        $mode = (string) ($trunk['auth_mode'] ?? 'ip');
        if ($mode === 'register') {
            $this->upsertRegistrant($trunk);
        } else {
            $this->removeRegistrant($id);
        }
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
            if ($hasSort) {
                $db->prepare('INSERT INTO dr_rules (groupid, prefix, timerec, priority, routeid, gwlist, sort_alg, description) VALUES (?,?,?,?,NULL,?,?,?)')
                    ->execute([self::DR_GROUP, $prefix, '', $prio++, $gwlist, 'N', 'ownswitch:' . $prefix]);
            } else {
                $db->prepare('INSERT INTO dr_rules (groupid, prefix, timerec, priority, routeid, gwlist, description) VALUES (?,?,?,?,NULL,?,?)')
                    ->execute([self::DR_GROUP, $prefix, '', $prio++, $gwlist, 'ownswitch:' . $prefix]);
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
