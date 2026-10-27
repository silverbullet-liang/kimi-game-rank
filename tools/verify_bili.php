<?php
/**
 * B站视频模块自检（离线，桩件）。
 *   php tools/verify_bili.php
 * 覆盖：BV 号提取 + 各文件契约锚点（含「已移除抽帧审核」的回归锚点）。
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

$pass = 0; $fail = 0;
function ck($n, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n\n"; } }

require __DIR__ . '/../app/bili.php';

echo "== BV 号提取 ==\n";
ck('完整链接', bili_bv_of('https://www.bilibili.com/video/BV1eb411L7g3/') === 'BV1eb411L7g3');
ck('带参数链接', bili_bv_of('https://www.bilibili.com/video/BV1eb411L7g3?share_source=copy&t=1') === 'BV1eb411L7g3');
ck('裸 BV 号', bili_bv_of('BV1eb411L7g3') === 'BV1eb411L7g3');
ck('前后有文字', bili_bv_of('看这个 BV1eb411L7g3 不错') === 'BV1eb411L7g3');
ck('小写前缀归一为大写 BV', bili_bv_of('bv1eb411L7g3') === 'BV1eb411L7g3');
ck('分P链接', bili_bv_of('https://www.bilibili.com/video/BV1eb411L7g3/?p=2') === 'BV1eb411L7g3');
ck('av 号不支持', bili_bv_of('av12345') === '');
ck('空串', bili_bv_of('') === '');
ck('乱输入', bili_bv_of('hello world') === '');

echo "== app/bili.php 契约 ==\n";
$src = (string)file_get_contents(__DIR__ . '/../app/bili.php');
ck('米人接口地址', strpos($src, 'api.mir6.com/api/bzjiexi') !== false);
ck('返回含简介 desc', strpos($src, "'desc'") !== false);
ck('已移除抽帧（无 videoshot）', strpos($src, 'videoshot') === false);
ck('已移除抽帧审核（无 image_audit）', strpos($src, 'image_audit') === false);

echo "== api/video.php 契约 ==\n";
$api = (string)file_get_contents(__DIR__ . '/../api/video.php');
ck('有 url 动作', strpos($api, "=== 'url'") !== false);
ck('有 preview 动作', strpos($api, "=== 'preview'") !== false);
ck('有 send 动作', strpos($api, "=== 'send'") !== false);
ck('入库写 media_url = $bv（只存 BV）', strpos($api, "col_ok('messages', 'media_url')") !== false && strpos($api, '$vals[] = $bv;') !== false);
ck('直链不写库', strpos($api, "'v' => \$video") !== false && strpos($api, "media_url' => \$m['video_url']") === false);
ck('审核走文本链路（moderate_text）', strpos($api, 'moderate_text(') !== false);
ck('审核标题 + 作者 + 附言', strpos($api, "\$m['title']") !== false && strpos($api, "\$m['author']") !== false);
ck('不再抽帧审核（无 bili_audit）', strpos($api, 'bili_audit') === false);

echo "== 其它文件接线 ==\n";
$lobby = (string)file_get_contents(__DIR__ . '/../api/lobby.php');
ck('消息序列化输出 video 字段', strpos($lobby, "'video'    =>") !== false);
$meta = (string)file_get_contents(__DIR__ . '/../app/chat_meta.php');
ck('meta 打包支持 extra', strpos($meta, 'array $extra = array()') !== false);
ck('meta 解包保留 v', strpos($meta, "['v']") !== false);
$boot = (string)file_get_contents(__DIR__ . '/../app/bootstrap.php');
ck('bootstrap 引入 bili.php', strpos($boot, "app/bili.php") !== false);

echo "== 前端 / 样式 ==\n";
$js = (string)file_get_contents(__DIR__ . '/../assets/js/src/pages/lobby.js');
ck('前端有视频按钮', strpos($js, 'id="wVideo"') !== false);
ck('前端视频卡片渲染', strpos($js, 'videoCardHtml') !== false);
ck('前端播放实时解析直链', strpos($js, "'video', 'url'") !== false);
ck('前端发送走 video 接口', strpos($js, "'video', 'send'") !== false);
ck('前端全屏', strpos($js, 'requestFullscreen') !== false);
ck('前端 iOS 全屏兜底', strpos($js, 'webkitEnterFullscreen') !== false);
ck('前端文案：检查标题与简介', strpos($js, '标题与简介') !== false);
ck('前端不再提「抽取数帧」', strpos($js, '抽取') === false);

$css = (string)file_get_contents(__DIR__ . '/../assets/css/app.css');
ck('视频卡片样式', strpos($css, '.mv-card {') !== false);
ck('播放器样式', strpos($css, '.mv-video {') !== false);
ck('全屏按钮样式', strpos($css, '.mv-full {') !== false);

echo "\nB站视频自检：通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail > 0 ? 1 : 0);
