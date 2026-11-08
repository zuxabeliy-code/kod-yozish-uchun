<?php
declare(strict_types=1);
defined('ADI') || exit;

const SCHEMA_VERSION = 1;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = cfg('db');
    if (!is_array($c)) {
        throw new RuntimeException('Baza sozlamalari topilmadi (config/config.php)');
    }
    $pdo = db_connect($c);
    return $pdo;
}

function db_connect(array $c): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', (int)($c['port'] ?? 3306), $c['name'] ?? '');
    $pdo = new PDO($dsn, (string)($c['user'] ?? ''), (string)($c['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $off = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
    $pdo->exec("SET time_zone = '" . $off . "', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

/** Baza ulanganmi (xato bermasdan tekshiradi). */
function db_ready(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        db();
        $ok = true;
    } catch (Throwable) {
        $ok = false;
    }
    return $ok;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Tranzaksiya ichida bajaradi. Ichma-ich chaqirilsa tashqi tranzaksiyadan foydalanadi. */
function tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** schema.sql ni bajaradi (CREATE TABLE IF NOT EXISTS — qayta ishga tushirish xavfsiz). */
function db_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $sql = (string)file_get_contents(ROOT . '/database/schema.sql');
    $done = 0;
    foreach (sql_split($sql) as $stmt) {
        $pdo->exec($stmt);
        $done++;
    }
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string)SCHEMA_VERSION]);
    return ['statements' => $done];
}

function sql_split(string $sql): array
{
    $sql = preg_replace('~^\s*--.*$~m', '', $sql) ?? $sql;
    $out = [];
    foreach (explode(";\n", $sql) as $s) {
        $s = trim($s);
        if ($s !== '') {
            $out[] = rtrim($s, ';');
        }
    }
    return $out;
}

/** Kerakli jadvallar ro'yxati (diagnostika shu bilan solishtiradi). */
function required_tables(): array
{
    return ['users', 'employees', 'categories', 'products', 'dining_tables', 'customers', 'orders', 'order_items', 'item_cancels',
        'payments', 'deliveries', 'shifts', 'print_jobs', 'sync_operations', 'devices', 'settings', 'diagnostic_checks',
        'error_logs', 'audit_logs', 'hamyon_payments', 'card_receipts'];
}

function audit(string $action, string $entity = '', string|int $entityId = '', array $data = [], ?int $employeeId = null): void
{
    try {
        q('INSERT INTO audit_logs (employee_id, action, entity, entity_id, data, ip) VALUES (?, ?, ?, ?, ?, ?)', [
            $employeeId ?? (current_admin()['id'] ?? null), $action, $entity, (string)$entityId,
            $data ? json_encode(redact($data), JSON_UNESCAPED_UNICODE) : null, client_ip(),
        ]);
    } catch (Throwable $e) {
        log_msg('audit', $action . ' ' . $e->getMessage());
    }
}
