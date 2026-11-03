<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Repositories\CustomerRepository;

/** Live calls from OpenSIPS dialogs — stats, user filter, VICIdial-style colors. */
final class CallsOnlineController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $filterUser = trim((string) $r->query('user', ''));
        [$calls, $error] = $this->loadCalls($r, $filterUser !== '' ? $filterUser : null);
        $stats = [
            'total' => count($calls),
            'trying' => 0,
            'ringing' => 0,
            'connected' => 0,
            'other' => 0,
        ];
        foreach ($calls as $c) {
            $bucket = (string) ($c['bucket'] ?? 'other');
            if (isset($stats[$bucket])) {
                $stats[$bucket]++;
            } else {
                $stats['other']++;
            }
        }
        $users = [];
        if ($this->scope($r)->isGlobal() || $this->scope($r)->isStaff()) {
            foreach ((new CustomerRepository($this->db()))->all($this->scope($r), [], 500, 'name') as $c) {
                $users[(string) $c['id']] = (string) $c['name'];
            }
            // Also offer SIP usernames
            try {
                foreach ($this->db()->query("SELECT username FROM sip_accounts WHERE status = 'active' ORDER BY username LIMIT 500")->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $u) {
                    $users['sip:' . $u] = 'SIP ' . $u;
                }
            } catch (\Throwable) {
            }
        }
        return $this->view('calls/online', [
            'title' => 'Calls Online',
            'calls' => $calls,
            'error' => $error,
            'count' => count($calls),
            'stats' => $stats,
            'users' => $users,
            'filterUser' => $filterUser,
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
        [$calls] = $this->loadCalls($r, null);
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
    private function loadCalls(Request $r, ?string $filterUser): array
    {
        $prov = OpensipsProvisioner::fromConfig();
        $error = null;
        $calls = [];
        if (!$prov->enabled()) {
            return [[], 'OpenSIPS is not configured (OPENSIPS_ENABLED / MI URL).'];
        }
        try {
            $mine = $this->mySipUsernames($r);
            $filterTokens = $this->filterTokens($filterUser);
            foreach ($prov->listDialogs() as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $n = self::normalize($d);
                if ($n['callid'] === '') {
                    continue;
                }
                if ($mine !== null) {
                    if (!$this->matchesUsers($n, $mine)) {
                        continue;
                    }
                }
                if ($filterTokens !== null && !$this->matchesUsers($n, $filterTokens)) {
                    continue;
                }
                $calls[] = $n;
            }
        } catch (\Throwable $e) {
            $error = 'Failed to query live dialogs: ' . $e->getMessage();
        }
        usort($calls, static fn ($a, $b) => ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0));
        return [$calls, $error];
    }

    /** @return list<string>|null */
    private function filterTokens(?string $filterUser): ?array
    {
        if ($filterUser === null || $filterUser === '') {
            return null;
        }
        if (str_starts_with($filterUser, 'sip:')) {
            return [strtolower(substr($filterUser, 4))];
        }
        if (ctype_digit($filterUser)) {
            $st = $this->db()->prepare('SELECT username FROM sip_accounts WHERE customer_id = ?');
            $st->execute([(int) $filterUser]);
            $list = array_map(static fn ($u) => strtolower((string) $u), $st->fetchAll(\PDO::FETCH_COLUMN) ?: []);
            // Also match customer name tokens lightly via sip usernames only
            return $list !== [] ? $list : ['__none__'];
        }
        return [strtolower($filterUser)];
    }

    /** @return list<string>|null null = no filter (staff) */
    private function mySipUsernames(Request $r): ?array
    {
        $scope = $this->scope($r);
        if ($scope->isGlobal() || $scope->isStaff()) {
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

    /** @param array<string,mixed> $n @param list<string> $users */
    private function matchesUsers(array $n, array $users): bool
    {
        $fu = strtolower((string) $n['from_user']);
        $tu = strtolower((string) $n['to_user']);
        $from = strtolower((string) $n['from']);
        $to = strtolower((string) $n['to']);
        foreach ($users as $u) {
            if ($u === '' || $u === '__none__') {
                continue;
            }
            if ($fu === $u || $tu === $u || str_contains($from, $u) || str_contains($to, $u)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $d */
    private static function normalize(array $d): array
    {
        $from = (string) ($d['from_uri'] ?? $d['caller'] ?? $d['From'] ?? $d['from'] ?? $d['caller_uri'] ?? '');
        $to = (string) ($d['to_uri'] ?? $d['callee'] ?? $d['To'] ?? $d['to'] ?? $d['callee_uri'] ?? '');
        $stateRaw = $d['state'] ?? $d['State'] ?? $d['dialog_state'] ?? $d['dlg_state'] ?? '';
        $start = (int) ($d['start_time'] ?? $d['timestart'] ?? $d['start'] ?? 0);
        $dur = (int) ($d['duration'] ?? $d['calltime'] ?? 0);
        if ($dur <= 0 && $start > 0) {
            $dur = max(0, time() - $start);
        }
        [$bucket, $stateLabel] = self::classifyState($stateRaw, $dur);
        $color = self::vicidialColor($bucket, $dur);
        $fromUser = self::userPart($from);
        $toUser = self::userPart($to);
        $dlg = (string) ($d['dialog'] ?? $d['hash'] ?? $d['dlg_id'] ?? '');
        $fromTag = (string) ($d['from_tag'] ?? $d['caller_tag'] ?? $d['ftag'] ?? '');
        return [
            'from' => $from !== '' ? $from : $fromUser,
            'to' => $to !== '' ? $to : $toUser,
            'from_user' => $fromUser,
            'to_user' => $toUser,
            'state' => $stateLabel,
            'state_raw' => (string) $stateRaw,
            'bucket' => $bucket,
            'color' => $color,
            'duration' => $dur,
            'callid' => (string) ($d['callid'] ?? $d['Call-ID'] ?? $d['call_id'] ?? ''),
            'from_tag' => $fromTag,
            'dialog' => $dlg,
        ];
    }

    /** @return array{0:string,1:string} bucket, label */
    private static function classifyState(mixed $stateRaw, int $dur): array
    {
        if (is_int($stateRaw) || (is_string($stateRaw) && ctype_digit($stateRaw))) {
            $n = (int) $stateRaw;
            // OpenSIPS dialog states: 1 unconfirmed, 2 early, 3 confirmed, 4 deleted, 5 …
            return match (true) {
                $n === 1 => ['trying', 'Trying'],
                $n === 2 => ['ringing', 'Ringing / Early'],
                $n === 3 => ['connected', 'Connected'],
                $n === 4, $n === 5 => ['other', 'Ending'],
                default => ['other', 'State ' . $n],
            };
        }
        $s = strtoupper(trim((string) $stateRaw));
        if ($s === '') {
            // No state from MI — treat as connected if duration growing, else trying
            return $dur >= 2 ? ['connected', 'Active'] : ['trying', 'Trying'];
        }
        if (str_contains($s, 'CONFIRM') || str_contains($s, 'CONNECT') || $s === 'INCALL' || $s === 'ANSWERED') {
            return ['connected', 'Connected'];
        }
        if (str_contains($s, 'EARLY') || str_contains($s, 'RING') || str_contains($s, 'PROGRESS') || str_contains($s, '183') || str_contains($s, '180')) {
            return ['ringing', 'Ringing / Progress'];
        }
        if (str_contains($s, 'TRY') || str_contains($s, 'UNCONFIRM') || str_contains($s, '100') || str_contains($s, 'INIT')) {
            return ['trying', 'Trying'];
        }
        if (str_contains($s, 'DEAD') || str_contains($s, 'DELETE')) {
            return ['other', 'Dead'];
        }
        return ['other', $s !== '' ? $s : 'Active'];
    }

    /** VICIdial-style row background class. */
    private static function vicidialColor(string $bucket, int $dur): string
    {
        if ($bucket === 'connected') {
            if ($dur >= 600) {
                return 'os-rt-green'; // >10 min light green
            }
            if ($dur >= 60) {
                return 'os-rt-pink'; // >1 min pink
            }
            if ($dur >= 10) {
                return 'os-rt-pink-soft';
            }
            return 'os-rt-white';
        }
        if ($bucket === 'ringing') {
            if ($dur >= 300) {
                return 'os-rt-blue-dark';
            }
            if ($dur >= 60) {
                return 'os-rt-blue';
            }
            return 'os-rt-blue-light';
        }
        if ($bucket === 'trying') {
            return 'os-rt-blue-light';
        }
        if ($bucket === 'other') {
            return 'os-rt-black';
        }
        return 'os-rt-white';
    }

    private static function userPart(string $uri): string
    {
        if (preg_match('/sip:([^@;>]+)/i', $uri, $m)) {
            return $m[1];
        }
        return $uri;
    }
}
