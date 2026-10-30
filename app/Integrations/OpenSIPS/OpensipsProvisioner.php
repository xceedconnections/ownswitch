<?php

declare(strict_types=1);

namespace Ownswitch\Integrations\OpenSIPS;

use PDO;

/**
 * Projects Ownswitch SIP accounts / IP endpoints into the OpenSIPS database (`subscriber`, `address`)
 * and asks OpenSIPS to reload via the MI HTTP JSON-RPC endpoint. Ownswitch tables stay authoritative.
 * Only OpenSIPS standard-schema tables are touched.
 */
final class OpensipsProvisioner
{
    public function __construct(private ?PDO $osDb, private string $miUrl = '')
    {
    }

    /** Build from config/app.php; returns a disabled instance when OPENSIPS_ENABLED=false. */
    public static function fromConfig(): self
    {
        $cfg = (array) \Ownswitch\App::config('app.integrations.opensips', []);
        if (empty($cfg['enabled'])) {
            return new self(null, '');
        }
        $db = $cfg['db'];
        $pdo = \Ownswitch\Database\Connection::make($db + ['charset' => 'utf8mb4']);
        return new self($pdo, (string) ($cfg['mi_url'] ?? ''));
    }

    public function enabled(): bool
    {
        return $this->osDb !== null;
    }

    /** HA1 values for OpenSIPS digest auth (calculate_ha1 = 1 not required when HA1s are supplied). */
    public static function ha1(string $username, string $realm, string $password): array
    {
        return [md5("{$username}:{$realm}:{$password}"), md5("{$username}@{$realm}:{$realm}:{$password}")];
    }

    public function upsertSubscriber(string $username, string $domain, string $ha1, string $ha1b): void
    {
        $db = $this->db();
        $st = $db->prepare('SELECT id FROM subscriber WHERE username = ? AND domain = ?');
        $st->execute([$username, $domain]);
        if ($id = $st->fetchColumn()) {
            $db->prepare("UPDATE subscriber SET ha1 = ?, ha1b = ?, password = '' WHERE id = ?")->execute([$ha1, $ha1b, $id]);
        } else {
            $db->prepare("INSERT INTO subscriber (username, domain, password, ha1, ha1b) VALUES (?,?, '', ?, ?)")->execute([$username, $domain, $ha1, $ha1b]);
        }
    }

    public function removeSubscriber(string $username, string $domain): void
    {
        $this->db()->prepare('DELETE FROM subscriber WHERE username = ? AND domain = ?')->execute([$username, $domain]);
    }

    /** `address` table (permissions module): group id is the customer id so routing can key off it. */
    public function upsertAddress(int $group, string $ip, int $mask, int $port, string $proto): void
    {
        $db = $this->db();
        $st = $db->prepare('SELECT id FROM address WHERE grp = ? AND ip = ? AND mask = ? AND port = ?');
        $st->execute([$group, $ip, $mask, $port]);
        if (!$st->fetchColumn()) {
            $db->prepare('INSERT INTO address (grp, ip, mask, port, proto, pattern, context_info) VALUES (?,?,?,?,?,NULL,NULL)')
                ->execute([$group, $ip, $mask, $port, $proto]);
        }
    }

    public function removeAddress(int $group, string $ip, int $mask, int $port): void
    {
        $this->db()->prepare('DELETE FROM address WHERE grp = ? AND ip = ? AND mask = ? AND port = ?')->execute([$group, $ip, $mask, $port]);
    }

    /** Ask OpenSIPS to reload the permissions `address` table through mi_http (JSON-RPC 2.0). */
    public function reloadAddresses(): bool
    {
        return $this->mi('address_reload');
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

    private function db(): PDO
    {
        if ($this->osDb === null) {
            throw new \RuntimeException('OpenSIPS database is not configured');
        }
        return $this->osDb;
    }
}
