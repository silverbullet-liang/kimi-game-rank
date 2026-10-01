<?php
/**
 * 智能链接识别的自检（构造样本，不联网）
 * 跑法：php tools/verify_smart_link.php
 *
 * 覆盖面：结构判定（单标题 / 单按钮 / 短脚本）、动画与画布排除、
 *         视频页 / 对话分享页 / API 地址剔除、域名优先级、同站与宿主保护。
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
if (!function_exists('cfg'))       { function cfg($k, $d = null) { return $d; } }
if (!function_exists('setting_get')) { function setting_get($k, $d = '') { return $d; } }
require dirname(__DIR__) . '/app/link_smart.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond) { global $pass, $fail; $cond ? $pass++ : $fail++; printf("%s %s\n", $cond ? 'OK  ' : 'FAIL', $name); }

$JUMP = '<html><head><title>某某小游戏</title></head><body><h1>某某小游戏</h1>'
      . '<p>点下面的按钮开始</p><button id="b">开始游戏</button>'
      . '<script>document.getElementById("b").onclick=function(){location.href="https://abc.ok.kimi.link/"}</script>'
      . '</body></html>';

/* ---------- 开关 ---------- */
ok('开关默认开启（正式功能）', smart_link_enabled() === true);

/* ---------- 结构判定 ---------- */
ok('典型跳转页 → 判为跳转页', smart_link_looks_like_jump($JUMP));
ok('典型跳转页 → 解析出 kimi.link', smart_link_resolve($JUMP, 'https://share.example.net/p/1') === 'https://abc.ok.kimi.link/');

ok('含 canvas 且无跳转声明 → 不判跳转页', !smart_link_looks_like_jump(
    '<html><body><h1>游戏</h1><button>开始</button><canvas id="c"></canvas><script>var g=document.getElementById("c").getContext("2d");</script></body></html>'));

ok('长正文 + 平台分享外链 → 由旁路判为跳转页', smart_link_looks_like_jump(
    '<html><body><h1>攻略</h1><p>' . str_repeat('这是一段很长的正文。', 20) . '</p><a href="https://x.kimi.link/">前往</a></body></html>'));

ok('长正文 + 作者自建外链 → 不判跳转页', !smart_link_looks_like_jump(
    '<html><body><h1>攻略</h1><p>' . str_repeat('这是一段很长的正文。', 20) . '</p><a href="https://me.github.io/x">前往</a></body></html>'));

ok('多个按钮 → 不判跳转页', !smart_link_looks_like_jump(
    '<html><body><h1>标题</h1><a href="https://a.kimi.link/">开始游戏</a><a href="https://b.kimi.link/">说明</a></body></html>'));

ok('脚本含动画且无跳转声明 → 不判跳转页', !smart_link_looks_like_jump(
    '<html><body><h1>标题</h1><button>开始</button><script>requestAnimationFrame(function f(){requestAnimationFrame(f);});</script></body></html>'));

ok('含 iframe → 不判跳转页', !smart_link_looks_like_jump(
    '<html><body><h1>标题</h1><iframe src="/x"></iframe></body></html>'));

/* ---------- 按钮计数（嵌套结构不得漏数） ---------- */
ok('纯布局 div 不算按钮', count(smart_link_buttons(
    '<html><body><div class="a"><div class="b">文字</div></div></body></html>')) === 0);

ok('外层 div 嵌套时 <a> 不漏数', count(smart_link_buttons(
    '<html><body><div class="wrap"><div class="card"><a href="https://a.miaoda.online/">开始</a></div></div></body></html>')) === 1);

ok('嵌套结构下仍能取出按钮地址', count(smart_link_buttons(
    '<html><body><div class="wrap"><div class="card"><a href="https://a.miaoda.online/">开始</a></div></div></body></html>')) === 1
    && smart_link_buttons('<html><body><div class="wrap"><div class="card"><a href="https://a.miaoda.online/">开始</a></div></div></body></html>')[0]['href'] === 'https://a.miaoda.online/');

