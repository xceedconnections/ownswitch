<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

/**
 * Live host resource snapshot for the admin dashboard (CPU, RAM, disk, NIC throughput).
 * Linux/proc based; returns zeros on unsupported platforms.
 */
final class HostMetricsService
{
    /**
     * @return array{
     *   hostname:string,uptime_s:int,cpu_cores:int,cpu_pct:float,load1:float,load5:float,load15:float,
     *   ram_total_mb:int,ram_used_mb:int,ram_pct:float,
     *   disk_total_gb:float,disk_used_gb:float,disk_free_gb:float,disk_pct:float,
     *   net_rx_mbps:float,net_tx_mbps:float,net_iface:string,sampled_at:string
     * }
     */
    public function snapshot(): array
    {
        $cores = (int) (function_exists('nproc') ? 0 : 0);
        $cores = max(1, (int) (@shell_exec('nproc 2>/dev/null') ?: 1));
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
        $cpuPct = $this->cpuPercent();
        [$ramTotal, $ramAvail] = $this->memKb();
        $ramUsed = max(0, $ramTotal - $ramAvail);
        $ramPct = $ramTotal > 0 ? round(100 * $ramUsed / $ramTotal, 1) : 0.0;

        $root = '/';
        $diskTotal = @disk_total_space($root) ?: 0.0;
        $diskFree = @disk_free_space($root) ?: 0.0;
        $diskUsed = max(0.0, $diskTotal - $diskFree);
        $diskPct = $diskTotal > 0 ? round(100 * $diskUsed / $diskTotal, 1) : 0.0;

        [$iface, $rxMbps, $txMbps] = $this->netMbps();

        return [
            'hostname' => gethostname() ?: 'server',
            'uptime_s' => $this->uptimeSeconds(),
            'cpu_cores' => $cores,
            'cpu_pct' => $cpuPct,
            'load1' => round((float) $load[0], 2),
            'load5' => round((float) $load[1], 2),
            'load15' => round((float) $load[2], 2),
            'ram_total_mb' => (int) round($ramTotal / 1024),
            'ram_used_mb' => (int) round($ramUsed / 1024),
            'ram_pct' => $ramPct,
            'disk_total_gb' => round($diskTotal / 1073741824, 1),
            'disk_used_gb' => round($diskUsed / 1073741824, 1),
            'disk_free_gb' => round($diskFree / 1073741824, 1),
            'disk_pct' => $diskPct,
            'net_rx_mbps' => $rxMbps,
            'net_tx_mbps' => $txMbps,
            'net_iface' => $iface,
            'sampled_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];
    }

    private function cpuPercent(): float
    {
        $a = $this->cpuTimes();
        usleep(400000);
        $b = $this->cpuTimes();
        if ($a === null || $b === null) {
            $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0]) : [0];
            $cores = max(1, (int) (@shell_exec('nproc 2>/dev/null') ?: 1));
            return round(min(100, 100 * ((float) $load[0] / $cores)), 1);
        }
        $idle = $b['idle'] - $a['idle'];
        $total = $b['total'] - $a['total'];
        if ($total <= 0) {
            return 0.0;
        }
        return round(max(0, min(100, 100 * (1 - ($idle / $total)))), 1);
    }

    /** @return array{idle:int,total:int}|null */
    private function cpuTimes(): ?array
    {
        $line = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0] ?? '';
        if (!str_starts_with($line, 'cpu ')) {
            return null;
        }
        $p = preg_split('/\s+/', trim($line)) ?: [];
        $nums = array_map('intval', array_slice($p, 1));
        if (count($nums) < 4) {
            return null;
        }
        $idle = ($nums[3] ?? 0) + ($nums[4] ?? 0);
        $total = array_sum($nums);
        return ['idle' => $idle, 'total' => $total];
    }

    /** @return array{0:int,1:int} totalKb, availableKb */
    private function memKb(): array
    {
        $total = $avail = 0;
        $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^MemTotal:\s+(\d+)/', $line, $m)) {
                $total = (int) $m[1];
            }
            if (preg_match('/^MemAvailable:\s+(\d+)/', $line, $m)) {
                $avail = (int) $m[1];
            }
        }
        return [$total, $avail];
    }

    private function uptimeSeconds(): int
    {
        $raw = @file_get_contents('/proc/uptime');
        if ($raw === false) {
            return 0;
        }
        return (int) floatval(explode(' ', $raw)[0] ?? 0);
    }

    /** @return array{0:string,1:float,2:float} iface, rx Mbps, tx Mbps */
    private function netMbps(): array
    {
        $a = $this->netCounters();
        usleep(500000);
        $b = $this->netCounters();
        if ($a === null || $b === null || $a['iface'] !== $b['iface']) {
            return ['—', 0.0, 0.0];
        }
        $dt = max(0.1, ($b['t'] - $a['t']));
        $rx = (($b['rx'] - $a['rx']) * 8) / $dt / 1_000_000;
        $tx = (($b['tx'] - $a['tx']) * 8) / $dt / 1_000_000;
        return [$a['iface'], round(max(0, $rx), 2), round(max(0, $tx), 2)];
    }

    /** @return array{iface:string,rx:int,tx:int,t:float}|null */
    private function netCounters(): ?array
    {
        $lines = @file('/proc/net/dev', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $best = null;
        $bestSum = -1;
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$iface, $rest] = array_map('trim', explode(':', $line, 2));
            if ($iface === 'lo' || $iface === '') {
                continue;
            }
            $p = preg_split('/\s+/', $rest) ?: [];
            $rx = (int) ($p[0] ?? 0);
            $tx = (int) ($p[8] ?? 0);
            $sum = $rx + $tx;
            if ($sum > $bestSum) {
                $bestSum = $sum;
                $best = ['iface' => $iface, 'rx' => $rx, 'tx' => $tx, 't' => microtime(true)];
            }
        }
        return $best;
    }
}
