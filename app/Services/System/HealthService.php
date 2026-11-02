<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

use Ownswitch\App;
use Ownswitch\Database\Migrator;
use Ownswitch\Integrations\CGRateS\CgratesClient;
use Ownswitch\Integrations\FreeSWITCH\EslClient;
use Ownswitch\Integrations\RTPengine\RtpengineClient;
use PDO;

/** Honest component status: "unconfigured" is reported as such, never faked as OK. */
final class HealthService
{
    /** @return list<array{component:string,status:string,detail:string}> */
    public function run(?PDO $pdo): array
    {
        $r = [];
        $r[] = ['component' => 'PHP', 'status' => PHP_VERSION_ID >= 80200 ? 'ok' : 'fail', 'detail' => 'PHP ' . PHP_VERSION];
        foreach (['pdo_mysql', 'bcmath', 'mbstring', 'openssl'] as $ext) {
            $r[] = ['component' => "ext-{$ext}", 'status' => extension_loaded($ext) ? 'ok' : 'fail', 'detail' => extension_loaded($ext) ? 'loaded' : 'missing'];
        }
        $r[] = ['component' => 'APP_KEY', 'status' => strlen((string) App::config('app.key', '')) >= 32 ? 'ok' : 'warn', 'detail' => strlen((string) App::config('app.key', '')) >= 32 ? 'configured' : 'not set (needed to store carrier credentials)'];

        if ($pdo === null) {
            $r[] = ['component' => 'MySQL', 'status' => 'fail', 'detail' => 'not connected'];
            return $r;
        }
        try {
            $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $r[] = ['component' => 'MySQL', 'status' => 'ok', 'detail' => $ver];
            $m = new Migrator($pdo, App::basePath('database/migrations'));
            $pending = array_filter($m->status(), static fn ($s) => $s['status'] === 'pending');
            $r[] = ['component' => 'Migrations', 'status' => $pending ? 'warn' : 'ok', 'detail' => $pending ? count($pending) . ' pending - run php bin/migrate.php' : 'up to date'];
            $q = (int) $pdo->query("SELECT COUNT(*) FROM cdr_ingest_queue WHERE status = 'pending'")->fetchColumn();
            $r[] = ['component' => 'CDR ingest queue', 'status' => $q > 1000 ? 'warn' : 'ok', 'detail' => "{$q} pending"];
        } catch (\Throwable $e) {
            $r[] = ['component' => 'MySQL', 'status' => 'fail', 'detail' => App::config('app.debug') ? $e->getMessage() : 'query failed (check logs)'];
        }

        $cg = App::config('app.integrations.cgrates');
        $c = new CgratesClient((string) $cg['url'], (string) $cg['user'], (string) $cg['password'], (string) $cg['tenant'], 2);
        $r[] = $this->probe('CGRateS', $c->configured(), static fn () => $c->ping() ? 'Pong' : 'no pong');

        $rt = App::config('app.integrations.rtpengine');
        $rc = new RtpengineClient((string) $rt['host'], (int) $rt['port']);
        $r[] = $this->probe('RTPengine', $rc->configured(), static fn () => $rc->ping() ? 'pong' : 'no pong');

        $fs = App::config('app.integrations.freeswitch');
        $ec = new EslClient((string) $fs['host'], (int) $fs['port'], (string) $fs['password']);
        $r[] = $this->probe('FreeSWITCH (optional)', $ec->configured(), static function () use ($ec) {
            try {
                return strtok($ec->status(), "\n") ?: 'connected';
            } finally {
                $ec->close();
            }
        });

        $r[] = $this->probeOpensips();
        return $r;
    }

    /** @return array{component:string,status:string,detail:string} */
    private function probeOpensips(): array
    {
        $os = (array) App::config('app.integrations.opensips', []);
        if (empty($os['enabled'])) {
            return ['component' => 'OpenSIPS', 'status' => 'unconfigured', 'detail' => 'OPENSIPS_ENABLED off'];
        }
        try {
            $prov = \Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner::fromConfig();
            if (!$prov->enabled()) {
                return ['component' => 'OpenSIPS', 'status' => 'fail', 'detail' => $prov->initError() ?: 'DB not connected'];
            }
            // Re-open via config for counts (provisioner PDO is private)
            $db = \Ownswitch\Database\Connection::make((array) $os['db'] + ['charset' => 'utf8mb4']);
            $subs = (int) $db->query('SELECT COUNT(*) FROM subscriber')->fetchColumn();
            $loc = 0;
            try {
                $loc = (int) $db->query('SELECT COUNT(*) FROM location')->fetchColumn();
            } catch (\Throwable) {
            }
            $gws = 0;
            try {
                $gws = (int) $db->query('SELECT COUNT(*) FROM dr_gateways')->fetchColumn();
            } catch (\Throwable) {
            }
            $domain = (string) ($os['sip_domain'] ?? '');
            $detail = "realm={$domain}; subscribers={$subs}; registered_contacts={$loc}; trunks/gateways={$gws}";
            if ($subs === 0) {
                return ['component' => 'OpenSIPS', 'status' => 'warn', 'detail' => $detail . ' — no SIP users in opensips.subscriber (reset SIP password in portal)'];
            }
            if ($loc === 0) {
                return ['component' => 'OpenSIPS', 'status' => 'warn', 'detail' => $detail . ' — softphone not registered (UDP 5060). Run: sudo bash /opt/ownswitch-src/deploy/fix-udp-5060.sh'];
            }
            return ['component' => 'OpenSIPS', 'status' => 'ok', 'detail' => $detail];
        } catch (\Throwable $e) {
            return ['component' => 'OpenSIPS', 'status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    /** @param callable():string $fn */
    private function probe(string $name, bool $configured, callable $fn): array
    {
        if (!$configured) {
            return ['component' => $name, 'status' => 'unconfigured', 'detail' => 'not configured'];
        }
        try {
            return ['component' => $name, 'status' => 'ok', 'detail' => $fn()];
        } catch (\Throwable $e) {
            return ['component' => $name, 'status' => 'fail', 'detail' => $e->getMessage()];
        }
    }
}