/* ---------- 介绍卡：长文案 + 装饰动画，靠旁路识别 ---------- */
$CARD = '<html><head><style>@keyframes float{from{opacity:.2}to{opacity:1}}</style></head><body>'
      . '<div class="wrap"><div class="card">'
      . '<h1>像素宝宠世界</h1>'
      . '<p>' . str_repeat('2.5D像素开放世界 探索无限可能 ', 10) . '</p>'
      . '<a href="https://app-ejojjjfa2v41.miaoda.online" target="_blank">开始冒险</a>'
      . '</div></div>'
      . '<script>var t=0;setInterval(function(){t++;},1000);</script></body></html>';

ok('介绍卡（长文案 + 装饰动画）→ 判为跳转页', smart_link_looks_like_jump($CARD));
ok('介绍卡 → 解析出妙搭地址', smart_link_resolve($CARD,
    'https://kimi-file.moonshot.cn/prod-chat-kimi/kfs/4/1/2026-09-29/1datsubl') === 'https://app-ejojjjfa2v41.miaoda.online');

ok('介绍卡带 canvas → 不认', !smart_link_looks_like_jump(
    '<html><body><div><h1>游戏</h1><p>' . str_repeat('说明文字，', 40) . '</p>'
    . '<a href="https://a.miaoda.online/">玩</a><canvas id="c"></canvas></div></body></html>'));

ok('介绍卡有两个平台外链 → 不认', !smart_link_looks_like_jump(
    '<html><body><div><h1>作品集</h1><p>' . str_repeat('说明文字，', 40) . '</p>'
    . '<a href="https://a.miaoda.online/">一</a><a href="https://b.kimi.link/">二</a></div></body></html>'));

/* ---------- 自动跳转页：加载动画 + 延时跳转 ---------- */
$LOADING = '<html><head><title>无限深入 - 加载中</title>'
         . '<style>@keyframes blink{to{opacity:.3}}</style></head><body>'
         . '<canvas id="pixelCanvas"></canvas>'
         . '<div class="loader"><p>正在加载</p><p>即将进入地牢世界...</p></div>'
         . '<script>function animate(){requestAnimationFrame(animate);}animate();'
         . 'setTimeout(function(){window.location.href="https://qwiu5q4yccbuo.kimi.site/";},1000);</script>'
         . '</body></html>';

ok('加载页 + 延时跳转 → 判为跳转页', smart_link_looks_like_jump($LOADING));
ok('加载页 → 解析出 kimi.site', smart_link_resolve($LOADING,
    'https://kimi-file.moonshot.cn/prod-chat-kimi/kfs/4/1/2026-09-30/1dau8vjd') === 'https://qwiu5q4yccbuo.kimi.site/');

ok('加载页跳去作者自建站 → 不判跳转页', !smart_link_looks_like_jump(
    '<html><body><h1>加载中</h1><canvas id="c"></canvas>'
    . '<script>location.href="https://me.github.io/game/";</script></body></html>'));

ok('跳转目标不在平台分享型域名 → 不判跳转页', !smart_link_looks_like_jump(
    '<html><body><h1>加载中</h1><canvas id="c"></canvas>'
    . '<script>window.location.replace("https://example.org/next");</script></body></html>'));

/* ---------- MiniMax 作品空间 ---------- */
ok('MiniMax 作品空间算作品宿主', smart_link_host_trusted('https://abc.space.mcode.cn/x'));
ok('MiniMax 作品空间进平台分享型白名单', smart_link_priority('https://abc.space.mcode.cn/') >= 20);
ok('来源在 MiniMax 作品空间 → 不替换', smart_link_resolve($LOADING, 'https://abc.space.mcode.cn/') === '');
ok('跳转到 MiniMax 作品空间 → 识别', smart_link_resolve(
    '<html><body><h1>加载中</h1><canvas id="c"></canvas><script>location.href="https://abc.space.mcode.cn/";</script></body></html>',
    'https://share.example.net/p/7') === 'https://abc.space.mcode.cn/');
ok('MiniMax 主站不受作品宿主保护', !smart_link_host_trusted('https://www.mcode.cn/'));

/* ---------- 来源保护 ---------- */
ok('来源是对话分享页 → 不替换', smart_link_resolve($CARD, 'https://kimi.moonshot.cn/share/abc') === '');
ok('来源是 Kimi 文件 CDN → 仍参与识别', smart_link_resolve($CARD,
    'https://kimi-file.moonshot.cn/prod-chat-kimi/kfs/4/1/2026-09-29/1datsubl') !== '');

