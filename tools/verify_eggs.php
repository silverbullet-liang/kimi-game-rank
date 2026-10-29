<?php
/**
 * 彩蛋自检（v3.54.0）—— 离线静态断言。
 * 跑法：php tools/verify_eggs.php
 *
 * 五个彩蛋必须都满足：不阻断功能、每个只触发一次、移动端可用（键盘彩蛋另有触屏等价）。
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

$pass = 0; $fail = 0;
function ck(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "OK   $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
function rd(string $rel): string
{
    $p = APP_ROOT . '/' . $rel;
    return is_file($p) ? (string)file_get_contents($p) : '';
}

$eggs = rd('assets/js/src/eggs.js');
$app  = rd('assets/js/src/app.js');
$build = rd('tools/build.py');

/* ---------- 存在与接线 ---------- */
ck('彩蛋模块存在', $eggs !== '');
ck('入口启动彩蛋', strpos($app, 'startEggs()') !== false);
ck('构建器登记 eggs.js', strpos($build, "'eggs.js'") !== false);
ck('彩蛋延迟启动（不抢首屏）', strpos($eggs, 'setTimeout(function () {') !== false && strpos($eggs, '}, 1800);') !== false);

/* ---------- 五个彩蛋各自的判据 ---------- */
ck('① 开发者工具检测存在', strpos($eggs, 'function watchDevTools()') !== false);
ck('① 用视口差判定（阈值 160）', strpos($eggs, 'dw <= 160 && dh <= 160') !== false);
ck('① 打开 F12 时提示开源仓库', strpos($eggs, '你打开了开发者工具') !== false && strpos($eggs, 'github.com/silverbullet-liang/kimi-game-rank') !== false);
ck('① 不对抗（无 debugger / 无死循环）', strpos($eggs, 'debugger;') === false && strpos($eggs, 'while (true)') === false);
ck('② 控制台留言', strpos($eggs, 'function consoleNote()') !== false);
ck('② 控制台含版本与仓库', strpos($eggs, 'console.log') !== false && strpos($eggs, 'AGPL-3.0') !== false);
ck('③ 方向键密码', strpos($eggs, 'KONAMI') !== false && strpos($eggs, "'b', 'a'") !== false);
ck('③ 撒星用 CSS 动画且自动清理', strpos($eggs, 'eggStar') !== false && strpos($eggs, 'box.remove()') !== false);
ck('④ 连点站名七次', strpos($eggs, 'function watchBrandTaps()') !== false && strpos($eggs, 'if (n < 7) { return; }') !== false);
ck('④ 连点含移动端等价（触屏点击即可）', strpos($eggs, "closest('.brand-name, .topbar .logo')") !== false);
ck('⑤ 凌晨问候（0–5 点）', strpos($eggs, "if (h < 0 || h >= 5)") !== false);
ck('⑤ 每天只问候一次', strpos($eggs, "localStorage.setItem('kimgr_egg_late', day)") !== false);

/* ---------- 一次性与免打扰 ---------- */
ck('每个彩蛋只触发一次（once 记账）', substr_count($eggs, 'once(') >= 4);
ck('一次性键各自独立', strpos($eggs, 'kimgr_egg_devtools') !== false && strpos($eggs, 'kimgr_egg_taps') !== false && strpos($eggs, 'kimgr_egg_stars') !== false);
ck('无全屏遮罩 / 无强制跳转', strpos($eggs, 'location.href =') === false);
ck('不破坏页面结构（无 innerHTML 覆盖 body/view）', strpos($eggs, 'document.body.innerHTML') === false && strpos($eggs, "getElementById('view')") === false);
ck('弹窗可关闭（点遮罩或按钮）', strpos($eggs, "scrim.onclick = function (e) { if (e.target === scrim) { close(); } }") !== false);

/* ---------- 长征纪念与周年 ---------- */
$fest = rd('app/festival.php');
ck('目录含长征胜利纪念', strpos($fest, "'longmarch'") !== false);
ck('10 月 22 日判定', strpos($fest, "'day' => array(10, 22),") !== false);
ck('周年自动计算（基准 1936）', strpos($fest, "'anniv' => array(1936,") !== false);
ck('三语周年模板', strpos($fest, '长征胜利 %d 周年') !== false
    && strpos($fest, '%d Years Since the Long March') !== false
    && strpos($fest, '長征勝利 %d 周年') !== false);
ck('前端按语言取节日名（festName）', strpos(rd('assets/js/src/i18n.js'), 'export function festName(') !== false);
ck('皮肤文件存在', rd('assets/css/skins/longmarch.css') !== '');
ck('装饰节点存在', strpos(rd('index.php'), 'fd-lm-ridge') !== false && strpos(rd('index.php'), 'fd-lm-snow') !== false);
ck('长征文档三语齐全', is_file(APP_ROOT . '/assets/docs/festivals/zh/longmarch.md')
    && is_file(APP_ROOT . '/assets/docs/festivals/en/longmarch.md')
    && is_file(APP_ROOT . '/assets/docs/festivals/ja/longmarch.md'));

echo "----------------------------------------------------\n";
echo '通过 ' . $pass . ' / 失败 ' . $fail . "\n";
exit($fail > 0 ? 1 : 0);
