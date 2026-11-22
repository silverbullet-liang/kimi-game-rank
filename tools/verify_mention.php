<?php
/**
 * 自检：世界对话 @ 提及（app/lobby_mention.php）
 * 离线运行：php tools/verify_mention.php
 * 覆盖：检索只做前缀 / 通配符转义 / 空查询不出人 / 只返回未封禁 /
 *       正文 token 提取 / 批量索引只查一次库 / @张三你好 最长前缀命中
 */
declare(strict_types=1);

$GLOBALS['SQL'] = array();
$GLOBALS['QRY'] = array();

/* ---- 桩件：与 app/helpers.php 中同名函数行为一致（仅排除无关依赖） ---- */
function nfc_normalize(string $s): string { return $s; }
function strip_invisible(string $s): string {
    return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
}
function norm_username(string $s): string {
    $s = nfc_normalize($s);
    $s = strip_invisible($s);
    $s = (string)preg_replace('/\s+/u', '', $s);
    $s = (string)preg_replace('/\p{Z}/u', '', $s);
    $s = (string)preg_replace('/\p{C}/u', '', $s);
    return mb_strtolower($s, 'UTF-8');
}
function db_all(string $sql, array $params = array()): array {
    $GLOBALS['SQL'][] = $sql;
    $GLOBALS['QRY'][] = $params;
    if (strpos($sql, 'username_norm FROM users') !== false) {
        return isset($GLOBALS['NORMS']) ? $GLOBALS['NORMS'] : array();
    }
    return isset($GLOBALS['ROWS']) ? $GLOBALS['ROWS'] : array();
}

require_once dirname(__DIR__) . '/app/lobby_mention.php';

$fail = 0; $n = 0;
function ck($cond, $label) {
    global $fail, $n;
    $n++;
    if ($cond) { echo "  ok   $label\n"; }
    else { $fail++; echo "  FAIL $label\n"; }
}

/* 1. 空查询不返回任何人（不提供「列出全部成员」的入口） */
$GLOBALS['ROWS'] = array(array('username' => '张三', 'role' => 'user'));
ck(lobby_mention_search('') === array(), '空查询 → 空结果');
ck(lobby_mention_search('   ') === array(), '纯空白查询 → 空结果');
ck(count($GLOBALS['SQL']) === 0, '空查询不产生任何 SQL');

/* 2. 前缀查询：参数是「转义后的 q」+ %，且带未封禁与无空格过滤 */
lobby_mention_search('Zhang');
$sql = end($GLOBALS['SQL']); $par = end($GLOBALS['QRY']);
ck(strpos($sql, 'username_norm LIKE ?') !== false, '使用前缀 LIKE');
ck(strpos($sql, 'is_banned = 0') !== false, '只查未封禁用户');
ck(strpos($sql, 'username_norm = username') !== false, '排除含空格用户名（无法被 @）');
ck($par[0] === 'zhang%', '查询参数按规整值加 %');
ck(strpos($sql, 'LIMIT 6') !== false, '默认只取 6 条');

/* 3. 通配符转义：_ 与 % 不得变成通配符 */
lobby_mention_search('a_b');
$par = end($GLOBALS['QRY']);
ck($par[0] === 'a\\_b%', '下划线被转义，不能探全表');
lobby_mention_search('50%');
$par = end($GLOBALS['QRY']);
ck($par[0] === '50\\%%', '百分号被转义');

/* 4. 超长查询直接返回空 */
ck(lobby_mention_search(str_repeat('a', 33)) === array(), '超过 32 字 → 空结果');

/* 5. 输出结构只含 name/role，不泄露 uid8 / 注册时间等 */
$GLOBALS['ROWS'] = array(
    array('username' => '张三', 'role' => 'user'),
    array('username' => '张四', 'role' => 'subadmin'),
);
$r = lobby_mention_search('张');
ck(count($r) === 2, '正常返回 2 条');
ck(array_keys($r[0]) === array('name', 'role'), '字段仅 name/role');
ck($r[1]['role'] === 'subadmin', '角色原样带出（用于徽章）');

/* 6. token 提取 */
$tk = lobby_mention_tokens('@张三，你好');
ck(count($tk) === 1, '@张三，你好 只算一个候选');
$tk = lobby_mention_tokens('您好 @李四 请看下');
ck(count($tk) === 1 && reset($tk) === '李四', '空格分隔的中文提及可识别');
ck(lobby_mention_tokens('邮箱 a@b.com') === array(), 'a@b.com 不被当成提及（@ 前非分隔符）');
ck(lobby_mention_tokens('@') === array(), '单独一个 @ → 无候选');
ck(lobby_mention_tokens('没有提及') === array(), '无 @ → 无候选');

/* 7. 批量索引：一批正文只查一次库 */
$GLOBALS['SQL'] = array();
$GLOBALS['NORMS'] = array(array('username_norm' => '张三'));
$set = lobby_mention_index(array('@张三 你好', '@张三 再来', '@王五 无效'));
ck(count($GLOBALS['SQL']) === 1, '批量解析只发 1 条 SQL');
ck(isset($set['张三']) && !isset($set['王五']), '只保留真实存在的用户');

/* 8. 单条 pick：严格命中 + 最长前缀兜底 */
$GLOBALS['NORMS'] = array(array('username_norm' => '张三'), array('username_norm' => '李四'));
$set = lobby_mention_index(array('@张三', '@李四'));
ck(lobby_mention_pick('@张三 你好', $set) === array('张三'), '@张三 正常命中');
ck(lobby_mention_pick('@张三你好', $set) === array(), '@张三你好 无边界时不乱猜（宁可不标）');
ck(lobby_mention_pick('@王五 你好', $set) === array(), '不存在的用户不高亮');
ck(lobby_mention_pick('@张三丰 你好', $set) === array(), '不存在的长名不被截成短名误标');
ck(lobby_mention_pick('@张三，你好', $set) === array('张三'), '@张三，你好 标点截断后命中');
ck(lobby_mention_pick('随便写点什么', $set) === array(), '无 @ 时不产生提及');
ck(lobby_mention_pick('@张三', array()) === array(), '空集合直接返回空');

/* 9. 上限保护 */
$GLOBALS['ROWS'] = array_fill(0, 20, array('username' => 'a', 'role' => 'user'));
ck(count(lobby_mention_search('a', 100)) === 20, 'limit 被夹到 10（桩件返回 20 条也照收，SQL 限 10）');
ck(strpos(end($GLOBALS['SQL']), 'LIMIT 10') !== false, 'limit 上限 10');

echo $fail === 0 ? "\n全部 $n 项通过\n" : "\n失败 $fail / $n 项\n";
exit($fail === 0 ? 0 : 1);
