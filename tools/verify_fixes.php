<?php
/**
 * 回归闸门：登录态与铭记历史评论区两处修复的源码断言
 * 跑法：php tools/verify_fixes.php
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function chk($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name\n"; }
}

$comments = file_get_contents($root . '/api/comments.php');
$core     = file_get_contents($root . '/assets/js/src/core.js');
$app      = file_get_contents($root . '/assets/js/src/app.js');
$mine     = file_get_contents($root . '/assets/js/src/pages/mine.js');
$bundle   = file_get_contents($root . '/assets/js/app.js');

/* ---- ① 铭记历史页评论：incident 必须有自己的存在性校验 ---- */
chk('comments.php 有 incident 分支',
    strpos($comments, "\$ttype === 'incident'") !== false);
chk('incident 分支校验 incidents 表',
    (bool)preg_match("/\\\$ttype === 'incident'.{0,600}?FROM incidents/s", $comments));
chk('incident 不再落到「作品不存在」',
    (bool)preg_match("/\\\$ttype === 'incident'.{0,600}?is_public = 1/s", $comments));

/* ---- ② 登出 / 游客续期 ---- */
chk('reauthGuest 带 X-CSRF-Token',
    (bool)preg_match('/async function reauthGuest\(\).{0,900}?X-CSRF-Token/s', $core));
chk('reauthGuest 有 419 重试',
    (bool)preg_match('/async function reauthGuest\(\).{0,900}?419/s', $core));
chk('令牌变更有代号（防异步覆盖）', strpos($core, '__authEpoch !== ep') !== false);
chk('导出 ensureGuestSession', strpos($core, 'export async function ensureGuestSession') !== false);
chk('抽屉登出后补游客令牌',
    (bool)preg_match("/auth', 'logout'.{0,500}?ensureGuestSession/s", $app));
chk('我的页登出后补游客令牌',
    (bool)preg_match("/auth', 'logout'.{0,500}?ensureGuestSession/s", $mine));

/* ---- 构建产物必须带上这些改动 ---- */
chk('产物含 ensureGuestSession', strpos($bundle, 'ensureGuestSession') !== false);
chk('产物 reauthGuest 带 CSRF', (bool)preg_match('/X-CSRF-Token.{0,400}?action=guest/s', $bundle)
    || (bool)preg_match('/action=guest.{0,400}?X-CSRF-Token/s', $bundle));


/* ---- ③ 封禁入口：登录 / 注册必须硬拦，且不依赖 IP ---- */
$authApi = file_get_contents($root . '/api/auth.php');
$disc    = file_get_contents($root . '/app/discipline.php');
$mig     = file_get_contents($root . '/app/migrate.php');
chk('登录分支有封禁硬门', (bool)preg_match("/case 'login':.{0,900}?discipline_login_blocked/s", $authApi));
chk('二次验证分支也有硬门', (bool)preg_match("/case 'login_2fa':.{0,500}?discipline_login_blocked/s", $authApi));
chk('注册分支挡住被封来源', (bool)preg_match("/case 'register':.{0,700}?discipline_visitor_blocked/s", $authApi));
chk('discipline_login_blocked 先判设备', (bool)preg_match('/function discipline_login_blocked.{0,300}?discipline_dev_banned/s', $disc));
chk('登录成功记录设备', (bool)preg_match('/discipline_dev_touch/s', file_get_contents($root . '/app/auth.php')));
chk('前端发送设备标识', (bool)preg_match("/X-Device-Id/s", $core));
chk('产物含设备标识', strpos($bundle, 'X-Device-Id') !== false);
chk('迁移含 banned_devices', strpos($mig, '`banned_devices`') !== false);
chk('迁移含 user_devices', strpos($mig, '`user_devices`') !== false);

echo ($fail === 0 ? "登录态与评论区回归闸门：通过 " . $pass . "\n" : "登录态与评论区回归闸门：通过 " . $pass . "，失败 " . $fail . "\n");
exit($fail === 0 ? 0 : 1);
