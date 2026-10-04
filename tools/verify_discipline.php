<?php
/**
 * 违纪通报回归：解封时间计算 + 列表字段透传
 * 运行：php tools/verify_discipline.php
 *
 * 不连数据库 —— 用桩件替换数据访问层，专测「展示口径」这一类结构化契约：
 * 通报列表必须把 ban_days / ban_until 原样交给前端，否则列表里无论选了几天
 * 都会因为取不到解封时间而显示成「永久」。
 */
declare(strict_types=1);

/* ---------- 桩件 ---------- */
$GLOBALS['SQL'] = array();
$GLOBALS['LIST_ROWS'] = array();

function setting_get(string $k, $d = null) { return $d; }
function setting_set(string $k, $v): void { }
function table_exists(string $t): bool { return true; }
function column_exists(string $t, string $c): bool { return true; }
function now_utc(): string { return gmdate('Y-m-d H:i:s'); }
function to_local(string $utc, string $fmt = 'Y-m-d H:i'): string { return $utc; }
function app_log(string $m): void { }
function cfg(string $p, $d = null) { return $d; }
function identicon_data_uri(string $s, int $n = 40): string { return 'data:,'; }
function current_identity() { return null; }

function db_one(string $sql, array $a = array()) {
    if (array_key_exists('ONE', $GLOBALS)) { return $GLOBALS['ONE']; }
    return $GLOBALS['LIST_ROWS'] ? $GLOBALS['LIST_ROWS'][0] : null;
}
function db_all(string $sql, array $a = array()) {
    $GLOBALS['SQL'][] = array('all', $sql, $a);
    if (array_key_exists('ROWS', $GLOBALS) && $GLOBALS['ROWS'] !== null) { return $GLOBALS['ROWS']; }
    return $GLOBALS['LIST_ROWS'];
}
function db_val(string $sql, array $a = array()) {
    if (strpos($sql, 'COUNT(*)') !== false && isset($GLOBALS['COUNT'])) { return $GLOBALS['COUNT']; }
    return isset($GLOBALS['DBVAL']) ? $GLOBALS['DBVAL'] : 0;
}
function db_exec(string $sql, array $a = array()) { $GLOBALS['SQL'][] = array('exec', $sql, $a); return 1; }
function db_insert(string $sql, array $a = array()) { $GLOBALS['SQL'][] = array('insert', $sql, $a); return 1; }

require __DIR__ . '/../app/discipline.php';

