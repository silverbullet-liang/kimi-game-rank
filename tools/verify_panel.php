<?php
/**
 * 控制面板二次认证自检 —— 离线，桩件驱动，不连库、不联网。
 *   php tools/verify_panel.php
 * 覆盖：无凭证、空闲失效、绝对上限、滑动续期、指纹隔离。
 */
declare(strict_types=1);

$TMP = sys_get_temp_dir() . '/kimgr_panel_' . getmypid();
@mkdir($TMP . '/storage/cache', 0775, true);
define('APP_ROOT', $TMP);

function cfg($k, $d = null) { return $d; }

$GLOBALS['IDENT'] = array('uid' => 7, 'role' => 'admin');
function current_identity() { return $GLOBALS['IDENT']; }

require __DIR__ . '/../app/panel.php';

$pass = 0; $fail = 0;
function ck(string $n, bool $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n\n"; } }

/** 直接写凭证文件，模拟不同时间点 */
function seed(int $birthAgo, int $lastAgo) {
    $now = time();
    file_put_contents(panel_file(panel_fp()),
        json_encode(array('birth' => $now - $birthAgo, 'last' => $now - $lastAgo)));
}

echo "== 1) 没有凭证 ==\n";
@unlink(panel_file(panel_fp()));
$st = panel_state();
ck('未验证 → ok=false', $st['ok'] === false);
ck('原因 none', $st['reason'] === 'none');

echo "== 2) 刚验证过 ==\n";
panel_grant();
$st = panel_state();
ck('验证后 ok=true', $st['ok'] === true);
ck('原因留空', $st['reason'] === '');

echo "== 3) 空闲超时（2 小时）==\n";
seed(600, 7201);
$st = panel_state();
ck('空闲 2h01m → 失效', $st['ok'] === false);
ck('原因 idle', $st['reason'] === 'idle');
ck('文件已清理', !is_file(panel_file(panel_fp())));

echo "== 4) 绝对上限（12 小时）==\n";
seed(43201, 10);
$st = panel_state();
ck('12h01m → 失效', $st['ok'] === false);
ck('原因 expired', $st['reason'] === 'expired');

echo "== 5) 滑动续期 ==\n";
seed(3600, 7200 - 60);          // 1 小时前验证，1 分钟前还在用
ck('边缘（空闲 1h59m）→ 仍有效', panel_state()['ok'] === true);
panel_touch();
$d = json_decode((string)file_get_contents(panel_file(panel_fp())), true);
ck('续期后 last≈now', time() - (int)$d['last'] <= 2);
ck('birth 未被改写', (int)$d['birth'] < time() - 3500);

echo "== 6) 指纹隔离（换个浏览器家族）==\n";
seed(10, 10);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537.36';
$fpChrome = panel_fp();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Firefox/119.0';
$fpFirefox = panel_fp();
ck('不同浏览器家族 → 指纹不同', $fpChrome !== $fpFirefox);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/126 Safari/537.36';
ck('同家族不同版本 → 指纹相同（不误伤浏览器更新）', panel_fp() === $fpChrome);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) Edg/120 Mobile Safari/537.36';
ck('Edge 优先识别（UA 里也含 Chrome/Safari）', panel_fp() !== $fpChrome);

echo "== 7) 撤销 ==\n";
panel_grant();
panel_drop();
ck('撤销后失效', panel_state()['ok'] === false);

array_map('unlink', glob($TMP . '/storage/cache/*') ?: array());
@rmdir($TMP . '/storage/cache'); @rmdir($TMP . '/storage'); @rmdir($TMP);

echo "== 8) 不依赖 session（本次修复的根因）==\n";
$panelSrc = (string)file_get_contents(__DIR__ . '/../app/panel.php');
ck('app/panel.php 不读写 $_SESSION', strpos($panelSrc, '$_SESSION[') === false);
$adminSrc = (string)file_get_contents(__DIR__ . '/../api/admin.php');
ck('api/admin.php 不再用 panel_ok 会话变量', strpos($adminSrc, 'panel_ok') === false);
$dashSrc = (string)file_get_contents(__DIR__ . '/../api/dashboard.php');
ck('api/dashboard.php 不再用 panel_ok 会话变量', strpos($dashSrc, 'panel_ok') === false);

echo "\n面板授权自检：通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail > 0 ? 1 : 0);
