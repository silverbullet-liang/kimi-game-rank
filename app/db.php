<?php
/**
 * 数据库封装（PDO）
 * ------------------------------------------------------------
 * db()     主库（业务）
 * db_admin() 管理员凭证库（物理分离的另一个数据库）
 * 全部查询走预处理，杜绝拼接注入。
 */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $c = $GLOBALS['APP_CONFIG']['db'];
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';dbname=' . $c['name'] . ';charset=' . $c['charset'];
    $pdo = new PDO($dsn, $c['user'], $c['pass'], array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ));
    return $pdo;
}

function db_admin(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $c = $GLOBALS['APP_CONFIG']['db_admin'];
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';dbname=' . $c['name'] . ';charset=' . $c['charset'];
    $pdo = new PDO($dsn, $c['user'], $c['pass'], array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ));
    return $pdo;
}

/** 取一行 */
function db_one(string $sql, array $args = array())
{
    $st = db()->prepare($sql);
    $st->execute($args);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** 取多行 */
function db_all(string $sql, array $args = array()): array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** 执行写操作，返回影响行数 */
function db_exec(string $sql, array $args = array()): int
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

/** 插入并返回自增 ID */
function db_insert(string $sql, array $args = array()): int
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int)db()->lastInsertId();
}

/** 单值查询 */
function db_val(string $sql, array $args = array())
{
    $st = db()->prepare($sql);
    $st->execute($args);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** 管理员库：取一行 */
function db_admin_one(string $sql, array $args = array())
{
    $st = db_admin()->prepare($sql);
    $st->execute($args);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** 当日/当月统计自增（stats_daily） */
function db_admin_val(string $sql, array $args = array())
{
    $st = db_admin()->prepare($sql);
    $st->execute($args);
    $row = $st->fetch();
    return $row === false ? null : reset($row);
}

function db_admin_exec(string $sql, array $args = array()): int
{
    $st = db_admin()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

function db_admin_all(string $sql, array $args = array()): array
{
    $st = db_admin()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * 表的全部列名（同一请求内按连接+表缓存；$refresh=true 强制重取）。
 * 不用 `SHOW COLUMNS ... LIKE ?`：连接开启了原生预处理，
 * 而 SHOW 语句不接受占位符，会直接报语法错误。
 */
function db_col_names(PDO $pdo, string $table, bool $refresh = false): array
{
    static $cache = array();
    $t = str_replace('`', '', $table);
    $key = spl_object_hash($pdo) . '|' . $t;
    if ($refresh) { unset($cache[$key]); }
    if (isset($cache[$key])) { return $cache[$key]; }

    $set = array();
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM `' . $t . '`')->fetchAll(PDO::FETCH_NUM) as $r) {
            $set[(string)$r[0]] = true;
        }
    } catch (Throwable $e) { /* 表不存在等 → 空集 */ }
    return $cache[$key] = $set;
}

/** 索引是否存在（主库） */
function db_index_exists(string $table, string $index): bool
{
    try {
        foreach (db()->query('SHOW INDEX FROM `' . str_replace('`', '', $table) . '`')->fetchAll() as $r) {
            if ((string)$r['Key_name'] === $index) { return true; }
        }
    } catch (Throwable $e) { /* 表不存在等 */ }
    return false;
}

/** 列是否存在（主库） */
function db_col_exists(string $table, string $col): bool
{
    try { $set = db_col_names(db(), $table); return isset($set[$col]); }
    catch (Throwable $e) { return false; }
}

/** 列是否存在（管理员库） */
function db_admin_col_exists(string $table, string $col): bool
{
    try { $set = db_col_names(db_admin(), $table); return isset($set[$col]); }
    catch (Throwable $e) { return false; }
}

function stats_bump(string $field, int $delta = 1)
{
    $allow = array('signups','logins','votes','work_updates','ai_calls','ai_tokens','comment_count','message_count','feedback_count');
    if (!in_array($field, $allow, true)) { return; }
    $day = gmdate('Y-m-d');
    $sql = 'INSERT INTO stats_daily (day, `' . $field . '`) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE `' . $field . '` = `' . $field . '` + VALUES(`' . $field . '`)';
    try { db_exec($sql, array($day, $delta)); }
    catch (Throwable $e) { app_log('stats_bump failed (' . $field . '): ' . $e->getMessage()); }
}
