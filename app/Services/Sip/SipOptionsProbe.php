<?php

declare(strict_types=1);

namespace Ownswitch\Services\Sip;

/**
 * Lightweight latency probe for Live column (Magnus-style ms).
 * Prefers SIP OPTIONS; falls back to ICMP ping when Asterisk/ViciDial ignore OPTIONS.
 */
final class SipOptionsProbe
{
    /** @var array<string, array{ms:?int, at:float}> */
    private static array $cache = [];

    /**
     * @return int|null Round-trip milliseconds, or null if unreachable / timed out
     */
    public static function rttMs(string $host, int $port = 5060, float $timeoutSec = 1.2): ?int
    {
        $host = trim($host);
        $port = $port > 0 && $port <= 65535 ? $port : 5060;
        if ($host === '') {
            return null;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $key = strtolower($host) . ':' . $port;
        $now = microtime(true);
        if (isset(self::$cache[$key]) && ($now - self::$cache[$key]['at']) < 15.0) {
            return self::$cache[$key]['ms'];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $resolved = @gethostbyname($host);
            if (!is_string($resolved) || $resolved === '' || $resolved === $host || !filter_var($resolved, FILTER_VALIDATE_IP)) {
                self::$cache[$key] = ['ms' => null, 'at' => $now];
                return null;
            }
            $ip = $resolved;
        }

        $ms = self::sipOptionsRtt($ip, $host, $port, $timeoutSec);
        if ($ms === null) {
            $ms = self::icmpPingMs($ip);
        }
        self::$cache[$key] = ['ms' => $ms, 'at' => $now];
        return $ms;
    }

    private static function sipOptionsRtt(string $ip, string $host, int $port, float $timeoutSec): ?int
    {
        $sock = @stream_socket_client(
            'udp://' . (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$ip}]" : $ip) . ':' . $port,
            $errno,
            $errstr,
            $timeoutSec
        );
        if ($sock === false) {
            return null;
        }
        stream_set_timeout($sock, (int) floor($timeoutSec), (int) (($timeoutSec - floor($timeoutSec)) * 1_000_000));

        $branch = 'z9hG4bK' . bin2hex(random_bytes(6));
        $callId = bin2hex(random_bytes(8)) . '@ownswitch';
        $tag = bin2hex(random_bytes(4));
        $fromHost = 'ownswitch.local';
        $msg = "OPTIONS sip:ping@{$host}:{$port} SIP/2.0\r\n"
            . "Via: SIP/2.0/UDP {$fromHost}:5060;branch={$branch};rport\r\n"
            . "Max-Forwards: 70\r\n"
            . "From: <sip:ping@{$fromHost}>;tag={$tag}\r\n"
            . "To: <sip:ping@{$host}:{$port}>\r\n"
            . "Call-ID: {$callId}\r\n"
            . "CSeq: 1 OPTIONS\r\n"
            . "Contact: <sip:ping@{$fromHost}:5060>\r\n"
            . "Accept: application/sdp\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n";

        $t0 = hrtime(true);
        @fwrite($sock, $msg);
        $resp = @fread($sock, 4096);
        $elapsedMs = (int) max(1, (int) round((hrtime(true) - $t0) / 1_000_000));
        fclose($sock);

        // Any SIP reply counts (200/404/405/501) — Asterisk often answers OPTIONS with 404/501
        if (is_string($resp) && $resp !== '' && str_starts_with($resp, 'SIP/2.0')) {
            return $elapsedMs;
        }
        return null;
    }

    /** ICMP ping fallback (Linux) when peer ignores SIP OPTIONS. */
    private static function icmpPingMs(string $ip): ?int
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP) || str_contains($ip, "'") || str_contains($ip, ' ')) {
            return null;
        }
        // Windows (local xampp) vs Linux (switch)
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'ping -n 1 -w 800 ' . escapeshellarg($ip) . ' 2>NUL';
        } else {
            $cmd = 'ping -c 1 -W 1 ' . escapeshellarg($ip) . ' 2>/dev/null';
        }
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        if ($code !== 0) {
            return null;
        }
        $blob = implode("\n", $out);
        // Linux: time=12.3 ms   Windows: time=12ms / time<1ms
        if (preg_match('/time[=<]([\d.]+)\s*ms/i', $blob, $m)) {
            $n = (int) max(1, (int) round((float) $m[1]));
            return min(9999, $n);
        }
        return null;
    }

    /** Parse host + port from a SIP Contact / received URI. */
    public static function endpointFromUri(string $uri): ?array
    {
        $uri = trim($uri);
        if ($uri === '') {
            return null;
        }
        if (preg_match('#(?:sip:)?(?:[^@;>\s]+@)?(\[[0-9a-fA-F:]+\]|[^:;>\s]+)(?::(\d+))?#', $uri, $m)) {
            $host = $m[1];
            $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 5060;
            return ['host' => $host, 'port' => $port];
        }
        return null;
    }

    public static function format(?int $ms, bool $online = true): string
    {
        if (!$online) {
            return '—';
        }
        if ($ms === null) {
            return 'n/a';
        }
        return $ms . ' ms';
    }
}
