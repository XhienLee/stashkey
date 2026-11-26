<?php
declare(strict_types=1);

const FAM_MAX_BLOB = 26214400;

function fam_env(string $name, int $default): int {
    $value = $_SERVER[$name] ?? getenv($name);
    return ($value !== false && ctype_digit((string) $value)) ? (int) $value : $default;
}

function fam_json(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body);
    exit;
}

function fam_fail(int $status, string $code, string $message): never {
    fam_json($status, ['ok' => false, 'code' => $code, 'message' => $message]);
}

function fam_start(string $method): void {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header("Allow: $method");
        fam_fail(405, 'method', 'Wrong request method.');
    }
    fam_sweep();
}

function fam_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;
    $want = $_SERVER['FAMILY_DATA_DIR'] ?? getenv('FAMILY_DATA_DIR') ?: dirname(__DIR__, 2) . '/family_data';
    if (!is_dir($want) && !@mkdir($want, 0700, true) && !is_dir($want)) {
        error_log("family: cannot create $want");
        fam_fail(500, 'config', 'The server is not set up correctly.');
    }
    $real = realpath($want);
    foreach ([$_SERVER['DOCUMENT_ROOT'] ?? '', dirname(__DIR__)] as $root) {
        $root = $root === '' ? false : realpath($root);
        if ($real === false || ($root !== false && str_starts_with($real . '/', rtrim($root, '/') . '/'))) {
            error_log('family: FAMILY_DATA_DIR must be outside the web root');
            fam_fail(500, 'config', 'The server is not set up correctly.');
        }
    }
    @mkdir("$real/blobs", 0700, true);
    return $dir = $real;
}

function fam_db(): PDO {
    static $db = null;
    if ($db !== null) return $db;
    $db = new PDO('sqlite:' . fam_dir() . '/family.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec("CREATE TABLE IF NOT EXISTS blobs (id TEXT PRIMARY KEY, kind TEXT NOT NULL, owner TEXT NOT NULL DEFAULT '', size INTEGER NOT NULL, taken INTEGER NOT NULL DEFAULT 0, expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL)");
    $db->exec('CREATE INDEX IF NOT EXISTS blobs_by_owner ON blobs(kind, owner)');
    $db->exec('CREATE INDEX IF NOT EXISTS blobs_by_expiry ON blobs(expires_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS stashes (id TEXT PRIMARY KEY, rev INTEGER NOT NULL, data BLOB NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS hits (kind TEXT NOT NULL, client TEXT NOT NULL, at INTEGER NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS hits_by_time ON hits(at)');
    $db->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
    @chmod(fam_dir() . '/family.db', 0600);
    return $db;
}

function fam_sweep(): void {
    $db = fam_db();
    $gone = $db->prepare('SELECT id FROM blobs WHERE expires_at <= ? OR taken = 1');
    $gone->execute([time()]);
    foreach ($gone->fetchAll(PDO::FETCH_COLUMN) as $id) {
        @unlink(fam_dir() . "/blobs/$id");
        $db->prepare('DELETE FROM blobs WHERE id = ?')->execute([$id]);
    }
    $db->prepare('DELETE FROM hits WHERE at <= ?')->execute([time() - 3600]);
}

function fam_client(): string {
    $packed = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: '';
    if (strlen($packed) === 16) {
        $mapped = str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff");
        $packed = $mapped ? substr($packed, 12) : substr($packed, 0, 8);
    }
    $db = fam_db();
    $db->prepare("INSERT OR IGNORE INTO settings (name, value) VALUES ('client_salt', ?)")->execute([bin2hex(random_bytes(32))]);
    $salt = $db->query("SELECT value FROM settings WHERE name = 'client_salt'")->fetchColumn();
    return hash_hmac('sha256', $packed, $salt);
}

function fam_limit(string $kind, int $perClient, int $global): void {
    $db = fam_db();
    $client = fam_client();
    $db->exec('BEGIN IMMEDIATE');
    $mine = $db->prepare('SELECT COUNT(*) FROM hits WHERE kind = ? AND client = ?');
    $mine->execute([$kind, $client]);
    $all = $db->prepare('SELECT COUNT(*) FROM hits WHERE kind = ?');
    $all->execute([$kind]);
    if ((int) $mine->fetchColumn() >= $perClient || (int) $all->fetchColumn() >= $global) {
        $db->exec('ROLLBACK');
        header('Retry-After: 3600');
        fam_fail(429, 'busy', 'Too many requests. Please try again in about an hour.');
    }
    $db->prepare('INSERT INTO hits (kind, client, at) VALUES (?, ?, ?)')->execute([$kind, $client, time()]);
    $db->exec('COMMIT');
}

function fam_body(int $max): string {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) fam_fail(413, 'too_large', 'That is larger than this server accepts.');
    $in = fopen('php://input', 'rb');
    $body = $in ? stream_get_contents($in, $max + 1) : false;
    if ($in) fclose($in);
    if ($body === false || $body === '') fam_fail(400, 'empty', 'Nothing was received.');
    if (strlen($body) > $max) fam_fail(413, 'too_large', 'That is larger than this server accepts.');
    return $body;
}

function fam_sealed(string $body, int $kdf): void {
    $ok = strlen($body) >= 604 && substr($body, 0, 4) === 'SEAL' && ord($body[4]) === 1 && ord($body[5]) === $kdf;
    if (!$ok) fam_fail(400, 'not_sealed', $kdf === 2 ? 'That is not a file locked for a public key.' : "That is not a locked Sealkey file.");
}

function fam_hex(mixed $value, int $length): string {
    $value = is_string($value) ? strtolower($value) : '';
    if (!preg_match('/^[0-9a-f]{' . $length . '}$/', $value)) fam_fail(400, 'bad_id', 'That address is not valid.');
    return $value;
}

function fam_space(int $incoming): void {
    $free = @disk_free_space(fam_dir());
    if ($free !== false && $free - 2 * $incoming < fam_env('FAMILY_MIN_FREE_BYTES', 1073741824)) {
        fam_fail(507, 'disk_full', 'The server is full. Please try again later.');
    }
}

function fam_store(string $kind, string $owner, string $body, int $ttl): array {
    fam_space(strlen($body));
    $id = bin2hex(random_bytes(16));
    $expires = time() + $ttl;
    if (file_put_contents(fam_dir() . "/blobs/$id", $body, LOCK_EX) !== strlen($body)) {
        @unlink(fam_dir() . "/blobs/$id");
        fam_fail(500, 'save_failed', 'Saving did not finish. Please try again.');
    }
    fam_db()->prepare('INSERT INTO blobs (id, kind, owner, size, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, $kind, $owner, strlen($body), $expires, time()]);
    return ['id' => $id, 'expires_at' => $expires];
}

function fam_send_file(string $id): never {
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize(fam_dir() . "/blobs/$id"));
    while (ob_get_level()) ob_end_clean();
    readfile(fam_dir() . "/blobs/$id");
    exit;
}
