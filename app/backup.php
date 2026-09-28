<?php
/**
 * 数据库备份（纯 PHP 导出）
 * ------------------------------------------------------------
 * 共享主机普遍禁用 exec / shell_exec，mysqldump 调不起来，
 * 所以这里用 PDO 逐表读出、拼成 SQL 文本落盘。
 *
 * 存放：storage/backups/（该目录被 .htaccess 拒绝直连，只能走鉴权接口下载）
 * 限额：单次导出超过 BACKUP_MAX_BYTES 即中止并删除半成品
 * 权限：只允许主管理员调用（见 api/admin.php 的 require_admin）
 */
declare(strict_types=1);

define('BACKUP_DIR', APP_ROOT . '/storage/backups');
define('BACKUP_NAME_RE', '/^kimgr-\d{8}-\d{6}-[a-f0-9]{6}\.sql(\.gz)?$/');
define('BACKUP_CHUNK', 500);              // 每次从库里读多少行
define('BACKUP_ROWS_PER_INSERT', 200);    // 每条 INSERT 拼多少行
define('BACKUP_MAX_BYTES', 268435456);    // 256MB 上限（未压缩字节）

function backup_dir(): string
{
    if (!is_dir(BACKUP_DIR)) { @mkdir(BACKUP_DIR, 0775, true); }
    return BACKUP_DIR;
}

function backup_writable(): bool
{
    $d = backup_dir();
    return is_dir($d) && is_writable($d);
}

/** 文件名白名单：既是下载/删除的校验，也是防路径穿越的唯一入口 */
function backup_valid_name(string $name): bool
{
    return (bool)preg_match(BACKUP_NAME_RE, $name);
}

function backup_path(string $name): string
{
    if (!backup_valid_name($name)) { throw new InvalidArgumentException('备份文件名不合法'); }
    return backup_dir() . '/' . $name;
}

/** 备份列表（按文件名倒序，新备份在前） */
function backup_list(): array
{
    $out = array();
    $d = backup_dir();
    $files = @scandir($d);
    if (!is_array($files)) { return $out; }
    foreach ($files as $f) {
        if (!backup_valid_name((string)$f)) { continue; }
        $p = $d . '/' . $f;
        if (!is_file($p)) { continue; }
        $out[] = array(
            'name'  => (string)$f,
            'bytes' => (int)@filesize($p),
            'time'  => to_local(gmdate('Y-m-d H:i:s', (int)@filemtime($p))),
        );
    }
    usort($out, function ($a, $b) { return strcmp($b['name'], $a['name']); });
    return $out;
}

function backup_delete(string $name)
{
    $p = backup_path($name);
    if (!is_file($p)) { throw new RuntimeException('该备份不存在'); }
    if (!@unlink($p)) { throw new RuntimeException('删除失败，请检查 storage/backups 目录权限'); }
}

/* ---------- 写出（能压就压，省磁盘也省下载流量） ---------- */

function backup_open(string $path, bool $gz)
{
    $h = $gz ? @gzopen($path, 'wb6') : @fopen($path, 'wb');
    if ($h === false) { throw new RuntimeException('无法创建备份文件，请检查磁盘空间与目录权限'); }
    return $h;
}

function backup_write($h, bool $gz, string $s, int &$bytes)
{
    if ($s === '') { return; }
    $n = $gz ? @gzwrite($h, $s) : @fwrite($h, $s);
    if ($n === false || $n === 0) { throw new RuntimeException('写入备份文件失败，可能是磁盘空间不足'); }
    $bytes += strlen($s);
    if ($bytes > BACKUP_MAX_BYTES) {
        throw new RuntimeException('备份体积超过 ' . (int)(BACKUP_MAX_BYTES / 1048576) . 'MB，已中止');
    }
}

function backup_close($h, bool $gz)
{
    if ($gz) { @gzclose($h); } else { @fclose($h); }
}

/** 单个值 → SQL 字面量。含 \0 或非 UTF-8 的按二进制十六进制写出 */
function backup_literal(PDO $pdo, $v): string
{
    if ($v === null) { return 'NULL'; }
    if (is_int($v) || is_float($v)) { return (string)$v; }
    $s = (string)$v;
    if ($s === '') { return "''"; }
    if (!preg_match('//u', $s)) { return '0x' . bin2hex($s); }
    return $pdo->quote($s);
}

/**
 * 执行一次导出，返回备份元信息。
 * 一致性：InnoDB 下开一个只读快照事务，避免边导边变；不支持（如 MyISAM）则照常导出。
 */
