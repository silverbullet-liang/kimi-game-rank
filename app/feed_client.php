<?php
/**
 * Kimi 社区 API 客户端（Connect-RPC）
 * ------------------------------------------------------------
 * POST {base}/apiv2/{service}/{method}   Content-Type: application/connect+json
 * 认证：Authorization: Bearer <accessToken> + x-msh-* 头
 * 端点与字段依据 docs/Kimi API详解与研究指南.md
 */
declare(strict_types=1);

define('KIMI_DEFAULT_BASE', 'https://www.kimi.com');

function kimi_base(): string
{
    return rtrim((string)cfg('kimi.api_base', KIMI_DEFAULT_BASE), '/');
}

function kimi_headers(string $token): array
{
    // 经用户实测可用的请求头（connect+json / 3.0.8 会被风控）
    return array(
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
        'x-msh-platform: web',
        'x-msh-version: 2.1.0',
        'x-msh-device-id: ' . md5('kimgr-' . (string)cfg('secrets.cron_key', 'dev')),
        'x-msh-session-id: ' . rand_hex(8),
        'R-Timezone: Asia/Shanghai',
    );
}

/**
 * 通用调用。$path 形如 'moment.v1.FeedService/ListFeeds'
 * 返回解析后的数组；失败抛出异常（带 HTTP 码）。
 */
function kimi_call(string $path, array $payload, string $token): array
{
    $url = kimi_base() . '/apiv2/' . $path;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => kimi_headers($token),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) { throw new RuntimeException('社区接口请求失败：' . $err); }
    if ($code === 401) { throw new RuntimeException('TOKEN_EXPIRED'); }
    if ($code === 403) { throw new RuntimeException('社区接口被风控拦截（403），请更新请求头或稍后再试'); }
    if ($code < 200 || $code >= 300) { throw new RuntimeException('社区接口返回 HTTP ' . $code); }

    $j = json_decode((string)$resp, true);
    if (!is_array($j)) { throw new RuntimeException('社区接口返回格式异常'); }
    return $j;
}

/* ---------- 具体端点 ---------- */

function kimi_list_feeds(string $token, string $category = 'recommend', string $pageToken = ''): array
{
    $payload = array('sessionId' => 'feed-' . time(), 'category' => $category);
    if ($pageToken !== '') { $payload['pageToken'] = $pageToken; }
    return kimi_call('moment.v1.FeedService/ListFeeds', $payload, $token);
}

function kimi_get_feed(string $token, string $feedId, int $feedType = 0): array
{
    return kimi_call('moment.v1.FeedService/GetFeed', array('feedType' => $feedType, 'feedId' => $feedId), $token);
}

function kimi_search(string $token, string $query, string $pageToken = ''): array
{
    $payload = array('query' => $query, 'sessionId' => 'search-' . time());
    if ($pageToken !== '') { $payload['_nextPageToken'] = $pageToken; }
    return kimi_call('moment.v1.SearchService/SearchMoments', $payload, $token);
}

function kimi_list_comments(string $token, string $momentId, int $pageSize = 20): array
{
    return kimi_call('moment.v1.CommentService/ListComments',
        array('momentId' => $momentId, 'pageSize' => $pageSize), $token);
}

/* ---------- 图片提取 ---------- */

/**
 * 从一个图片节点里取出可直连的地址。
 * 同一张图在不同接口里可能是裸字符串地址，也可能是带 url / originUrl /
 * thumbnailUrl 等字段的对象，这里统一收口，非 http(s) 的一律判为不可用
 * （fileId 之类拿不到地址的值不参与）。
 */
function kimi_img_url($node): string
{
    if (is_string($node)) {
        $u = trim($node);
        return preg_match('#^https?://#i', $u) ? $u : '';
    }
    if (!is_array($node)) { return ''; }
    $keys = array('url', 'originUrl', 'fullSizeUrl', 'thumbnailUrl', 'downloadUrl', 'coverUrl');
    foreach ($keys as $k) {
        if (!empty($node[$k]) && is_string($node[$k])) {
            $u = trim($node[$k]);
            if (preg_match('#^https?://#i', $u)) { return $u; }
        }
    }
    return '';
}