$GLOBALS['fail_n'] = 0;
$GLOBALS['pass_n'] = 0;
function ck(string $name, $got, $want): void
{
    $ok = ($got === $want);
    $ok ? $GLOBALS['pass_n']++ : $GLOBALS['fail_n']++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* ---------- 1. 解封时间计算 ---------- */
$now = time();
ck('0 天 = 永久（返回 null）',        discipline_ban_until(0), null);
ck('负数按永久处理',                  discipline_ban_until(-3), null);
ck('空值按永久处理',                  discipline_ban_until(''), null);

$u7 = discipline_ban_until(7);
ck('7 天 ≈ 现在 + 7×86400 秒',        abs(strtotime($u7 . ' UTC') - ($now + 7 * 86400)) <= 2, true);

$uH = discipline_ban_until(0.5);
ck('0.5 天 ≈ 现在 + 12 小时',         abs(strtotime($uH . ' UTC') - ($now + 43200)) <= 2, true);

$u15 = discipline_ban_until(1.5);
ck('1.5 天 ≈ 现在 + 36 小时',         abs(strtotime($u15 . ' UTC') - ($now + 129600)) <= 2, true);

ck('0.01 天 ≈ 现在 + 14.4 分钟',      abs(strtotime(discipline_ban_until(0.01) . ' UTC') - ($now + 864)) <= 2, true);

/* ---------- 2. 封禁是否仍在有效期 ---------- */
ck('ban_until 为 null → 视为永久在效', discipline_ban_alive(array('ban_until' => null)), true);
ck('ban_until 为空串 → 视为永久在效',  discipline_ban_alive(array('ban_until' => '')), true);
ck('ban_until 在未来 → 仍在效',        discipline_ban_alive(array('ban_until' => gmdate('Y-m-d H:i:s', $now + 3600))), true);
ck('ban_until 在过去 → 已失效',        discipline_ban_alive(array('ban_until' => gmdate('Y-m-d H:i:s', $now - 60))), false);

/* ---------- 3. 通报列表必须交出 ban_days 与 ban_until ---------- */
function list_row($id, $days, $until)
{
    return array(
        'id' => $id, 'user_id' => 10 + $id, 'username' => 'u' . $id,
        'reasons' => json_encode(array('测试理由'), JSON_UNESCAPED_UNICODE),
        'note' => '', 'banned' => 1, 'ban_days' => $days,
        'ban_until' => $until, 'purged' => 0, 'ip_banned' => 1, 'by_uid' => 1,
        'views' => 3, 'created_at' => '2026-10-03 00:00:00',
    );
}
$GLOBALS['LIST_ROWS'] = array(
    list_row(1, '7.00',  gmdate('Y-m-d H:i:s', $now + 7 * 86400)),   // 7 天（DECIMAL 取回是字符串）
    list_row(2, '0.50',  gmdate('Y-m-d H:i:s', $now + 43200)),       // 12 小时
    list_row(3, '30.00', gmdate('Y-m-d H:i:s', $now + 30 * 86400)),  // 30 天
    list_row(4, '0.00',  null),                                      // 永久
);

$r = discipline_list(1, 20);
$items = $r['items'];
ck('列表返回 4 条',                  count($items), 4);
ck('每条都带 ban_days 字段',          (bool)array_diff(array('ban_days'), array_keys($items[0])) === false, true);
ck('每条都带 ban_until 字段',         array_key_exists('ban_until', $items[0]), true);
ck('7 天 → 数值 7.0（未被截断）',      $items[0]['ban_days'], 7.0);
ck('7 天 → 不是「无解封时间」',        $items[0]['ban_until'] !== '', true);
ck('0.5 天 → 数值 0.5（未被截成 0）',  $items[1]['ban_days'], 0.5);
ck('0.5 天 → 有解封时间',             $items[1]['ban_until'] !== '', true);
ck('30 天 → 数值 30.0',               $items[2]['ban_days'], 30.0);
ck('永久 → ban_days 为 0',            $items[3]['ban_days'], 0.0);
ck('永久 → ban_until 为空串',         $items[3]['ban_until'], '');
ck('banned 仍为布尔真',               $items[0]['banned'], true);
ck('ip_banned 仍为布尔真',            $items[0]['ip_banned'], true);

/* 前端判定：days>0 或 until 非空 → 不显示「永久」 */
function front_text(array $it): string
{
    $days = (float)($it['ban_days'] ?? 0);
    $until = (string)($it['ban_until'] ?? '');
    if (!$it['banned']) { return '未封停'; }
    return $until !== '' ? '至 ' . $until : '永久';
}
ck('前端文案：7 天 → 「至 …」',        strpos(front_text($items[0]), '至 ') === 0, true);
ck('前端文案：0.5 天 → 「至 …」',      strpos(front_text($items[1]), '至 ') === 0, true);
ck('前端文案：永久 → 「永久」',        front_text($items[3]), '永久');

/* ---------- 4. 创建通报时把天数与解封时间一起落库 ---------- */
$GLOBALS['SQL'] = array();
$GLOBALS['LIST_ROWS'] = array(array('id' => 7, 'username' => 'bob', 'role' => 'user'));
discipline_create(array(7), array('测试理由'), '', true, false, 1, 0.5, false);

$ins = null;
foreach ($GLOBALS['SQL'] as $s) { if ($s[0] === 'insert' && strpos($s[1], 'discipline_reports') !== false) { $ins = $s; } }
ck('创建时插入通报记录',              $ins !== null, true);
ck('落库天数 = 0.5（未变 0）',         $ins[2][5], 0.5);
ck('落库解封时间非空（非永久）',       $ins[2][6] !== null, true);
ck('落库解封时间 ≈ +12 小时',          abs(strtotime($ins[2][6] . ' UTC') - ($now + 43200)) <= 3, true);

$GLOBALS['SQL'] = array();
$GLOBALS['LIST_ROWS'] = array(array('id' => 8, 'username' => 'kate', 'role' => 'user'));
discipline_create(array(8), array('测试理由'), '', true, false, 1, 0, false);
$ins2 = null;
foreach ($GLOBALS['SQL'] as $s) { if ($s[0] === 'insert' && strpos($s[1], 'discipline_reports') !== false) { $ins2 = $s; } }
ck('填 0 → 落库天数 0',                $ins2[2][5], 0.0);
ck('填 0 → 解封时间为 null（永久）',   $ins2[2][6], null);


/* ---------- 5. 封禁状态：是否已解封（discipline_item 的 alive） ---------- */
function item_row($banned, $until)
{
    return array('id' => 9, 'user_id' => 3, 'username' => 'x', 'reasons' => '["a"]', 'note' => 'n',
                 'banned' => $banned, 'ban_days' => 7, 'ban_until' => $until, 'ip_banned' => 0,
                 'views' => 0, 'created_at' => '2026-10-04 00:00:00');
}
$nowT = time();
ck('封停中 → alive = true',   discipline_item(item_row(1, gmdate('Y-m-d H:i:s', $nowT + 86400)))['alive'], true);
ck('已过期 → alive = false',  discipline_item(item_row(1, gmdate('Y-m-d H:i:s', $nowT - 86400)))['alive'], false);
ck('永久封停 → alive = true', discipline_item(item_row(1, null))['alive'], true);
ck('未封停 → alive = false',  discipline_item(item_row(0, null))['alive'], false);
ck('天数原样透传（7.0）',     discipline_item(item_row(1, null))['ban_days'], 7.0);
ck('列表同样带 alive',        array_key_exists('alive', $items[0]), true);

/* ---------- 6. 超限清理：只删已解封的，越旧越先 ---------- */
$GLOBALS['SQL'] = array();
$GLOBALS['COUNT'] = 13;
$GLOBALS['ROWS'] = array(array('id' => 1, 'user_id' => 101), array('id' => 2, 'user_id' => 102), array('id' => 3, 'user_id' => 103));
$GLOBALS['ONE'] = null;
$n = discipline_prune(10);
ck('13 条上限 10 → 删 3 条', $n, 3);
$sqlAll = implode("\n", array_map(function ($x) { return $x[1]; }, $GLOBALS['SQL']));
ck('只挑「已解封」的（banned=0 或已过期）', strpos($sqlAll, 'ban_until <= UTC_TIMESTAMP()') !== false, true);
ck('越旧越先删（ORDER BY id ASC）',        strpos($sqlAll, 'ORDER BY id ASC') !== false, true);
ck('按差额 LIMIT 3',                        strpos($sqlAll, 'LIMIT 3') !== false, true);
ck('删记录时同步清 IP 黑名单',              strpos($sqlAll, 'DELETE FROM banned_ips') !== false, true);

$GLOBALS['COUNT'] = 10;
ck('未超上限 → 一条不删', discipline_prune(10), 0);
$GLOBALS['COUNT'] = 0;
ck('空表 → 安全返回 0',   discipline_prune(10), 0);

/* ---------- 7. 源码闸门：封禁机制不得再打架 ----------
   「可登录 + 全屏说明 + 后端统一拒绝」是封禁的唯一口径。
   若登录处再出现直接拒绝、或身份解析再把封停当「查无此人」，v3.16.4 修过的问题就会复发。 */
$authSrc = (string)@file_get_contents(dirname(__DIR__) . '/app/auth.php');
ck('登录处不再直接拒绝封停账号', strpos($authSrc, "fail(403, '账号已被封禁')") === false, true);
ck('身份解析不再把封停当不存在', strpos($authSrc, 'is_banned = 0') === false, true);
ck('登录处仍做到期自动解封',     strpos($authSrc, 'discipline_auto_unban') !== false, true);

printf("\n通过 %d，失败 %d\n", $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
