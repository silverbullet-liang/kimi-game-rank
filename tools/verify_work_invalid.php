<?php
/**
 * 「无效作品」治理自检（离线，桩件）。
 *   无效 = 没有标题（或兜底名「未命名作品」），或既无 CDN 链接也无分享链接。
 *   php tools/verify_work_invalid.php
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

$pass = 0; $fail = 0;
function ck($n, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n\n"; } }

require __DIR__ . '/../app/feed_client.php';

echo "== work_has_link 判定 ==\n";
ck('有 CDN 链接', work_has_link(array('html_url' => 'https://x/a.html')) === true);
ck('有分享 id', work_has_link(array('chat_share_id' => 'abc123')) === true);
ck('有 share_link', work_has_link(array('share_link' => 'https://www.kimi.com/share/x')) === true);
ck('三项皆空 → false', work_has_link(array('html_url' => '', 'chat_share_id' => '', 'share_link' => '')) === false);
ck('缺字段 → false', work_has_link(array()) === false);
ck('纯空白不算链接', work_has_link(array('html_url' => '   ')) === false);

echo "== 契约锚点 ==\n";
$fc = (string)file_get_contents(__DIR__ . '/../app/feed_client.php');
ck('标题判定（$titleGiven）', strpos($fc, "\$titleGiven = (\$item['title'] !== '')") !== false);
ck('新增无标题即跳过', strpos($fc, '作品没有标题，已跳过') !== false);
ck('新增无链接即跳过', strpos($fc, '作品没有 CDN / 分享链接，已跳过') !== false);
ck('prune 函数存在', strpos($fc, 'function work_prune_invalid') !== false);
ck('prune 覆盖无标题', strpos($fc, "title = '未命名作品'") !== false);
ck('prune 采用软删（is_hidden=1）', strpos($fc, 'SET is_hidden = 1') !== false);
ck('同步末尾自动清理', strpos($fc, '$pruned = work_prune_invalid();') !== false);

$ad = (string)file_get_contents(__DIR__ . '/../api/admin.php');
ck('admin 有 prune_invalid 动作', strpos($ad, "case 'prune_invalid'") !== false);
ck('prune 属写操作（清榜单缓存）', strpos($ad, "'refresh_batch', 'prune_invalid'") !== false);

$js = (string)file_get_contents(__DIR__ . '/../assets/js/src/pages/panel.js');
ck('面板有清理按钮', strpos($js, 'id="pruneBtn"') !== false);
ck('按钮调用 prune_invalid', strpos($js, "'prune_invalid'") !== false);

echo "\n无效作品治理自检：通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail > 0 ? 1 : 0);