/** 正文里用户上传的图片（评分「图片数」维度的口径只认这些） */
function kimi_content_images(array $content): array
{
    $out = array();
    $raw = isset($content['media']['images']) && is_array($content['media']['images'])
         ? $content['media']['images'] : array();
    foreach ($raw as $img) {
        $u = kimi_img_url($img);
        if ($u !== '') { $out[] = $u; }
    }
    return array_values(array_unique($out));
}

/**
 * 封面候选：站内收录的 HTML / 代码类作品很少在正文传图，预览图实际挂在
 * 作品分享卡上（htmlFile / codeArtifact / okcArtifact 各自的 coverImage，
 * 以及 imageList 里的图）；moment 自身还有一个 coverImage。逐个兜底。
 */
function kimi_cover_images(array $m, array $content): array
{
    $nodes = array();
    if (isset($m['coverImage'])) { $nodes[] = $m['coverImage']; }
    $card = isset($m['chatShareCard']) && is_array($m['chatShareCard']) ? $m['chatShareCard'] : array();
    foreach (array('htmlFile', 'codeArtifact', 'okcArtifact') as $k) {
        if (isset($card[$k]['coverImage'])) { $nodes[] = $card[$k]['coverImage']; }
    }
    if (isset($card['imageList']['items']) && is_array($card['imageList']['items'])) {
        foreach ($card['imageList']['items'] as $it) {
            if (isset($it['imageInfo'])) { $nodes[] = $it['imageInfo']; }
        }
    }
    if (isset($content['externalLink']['coverImage'])) { $nodes[] = $content['externalLink']['coverImage']; }
    if (isset($m['askKimiQuestion']['coverImage'])) { $nodes[] = $m['askKimiQuestion']['coverImage']; }

    $out = array();
    foreach ($nodes as $n) {
        $u = kimi_img_url($n);
        if ($u !== '') { $out[] = $u; }
    }
    return array_values(array_unique($out));
}

/* ---------- 字段归一化 ---------- */

/**
 * 把 ListFeeds / SearchMoments 返回的 moment 结构统一为本站字段。
 * 支持两种包裹：feed 级（含 moment）与裸 moment。
 */
function kimi_normalize_item(array $item): array
{
    $m = $item;
    if (isset($item['moment']) && is_array($item['moment'])) { $m = $item['moment']; }

    $content = isset($m['content']) && is_array($m['content']) ? $m['content'] : array();
    $author  = isset($m['author']['userBase']) && is_array($m['author']['userBase']) ? $m['author']['userBase'] : array();
    $stat    = isset($m['stat']) && is_array($m['stat']) ? $m['stat'] : array();

    /* 展示用图集 = 正文图片 + 封面候选（正文在前，封面补空位）；
       评分口径单独记 image_count，只数正文图片，不动已公开的评分标准。 */
    $mediaImages = kimi_content_images($content);
    $images = array_values(array_unique(array_merge($mediaImages, kimi_cover_images($m, $content))));
    if (count($images) > 9) { $images = array_slice($images, 0, 9); }

    $htmlUrl = '';
    if (!empty($m['chatShareCard']['htmlFile']['cdnUrl'])) {
        $htmlUrl = (string)$m['chatShareCard']['htmlFile']['cdnUrl'];
    }

    $id = isset($m['id']) ? (string)$m['id'] : (isset($item['feedId']) ? (string)$item['feedId'] : '');

    return array(
        'community_id'    => $id,
        'title'           => isset($content['title']) ? (string)$content['title'] : '',
        'intro'           => isset($content['excerpt']) && $content['excerpt'] !== ''
                                ? (string)$content['excerpt']
                                : (isset($content['text']) ? (string)$content['text'] : ''),
        'body'            => isset($content['text']) ? (string)$content['text'] : '',
        'author_name'     => isset($author['name']) ? (string)$author['name'] : '',
        'author_avatar'   => isset($author['avatarImage']['url']) ? (string)$author['avatarImage']['url'] : '',
        'like_num'        => isset($stat['likeNum']) ? (int)$stat['likeNum'] : 0,
        'comment_num'     => isset($stat['commentNum']) ? (int)$stat['commentNum'] : 0,
        'collect_num'     => isset($stat['collectNum']) ? (int)$stat['collectNum'] : 0,
        'images'          => $images,
        'image_count'     => count($mediaImages),
        'chat_share_id'   => isset($content['chatShareId']) ? (string)$content['chatShareId'] : '',
        'artifact_share_id'=> isset($content['artifactShareId']) ? (string)$content['artifactShareId'] : '',
        'html_url'        => $htmlUrl,
        'create_time'     => isset($m['createTime']) ? (string)$m['createTime'] : '',
    );
}

