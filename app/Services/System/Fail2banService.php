<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

/**
 * Portal wrapper around /usr/local/sbin/ownswitch-fail2ban (sudo).
 * Install via deploy/fix-sip-firewall.sh or stage 20.
 */
final class Fail2banService
{
    private const HELPER = '/usr/local/sbin/ownswitch-fail2ban';

    public function available(): bool
    {
        return is_file(self::HELPER) && is_executable(self::HELPER);
    }

    /** @return array{ok:bool,detail:string,enabled:bool,currently_banned:int,total_banned:int,banned:list<string>,maxretry:string,findtime:string,bantime:string,jail_enabled:string,sip_port:string} */
    public function status(): array
    {
        $base = [
            'ok' => false,
            'detail' => '',
            'enabled' => false,
            'currently_banned' => 0,
            'total_banned' => 0,
            'banned' => [],
            'maxretry' => '5',
            'findtime' => '10m',
            'bantime' => '1h',
            'jail_enabled' => 'true',
            'sip_port' => '5060',
        ];
        if (!$this->available()) {
            $base['detail'] = 'Helper missing. On the server run: sudo bash /opt/ownswitch-src/deploy/fix-sip-firewall.sh';
            return $base;
        }
        [$code, $out, $err] = $this->run(['status']);
        if ($code !== 0) {
            $base['detail'] = trim($err !== '' ? $err : $out) ?: 'fail2ban status failed';
            return $base;
        }
        $kv = $this->parseKv($out);
        $list = [];
        if (($kv['banned_list'] ?? '') !== '') {
            foreach (preg_split('/[\s,]+/', (string) $kv['banned_list']) ?: [] as $ip) {
                $ip = trim($ip);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $list[] = $ip;
                }
            }
        }
        if ($list === []) {
            [$c2, $out2] = $this->run(['banned']);
            if ($c2 === 0) {
                foreach (preg_split("/\r\n|\n|\r/", trim($out2)) ?: [] as $line) {
                    $ip = trim($line);
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                        $list[] = $ip;
                    }
                }
            }
        }
        $list = array_values(array_unique($list));
        return [
            'ok' => true,
            'detail' => ($kv['detail'] ?? '') !== '' ? (string) $kv['detail'] : 'ok',
            'enabled' => ((string) ($kv['enabled'] ?? '0')) === '1',
            'currently_banned' => (int) ($kv['currently_banned'] ?? count($list)),
            'total_banned' => (int) ($kv['total_banned'] ?? 0),
            'banned' => $list,
            'maxretry' => (string) ($kv['maxretry'] ?? '5'),
            'findtime' => (string) ($kv['findtime'] ?? '10m'),
            'bantime' => (string) ($kv['bantime'] ?? '1h'),
            'jail_enabled' => (string) ($kv['jail_enabled'] ?? 'true'),
            'sip_port' => (string) ($kv['sip_port'] ?? '5060'),
        ];
    }

    /** @return array{ok:bool,detail:string} */
    public function unban(string $ip): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'detail' => 'Invalid IP'];
        }
        [$code, $out, $err] = $this->run(['unban', $ip]);
        return ['ok' => $code === 0, 'detail' => trim($code === 0 ? $out : ($err ?: $out))];
    }

    /** @return array{ok:bool,detail:string} */
    public function ban(string $ip): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'detail' => 'Invalid IP'];
        }
        [$code, $out, $err] = $this->run(['ban', $ip]);
        return ['ok' => $code === 0, 'detail' => trim($code === 0 ? $out : ($err ?: $out))];
    }

    /**
     * @param array{maxretry?:string,findtime?:string,bantime?:string,enabled?:string} $cfg
     * @return array{ok:bool,detail:string}
     */
    public function setConfig(array $cfg): array
    {
        $args = ['set-config'];
        foreach (['maxretry', 'findtime', 'bantime', 'enabled'] as $k) {
            if (isset($cfg[$k]) && is_string($cfg[$k]) && $cfg[$k] !== '') {
                if (!preg_match('/^[a-zA-Z0-9]+$/', $k)) {
                    continue;
                }
                $v = $cfg[$k];
                if (!preg_match('/^[0-9a-zA-Z.]+$/', $v)) {
                    return ['ok' => false, 'detail' => "Invalid {$k}"];
                }
                $args[] = "{$k}={$v}";
            }
        }
        if (count($args) === 1) {
            return ['ok' => false, 'detail' => 'No settings to change'];
        }
        [$code, $out, $err] = $this->run($args);
        return ['ok' => $code === 0, 'detail' => trim($code === 0 ? $out : ($err ?: $out))];
    }

    /**
     * @param list<string> $args
     * @return array{0:int,1:string,2:string}
     */
    private function run(array $args): array
    {
        if (!$this->available()) {
            return [1, '', 'helper missing'];
        }
        $cmd = 'sudo -n ' . escapeshellarg(self::HELPER);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        // helper prints ERR: on stderr merged into 2>&1
        $err = '';
        if ($code !== 0) {
            $err = $text;
        }
        return [$code, $text, $err];
    }

    /** @return array<string,string> */
    private function parseKv(string $out): array
    {
        $kv = [];
        foreach (preg_split("/\r\n|\n|\r/", $out) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $kv[trim($k)] = trim($v);
        }
        return $kv;
    }
}