/* ---------- 排除清单 ---------- */
ok('唯一候选是 B 站视频页 → 不认', smart_link_resolve(
    '<html><body><h1>看视频</h1><a href="https://www.bilibili.com/video/BV1xx">前往</a></body></html>',
    'https://share.example.net/p/2') === '');

ok('唯一候选是 B 站短链 → 不认', smart_link_resolve(
    '<html><body><h1>看视频</h1><a href="https://b23.tv/abc">前往</a></body></html>',
    'https://share.example.net/p/2b') === '');

ok('唯一候选是对话分享页 → 不认', smart_link_resolve(
    '<html><body><h1>对话</h1><a href="https://chatgpt.com/share/abc-123">查看</a></body></html>',
    'https://share.example.net/p/3') === '');

ok('Claude / Kimi 分享页同样不认',
    smart_link_resolve('<html><body><h1>对话</h1><a href="https://claude.ai/share/x">看</a></body></html>', 'https://share.example.net/p/3b') === ''
    && smart_link_resolve('<html><body><h1>对话</h1><a href="https://www.kimi.com/share/abc">看</a></body></html>', 'https://share.example.net/p/3c') === '');

ok('API 地址被剔除、保留 kimi.link', smart_link_resolve(
    '<html><body><h1>接口</h1><button>打开</button><script>fetch("https://api.example.com/v1/chat/completions")</script>'
    . '<script>location.href="https://abc.ok.kimi.link/"</script></body></html>',
    'https://share.example.net/p/4') === 'https://abc.ok.kimi.link/');

ok('资源文件后缀不认', smart_link_resolve(
    '<html><body><h1>标题</h1><a href="https://cdn.example.com/a.js">开始</a></body></html>',
    'https://share.example.net/p/4b') === '');

/* ---------- 优先级 ---------- */
ok('多候选 → 优先 kimi.link', smart_link_resolve(
    '<html><body><h1>标题</h1><button id="b">开始游戏</button>'
    . '<script>var a="https://some-random-site.example.com/play";var b="https://xyz.ok.kimi.link/";'
    . 'document.getElementById("b").onclick=function(){location.href=b}</script></body></html>',
    'https://share.example.net/p/5') === 'https://xyz.ok.kimi.link/');

ok('kimi.link 优先于 miaoda', smart_link_resolve(
    '<html><body><h1>标题</h1><button id="b">开始游戏</button>'
    . '<script>var a="https://x.miaoda.online/";var b="https://y.ok.kimi.link/";'
    . 'document.getElementById("b").onclick=function(){location.href=b}</script></body></html>',
    'https://share.example.net/p/5b') === 'https://y.ok.kimi.link/');

ok('优先级：kimi.link > upma > 普通域名',
    smart_link_priority('https://a.ok.kimi.link/') > smart_link_priority('https://a.upma.site/')
    && smart_link_priority('https://a.upma.site/') > smart_link_priority('https://example.org/x'));

/* ---------- 保护 ---------- */
ok('来源本身是作品宿主 → 不替换', smart_link_resolve($JUMP, 'https://abc.kimi.link/x') === '');
ok('来源是 kimi.com（AI 对话页）→ 不替换', smart_link_resolve($JUMP, 'https://www.kimi.com/chat/1') === '');
ok('同站候选 → 不替换', smart_link_resolve(
    '<html><body><h1>标题</h1><a href="https://share.example.net/other">开始游戏</a></body></html>',
    'https://share.example.net/p/7') === '');
ok('相对地址必然同站 → 不替换', smart_link_resolve(
    '<html><body><h1>标题</h1><a href="/play/index.html">开始游戏</a></body></html>',
    'https://games.example.net/jump/1') === '');

/* ---------- meta refresh ---------- */
ok('meta refresh 页（无按钮）→ 识别 kimi.site', smart_link_resolve(
    '<html><head><meta http-equiv="refresh" content="0;url=https://abc.kimi.site/"></head><body><h1>正在前往</h1></body></html>',
    'https://share.example.net/p/6') === 'https://abc.kimi.site/');

echo "\n通过 $pass / 失败 $fail\n";
exit($fail > 0 ? 1 : 0);
