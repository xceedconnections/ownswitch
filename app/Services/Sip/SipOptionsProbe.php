<?php

declare(strict_types=1);

namespace Ownswitch\Services\Sip;

/**
 * Lightweight SIP OPTIONS RTT probe (Magnus-style “latency” for trunks / registered contacts).
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
        // Strip brackets from IPv6
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $key = strtolower($host) . ':' . $port;
        $now = microtime(true);
        if (isset(self::$cache[$key]) && ($now - self::$cache[$key]['at']) < 20.0) {
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

        $sock = @stream_socket_client(
            'udp://' . (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$ip}]" : $ip) . ':' . $port,
            $errno,
            $errstr,
            $timeoutSec
        );
        if ($sock === false) {
            self::$cache[$key] = ['ms' => null, 'at' => $now];
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

        $ok = is_string($resp) && $resp !== '' && str_starts_with($resp, 'SIP/2.0');
        $ms = $ok ? $elapsedMs : null;
        self::$cache[$key] = ['ms' => $ms, 'at' => $now];
        return $ms;
    }

    /** Parse host + port from a SIP Contact / received URI. */
    public static function endpointFromUri(string $uri): ?array
    {
        $uri = trim($uri);
        if ($uri === '') {
            return null;
        }
        // received=sip:1.2.3.4:5060 or sip:user@host:port;params
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