/**
 * 抓取作品 HTML（用于标题 <title> 与代码特征）；限 1MB / 6s。
 */
function fetch_work_html(string $url): string
{
    if ($url === '' || !preg_match('#^https?://#i', $url)) { return ''; }
    /* 抓取预算：一次批量入库会逐条抓作品页，对外请求必须设上限 —— 免费主机上
       「像在抓取 / 过载」是最常见的暂停理由。超出当小时预算就不再抓，标题回退信息流数据。 */
    if (!net_budget_allow('work_html', (int)cfg('feed.fetch_max_per_hour', 150), 3600)) { return ''; }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; KimiRankBot/1.0)',
    ));
    $html = curl_exec($ch);
    curl_close($ch);
    if (!is_string($html)) { return ''; }
    if (strlen($html) > 1048576) { $html = substr($html, 0, 1048576); }
    return $html;
}

/** 从 HTML 中取 <title> */
function html_title(string $html): string
{
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

/* ---------- 入库（查重 + 部分更新 + 重算） ---------- */

/**
 * 作品入库/更新。返回 array(状态: inserted|updated, 分数, 作品ID, 是否重算了评分)
 * @param array $item      归一化后的作品数据
 * @param string $html     作品 HTML（可空；空则尝试抓取）
 * @param mixed $autoScore 是否重算评分；null = 读站点设置（面板可统一关闭）
 */
function work_upsert(array $item, string $html = '', $autoScore = null): array
{
    if ($item['community_id'] === '') { throw new InvalidArgumentException('缺少作品 ID'); }

    // 标题：优先作品 HTML 的 <title>
    if ($html === '' && $item['html_url'] !== '') { $html = fetch_work_html($item['html_url']); }

    /* 智能链接识别（默认开启）：原页面只是个跳转页时，改用真实地址，
       并重新抓一次真实页面——这样标题与各项特征都基于真实内容而不是那张跳转页 */
    $realLink = '';
    if (smart_link_enabled() && $item['html_url'] !== '') {
        $realLink = smart_link_resolve($html, $item['html_url']);
        if ($realLink !== '') {
            $item['html_url'] = $realLink;
            $html = fetch_work_html($realLink);
        }
    }

    $htmlTitle = $html !== '' ? html_title($html) : '';
    if ($htmlTitle !== '') { $item['title'] = $htmlTitle; }
    if ($item['title'] === '') { $item['title'] = '未命名作品'; }

    // 库内其它标题（创意-标题独特性参照）
    $titles = array();
    $rows = db_all('SELECT title FROM works WHERE community_id <> ? ORDER BY id DESC LIMIT 300', array($item['community_id']));
    foreach ($rows as $r) { $titles[] = (string)$r['title']; }

    // 作者回复率 / 楼中楼（若可拿到评论，由调用方补充）
    $work = array(
        'title' => $item['title'],
        'intro' => $item['intro'],
        'body'  => $item['body'],
        'like_num' => $item['like_num'],
        'comment_num' => $item['comment_num'],
        'collect_num' => $item['collect_num'],
        'images' => isset($item['image_count']) ? (int)$item['image_count'] : count($item['images']),
        'artifact_share_id' => $item['artifact_share_id'],
        'author_reply' => isset($item['author_reply']) ? $item['author_reply'] : 0,
        'sub_comment'  => isset($item['sub_comment']) ? $item['sub_comment'] : 0,
        'ongoing'      => isset($item['ongoing']) ? $item['ongoing'] : false,
        'age_hours'    => 999.0,
    );
    if ($item['create_time'] !== '') {
        $ts = strtotime($item['create_time']);
        if ($ts !== false) { $work['age_hours'] = max(0.0, (time() - $ts) / 3600.0); }
    }

    /* 评分口径：
       新收录的作品一律给出初值分；已收录的作品只在「重算」开启时才覆盖评分——
       否则会把管理员按实际情况手动调过的分数抹掉。 */
    $auto = ($autoScore === null) ? score_auto_enabled() : (bool)$autoScore;

    $existing = db_one('SELECT id, total_score FROM works WHERE community_id = ? LIMIT 1', array($item['community_id']));
    $now = now_utc();

    $scoreIt = $auto || $existing === null;
    $scores  = $scoreIt ? compute_scores(extract_features($work, $html, $titles)) : null;
    $scoreJson = ($scores === null) ? '' : json_encode(array(
        'creativity' => $scores['creativity'], 'experience' => $scores['experience'],
        'depth' => $scores['depth'], 'cost' => $scores['cost'],
        'attitude' => $scores['attitude'], 'heat' => $scores['heat'],
    ), JSON_UNESCAPED_UNICODE);

    if ($existing === null) {
        /* peak_score 是迁移新增列，未就绪时省掉它，插入照常成功 */
        $hasPeak = col_ok('works', 'peak_score');
        $cols = 'community_id, title, intro, author_name, author_avatar, category, share_link, html_url,'
              . ' like_num, comment_num, collect_num, images, score, total_score'
              . ($hasPeak ? ', peak_score' : '')
              . ', heat_score, rating, has_html, added_by, created_at, updated_at';
        $vals = array(
            $item['community_id'], $item['title'], $item['intro'], $item['author_name'], $item['author_avatar'],
            isset($item['category']) ? $item['category'] : 'game',
            $item['chat_share_id'] !== '' ? 'https://www.kimi.com/share/' . $item['chat_share_id'] : '',
            $item['html_url'],
            $item['like_num'], $item['comment_num'], $item['collect_num'],
            json_encode($item['images'], JSON_UNESCAPED_UNICODE),
            $scoreJson, $scores['total'],
        );
        if ($hasPeak) { $vals[] = $scores['total']; }
        array_push($vals, $scores['heat'], $scores['rating'],
            $item['html_url'] !== '' ? 1 : 0, 1, $now, $now);

        if (oid_ready('works')) { $cols .= ', oid'; $vals[] = oid_new('works'); }
        $wid = db_insert(
            'INSERT INTO works (' . $cols . ') VALUES ('
            . implode(',', array_fill(0, count($vals), '?')) . ')',
            $vals
        );
        /* 新收录的作品必定有初值分，分数曲线照写 */
        db_exec('INSERT INTO work_score_history (work_id, total_score, created_at) VALUES (?, ?, ?)', array($wid, $scores['total'], $now));
        return array('status' => 'inserted', 'work_id' => $wid, 'scores' => $scores,
                     'scored' => $scoreIt, 'link_real' => $realLink);
    }

    // 已存在：只更新作品信息，不重复插入
    $wid = (int)$existing['id'];
    $sets = 'title=?, intro=?, author_name=?, author_avatar=?, html_url=?,
            like_num=?, comment_num=?, collect_num=?, images=?, has_html=?, updated_at=?';
    $args = array(
        $item['title'], $item['intro'], $item['author_name'], $item['author_avatar'], $item['html_url'],
        $item['like_num'], $item['comment_num'], $item['collect_num'],
        json_encode($item['images'], JSON_UNESCAPED_UNICODE),
        $item['html_url'] !== '' ? 1 : 0, $now,
    );
    if ($auto) {
        /* 评分列只在重算时写入：管理员手动调过的分数不会被自动更新抹掉 */
        $sets .= ', score=?, total_score=?, heat_score=?, rating=?';
        array_push($args, $scoreJson, $scores['total'], $scores['heat'], $scores['rating']);
    }
    $args[] = $wid;
    db_exec('UPDATE works SET ' . $sets . ' WHERE id = ?', $args);

    if ($auto) {
        $peak = (int)db_val('SELECT MAX(total_score) FROM work_score_history WHERE work_id = ?', array($wid));
        /* 同步物化列，供高手榜排序走索引；列未就绪则跳过 */
        if (col_ok('works', 'peak_score')) {
            db_exec('UPDATE works SET peak_score = ? WHERE id = ?', array(max($peak, (int)$scores['total']), $wid));
        }
        if ($scores['total'] > $peak) {
            db_exec('INSERT INTO work_score_history (work_id, total_score, created_at) VALUES (?, ?, ?)', array($wid, $scores['total'], $now));
        }
        if ((int)$existing['total_score'] !== $scores['total']) { stats_bump('work_updates'); }
    }
    return array('status' => 'updated', 'work_id' => $wid, 'scores' => $scores,
                 'scored' => $scoreIt, 'link_real' => $realLink);
}

/**
 * 自动同步：按信息流翻页发现新作品（供管理面板与 cron 调用）
 */
function sync_from_feeds(string $token, string $category = 'recommend', int $maxPages = 3, int $maxItems = 60, bool $force = false): array
{
    /* 自动同步限频：主机的计划任务若触发得比预期勤，这里兜底 —— 非强制调用在最小
       间隔内直接跳过，避免对社区接口高频抓取（面板手动刷新传 $force=true 不受限）。 */
    $minGap = (int)cfg('feed.min_interval', 300);
    if (!$force && $minGap > 0) {
        $last = cache_get('feed_sync_last', 86400);
        if (is_array($last) && isset($last['at']) && (time() - (int)$last['at']) < $minGap) {
            return array('found' => 0, 'inserted' => 0, 'updated' => 0,
                         'scored' => score_auto_enabled(), 'skipped' => true);
        }
    }
    cache_set('feed_sync_last', array('at' => time()), 86400);

    $inserted = 0; $updated = 0; $found = 0;
    $pageToken = '';
    for ($p = 0; $p < $maxPages; $p++) {
        $resp = kimi_list_feeds($token, $category, $pageToken);
        $feeds = isset($resp['feeds']) && is_array($resp['feeds']) ? $resp['feeds'] : array();
        if (empty($feeds)) { break; }
        foreach ($feeds as $fd) {
            if ($found >= $maxItems) { break 2; }
            $item = kimi_normalize_item($fd);
            if ($item['community_id'] === '') { continue; }
            $found++;
            try {
                $r = work_upsert($item);
                if ($r['status'] === 'inserted') { $inserted++; } else { $updated++; }
            } catch (Exception $e) {
                app_log('sync item fail: ' . $e->getMessage());
            }
            usleep(300000);   // 节流防风控
        }
        $pageToken = isset($resp['nextPageToken']) ? (is_string($resp['nextPageToken']) ? $resp['nextPageToken'] : '') : '';
        if ($pageToken === '') { break; }
        usleep(500000);
    }
    db_exec('INSERT INTO sync_log (mode, found, inserted, updated, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
        array('feeds', $found, $inserted, $updated));
    return array('found' => $found, 'inserted' => $inserted, 'updated' => $updated,
                 'scored' => score_auto_enabled());
}

/**
 * 手动添加：按作品 ID 查询（GetFeed 优先，失败回落搜索）
 */
function find_work_by_id(string $token, string $workId): array
{
    try {
        $resp = kimi_get_feed($token, $workId);
        $feed = null;
        if (isset($resp['feed']) && is_array($resp['feed'])) { $feed = $resp['feed']; }
        elseif (isset($resp['_feed']) && is_array($resp['_feed'])) { $feed = $resp['_feed']; }
        if ($feed !== null) {
            $item = kimi_normalize_item($feed);
            if ($item['community_id'] !== '') { return $item; }
        }
    } catch (Exception $e) {
        // 继续尝试搜索
    }
    $resp = kimi_search($token, $workId);
    $moments = isset($resp['moments']) && is_array($resp['moments']) ? $resp['moments'] : array();
    foreach ($moments as $m) {
        $item = kimi_normalize_item($m);
        if ($item['community_id'] === $workId) { return $item; }
    }
    if (!empty($moments)) { return kimi_normalize_item($moments[0]); }
    throw new RuntimeException('未找到该作品，请检查作品 ID 或 cookie 是否有效');
}
