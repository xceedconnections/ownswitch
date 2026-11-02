<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;

/** Live calls from OpenSIPS dialog module (dlg_list). */
final class CallsOnlineController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $prov = OpensipsProvisioner::fromConfig();
        $error = null;
        $calls = [];
        if (!$prov->enabled()) {
            $error = 'OpenSIPS is not configured (OPENSIPS_ENABLED / MI URL).';
        } else {
            try {
                $raw = $prov->listDialogs();
                foreach ($raw as $d) {
                    if (!is_array($d)) {
                        continue;
                    }
                    $calls[] = self::normalize($d);
                }
            } catch (\Throwable $e) {
                $error = 'Failed to query OpenSIPS dlg_list: ' . $e->getMessage();
            }
            if ($error === null && $calls === [] && !$prov->hasMi()) {
                $error = 'OpenSIPS MI URL is empty — set OPENSIPS_MI_URL.';
            }
        }
        usort($calls, static fn ($a, $b) => ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0));
        return $this->view('calls/online', [
            'title' => 'Calls Online',
            'calls' => $calls,
            'error' => $error,
            'count' => count($calls),
        ]);
    }

    /** @param array<string,mixed> $d */
    private static function normalize(array $d): array
    {
        // OpenSIPS dlg_list field names vary by version
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
        return [
            'from' => $from !== '' ? $from : $fromUser,
            'to' => $to !== '' ? $to : $toUser,
            'from_user' => $fromUser,
            'to_user' => $toUser,
            'state' => $state !== '' ? $state : 'active',
            'duration' => $dur,
            'callid' => (string) ($d['callid'] ?? $d['Call-ID'] ?? $d['call_id'] ?? ''),
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
