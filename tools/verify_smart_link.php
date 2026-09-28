<?php
/**
 * app/link_smart.php 的校验脚本（BETA 功能，只覆盖关键防线）
 * 跑法：php tools/verify_smart_link.php
 * 说明：tools/ 不进发布包。
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
$GLOBALS['S'] = array();
function setting_get(string $k, $d = '') { return array_key_exists($k, $GLOBALS['S']) ? $GLOBALS['S'][$k] : $d; }
function cfg($k, $d = null) { return $k === 'site.url' ? 'https://kimi-game-rank.wuaze.com' : $d; }
require APP_ROOT . '/app/link_smart.php';
error_reporting(E_ALL);
set_error_handler(function ($n, $s, $f, $l) { if (error_reporting() === 0) { return true; } echo "  ⚠ PHP告警: $s @ $f:$l\n"; return true; });

$pass = 0; $fail = 0;
function ok($t, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ✔ $t\n"; } else { $fail++; echo "  ✗ $t\n"; } }

/** 仿"我正在前往…"那类跳转页：提示文字 + 跳转机制 + 一个可点链接，正文极短 */
function jump_html($target, $body = '')
{
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>正在前往 目标站点</title>'
        . '<meta http-equiv="refresh" content="0;url=' . $target . '">'
        . '<style>body{margin:0;background:#06070b}</style></head><body>'
        . '<div class="card"><h1>正在跳转</h1><p>即将前往目标页面…</p>'
        . '<a href="' . $target . '">如果没反应，点这里</a></div>'
        . '<script>setTimeout(function(){location.replace("' . $target . '");},300);</script>'
        . $body . '</body></html>';
}

echo "【1】开关默认关闭\n";
$GLOBALS['S'] = array();
ok('未设置 → 关闭', smart_link_enabled() === false);

echo "\n【2】不误伤作品宿主（这是本轮重点）\n";
$t = 'https://real.example.com/play';
foreach (array('https://alice.github.io/game/index.html', 'https://mygame.vercel.app/',
               'https://kimi.com/artifact/abc', 'https://space.kimi.com/x',
               'https://chat.z.ai/c/1', 'https://x.netlify.app/', 'https://y.pages.dev/',
               'https://huggingface.co/spaces/a/b', 'https://modelscope.cn/studios/a/b') as $src) {
    ok('来源在作品宿主 → 不替换：' . preg_replace('#^https?://#', '', $src),
        smart_link_resolve(jump_html($t), $src) === '');
}
ok('host 判定：github.io / kimi 子域 / vercel.app / HF 命中',
    smart_link_host_trusted('https://a.github.io/') && smart_link_host_trusted('https://x.kimi.com/p')
    && smart_link_host_trusted('https://a.vercel.app') && smart_link_host_trusted('https://huggingface.co/spaces/a')
    && !smart_link_host_trusted('https://a.example.com'));

echo "\n【3】有作品特征的页面不当跳转页\n";
ok('含 canvas 的短页面 → 不判跳转页',
    !smart_link_looks_like_jump('<title>正在前往</title><canvas id="c"></canvas><script>location.href="https://a.example.com/"</script>'));
ok('含 iframe 的短页面 → 不判跳转页', !smart_link_looks_like_jump('<title>跳转</title><iframe src="/x"></iframe>'));

echo "\n【3.5】没有任何跳转机制 → 一律不当跳转页（保守红线）\n";
ok('只有标题像 + 一个外链，但没有 refresh / JS 跳转 → 不动',
    !smart_link_looks_like_jump('<title>正在前往 某站</title><p>正文</p><a href="https://a.example.com/">去</a>'));

echo "\n【4】真正的跳转页照旧识别\n";
ok('典型跳转页 → 解析出唯一真实地址',
    smart_link_resolve(jump_html('https://game.example.com/play'), 'https://jump.example.net/p/1') === 'https://game.example.com/play');
ok('同址多处出现（meta/JS/链接）只算一个候选',
    smart_link_resolve(jump_html('https://one.example.com/play'), 'https://jump.example.net/p/2') !== '');
ok('两个不同候选 → 保持原样', smart_link_resolve(
    jump_html('https://a.example.com/', '<a href="https://b.example.com/">B</a>'),
    'https://jump.example.net/p/3') === '');

echo "\n【5】排除规则\n";
ok('唯一候选是 B 站 → 不认', smart_link_resolve(jump_html('https://www.bilibili.com/video/BV1'),
    'https://jump.example.net/p/4') === '');
ok('唯一候选是本站 → 不认', smart_link_resolve(jump_html('https://kimi-game-rank.wuaze.com/'),
    'https://jump.example.net/p/5') === '');
ok('大站被剔除后只剩一个真站 → 用它', smart_link_resolve(
    jump_html('https://real-game.example.com/play', '<a href="https://space.bilibili.com/1">作者</a>'),
    'https://jump.example.net/p/6') === 'https://real-game.example.com/play');
ok('资源后缀与 IP 不认', smart_link_resolve(jump_html('https://cdn.example.com/a.js'),
    'https://jump.example.net/p/7') === ''
    && smart_link_resolve(jump_html('http://10.0.0.2/'), 'https://jump.example.net/p/8') === '');

echo "\n【6】真实样本（我做的那份跳转页）\n";
$real = '';
foreach (array(APP_ROOT . '/standalone/jump.html', '/tmp/jump.html') as $p) {
    if (is_file($p)) { $real = (string)file_get_contents($p); break; }
}
if ($real === '') { echo "  （样本不在项目内，跳过）\n"; }
else {
    ok('判定为跳转页', smart_link_looks_like_jump($real));
    ok('它指向本站 → 不替换', smart_link_resolve($real, 'https://example.com/jump.html') === '');
    /* 把同一份样本的目标换成外站，验证"真实跳转页 + 唯一外链"能解析出来 */
    $variant = str_replace('https://kimi-game-rank.wuaze.com', 'https://real-game.example.com/play', $real);
    ok('换成外站目标时，按这份真实样本解析出该地址',
        smart_link_resolve($variant, 'https://jump.example.net/p/9') === 'https://real-game.example.com/play');
}

echo "\n结果：通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail === 0 ? 0 : 1);
