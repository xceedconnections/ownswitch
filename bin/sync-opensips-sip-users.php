<?php

declare(strict_types=1);

/**
 * Push all active Ownswitch SIP accounts into opensips.subscriber.
 * Does NOT reset passwords (HA1 already stored). If domain was wrong at create time,
 * re-save the SIP user with a new password in the portal after fixing OPENSIPS_SIP_DOMAIN.
 *
 *   sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
 *     php /opt/ownswitch/current/bin/sync-opensips-sip-users.php
 */

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;

$prov = OpensipsProvisioner::fromConfig();
if (!$prov->enabled()) {
    fwrite(STDERR, "OpenSIPS provisioning disabled. Set OPENSIPS_ENABLED=1 in /etc/ownswitch/ownswitch.env\n");
    exit(1);
}

$domain = (string) App::config('app.integrations.opensips.sip_domain');
$pdo = App::db();
$rows = $pdo->query("SELECT id, username, domain, ha1, ha1b, status FROM sip_accounts")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$ok = 0;
$skip = 0;
foreach ($rows as $r) {
    if ($r['status'] !== 'active') {
        $prov->removeSubscriber((string) $r['username'], (string) $r['domain']);
        if ($r['domain'] !== $domain) {
            $prov->removeSubscriber((string) $r['username'], $domain);
        }
        $skip++;
        continue;
    }
    // Prefer configured SIP domain; HA1 was computed with the domain stored at create time.
    $useDomain = (string) $r['domain'];
    $prov->upsertSubscriber((string) $r['username'], $useDomain, (string) $r['ha1'], (string) $r['ha1b']);
    if ($useDomain !== $domain) {
        fwrite(STDERR, "WARN: SIP user {$r['username']} domain={$useDomain} but OPENSIPS_SIP_DOMAIN={$domain}. Softphone realm must match {$useDomain}, OR reset password in portal after domain fix.\n");
    }
    $ok++;
}

echo "Provisioned {$ok} active SIP user(s); skipped/disabled {$skip}. SIP domain config={$domain}\n";
echo "Softphone settings:\n";
echo "  Domain / Realm : {$domain}\n";
echo "  Proxy / Server : (this server public IP or {$domain})\n";
echo "  Port           : 5060 UDP\n";
exit(0);