function backup_run(): array
{
    if (!backup_writable()) {
        throw new RuntimeException('备份目录不可写：storage/backups');
    }

    /* 单飞锁：两次点击不并发导出，避免抢 I/O 与互相覆盖 */
    $lock = @fopen(backup_dir() . '/.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('已有一个备份任务在进行，请稍候再试');
    }

    $pdo = db();
    $gz  = function_exists('gzopen');
    $base = 'kimgr-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $name = $base . ($gz ? '.sql.gz' : '.sql');
    $path = backup_dir() . '/' . $name;

    $h = null; $bytes = 0;
    try {
        $h = backup_open($path, $gz);

        $snap = false;
        try {
            db_exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            db_exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $snap = true;
        } catch (Throwable $e) { /* 非 InnoDB 引擎等 → 不加锁，照常导出 */ }

        $tables = array();
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $r) {
            $tables[] = (string)$r[0];
        }

        $t0 = microtime(true);
        $head = "-- kimi-game-rank 数据备份\n"
              . '-- 生成时间（UTC）：' . gmdate('Y-m-d H:i:s') . "\n"
              . '-- 表数量：' . count($tables) . "\n"
              . ($snap ? '' : "-- 注意：本次未能开启一致性快照，导出期间的数据变动可能未完全同步\n")
              . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        backup_write($h, $gz, $head, $bytes);

        $rows = 0;
        foreach ($tables as $t) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM);
            if (!is_array($create) || empty($create[1])) { continue; }

            backup_write($h, $gz,
                "-- ---------- 表 `$t` ----------\n"
                . 'DROP TABLE IF EXISTS `' . $t . "`;\n"
                . (string)$create[1] . ";\n\n", $bytes);

            $cols = ''; $batch = array();
            for ($off = 0; ; $off += BACKUP_CHUNK) {
                $chunk = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '` LIMIT '
                    . BACKUP_CHUNK . ' OFFSET ' . $off)->fetchAll();
                if (empty($chunk)) { break; }
                foreach ($chunk as $row) {
                    if ($cols === '') { $cols = '(`' . implode('`,`', array_keys($row)) . '`)'; }
                    $vals = array();
                    foreach ($row as $v) { $vals[] = backup_literal($pdo, $v); }
                    $batch[] = '(' . implode(',', $vals) . ')';
                    $rows++;
                    if (count($batch) >= BACKUP_ROWS_PER_INSERT) {
                        backup_write($h, $gz, 'INSERT INTO `' . $t . '` ' . $cols . " VALUES\n"
                            . implode(",\n", $batch) . ";\n", $bytes);
                        $batch = array();
                    }
                }
                if (count($chunk) < BACKUP_CHUNK) { break; }
            }
            if (!empty($batch)) {
                backup_write($h, $gz, 'INSERT INTO `' . $t . '` ' . $cols . " VALUES\n"
                    . implode(",\n", $batch) . ";\n", $bytes);
            }
            backup_write($h, $gz, "\n", $bytes);
        }

        backup_write($h, $gz, "SET FOREIGN_KEY_CHECKS=1;\n-- 导出行数：$rows\n", $bytes);
        backup_close($h, $gz);
        $h = null;

        if ($snap) { try { db_exec('ROLLBACK'); } catch (Throwable $e) { /* 只读事务，回滚失败无影响 */ } }

        $meta = array(
            'name'       => $name,
            'bytes'      => (int)@filesize($path),
            'tables'     => count($tables),
            'rows'       => $rows,
            'seconds'    => round(microtime(true) - $t0, 2),
            'gz'         => $gz,
            'consistent' => $snap,
        );
        app_log('backup ok: ' . $name . ' (' . $meta['bytes'] . ' bytes, ' . $rows . ' rows)');
        return $meta;
    } catch (Throwable $e) {
        if ($h !== null) { backup_close($h, $gz); }
        @unlink($path);   // 半成品没有价值，留着只会误导
        app_log('backup fail: ' . $e->getMessage());
        throw $e;
    } finally {
        flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/** 流式输出备份文件（放在最后，函数内部会 exit） */
function backup_stream(string $name)
{
    $p = backup_path($name);
    if (!is_file($p)) { http_response_code(404); exit('not found'); }

    $size = (int)@filesize($p);
    header('Content-Type: ' . (substr($name, -3) === '.gz' ? 'application/gzip' : 'application/sql; charset=utf-8'));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    /* 清掉已有输出缓冲：否则整份备份会先堆进内存再吐出去 */
    while (ob_get_level() > 0) { @ob_end_clean(); }

    $fp = @fopen($p, 'rb');
    if ($fp === false) { http_response_code(500); exit('open failed'); }
    while (!feof($fp)) {
        $buf = fread($fp, 262144);
        if ($buf === false) { break; }
        echo $buf;
        @flush();
    }
    fclose($fp);
    exit;
}
