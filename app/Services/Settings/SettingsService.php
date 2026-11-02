<?php

declare(strict_types=1);

namespace Ownswitch\Services\Settings;

use Ownswitch\App;
use PDO;

/** Key/value settings with config/app.php defaults. */
final class SettingsService
{
    /** key => [label, type, help]. Only these keys are editable. */
    public const SCHEMA = [
        'default_currency'            => ['Default currency', 'text', '3-letter ISO code'],
        'charge_connect_on_zero'      => ['Charge connection fee on zero-duration calls', 'bool', 'Default off'],
        'rating_tolerance'            => ['Rating discrepancy tolerance', 'decimal', 'Flag when |CGRateS − portal| exceeds this'],
        'prepaid_hard_cap_seconds'    => ['Prepaid hard max call duration (s)', 'int', ''],
        'default_media_policy'        => [
            'Default media policy',
            'media',
            'Relay = RTP through this switch (RTPengine; SDP media IP = your switch). Direct = RTP peer-to-peer (signalling only on switch). Record = relay + record.',
        ],
        'recording_retention_days'    => ['Recording retention (days)', 'int', ''],
        'switch_logo'                 => ['Switch logo', 'logo', 'PNG/JPG/WEBP/SVG, max 2 MB. Shown in the top navigation for all users.'],
        'switch_name'                 => ['Switch display name', 'text', 'Optional brand name next to logo when no image is set'],
    ];

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->cache === null) {
            try {
                $this->cache = $this->pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (\Throwable) {
                $this->cache = [];
            }
        }
        return $this->cache;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();
        if (array_key_exists($key, $all) && $all[$key] !== null) {
            return $all[$key];
        }
        $cfgKey = match ($key) {
            'default_currency'            => 'currency',
            'default_customer_initial'    => 'customer_initial',
            'default_customer_subsequent' => 'customer_subsequent',
            'default_carrier_initial'     => 'carrier_initial',
            'default_carrier_subsequent'  => 'carrier_subsequent',
            'default_media_policy'        => 'media_policy',
            default                       => $key,
        };
        $v = App::config('app.defaults.' . $cfgKey);
        return $v === null ? $default : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
    }

    public function int(string $key, int $default): int
    {
        return (int) ($this->get($key) ?? $default);
    }

    public function bool(string $key): bool
    {
        return in_array($this->get($key), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value, ?int $userId): void
    {
        if (!isset(self::SCHEMA[$key])) {
            throw new \InvalidArgumentException('Unknown setting');
        }
        $this->pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)')
            ->execute([$key, $value, $userId]);
        $this->cache = null;
    }

    /**
     * Validate a submitted value for a schema key.
     *
     * @return array{0:?string,1:?string} [normalised value, error]
     */
    public static function validate(string $key, mixed $raw): array
    {
        [$label, $type] = self::SCHEMA[$key];
        $v = is_string($raw) ? trim($raw) : '';
        switch ($type) {
            case 'bool':
                return [!empty($raw) ? '1' : '0', null];
            case 'int':
                return preg_match('/^\d{1,7}$/', $v) && (int) $v >= 1 ? [(string) (int) $v, null] : [null, "{$label} must be a positive whole number."];
            case 'decimal':
                return preg_match('/^\d+(\.\d{1,8})?$/', $v) ? [$v, null] : [null, "{$label} must be a non-negative decimal."];
            case 'media':
                return in_array($v, ['direct', 'relay', 'record'], true) ? [$v, null] : [null, "{$label} must be direct, relay or record."];
            case 'logo':
                // Path set by controller after upload; empty keeps existing.
                return [$v, null];
            case 'text':
                if ($key === 'default_currency') {
                    return preg_match('/^[A-Z]{3}$/', strtoupper($v)) ? [strtoupper($v), null] : [null, "{$label} must be a 3-letter code."];
                }
                if (mb_strlen($v) > 80) {
                    return [null, "{$label} must be at most 80 characters."];
                }
                return [$v, null];
            default:
                return preg_match('/^[A-Z]{3}$/', strtoupper($v)) ? [strtoupper($v), null] : [null, "{$label} must be a 3-letter code."];
        }
    }
}
