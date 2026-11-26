<?php
// Run with: php tests/api.php   (needs pdo_sqlite and curl)
declare(strict_types=1);

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/family-test-' . bin2hex(random_bytes(4));
mkdir("$work/data", 0700, true);
$passed = $failed = 0;

function check(string $name, bool $ok, string $info = ''): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : "  ($info)") . "\n";
}

$port = 18100 + random_int(0, 800);
$env = [
    'FAMILY_DATA_DIR' => "$work/data", 'FAMILY_MIN_FREE_BYTES' => '0', 'STASHKEY_NEW_PER_IP_HOUR' => '5',
] + getenv();
$server = proc_open(
    [PHP_BINARY, '-d', 'post_max_size=40M', '-d', 'upload_max_filesize=40M', '-S', "127.0.0.1:$port", '-t', $root, "$root/router.php"],
    [0 => ['pipe', 'r'], 1 => ['file', "$work/server.log", 'a'], 2 => ['file', "$work/server.log", 'a']], $pipes, $root, $env
);
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

function req(string $method, string $path, ?string $body = null): array {
    global $base;
    $c = curl_init($base . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method]);
    if ($body !== null) curl_setopt_array($c, [CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream']]);
    $raw = (string) curl_exec($c);
    $size = curl_getinfo($c, CURLINFO_HEADER_SIZE);
    $out = ['status' => curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($c);
    return $out;
}

function sealed(int $kdf, int $size = 2000): string {
    return 'SEAL' . chr(1) . chr($kdf) . random_bytes($size - 6);
}

function json(array $r): array {
    return json_decode($r['body'], true) ?: [];
}

try {
    $sid = bin2hex(random_bytes(32));
    $blob = random_bytes(1500);
    $made = req('POST', "/api.php?action=put&id=$sid&rev=0", $blob);
    check('stash: a new stash is created', $made['status'] === 200 && (json($made)['rev'] ?? 0) === 1);
    $got = req('GET', "/api.php?action=get&id=$sid");
    check('stash: it comes back unchanged with its version', $got['status'] === 200 && $got['body'] === $blob && stripos($got['headers'], 'X-Rev: 1') !== false);
    check('stash: the same words cannot create it twice', req('POST', "/api.php?action=put&id=$sid&rev=0", random_bytes(1500))['status'] === 409);
    $new = random_bytes(2500);
    check('stash: saving the current version works', json(req('POST', "/api.php?action=put&id=$sid&rev=1", $new))['rev'] === 2);
    check('stash: saving an old version is refused', req('POST', "/api.php?action=put&id=$sid&rev=1", random_bytes(1500))['status'] === 409);
    check('stash: the latest save is what comes back', req('GET', "/api.php?action=get&id=$sid")['body'] === $new);
    check('stash: a stash over 256 KiB is refused', req('POST', "/api.php?action=put&id=$sid&rev=2", str_repeat('x', 262145))['status'] === 413);
    check('stash: an unknown stash is not found', req('GET', '/api.php?action=get&id=' . bin2hex(random_bytes(32)))['status'] === 404);
    check('stash: a malformed id is refused', req('GET', '/api.php?action=get&id=' . str_repeat('a', 63))['status'] === 400);
    check('stash: saving to a stash that does not exist is refused', req('POST', '/api.php?action=put&id=' . bin2hex(random_bytes(32)) . '&rev=3', $blob)['status'] === 409);
    $gone = bin2hex(random_bytes(32));
    req('POST', "/api.php?action=put&id=$gone&rev=0", $blob);
    check('stash: a stash can be deleted', (json(req('POST', "/api.php?action=del&id=$gone"))['deleted'] ?? false) === true && req('GET', "/api.php?action=get&id=$gone")['status'] === 404);
    req('POST', '/api.php?action=put&id=' . bin2hex(random_bytes(32)) . '&rev=0', $blob);
    req('POST', '/api.php?action=put&id=' . bin2hex(random_bytes(32)) . '&rev=0', $blob);
    check('stash: too many new stashes from one place are slowed down', req('POST', '/api.php?action=put&id=' . bin2hex(random_bytes(32)) . '&rev=0', $blob)['status'] === 429);

    $page = req('GET', '/api.php?action=get&id=zzz');
    check('headers: the API sets safe headers', stripos($page['headers'], 'nosniff') !== false && stripos($page['headers'], 'no-store') !== false);
    $db = new PDO("sqlite:$work/data/family.db");
    $stored = (string) $db->query("SELECT group_concat(hex(data)) FROM stashes")->fetchColumn();
    check('data: nothing but what the browser sent is stored', strlen($stored) > 0 && !str_contains($stored, bin2hex('stash')));
    check('data: the data folder is not inside the web root', !str_starts_with(realpath("$work/data"), realpath($root)));
} finally {
    proc_terminate($server);
    proc_close($server);
    exec('rm -rf ' . escapeshellarg($work));
}

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
