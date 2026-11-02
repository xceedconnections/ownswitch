<?php

declare(strict_types=1);

/**
 * Local REGISTER probe against OpenSIPS (run ON the server).
 *
 *   sudo OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
 *     php /opt/ownswitch/current/bin/test-sip-register.php USER PASS
 *
 * Sends REGISTER to 127.0.0.1:5060 with realm from OPENSIPS_SIP_DOMAIN.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$user = $argv[1] ?? '';
$pass = $argv[2] ?? '';
if ($user === '' || $pass === '') {
    fwrite(STDERR, "Usage: php bin/test-sip-register.php <username> <password>\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Ownswitch\App;

$domain = (string) App::config('app.integrations.opensips.sip_domain', 'localhost');
$port = 5060;
$dst = '127.0.0.1';
$callId = bin2hex(random_bytes(8)) . '@127.0.0.1';
$branch = 'z9hG4bK' . bin2hex(random_bytes(4));
$tag = bin2hex(random_bytes(4));

$sock = @stream_socket_client("udp://{$dst}:{$port}", $errno, $errstr, 2);
if (!$sock) {
    fwrite(STDERR, "Cannot open UDP to {$dst}:{$port}: {$errstr}\n");
    exit(1);
}
stream_set_timeout($sock, 3);

$send = static function (string $msg) use ($sock): void {
    fwrite($sock, $msg);
};

$recv = static function () use ($sock): string {
    $data = fread($sock, 8192);
    return is_string($data) ? $data : '';
};

$req1 = "REGISTER sip:{$domain} SIP/2.0\r\n"
    . "Via: SIP/2.0/UDP 127.0.0.1:5090;branch={$branch};rport\r\n"
    . "Max-Forwards: 70\r\n"
    . "From: <sip:{$user}@{$domain}>;tag={$tag}\r\n"
    . "To: <sip:{$user}@{$domain}>\r\n"
    . "Call-ID: {$callId}\r\n"
    . "CSeq: 1 REGISTER\r\n"
    . "Contact: <sip:{$user}@127.0.0.1:5090>\r\n"
    . "Expires: 60\r\n"
    . "Content-Length: 0\r\n\r\n";

echo ">>> REGISTER (no auth) to {$dst}:{$port} realm/domain={$domain} user={$user}\n";
$send($req1);
$r1 = $recv();
echo $r1 !== '' ? $r1 : "(no response — OpenSIPS not listening on UDP {$port}?)\n";

if ($r1 === '' || !preg_match('/^SIP\/2\.0\s+(\d+)/', $r1, $m)) {
    exit(1);
}
$code = (int) $m[1];
if ($code === 200) {
    echo "OK: accepted without challenge (unexpected but fine)\n";
    exit(0);
}
if ($code !== 401 && $code !== 407) {
    fwrite(STDERR, "Unexpected status {$code}\n");
    exit(1);
}

if (!preg_match('/realm="([^"]+)"/i', $r1, $rm) || !preg_match('/nonce="([^"]+)"/i', $r1, $nm)) {
    fwrite(STDERR, "Challenge missing realm/nonce\n");
    exit(1);
}
$realm = $rm[1];
$nonce = $nm[1];
$uri = "sip:{$domain}";
$ha1 = md5("{$user}:{$realm}:{$pass}");
$ha2 = md5("REGISTER:{$uri}");
$response = md5("{$ha1}:{$nonce}:{$ha2}");

$branch2 = 'z9hG4bK' . bin2hex(random_bytes(4));
$req2 = "REGISTER sip:{$domain} SIP/2.0\r\n"
    . "Via: SIP/2.0/UDP 127.0.0.1:5090;branch={$branch2};rport\r\n"
    . "Max-Forwards: 70\r\n"
    . "From: <sip:{$user}@{$domain}>;tag={$tag}\r\n"
    . "To: <sip:{$user}@{$domain}>\r\n"
    . "Call-ID: {$callId}\r\n"
    . "CSeq: 2 REGISTER\r\n"
    . "Contact: <sip:{$user}@127.0.0.1:5090>\r\n"
    . "Authorization: Digest username=\"{$user}\", realm=\"{$realm}\", nonce=\"{$nonce}\", uri=\"{$uri}\", response=\"{$response}\", algorithm=MD5\r\n"
    . "Expires: 60\r\n"
    . "Content-Length: 0\r\n\r\n";

echo ">>> REGISTER (with digest) realm={$realm}\n";
$send($req2);
$r2 = $recv();
echo $r2 !== '' ? $r2 : "(no response)\n";

if (preg_match('/^SIP\/2\.0\s+200/', $r2)) {
    echo "\nSUCCESS: OpenSIPS accepted REGISTER for {$user}@{$domain}\n";
    echo "If softphone still fails, the problem is network/firewall/NAT to this server (UDP/TCP 5060), not passwords.\n";
    exit(0);
}

echo "\nFAILED: OpenSIPS did not return 200. Check subscriber HA1 matches realm={$realm}.\n";
echo "Reset SIP password in portal, then re-run this test.\n";
exit(1);
