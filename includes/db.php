<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/* ── kv cache (Redis replacement) ─────────────────────────────────────────── */

function kv_get(string $key) {
    $st = db()->prepare(
        'SELECT value FROM kv WHERE key = :k AND (expires_at IS NULL OR expires_at > now())'
    );
    $st->execute([':k' => $key]);
    $row = $st->fetch();
    return $row ? json_decode($row['value'], true) : null;
}

function kv_set(string $key, $value, ?int $ttl_seconds = null): void {
    $st = db()->prepare(
        'INSERT INTO kv (key, value, updated_at, expires_at)
         VALUES (:k, :v, now(), :exp)
         ON CONFLICT (key) DO UPDATE
         SET value = EXCLUDED.value, updated_at = now(), expires_at = EXCLUDED.expires_at'
    );
    $exp = $ttl_seconds !== null ? gmdate('Y-m-d H:i:s+00', time() + $ttl_seconds) : null;
    $st->execute([':k' => $key, ':v' => json_encode($value), ':exp' => $exp]);
}

function kv_del(string $key): void {
    $st = db()->prepare('DELETE FROM kv WHERE key = :k');
    $st->execute([':k' => $key]);
}

function kv_purge_expired(): void {
    db()->exec('DELETE FROM kv WHERE expires_at IS NOT NULL AND expires_at <= now()');
}

/* ── settings ─────────────────────────────────────────────────────────────── */

function setting(string $key, string $default = ''): string {
    $st = db()->prepare('SELECT value FROM settings WHERE key = :k');
    $st->execute([':k' => $key]);
    $row = $st->fetch();
    return $row !== false ? $row['value'] : $default;
}

function set_setting(string $key, string $value): void {
    $st = db()->prepare(
        'INSERT INTO settings (key, value) VALUES (:k, :v)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
    );
    $st->execute([':k' => $key, ':v' => $value]);
}

/* ── misc helpers ─────────────────────────────────────────────────────────── */

// ISO-8601 UTC timestamp, e.g. 2026-06-12T17:03:00+00:00 (JS Date parses this).
function iso_now(): string {
    return gmdate('c');
}

function holdings_rows(): array {
    return db()->query('SELECT ticker, name, quantity, avg_cost FROM holdings ORDER BY ticker')->fetchAll();
}
