<?php
/**
 * 自检：5×5 identicon 头像（离线，不连库）
 * 运行：php tools/verify_avatar.php
 */
declare(strict_types=1);

$GLOBALS['AVATAR_KEYS'] = array();   // 模拟已被占用的 avatar_key

function norm_username(string $s): string { return mb_strtolower(preg_replace('/\s+/u', '', $s), 'UTF-8'); }
function user_settings($u): array {
    if (is_array($u) && !empty($u['settings'])) {
        $s = json_decode((string)$u['settings'], true);
        if (is_array($s)) { return $s; }
    }
    return array();
}
function db_val(string $sql, array $p = array()) {
    $needle = isset($p[0]) ? trim($p[0], '%') : '';
    foreach ($GLOBALS['AVATAR_KEYS'] as $k) { if ($k === $needle) { return 1; } }
    return 0;
}
function db_exec(string $sql, array $p = array()) {
    if (isset($p[0])) {
        $s = json_decode((string)$p[0], true);
        if (is_array($s) && isset($s['avatar_key'])) { $GLOBALS['AVATAR_KEYS'][] = $s['avatar_key']; }
    }
    return true;
}

require_once dirname(__DIR__) . '/app/avatar.php';

$fail = 0; $n = 0;
function ck($cond, $label) {
    global $fail, $n;
    $n++;
    if ($cond) { echo "  ok   $label\n"; }
    else { $fail++; echo "  FAIL $label\n"; }
}

/* 1. 网格：5×5，左右镜像，15 位决定 */
$g = avatar_grid('alice|1');
ck(count($g) === 5 && count($g[0]) === 5, '网格 5×5');
$mirror = true;
foreach ($g as $row) {
    for ($x = 0; $x < 5; $x++) { if ($row[$x] !== $row[4 - $x]) { $mirror = false; } }
}
ck($mirror, '左右镜像（GitHub identicon 特征）');
ck(avatar_grid('alice|1') === $g, '同 key 图案一致');
ck(avatar_grid('alice|2') !== $g, '换 UID 图案改变');

/* 2. SVG：尺寸、阶数、转义、确定性 */
$svg = avatar_svg('alice|1', 80, 2);
ck(strpos($svg, 'viewBox="0 0 5 5"') !== false, 'viewBox 为 5×5');
ck(strpos($svg, 'width="80" height="80"') !== false, '尺寸参数生效');
ck(avatar_svg('alice|1', 80, 2) === $svg, '同 key 同阶数逐字节一致');
ck(avatar_svg('alice|1', 80, 3) !== $svg, '换色阶图案配色变化');
ck(avatar_svg('alice|1', 80, 0) === avatar_svg('alice|1', 80, 2), '非法阶数回落 2');
function avatar_fills($svg) {
    preg_match_all('/fill="(hsl\([^"]*\))"/', $svg, $m);
    return array_unique($m[1]);
}
ck(count(avatar_fills(avatar_svg('alice|1', 80, 1))) === 1, '1 阶只有一种颜色');
ck(count(avatar_fills(avatar_svg('alice|1', 80, 4))) <= 4, '4 阶最多四种颜色');
ck(count(avatar_fills(avatar_svg('alice|1', 80, 4))) > count(avatar_fills(avatar_svg('alice|1', 80, 1))), '阶数越高颜色越多');
ck(strpos($svg, '<script') === false && strpos($svg, 'javascript:') === false, 'SVG 无脚本注入');

/* 3. 名字 + UID：同名不同人必须不同 */
$a = avatar_key_ensure(array('id' => 1, 'username' => '张三', 'registered_at' => '2026-01-01 00:00:00', 'uid8' => '12345678', 'settings' => ''));
$b = avatar_key_ensure(array('id' => 2, 'username' => '张三', 'registered_at' => '2026-02-02 00:00:00', 'uid8' => '87654321', 'settings' => ''));
ck($a !== $b, '同名不同 UID → 不同头像 key');

/* 4. 冲突加盐：key 被占用时改用注册时间，再占用用 UID8 */
$GLOBALS['AVATAR_KEYS'] = array();
$c = avatar_key_ensure(array('id' => 3, 'username' => '李四', 'registered_at' => '2026-03-03 00:00:00', 'uid8' => 'ABCDEF12', 'settings' => ''));
$d = avatar_key_ensure(array('id' => 4, 'username' => '李四', 'registered_at' => '2026-04-04 00:00:00', 'uid8' => 'FEDCBA98', 'settings' => ''));
ck($c !== $d, '撞车后追加注册时间/UID8，头像仍不重复');
ck(strlen($c) === 64 && ctype_xdigit($c), '头像 key 是 64 位十六进制');

/* 5. 已有 key 长期沿用 */
$row = array('id' => 5, 'username' => '王五', 'registered_at' => '2026-05-05 00:00:00', 'settings' => json_encode(array('avatar_key' => str_repeat('a', 64))));
ck(avatar_key_ensure($row) === str_repeat('a', 64), '已存储 key 直接沿用');
ck(avatar_levels_of(array('settings' => json_encode(array('avatar_levels' => 4)))) === 4, '读取用户设定的色阶');
ck(avatar_levels_of(array('settings' => '')) === 2, '未设定时默认 2 阶');

echo $fail === 0 ? "\n全部 $n 项通过\n" : "\n失败 $fail / $n 项\n";
exit($fail === 0 ? 0 : 1);
