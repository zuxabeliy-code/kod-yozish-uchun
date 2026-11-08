<?php
declare(strict_types=1);
defined('ADI') || exit;

const BACKUP_KEEP = 14;

function backup_dir(): string
{
    return ROOT . '/storage/backups';
}

/** Bazaning to'liq SQL nusxasi (mysqldump shart emas — PDO orqali). */
function backup_create(): array
{
    @set_time_limit(300);
    $name = 'backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql.gz';
    $path = backup_dir() . '/' . $name;
    $gz = gzopen($path, 'wb6');
    if (!$gz) {
        throw new RuntimeException('Zaxira faylini yaratib bo\'lmadi: storage/backups yozishga ruxsat yo\'q');
    }
    $pdo = db();
    gzwrite($gz, "-- ADI BURGER 2.0 zaxira nusxasi " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
        $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
        $batch = [];
        while ($r = $st->fetch()) {
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            if (count($batch) >= 200) {
                gzwrite($gz, "INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            gzwrite($gz, "INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    setting_set('last_backup_at', date('Y-m-d H:i:s'));
    // Eski nusxalarni o'chirish (oxirgi 14 tasi qoladi)
    $all = backup_list();
    foreach (array_slice($all, BACKUP_KEEP) as $old) {
        @unlink(backup_dir() . '/' . $old['name']);
    }
    return ['name' => $name, 'size' => filesize($path)];
}

function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/backup-*.sql.gz') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

function backup_path(string $name): ?string
{
    if (!preg_match('~^backup-\d{8}-\d{6}-[0-9a-f]{8}\.sql\.gz$~', $name)) {
        return null;
    }
    $p = backup_dir() . '/' . $name;
    return is_file($p) ? $p : null;
}
