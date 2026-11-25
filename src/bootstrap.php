<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('TIG Door needs PHP 8.0 or newer, but this server is running PHP ' . PHP_VERSION
        . '. Choose a newer PHP version for this subdomain in your hosting control panel.');
}
foreach (['pdo_sqlite', 'openssl', 'curl', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        exit("TIG Door needs the PHP '{$ext}' extension. Enable it in your hosting control panel (PHP settings / extensions).");
    }
}

// Turn uncaught errors into a readable page. With 'debug' => true in config.php the real
// message is shown; otherwise it goes to the server's PHP error log.
set_exception_handler(function (Throwable $e): void {
    error_log('[tig-door] ' . $e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e . "\n");
        exit(1);
    }
    $detail = config('debug')
        ? '<pre style="white-space:pre-wrap;font-size:13px">' . htmlspecialchars(get_class($e) . ': ' . $e->getMessage()
            . "\nin " . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()) . '</pre>'
        : "The error has been written to the server's PHP error log. To show it here, set <code>'debug' => true</code> in config.php (turn it off again afterwards).";
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
        . '<body style="font:16px system-ui;max-width:760px;margin:3em auto;padding:0 16px">'
        . '<h1>Something went wrong</h1><p>' . $detail . '</p></body>';
});
$GLOBALS['TIG_CONFIG'] = load_config();
date_default_timezone_set((string) config('timezone') ?: 'UTC');

if (config('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

/**
 * config.example.php supplies defaults; config.php (not in git) overrides them.
 * 'branding' and 'theme' are merged key by key so a partial section still works.
 */
function load_config(): array
{
    $root = dirname(__DIR__);
    $defaults = require $root . '/config.example.php';
    if (!is_file($root . '/config.php')) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "config.php not found. Copy config.example.php to config.php and edit it.\n");
            exit(1);
        }
        fatal_page('No <code>config.php</code> found. Make a copy of <code>config.example.php</code> named '
            . '<code>config.php</code>, edit it (at least set <code>admin_access_word</code>), upload it and reload.');
    }
    $local = require $root . '/config.php';
    if (!is_array($local)) {
        fatal_page('<code>config.php</code> must <code>return [ ... ];</code> an array of settings, like <code>config.example.php</code>.');
    }
    $config = array_replace($defaults, $local);
    foreach (['branding', 'theme'] as $section) {
        $config[$section] = array_replace((array) ($defaults[$section] ?? []), (array) ($local[$section] ?? []));
    }
    return $config;
}

require_once __DIR__ . '/webpush.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/events.php';

function config(string $key)
{
    return $GLOBALS['TIG_CONFIG'][$key] ?? null;
}

// ---------------------------------------------------------------- database

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . db_path(), null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

/**
 * Location of the SQLite file. Unless config.php sets db_path, it lives in data/ under a
 * random, unguessable name (recorded in data/db-name.php) so it can't be downloaded even
 * on servers that ignore .htaccess.
 */
function db_path(): string
{
    if (config('db_path')) {
        return config('db_path');
    }
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (!is_writable($dir)) {
        fatal_page('The <code>data</code> folder is missing or not writable by PHP. Create it and set its permissions to 755 (or 775), then reload.');
    }
    $pointer = $dir . '/db-name.php';
    if (!is_file($pointer)) {
        $name = 'tig-door-' . bin2hex(random_bytes(16)) . '.sqlite';
        $fh = @fopen($pointer, 'x'); // 'x' fails if another request created it first
        if ($fh) {
            fwrite($fh, "<?php\n// Auto-generated. Do not delete: it names the database file.\nreturn " . var_export($name, true) . ";\n");
            fclose($fh);
        }
    }
    return $dir . '/' . (require $pointer);
}

