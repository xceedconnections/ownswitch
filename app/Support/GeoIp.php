<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** Lightweight country lookup (ip-api.com, cached in APCu/file). */
final class GeoIp
{
    /** @return array{country:string,countryCode:string,city:string,isp:string} */
    public static function lookup(string $ip): array
    {
        $empty = ['country' => 'Unknown', 'countryCode' => '', 'city' => '', 'isp' => ''];
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['country' => 'Private / local', 'countryCode' => '', 'city' => '', 'isp' => ''];
        }
        $cacheKey = 'os_geo_' . $ip;
        if (function_exists('apcu_fetch')) {
            $hit = apcu_fetch($cacheKey, $ok);
            if ($ok && is_array($hit)) {
                return $hit;
            }
        }
        $file = sys_get_temp_dir() . '/ownswitch_geo_' . md5($ip) . '.json';
        if (is_file($file) && (time() - (int) filemtime($file)) < 86400) {
            $j = json_decode((string) file_get_contents($file), true);
            if (is_array($j)) {
                return $j + $empty;
            }
        }
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $raw = @file_get_contents('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,countryCode,city,isp', false, $ctx);
        if ($raw === false) {
            return $empty;
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || ($j['status'] ?? '') !== 'success') {
            return $empty;
        }
        $out = [
            'country' => (string) ($j['country'] ?? 'Unknown'),
            'countryCode' => (string) ($j['countryCode'] ?? ''),
            'city' => (string) ($j['city'] ?? ''),
            'isp' => (string) ($j['isp'] ?? ''),
        ];
        @file_put_contents($file, json_encode($out));
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $out, 86400);
        }
        return $out;
    }
}
