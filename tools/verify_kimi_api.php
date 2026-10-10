<?php
/**
 * Kimi 社区接口调用契约闸门（对照官方接口文档，离线）。
 * 运行：php tools/verify_kimi_api.php
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

$P = 0; $F = 0;
function ck(string $n, $g, $w): void
{
    global $P, $F;
    $ok = ($g === $w);
    $ok ? $P++ : $F++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $n);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($w, true), var_export($g, true)); }
}

$fc = (string)file_get_contents(APP_ROOT . '/app/feed_client.php');
$ad = (string)file_get_contents(APP_ROOT . '/api/admin.php');

/* ---------- SearchMoments：query / pageSize / pageToken ---------- */
ck('SearchMoments 路径正确', strpos($fc, "'moment.v1.SearchService/SearchMoments'") !== false, true);
ck('search 用 pageToken（非 _nextPageToken）', strpos($fc, "_nextPageToken") === false, true);
ck('search 请求体含 query', strpos($fc, "\$payload = array('query' => \$query)") !== false, true);
ck('search 支持 pageSize', strpos($fc, '$payload[\'pageSize\'] = $pageSize;') !== false, true);
ck('search 传 pageToken', strpos($fc, '$payload[\'pageToken\'] = $pageToken;') !== false, true);
ck('community_search 一页 10 条', strpos($ad, "trim(param_str('page_token', '')), 10") !== false, true);

/* ---------- ListFeeds：category 用 FEED_CATEGORY_* 枚举名 ---------- */
ck('ListFeeds 路径正确', strpos($fc, "'moment.v1.FeedService/ListFeeds'") !== false, true);
ck('新增分类枚举映射函数', strpos($fc, 'function kimi_feed_category(') !== false, true);
ck('recommend → FEED_CATEGORY_RECOMMEND', strpos($fc, "'recommend' => 'FEED_CATEGORY_RECOMMEND'") !== false, true);
ck('ListFeeds 走映射', strpos($fc, "'category' => kimi_feed_category(\$category)") !== false, true);
ck('ListFeeds 保留 sessionId', strpos($fc, "'sessionId' => 'feed-'") !== false, true);

/* ---------- GetFeed：feedType 用 FEED_TYPE_MOMENT ---------- */
ck('GetFeed 路径正确', strpos($fc, "'moment.v1.FeedService/GetFeed'") !== false, true);
ck('GetFeed 默认 FEED_TYPE_MOMENT', strpos($fc, "\$feedType = 'FEED_TYPE_MOMENT'") !== false, true);
ck('GetFeed 传 feedId + feedType', strpos($fc, "array('feedType' => \$feedType, 'feedId' => \$feedId)") !== false, true);

/* ---------- 响应游标字段与文档一致 ---------- */
ck('ListFeeds 取 nextPageToken', strpos($fc, "\$resp['nextPageToken']") !== false, true);
ck('回退仍走搜索兜底', strpos($fc, '$resp = kimi_search($token, $workId);') !== false, true);

printf("\n通过 %d，失败 %d\n", $P, $F);
exit($F === 0 ? 0 : 1);
