<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;

/** Live calls from OpenSIPS dialog module — scoped for customers; hangup supported. */
final class CallsOnlineController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        [$calls, $error] = $this->loadCalls($r);
        return $this->view('calls/online', [
            'title' => 'Calls Online',
            'calls' => $calls,
            'error' => $error,
            'count' => count($calls),
            'canHangup' => true,
        ]);
    }

    public function hangup(Request $r, array $p = []): Response
    {
        $callId = trim((string) $r->input('callid', ''));
        $fromTag = trim((string) $r->input('from_tag', ''));
        if ($callId === '') {
            return $this->redirect($r, '/calls-online', 'Missing Call-ID.', 'danger');
        }
        [$calls] = $this->loadCalls($r);
        $allowed = false;
        foreach ($calls as $c) {
            if (($c['callid'] ?? '') === $callId) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed && !$this->scope($r)->isGlobal()) {
            return $this->redirect($r, '/calls-online', 'You can only hang up your own calls.', 'danger');
        }
        $prov = OpensipsProvisioner::fromConfig();
        if (!$prov->enabled() || !$prov->hasMi()) {
            return $this->redirect($r, '/calls-online', 'OpenSIPS MI not available.', 'danger');
        }
        $res = $prov->endDialog($callId, $fromTag !== '' ? $fromTag : null);
        return $this->redirect(
            $r,
            '/calls-online',
            $res['ok'] ? 'Hangup requested.' : ('Hangup failed: ' . $res['detail']),
            $res['ok'] ? 'success' : 'danger'
        );
    }

    /** @return array{0:list<array<string,mixed>>,1:?string} */
    private function loadCalls(Request $r): array
    {
        $prov = OpensipsProvisioner::fromConfig();
        $error = null;
        $calls = [];
        if (!$prov->enabled()) {
            return [[], 'OpenSIPS is not configured (OPENSIPS_ENABLED / MI URL).'];
        }
        if (!$prov->hasMi()) {
            return [[], 'OpenSIPS MI URL is empty — set OPENSIPS_MI_URL.'];
        }
        try {
            $mine = $this->mySipUsernames($r);
            foreach ($prov->listDialogs() as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $n = self::normalize($d);
                if ($mine !== null) {
                    $fu = strtolower($n['from_user']);
                    $tu = strtolower($n['to_user']);
                    $hit = false;
                    foreach ($mine as $u) {
                        if ($fu === $u || $tu === $u || str_contains(strtolower($n['from']), $u) || str_contains(strtolower($n['to']), $u)) {
                            $hit = true;
                            break;
                        }
                    }
                    if (!$hit) {
                        continue;
                    }
                }
                $calls[] = $n;
            }
        } catch (\Throwable $e) {
            $error = 'Failed to query OpenSIPS dlg_list: ' . $e->getMessage();
        }
        usort($calls, static fn ($a, $b) => ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0));
        return [$calls, $error];
    }

    /** @return list<string>|null null = no filter (staff) */
    private function mySipUsernames(Request $r): ?array
    {
        $scope = $this->scope($r);
        if ($scope->isGlobal()) {
            return null;
        }
        $cid = $scope->customerId;
        if ($cid === null) {
            return [];
        }
        $st = $this->db()->prepare('SELECT username FROM sip_accounts WHERE customer_id = ?');
        $st->execute([$cid]);
        return array_map(static fn ($u) => strtolower((string) $u), $st->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<string,mixed> $d */
    private static function normalize(array $d): array
    {
        $from = (string) ($d['from_uri'] ?? $d['caller'] ?? $d['From'] ?? $d['from'] ?? '');
        $to = (string) ($d['to_uri'] ?? $d['callee'] ?? $d['To'] ?? $d['to'] ?? '');
        $state = (string) ($d['state'] ?? $d['State'] ?? $d['dialog_state'] ?? '');
        $start = (int) ($d['start_time'] ?? $d['timestart'] ?? $d['start'] ?? 0);
        $dur = (int) ($d['duration'] ?? $d['calltime'] ?? 0);
        if ($dur <= 0 && $start > 0) {
            $dur = max(0, time() - $start);
        }
        $fromUser = self::userPart($from);
        $toUser = self::userPart($to);
        $dlg = (string) ($d['dialog'] ?? $d['hash'] ?? $d['dlg_id'] ?? '');
        $fromTag = (string) ($d['from_tag'] ?? $d['caller_tag'] ?? $d['ftag'] ?? '');
        return [
            'from' => $from !== '' ? $from : $fromUser,
            'to' => $to !== '' ? $to : $toUser,
            'from_user' => $fromUser,
            'to_user' => $toUser,
            'state' => $state !== '' ? $state : 'active',
            'duration' => $dur,
            'callid' => (string) ($d['callid'] ?? $d['Call-ID'] ?? $d['call_id'] ?? ''),
            'from_tag' => $fromTag,
            'dialog' => $dlg,
            'raw' => $d,
        ];
    }

    private static function userPart(string $uri): string
    {
        if (preg_match('/sip:([^@;>]+)/i', $uri, $m)) {
            return $m[1];
        }
        return $uri;
    }
}
