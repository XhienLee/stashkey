<?php
declare(strict_types=1);
require __DIR__ . '/family/lib.php';

const STASH_MAX = 262144;

$action = $_GET['action'] ?? '';

if ($action === 'put') {
    fam_start('POST');
    $id = fam_hex($_GET['id'] ?? '', 64);
    $rev = (int) ($_GET['rev'] ?? -1);
    if ($rev < 0) fam_fail(400, 'bad_rev', 'Missing version.');
    $body = fam_body(STASH_MAX);
    $db = fam_db();
    if ($rev === 0) {
        fam_limit('stash-new', fam_env('STASHKEY_NEW_PER_IP_HOUR', 5), fam_env('STASHKEY_NEW_GLOBAL_HOUR', 100));
        if ((int) $db->query('SELECT COUNT(*) FROM stashes')->fetchColumn() >= fam_env('STASHKEY_MAX_STASHES', 10000)) {
            fam_fail(507, 'full', 'This server cannot take new stashes right now.');
        }
        $made = $db->prepare('INSERT OR IGNORE INTO stashes (id, rev, data, created_at, updated_at) VALUES (?, 1, ?, ?, ?)');
        $made->bindValue(1, $id);
        $made->bindValue(2, $body, PDO::PARAM_LOB);
        $made->bindValue(3, time(), PDO::PARAM_INT);
        $made->bindValue(4, time(), PDO::PARAM_INT);
        $made->execute();
        if ($made->rowCount() !== 1) fam_fail(409, 'exists', 'A stash with these words already exists.');
        fam_json(200, ['ok' => true, 'rev' => 1]);
    }
    fam_limit('stash-save', fam_env('STASHKEY_SAVES_PER_IP_HOUR', 600), fam_env('STASHKEY_SAVES_GLOBAL_HOUR', 20000));
    $save = $db->prepare('UPDATE stashes SET data = ?, rev = rev + 1, updated_at = ? WHERE id = ? AND rev = ?');
    $save->bindValue(1, $body, PDO::PARAM_LOB);
    $save->bindValue(2, time(), PDO::PARAM_INT);
    $save->bindValue(3, $id);
    $save->bindValue(4, $rev, PDO::PARAM_INT);
    $save->execute();
    if ($save->rowCount() !== 1) fam_fail(409, 'changed', 'This stash changed somewhere else. Reload it first.');
    fam_json(200, ['ok' => true, 'rev' => $rev + 1]);
}

if ($action === 'del') {
    fam_start('POST');
    $id = fam_hex($_GET['id'] ?? '', 64);
    $gone = fam_db()->prepare('DELETE FROM stashes WHERE id = ?');
    $gone->execute([$id]);
    fam_json(200, ['ok' => true, 'deleted' => $gone->rowCount() === 1]);
}

fam_start('GET');
$id = fam_hex($_GET['id'] ?? '', 64);
if ($action === 'get') {
    fam_limit('stash-open', fam_env('STASHKEY_OPEN_PER_IP_HOUR', 120), fam_env('STASHKEY_OPEN_GLOBAL_HOUR', 5000));
    $row = fam_db()->prepare('SELECT rev, data FROM stashes WHERE id = ?');
    $row->execute([$id]);
    $found = $row->fetch();
    if (!$found) fam_fail(404, 'none', 'No stash found for those words.');
    header('Content-Type: application/octet-stream');
    header('X-Rev: ' . $found['rev']);
    echo is_resource($found['data']) ? stream_get_contents($found['data']) : $found['data'];
    exit;
}

fam_fail(400, 'bad_action', 'Unknown request.');
