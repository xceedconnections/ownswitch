<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\System\Fail2banService;

/** Settings → Firewall: SIP + portal fail2ban bans + thresholds. */
final class FirewallController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $svc = new Fail2banService();
        return $this->view('settings/firewall', [
            'title' => 'Firewall',
            'status' => $svc->status(),
            'available' => $svc->available(),
            'helperPath' => '/usr/local/sbin/ownswitch-fail2ban',
        ]);
    }

    public function unban(Request $r, array $p = []): Response
    {
        $ip = trim((string) $r->input('ip', ''));
        $jail = trim((string) $r->input('jail', ''));
        $res = (new Fail2banService())->unban($ip, $jail !== '' ? $jail : null);
        $this->audit($r, 'firewall_unban', 'firewall', null, null, ['ip' => $ip, 'jail' => $jail, 'ok' => $res['ok']]);
        return $this->redirect($r, '/settings/firewall', $res['detail'], $res['ok'] ? 'success' : 'danger');
    }

    public function ban(Request $r, array $p = []): Response
    {
        $ip = trim((string) $r->input('ip', ''));
        $jail = trim((string) $r->input('jail', Fail2banService::JAIL_SIP));
        $res = (new Fail2banService())->ban($ip, $jail);
        $this->audit($r, 'firewall_ban', 'firewall', null, null, ['ip' => $ip, 'jail' => $jail, 'ok' => $res['ok']]);
        return $this->redirect($r, '/settings/firewall', $res['detail'], $res['ok'] ? 'success' : 'danger');
    }

    public function saveConfig(Request $r, array $p = []): Response
    {
        $jail = trim((string) $r->input('jail', Fail2banService::JAIL_SIP));
        $cfg = [
            'jail' => $jail,
            'maxretry' => trim((string) $r->input('maxretry', '')),
            'findtime' => trim((string) $r->input('findtime', '')),
            'bantime' => trim((string) $r->input('bantime', '')),
            'enabled' => $r->input('jail_enabled') ? 'true' : 'false',
        ];
        $res = (new Fail2banService())->setConfig($cfg);
        $this->audit($r, 'firewall_config', 'firewall', null, null, $cfg + ['ok' => $res['ok']]);
        return $this->redirect($r, '/settings/firewall', $res['detail'] !== '' ? $res['detail'] : ($res['ok'] ? 'Saved.' : 'Save failed'), $res['ok'] ? 'success' : 'danger');
    }
}