function fatal_page(string $html): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Setup needed</title>'
        . '<body style="font:16px system-ui;max-width:560px;margin:3em auto;padding:0 16px">'
        . '<h1>TIG Door — setup needed</h1><p>' . $html . '</p></body>';
    exit;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS organisers (
            id            INTEGER PRIMARY KEY,
            username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
            display_name  TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            created_at    INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS devices (
            id             INTEGER PRIMARY KEY,
            organiser_id   INTEGER NOT NULL REFERENCES organisers(id) ON DELETE CASCADE,
            token_hash     TEXT NOT NULL UNIQUE,
            user_agent     TEXT,
            test_pending   INTEGER NOT NULL DEFAULT 0,
            created_at     INTEGER NOT NULL,
            last_seen_at   INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS subscriptions (
            id         INTEGER PRIMARY KEY,
            device_id  INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
            endpoint   TEXT NOT NULL UNIQUE,
            p256dh     TEXT,
            auth       TEXT,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS knocks (
            id               INTEGER PRIMARY KEY,
            visitor_token    TEXT NOT NULL UNIQUE,
            name             TEXT NOT NULL,
            message          TEXT,
            ip               TEXT,
            status           TEXT NOT NULL DEFAULT 'waiting',
            answered_by      TEXT,
            created_at       INTEGER NOT NULL,
            last_notified_at INTEGER NOT NULL,
            answered_at      INTEGER
        );
        CREATE INDEX IF NOT EXISTS knocks_created ON knocks(created_at);
        CREATE INDEX IF NOT EXISTS knocks_ip ON knocks(ip, created_at);
        CREATE TABLE IF NOT EXISTS events (
            id           INTEGER PRIMARY KEY,
            title        TEXT NOT NULL,
            location     TEXT,
            description  TEXT,
            start_date   TEXT NOT NULL,           -- Y-m-d (first occurrence)
            start_time   TEXT NOT NULL,           -- H:i
            end_time     TEXT NOT NULL,           -- H:i
            recurrence   TEXT NOT NULL DEFAULT 'none',
            repeat_until TEXT,                    -- Y-m-d or NULL for forever
            skip_dates   TEXT,                    -- comma-separated Y-m-d dates with no session
            active       INTEGER NOT NULL DEFAULT 1,
            created_by   TEXT,
            created_at   INTEGER NOT NULL,
            updated_at   INTEGER NOT NULL
        );
    SQL);

    // Columns added after the first release.
    add_column_if_missing($pdo, 'knocks', 'event_id', 'INTEGER');
    add_column_if_missing($pdo, 'knocks', 'event_title', 'TEXT');
}

function add_column_if_missing(PDO $pdo, string $table, string $column, string $type): void
{
    foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $col) {
        if ($col['name'] === $column) {
            return;
        }
    }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
}

function setting(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
        return $value;
    }
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : $v;
}

// ---------------------------------------------------------------- http helpers

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return config('trust_proxy') && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function client_ip(): string
{
    if (config('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_response(['error' => 'Method not allowed'], 405);
    }
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function random_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

// ---------------------------------------------------------------- sessions & auth

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.use_strict_mode', '1');
    session_name('tigdoor');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token();
    }
    return $_SESSION['csrf'];
}

function csrf_check(): bool
{
    start_session();
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));
}

