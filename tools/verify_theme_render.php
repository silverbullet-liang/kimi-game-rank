<?php
/**
 * 内置主题渲染自检：49 套节日主题的 token / 图标 / 装饰 / 背景是否完整可用，
 * 以及服务端净化是否真的挡得住危险输入。
 * 用法：php tools/verify_theme_render.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/theme.php';

$pass = 0;
$fail = 0;

function ck(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $label\n"; }
    else { $fail++; echo "  FAIL $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

echo "1. 主题文件与首屏渲染\n";
$files = glob(THEME_DIR . '/*.theme.json');
$keys = array();
foreach ($files as $f) {
    $k = basename($f, '.theme.json');
    if ($k !== 'default') { $keys[] = $k; }
}
sort($keys);
ck('节日主题 49 套', count($keys) === 49, (string)count($keys));

$bad = array();
foreach ($keys as $k) {
    $t = theme_load($k);
    if (!is_array($t)) { $bad[] = $k . ':读取'; continue; }
    $p = theme_first_paint($t);
    if (strpos($p['css'], ':root{') === false) { $bad[] = $k . ':light'; }
    if (strpos($p['css'], 'data-theme="dark"') === false) { $bad[] = $k . ':dark'; }
    if ($p['deco'] === '') { $bad[] = $k . ':deco'; }
    if ($p['bg'] === '') { $bad[] = $k . ':bg'; }
    $ic = theme_icons_map($t);
    if (count($ic) < 8 || count($ic) > 12) { $bad[] = $k . ':icons=' . count($ic); }
    if (!isset($t['tokens']['light']['--bg-1']) || !isset($t['tokens']['dark']['--bg-1'])) {
        $bad[] = $k . ':bg-1 缺一套';
    }
}
ck('49 套全部可渲染且字段齐全', $bad === array(), implode(' ', array_slice($bad, 0, 6)));

echo "\n2. 默认主题与路径白名单\n";
ck('default 不注入（就是 app.css）', theme_load('default') === null);
ck('风格皮肤不注入', theme_load('md3') === null && theme_load('pixel') === null);
ck('路径穿越被拒', theme_load('../../config/config') === null);
ck('大写/非法字符被拒', theme_load('Spring') === null && theme_load('a b') === null);

echo "\n3. 装饰层净化\n";
$evil = array('deco' => array(
    'html' => '<script>alert(1)</script>',
    'css' => '.fd-x{background:url(https://evil/x.png)}',
));
ck('script 标签被丢弃', theme_deco_html($evil) === '');
ck('url() 的 CSS 被丢弃', theme_deco_css($evil) === '');
ck('内联样式被丢弃', theme_deco_html(array('deco' => array('html' => '<div class="fd-x" style="x:y"></div>'))) === '');
ck('事件属性被丢弃', theme_deco_html(array('deco' => array('html' => '<div class="fd-x" onclick="x()"></div>'))) === '');
ck('非 fd- 的 class 被丢弃', theme_deco_html(array('deco' => array('html' => '<div class="card"></div>'))) === '');
ck('超长装饰被丢弃', theme_deco_html(array('deco' => array('html' => str_repeat('<i class="fd-a"></i>', 200)))) === '');
ck('合法装饰保留', theme_deco_html(array('deco' => array('html' => '<div class="fd-moon"></div>'))) === '<div class="fd-moon"></div>');

echo "\n4. token 白名单\n";
ck('白名单外变量被忽略',
    theme_decls(array('--bg-1' => '#ffffff', '--width' => '9999px')) === '--bg-1:#ffffff');
ck('url() 值被忽略',
    theme_decls(array('--surface' => 'url(https://evil/x.png)')) === '');
ck('!important 被忽略',
    theme_decls(array('--text' => '#000 !important')) === '');

echo "\n5. 图标表净化\n";
$ico = theme_icons_map(array('icons' => array(
    'nav.total'  => 'M1 1h2v2H1z',
    'bad.slot'   => 'M1 1h2v2H1z',
    'action.star' => 'M1 1<script>',
    'action.send' => str_repeat('M1 1h', 400),
)));
ck('非法位置名被剔除', !isset($ico['bad.slot']));
ck('非法字符被剔除', !isset($ico['action.star']));
ck('超长 path 被剔除', !isset($ico['action.send']));
ck('合法 path 保留', isset($ico['nav.total']));

echo "\n----------------------------------------\n";
echo "通过 $pass / 失败 $fail\n";
exit($fail === 0 ? 0 : 1);
