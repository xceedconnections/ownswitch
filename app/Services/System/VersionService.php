<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

/**
 * Current install vs GitHub main (xceedconnections/ownswitch).
 */
final class VersionService
{
    private const HELPER = '/usr/local/sbin/ownswitch-update';
    private const REPO = 'xceedconnections/ownswitch';
    private const SRC = '/opt/ownswitch-src';
    private const CURRENT = '/opt/ownswitch/current';

    /** @return array{ok:bool,current_sha:string,current_short:string,current_date:string,remote_sha:string,remote_short:string,remote_date:string,remote_message:string,update_available:bool,detail:string,helper:bool} */
    public function status(): array
    {
        $cur = $this->localHead();
        $remote = $this->githubHead();
        $update = false;
        if ($cur['sha'] !== '' && $remote['sha'] !== '' && !hash_equals($cur['sha'], $remote['sha'])) {
            $update = true;
        }
        return [
            'ok' => $cur['sha'] !== '' || $remote['sha'] !== '',
            'current_sha' => $cur['sha'],
            'current_short' => $cur['short'],
            'current_date' => $cur['date'],
            'remote_sha' => $remote['sha'],
            'remote_short' => $remote['short'],
            'remote_date' => $remote['date'],
            'remote_message' => $remote['message'],
            'update_available' => $update,
            'detail' => $remote['error'] !== '' ? $remote['error'] : ($cur['sha'] === '' ? 'Local git SHA not found' : 'ok'),
            'helper' => is_file(self::HELPER) && is_executable(self::HELPER),
            'repo' => self::REPO,
            'branch' => 'main',
        ];
    }

    /** @return array{ok:bool,detail:string} */
    public function updateNow(): array
    {
        if (!is_file(self::HELPER) || !is_executable(self::HELPER)) {
            return ['ok' => false, 'detail' => 'Update helper missing. On the server run refresh-ui once as root, then try again.'];
        }
        $cmd = 'sudo -n ' . escapeshellarg(self::HELPER) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = trim(implode("\n", $out));
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, -4000);
        }
        return ['ok' => $code === 0, 'detail' => $text !== '' ? $text : ($code === 0 ? 'Updated.' : 'Update failed')];
    }

    /** @return array{sha:string,short:string,date:string} */
    private function localHead(): array
    {
        $sha = '';
        $date = '';
        foreach ([self::SRC, self::CURRENT] as $dir) {
            if (!is_dir($dir . '/.git') && $dir === self::CURRENT) {
                // release dir may not be a git repo — parse symlink name ui-YYYY…-HASH
                $link = @readlink(self::CURRENT);
                if (is_string($link) && preg_match('/-([0-9a-f]{7,40})$/i', basename($link), $m)) {
                    $sha = strtolower($m[1]);
                    break;
                }
                continue;
            }
            if (!is_dir($dir)) {
                continue;
            }
            $out = [];
            $code = 0;
            exec('git -C ' . escapeshellarg($dir) . ' rev-parse HEAD 2>/dev/null', $out, $code);
            if ($code === 0 && isset($out[0]) && preg_match('/^[0-9a-f]{7,40}$/i', trim($out[0]))) {
                $sha = strtolower(trim($out[0]));
                $dout = [];
                exec('git -C ' . escapeshellarg($dir) . ' show -s --format=%ci HEAD 2>/dev/null', $dout);
                $date = trim($dout[0] ?? '');
                break;
            }
        }
        return [
            'sha' => $sha,
            'short' => $sha !== '' ? substr($sha, 0, 7) : '',
            'date' => $date,
        ];
    }

    /** @return array{sha:string,short:string,date:string,message:string,error:string} */
    private function githubHead(): array
    {
        $url = 'https://api.github.com/repos/' . self::REPO . '/commits/main';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/vnd.github+json\r\nUser-Agent: Ownswitch-Portal\r\n",
                'timeout' => 8,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return ['sha' => '', 'short' => '', 'date' => '', 'message' => '', 'error' => 'Could not reach GitHub API'];
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['sha'])) {
            return ['sha' => '', 'short' => '', 'date' => '', 'message' => '', 'error' => 'Unexpected GitHub response'];
        }
        $sha = strtolower((string) $j['sha']);
        $msg = trim((string) ($j['commit']['message'] ?? ''));
        if (($nl = strpos($msg, "\n")) !== false) {
            $msg = substr($msg, 0, $nl);
        }
        return [
            'sha' => $sha,
            'short' => substr($sha, 0, 7),
            'date' => (string) ($j['commit']['committer']['date'] ?? ''),
            'message' => mb_substr($msg, 0, 160),
            'error' => '',
        ];
    }
}