function current_organiser(): ?array
{
    start_session();
    if (empty($_SESSION['organiser_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, display_name FROM organisers WHERE id = ?');
    $stmt->execute([$_SESSION['organiser_id']]);
    return $stmt->fetch() ?: null;
}

/** Creates a device token for this browser on login. The plain token lives only in the session + service worker. */
function create_device(int $organiserId): string
{
    $token = random_token();
    $now = time();
    db()->prepare('INSERT INTO devices(organiser_id, token_hash, user_agent, created_at, last_seen_at) VALUES(?, ?, ?, ?, ?)')
        ->execute([$organiserId, hash('sha256', $token), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $now, $now]);
    return $token;
}

/** Authenticates API calls (dashboard + service worker) via the X-Token header. */
function require_device(): array
{
    $token = $_SERVER['HTTP_X_TOKEN'] ?? '';
    if ($token === '') {
        json_response(['error' => 'Missing token'], 401);
    }
    $stmt = db()->prepare(
        'SELECT d.id, d.organiser_id, d.test_pending, o.display_name
           FROM devices d JOIN organisers o ON o.id = d.organiser_id
          WHERE d.token_hash = ?'
    );
    $stmt->execute([hash('sha256', $token)]);
    $device = $stmt->fetch();
    if (!$device) {
        json_response(['error' => 'Invalid or expired token — please log in again'], 401);
    }
    db()->prepare('UPDATE devices SET last_seen_at = ? WHERE id = ?')->execute([time(), $device['id']]);
    return $device;
}

// ---------------------------------------------------------------- knocks & notifications

function knock_public(array $k): array
{
    return [
        'id'          => (int) $k['id'],
        'name'        => $k['name'],
        'message'     => $k['message'],
        'event'       => $k['event_title'] ?? null,
        'status'      => $k['status'],
        'answered_by' => $k['answered_by'],
        'created_at'  => (int) $k['created_at'],
        'answered_at' => $k['answered_at'] !== null ? (int) $k['answered_at'] : null,
    ];
}

function find_knock_by_visitor_token(string $token): ?array
{
    $stmt = db()->prepare('SELECT * FROM knocks WHERE visitor_token = ?');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/**
 * Wakes every subscribed organiser device (optionally excluding one device).
 * Pushes carry no payload; the service worker fetches details from api/latest.php.
 */
function notify_devices(?int $excludeDeviceId = null, ?int $onlyDeviceId = null): array
{
    $sql = 'SELECT id, endpoint FROM subscriptions WHERE 1=1';
    $args = [];
    if ($excludeDeviceId !== null) {
        $sql .= ' AND device_id != ?';
        $args[] = $excludeDeviceId;
    }
    if ($onlyDeviceId !== null) {
        $sql .= ' AND device_id = ?';
        $args[] = $onlyDeviceId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $endpoints = [];
    foreach ($stmt->fetchAll() as $row) {
        $endpoints[(int) $row['id']] = $row['endpoint'];
    }
    if (!$endpoints) {
        return ['sent' => 0, 'failed' => 0];
    }

    $results = WebPush::sendMany($endpoints);
    $sent = $failed = 0;
    $delete = db()->prepare('DELETE FROM subscriptions WHERE id = ?');
    foreach ($results as $id => $r) {
        if ($r['status'] >= 200 && $r['status'] < 300) {
            $sent++;
            continue;
        }
        $failed++;
        if (in_array($r['status'], [404, 410], true)) {
            $delete->execute([$id]); // subscription expired or revoked
        } else {
            error_log(sprintf('[tig-door] push to subscription %d failed: HTTP %d %s %s', $id, $r['status'], $r['error'], substr((string) $r['body'], 0, 200)));
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

function push_host_allowed(string $endpoint): bool
{
    $p = parse_url($endpoint);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
        return false;
    }
    $host = strtolower($p['host']);
    foreach (config('push_hosts') as $allowed) {
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------- organisers

function organiser_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM organisers')->fetchColumn();
}

function validate_organiser(string $username, string $displayName, string $password, string $password2): array
{
    $errors = [];
    if (!preg_match('/^[A-Za-z0-9._-]{2,40}$/', $username)) {
        $errors[] = 'Username must be 2–40 characters: letters, numbers, dot, dash or underscore.';
    }
    if ($displayName === '' || mb_strlen($displayName) > 40) {
        $errors[] = 'Please enter a display name (up to 40 characters) — visitors see it, e.g. "Alex is on the way".';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== $password2) {
        $errors[] = 'Passwords do not match.';
    }
    return $errors;
}

/** Creates an organiser, or updates name + password if the username exists. Returns the id. */
function save_organiser(string $username, string $displayName, string $password): int
{
    db()->prepare(
        'INSERT INTO organisers(username, display_name, password_hash, created_at) VALUES(?, ?, ?, ?)
         ON CONFLICT(username) DO UPDATE SET display_name = excluded.display_name, password_hash = excluded.password_hash'
    )->execute([$username, $displayName, password_hash($password, PASSWORD_DEFAULT), time()]);
    $stmt = db()->prepare('SELECT id FROM organisers WHERE username = ?');
    $stmt->execute([$username]);
    return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------------- admin access word

function admin_word_configured(): bool
{
    $word = (string) config('admin_access_word');
    return strlen($word) >= 8 && $word !== 'change-me';
}

function admin_word_matches(string $given): bool
{
    if (!admin_word_configured() || !hash_equals((string) config('admin_access_word'), $given)) {
        sleep(1); // slow down guessing
        return false;
    }
    return true;
}

/** Remembers in the session that the admin word was entered (locks again if the word changes). */
function admin_unlock(): void
{
    start_session();
    $_SESSION['admin_unlocked'] = hash('sha256', (string) config('admin_access_word'));
}

function admin_lock(): void
{
    start_session();
    unset($_SESSION['admin_unlocked']);
}

function admin_unlocked(): bool
{
    start_session();
    return admin_word_configured()
        && isset($_SESSION['admin_unlocked'])
        && hash_equals(hash('sha256', (string) config('admin_access_word')), (string) $_SESSION['admin_unlocked']);
}
