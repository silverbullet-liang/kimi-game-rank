<?php
/**
 * Works 工具执行器（AI 对话的站内/站外数据查询）
 * ------------------------------------------------------------
 * AI 通过 <Works check>{"action":...}</Works check> 文本标签发起调用，
 * 本文件在服务端执行并返回结构化结果（不用 role=tool，因 glm-4-flash 不支持 tool 消息）。
 *
 * 工具集：
 *   search    query / limit            → 站内作品检索
 *   get       id                       → 单作品完整信息（六维/总分/作者/ID/链接）
 *   rank      from, to                 → 总榜排名区间
 *   comments  id, limit                → 指定作品的评论区
 *   web_open  url                      → 任意链接 HTML 转正文（含 SSRF 防护）
 */
declare(strict_types=1);

define('WORKS_TOOL_NAMES', 'search, get, rank, comments, web_open, weather, time');

/** 工具名正则片段（供解析器复用） */
function works_tool_names_re(): string
{
    return 'web_open|webopen|search|get|rank|comments|weather|time';
}

/** 名称归一化 */
function works_tool_norm_name(string $n): string
{
    $n = strtolower(trim($n));
    if ($n === 'webopen' || $n === 'web-open') { return 'web_open'; }
    return $n;
}

/**
 * 判定一条 JSON 参数属于哪个工具。
 * ------------------------------------------------------------
 * 模型经常不按协议输出：要么丢掉工具名只给 JSON，要么把工具名与参数分成两行，
 * 要么 JSON 里干脆没有 action 键。这里做三级判定，尽量把调用认出来：
 *   1) JSON 自带合法 action       → 用它
 *   2) 外部已捕获到工具名（$hint）→ 用它（工具名与参数分行的情况）
 *   3) 按参数键反推               → 哪些键只可能属于某个工具，就归给它
 * 三级都判不出返回空串，调用方据此**保留原文**（绝不误吞用户的正常 JSON）。
 */
function works_tool_guess_action(array $j, string $hint = ''): string
{
    $known = array('search', 'get', 'rank', 'comments', 'web_open', 'weather', 'time');

    if (isset($j['action'])) {
        $a = works_tool_norm_name((string)$j['action']);
        if (in_array($a, $known, true)) { return $a; }
    }
    if ($hint !== '') {
        $h = works_tool_norm_name($hint);
        if (in_array($h, $known, true)) { return $h; }
    }

    /* 参数键的唯一归属：只有某个工具会用到这个键 */
    if (isset($j['url']))      { return 'web_open'; }
    if (isset($j['city']))     { return 'weather'; }
    if (isset($j['timezone'])) { return 'time'; }
    if (isset($j['from']) || isset($j['to'])) { return 'rank'; }
    if (isset($j['query']))    { return 'search'; }
    if (isset($j['id']))       { return isset($j['limit']) ? 'comments' : 'get'; }

    return '';
}

function works_tool_execute(array $params): array
{
    $action = isset($params['action']) ? strtolower(trim((string)$params['action'])) : 'search';

    if ($action === 'search') {
        $q = isset($params['query']) ? trim((string)$params['query']) : '';
        if ($q === '') { return array('ok' => false, 'error' => '缺少 query 参数', 'hint' => 'search 必须带 query，例如 {"action":"search","query":"解谜"}'); }
        $limit = min(10, max(1, isset($params['limit']) ? (int)$params['limit'] : 6));
        $like = '%' . $q . '%';
        $rows = db_all(
            'SELECT id, community_id, title, author_name, category, total_score, rating, like_num, html_url, share_link
             FROM works WHERE is_hidden = 0 AND (title LIKE ? OR author_name LIKE ? OR intro LIKE ?)
             ORDER BY total_score DESC LIMIT ' . $limit,
            array($like, $like, $like)
        );
        foreach ($rows as $i => $r) { $rows[$i]['link'] = work_link($r); }
        return array('ok' => true, 'action' => 'search', 'query' => $q, 'count' => count($rows), 'works' => $rows);
    }

    if ($action === 'get') {
        $id = isset($params['id']) ? trim((string)$params['id']) : '';
        if ($id === '') { return array('ok' => false, 'error' => '缺少 id 参数', 'hint' => 'get 需要作品 ID 或社区 ID，例如 {"action":"get","id":"12345"}'); }
        $row = db_one(
            'SELECT id, community_id, title, intro, author_name, category, total_score, rating, score, heat_score,
                    like_num, comment_num, collect_num, share_link, html_url, updated_at
             FROM works WHERE is_hidden = 0 AND (community_id = ? OR id = ?) LIMIT 1',
            array($id, preg_match('/^\d+$/', $id) ? (int)$id : 0)
        );
        if ($row === null) { return array('ok' => false, 'error' => '作品不存在或已下架'); }
        $score = json_decode((string)$row['score'], true);
        unset($row['score']);
        $row['dims'] = is_array($score) ? $score : array();
        $row['link'] = work_link($row);
        $row['intro'] = mb_substr((string)$row['intro'], 0, 400, 'UTF-8');
        return array('ok' => true, 'action' => 'get', 'work' => $row);
    }

    if ($action === 'rank') {
        $from = max(1, isset($params['from']) ? (int)$params['from'] : 1);
        $to = min(50, isset($params['to']) ? (int)$params['to'] : ($from + 9));
        if ($to < $from) { $to = $from; }
        $span = $to - $from + 1;
        $rows = db_all(
            'SELECT title, author_name, category, total_score, rating, community_id, html_url, share_link FROM works
             WHERE is_hidden = 0 ORDER BY total_score DESC LIMIT ' . $span . ' OFFSET ' . ($from - 1)
        );
        $out = array();
        foreach ($rows as $i => $r) {
            $r['rank'] = $from + $i;
            $r['link'] = work_link($r);
            $out[] = $r;
        }
        return array('ok' => true, 'action' => 'rank', 'from' => $from, 'to' => $to, 'count' => count($out), 'works' => $out);
    }

    if ($action === 'comments') {
        $id = isset($params['id']) ? trim((string)$params['id']) : '';
        if ($id === '') { return array('ok' => false, 'error' => '缺少 id'); }
        $limit = min(30, max(1, isset($params['limit']) ? (int)$params['limit'] : 15));
        $work = db_one('SELECT id, title FROM works WHERE is_hidden = 0 AND (community_id = ? OR id = ?) LIMIT 1',
            array($id, preg_match('/^\d+$/', $id) ? (int)$id : 0));
        if ($work === null) { return array('ok' => false, 'error' => '作品不存在或已下架'); }
        $rows = db_all(
            'SELECT c.content, c.created_at, u.username
             FROM comments c JOIN users u ON u.id = c.user_id
             WHERE c.work_id = ? AND c.is_deleted = 0 ORDER BY c.id ASC LIMIT ' . $limit,
            array((int)$work['id'])
        );
        $items = array();
        foreach ($rows as $r) {
            $items[] = array('user' => (string)$r['username'], 'content' => mb_substr((string)$r['content'], 0, 200, 'UTF-8'));
        }
        $total = (int)db_val('SELECT COUNT(*) FROM comments WHERE work_id = ? AND is_deleted = 0', array((int)$work['id']));
        return array('ok' => true, 'action' => 'comments', 'work' => (string)$work['title'],
            'total' => $total, 'count' => count($items), 'comments' => $items);
    }

    if ($action === 'time') {
        return tool_current_time($params);
    }

    if ($action === 'weather') {
        $city = isset($params['city']) ? trim((string)$params['city']) : '';
        if ($city === '') { return array('ok' => false, 'error' => '缺少 city 参数', 'hint' => 'weather 必须带 city，例如 {"action":"weather","city":"北京"}'); }
        return weather_lookup($city);
    }

    if ($action === 'web_open') {
        $url = isset($params['url']) ? trim((string)$params['url']) : '';
        return web_open_text($url);
    }

    return array('ok' => false, 'error' => '未知 action，可用：' . WORKS_TOOL_NAMES);
}

/* ============================================================
 * web_open：抓取网页正文（严格 SSRF 防护）
 * ============================================================ */
function web_open_text(string $url): array
{
    $bad = url_is_safe($url);
    if ($bad !== '') { return array('ok' => false, 'error' => $bad); }

    /* 逐跳跟随重定向（每跳都校验目标地址，防止 302 绕到内网） */
    $r = http_fetch_follow($url, 3, 8, 400000);
    if (empty($r['ok'])) {
        return array('ok' => false, 'error' => '抓取失败：' . (string)$r['error']);
    }

    $text = html_to_text((string)$r['body']);
    if ($text === '') { return array('ok' => false, 'error' => '页面无可读正文（可能是动态渲染页面）'); }

    $len = mb_strlen($text, 'UTF-8');
    if ($len < 80) {
        $text .= "\n（提示：该页面可读正文很少，可能由脚本动态渲染，请改用其它可静态阅读的来源）";
    }
    return array(
        'ok'        => true,
        'action'    => 'web_open',
        'url'       => $url,
        'final_url' => isset($r['final_url']) ? (string)$r['final_url'] : $url,
        'redirects' => isset($r['hops']) ? (int)$r['hops'] : 0,
        'length'    => $len,
        'text'      => $text,
    );
}

/**
 * 主机名是否解析到内网/保留地址。
 *
 * 注意：gethostbynamel / dns_get_record 在共享主机上常被 disable_functions 禁用。
 * 此时**不能**据此判定为不安全（那会让所有网页都打不开），而是放行，
 * 交由 http_fetch() 用 CURLINFO_PRIMARY_IP 校验「实际连上的地址」兜底——
 * 那才是真正可靠的判据，且不依赖任何解析函数。
 */
function ip_is_private_block(string $host): bool
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) { return ip_bad($host); }
    if (!preg_match('/^[a-z0-9\.\-]+$/i', $host)) { return true; }
    if (substr($host, -6) === '.local' || $host === 'localhost') { return true; }

    $ips = host_ips($host);
    if ($ips === null) { return false; }          // 环境无法解析：交给连接后校验
    foreach ($ips as $ip) { if (ip_bad($ip)) { return true; } }
    return false;
}

/**
 * 解析主机名为 IP 列表（多级降级）。
 * 返回 null 表示「当前环境无法解析」，与「解析到内网」区分开。
 *
 * 注意：这里不能用 `?array` 返回类型——那是 PHP 7.1+ 语法，
 * 在 PHP 7.0 环境会直接 parse error。用 PHPDoc 表达即可。
 *
 * @return array|null
 */
function host_ips(string $host)
{
    if (function_exists('gethostbynamel')) {
        $ips = @gethostbynamel($host);
        if (is_array($ips) && !empty($ips)) { return $ips; }
    }
    if (function_exists('dns_get_record')) {
        $recs = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($recs)) {
            $out = array();
            foreach ($recs as $r) {
                if (!empty($r['ip']))   { $out[] = (string)$r['ip']; }
                if (!empty($r['ipv6'])) { $out[] = (string)$r['ipv6']; }
            }
            if (!empty($out)) { return $out; }
        }
    }
    $one = @gethostbyname($host);
    if (is_string($one) && $one !== '' && $one !== $host) { return array($one); }
    return null;
}

function ip_bad(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    return false;
}

/** HTML → 纯文本 */
function html_to_text(string $html): string
{
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html);
    $html = preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);
    $title = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = trim($text);
    $text = mb_substr($text, 0, 4000, 'UTF-8');
    if ($title !== '') { $text = $title . "\n\n" . $text; }
    return trim($text);
}

/* ============================================================
 * 工具结果 → 回传给模型的可读文本
 * ============================================================ */
function works_tool_result_text(array $result): string
{
    if (empty($result['ok'])) {
        $act = isset($result['action']) ? (string)$result['action'] : '';
        $err = isset($result['error']) ? (string)$result['error'] : '未知错误';
        $hint = isset($result['hint']) ? (string)$result['hint'] : '';
        /* 把失败原因显式交给模型，并给出可执行的修正方向，便于它换参重试 */
        $t = "<tool_result>\n执行失败\n";
        if ($act !== '') { $t .= "action=" . $act . "\n"; }
        $t .= "error=" . $err . "\n";
        if ($hint !== '') { $t .= "hint=" . $hint . "\n"; }
        $t .= "请据此修正参数后重新调用该工具（例如补全缺失字段、改用更常见的取值）。"
            . "若两次修正仍失败，请如实向用户说明失败原因，不得编造结果。\n</tool_result>";
        return $t;
    }
    $catMap = array('game' => '游戏', 'tool' => '工具', 'literature' => '文学', 'fanart' => '二创');
    $cat = function ($c) use ($catMap) { return isset($catMap[$c]) ? $catMap[$c] : (string)$c; };
    $lines = array();
    $head = 'action=' . (isset($result['action']) ? $result['action'] : '');

    if ($result['action'] === 'search' || $result['action'] === 'rank') {
        if (isset($result['query'])) { $head .= ' query=' . $result['query']; }
        if (isset($result['from'])) { $head .= ' 范围=' . $result['from'] . '-' . $result['to']; }
        foreach ((array)$result['works'] as $w) {
            $l = (isset($w['rank']) ? '第' . $w['rank'] . '名 ' : '') . '《' . $w['title'] . '》'
               . ' 作者:' . $w['author_name'] . ' 分类:' . $cat($w['category'])
               . ' 总分:' . $w['total_score'] . '(' . $w['rating'] . ')'
               . ' 社区ID:' . $w['community_id']
               . (empty($w['link']) ? '' : ' 链接:' . $w['link']);
            $lines[] = $l;
        }
        $head .= ' 结果数=' . count($lines);
    } elseif ($result['action'] === 'get') {
        $w = $result['work'];
        $d = isset($w['dims']) ? $w['dims'] : array();
        $head .= ' 《' . $w['title'] . '》作者:' . $w['author_name'] . ' 社区ID:' . $w['community_id'];
        $lines[] = '总分:' . $w['total_score'] . ' 评级:' . $w['rating'] . ' 分类:' . $cat($w['category']);
        $lines[] = '六维 创意:' . (int)(isset($d['creativity']) ? $d['creativity'] : 0)
            . ' 体验:' . (int)(isset($d['experience']) ? $d['experience'] : 0)
            . ' 深度:' . (int)(isset($d['depth']) ? $d['depth'] : 0)
            . ' 成本:' . (int)(isset($d['cost']) ? $d['cost'] : 0)
            . ' 态度:' . (int)(isset($d['attitude']) ? $d['attitude'] : 0)
            . ' 热度:' . (int)(isset($d['heat']) ? $d['heat'] : 0);
        if (!empty($w['link'])) { $lines[] = '链接:' . $w['link']; }
        $lines[] = '点赞:' . $w['like_num'] . ' 评论:' . $w['comment_num'];
        if (!empty($w['intro'])) { $lines[] = '简介:' . str_replace("\n", ' ', (string)$w['intro']); }
    } elseif ($result['action'] === 'comments') {
        $head .= ' 《' . $result['work'] . '》评论总数=' . $result['total'];
        foreach ((array)$result['comments'] as $c) { $lines[] = $c['user'] . '：' . str_replace("\n", ' ', $c['content']); }
    } elseif ($result['action'] === 'time') {
        $head .= ' 服务器UTC=' . $result['utc'] . ' 站点时区=' . $result['site_timezone']
               . ' 站点时间=' . $result['site_time'] . '（' . $result['site_weekday'] . '）'
               . ' 时间戳=' . $result['timestamp'];
        if (isset($result['query_time'])) {
            $head .= ' | 指定时区=' . $result['query_timezone'] . ' 当地时间=' . $result['query_time'];
        }
        if (isset($result['timezone_error'])) { $head .= ' | ' . $result['timezone_error']; }
    } elseif ($result['action'] === 'weather') {
        $where = $result['city'];
        if (!empty($result['admin']) && $result['admin'] !== $result['city']) { $where .= '（' . $result['admin'] . '）'; }
        if (!empty($result['country'])) { $where .= '·' . $result['country']; }
        $head .= ' 城市=' . $where . ' 观测=' . (isset($result['observed_at']) ? $result['observed_at'] : '')
               . ' 数据源=' . (isset($result['source']) ? $result['source'] : '');
        $cur = '当前天气：' . $result['condition'];
        if ($result['temperature'] !== null) { $cur .= '，气温 ' . $result['temperature'] . '°C'; }
        if ($result['feels_like'] !== null) { $cur .= '，体感 ' . $result['feels_like'] . '°C'; }
        if ($result['humidity'] !== null) { $cur .= '，湿度 ' . $result['humidity'] . '%'; }
        if ($result['wind_speed'] !== null) { $cur .= '，风速 ' . $result['wind_speed'] . ' km/h'; }
        $lines[] = $cur;
        if (is_array($result['today'])) {
            $lines[] = '今日：' . $result['today']['condition'] . '，最高 ' . $result['today']['max']
                     . '°C / 最低 ' . $result['today']['min'] . '°C';
        }
    } elseif ($result['action'] === 'web_open') {
        $head .= ' url=' . $result['url'] . ' 字数=' . $result['length'];
        $lines[] = (string)$result['text'];
    }

    return "<tool_result>\n" . $head . "\n" . implode("\n", $lines) . "\n</tool_result>";
}

/** 工具结果一句话摘要（下发前端卡片显示） */
function works_tool_summary(array $r): string
{
    if (empty($r['ok'])) { return '失败：' . (isset($r['error']) ? $r['error'] : '未知错误'); }
    $a = isset($r['action']) ? $r['action'] : '';
    if ($a === 'search')   { return '检索「' . $r['query'] . '」命中 ' . $r['count'] . ' 件作品'; }
    if ($a === 'get')      { return '《' . $r['work']['title'] . '》总分 ' . $r['work']['total_score'] . '（' . $r['work']['rating'] . '）'; }
    if ($a === 'rank')     { return '总榜第 ' . $r['from'] . '-' . $r['to'] . ' 名，返回 ' . $r['count'] . ' 条'; }
    if ($a === 'comments') { return '《' . $r['work'] . '》评论 ' . $r['total'] . ' 条，读取 ' . $r['count'] . ' 条'; }
    if ($a === 'web_open') { return '已读取网页正文 ' . $r['length'] . ' 字'; }
    if ($a === 'time')    { return '当前时间：' . $r['site_time'] . '（' . $r['site_timezone'] . '）'; }
    if ($a === 'weather') {
        $t = ($r['temperature'] === null) ? '' : (' ' . $r['temperature'] . '°C');
        return $r['city'] . '：' . $r['condition'] . $t . '（' . (isset($r['source']) ? $r['source'] : '') . '）';
    }
    return '已完成';
}

/**
 * 统一解析模型输出中的工具调用（兼容多种写法，绝不把标签裸露给用户）
 * 支持：
 *   1) 标准 JSON 标签   <Works check>{"action":"search","query":"x"}</Works check>
 *   2) 标签 + JSON 体    <web_open>{"url":"https://..."}</web_open>
 *   3) 属性式标签        <web_open url="https://..." />  或  <web_open url="..."></web_open>
 *   4) 关闭标签缺失      <search query="x">
 * 返回 array(calls => [...], clean => 去掉全部标签后的可见文本)
 */
function works_tool_parse(string $text): array
{
    $calls = array();
    $clean = (string)$text;
    $TOOL_NAMES = 'web_open|webopen|search|get|rank|comments|weather|time';

    /* ---------- 1) 标准 JSON 标签 ---------- */
    if (preg_match_all('#<Works\s*[\-_ ]?\s*check\s*>(.*?)(?:</Works\s*[\-_ ]?\s*check\s*>|$)#is', $clean, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $json = trim((string)$x[1]);
            if ($json === '') { continue; }
            $j = json_decode($json, true);
            if (!is_array($j)) { $j = json_decode(rtrim($json, ", \n\r\t") . '}', true); }   // 补全被截断的 JSON
            if (is_array($j) && isset($j['action'])) { $calls[] = $j; }
        }
        $clean = preg_replace('#<Works\s*[\-_ ]?\s*check\s*>.*?(?:</Works\s*[\-_ ]?\s*check\s*>|$)#is', '', $clean);
    }

    /* ---------- 2/3/4) 标签名式（JSON 体 或 属性） ---------- */
    if (preg_match_all('#<(' . $TOOL_NAMES . ')\b([^>]*?)(?:/>|>(.*?)(?:</\1\s*>|$)|$)#is', $clean, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $name  = strtolower((string)$x[1]);
            $attrs = works_tool_parse_attrs((string)$x[2]);
            $body  = isset($x[3]) ? trim((string)$x[3]) : '';
            if ($body !== '' && $body[0] === '{') {
                $j = json_decode($body, true);
                if (is_array($j)) { $attrs = array_merge($attrs, $j); }
            }
            $name = ($name === 'webopen') ? 'web_open' : $name;
            if (!empty($attrs)) { $calls[] = array_merge(array('action' => $name), $attrs); }
            else { $calls[] = array('action' => $name); }
        }
        $clean = preg_replace('#<' . $TOOL_NAMES . '\b[^>]*>.*?(?:</(?:' . $TOOL_NAMES . ')\s*>|$)#is', '', $clean);
        $clean = preg_replace('#</?(?:' . $TOOL_NAMES . ')\b[^>]*/?>#i', '', $clean);
    }

    /* ---------- 3) 裸工具名 + JSON（可被代码块包裹）：web_open 换行 {"url":"..."} ---------- */
    $RO = works_tool_names_re();
    $fence = '(?:\x60{3}[a-zA-Z0-9]*[ \t]*[\r\n]?)?';
    $nameJson = '#(?:^|[\r\n])[ \t]*' . $fence . '[ \t]*(' . $RO . ')[ \t]*[:：]?[ \t]*'
              . $fence . '[\r\n]?[ \t]*(\{[^{}]*\})[ \t]*(?:\x60{3})?#i';
    if (preg_match_all($nameJson, $clean, $m1, PREG_SET_ORDER)) {
        foreach ($m1 as $x) {
            $j = json_decode(trim((string)$x[2]), true);
            if (is_array($j)) {
                $nm = (isset($j['action']) && $j['action'] !== '') ? (string)$j['action'] : (string)$x[1];
                $j['action'] = works_tool_norm_name($nm);
                $calls[] = $j;
            }
        }
        $clean = preg_replace($nameJson, '', $clean);
    }

    /* ---------- 4) 裸 JSON：{"action":"web_open","url":"…"}，以及只给参数的 {"url":"…"} ---------- */
    $bareJson = '#(?:^|[\r\n])[ \t]*(\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\})#';
    if (preg_match_all($bareJson, $clean, $m2, PREG_SET_ORDER)) {
        foreach ($m2 as $x) {
            $j = json_decode(trim((string)$x[1]), true);
            if (!is_array($j)) { continue; }
            $nm = works_tool_guess_action($j);
            if ($nm === '') { continue; }        // 认不出来 → 原样保留，不误吞正文
            $j['action'] = $nm;
            $calls[] = $j;
            $clean = str_replace($x[0], "\n", $clean);
        }
    }


    /* ---------- 兜底：任何残留的同名标签一律剥离，避免裸露给用户 ---------- */
    $clean = preg_replace('#</?(?:Works\s*[\-_ ]?\s*check|' . $TOOL_NAMES . ')\b[^>]*>#i', '', $clean);
    $clean = preg_replace('#^[ \t]*\x60{3}[a-zA-Z0-9]*[ \t]*$#m', '', $clean);

    return array('calls' => array_slice($calls, 0, 3), 'clean' => trim($clean));
}

/** 解析标签属性：key="v" / key='v' / key=v */
function works_tool_parse_attrs(string $s): array
{
    $out = array();
    if ($s === '') { return $out; }
    if (preg_match_all('#([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))#', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $k = strtolower((string)$x[1]);
            $v = (string)$x[2] !== '' ? (string)$x[2] : ((string)$x[3] !== '' ? (string)$x[3] : (string)$x[4]);
            if ($k !== '' && $v !== '') { $out[$k] = $v; }
        }
    }
    return $out;
}

/** 从模型文本中提取所有工具调用（最多 $max 个） */
function works_tool_extract(string $text, int $max = 3): array
{
    $r = works_tool_parse($text);
    return array_slice($r['calls'], 0, $max);
}

/** 去掉工具标签后的可见文本 */
function works_tool_strip(string $text): string
{
    $r = works_tool_parse($text);
    return $r['clean'];
}

/* ============================================================
 * 流式扫描器：边收边判，标签绝不外泄
 * ============================================================ */

/**
 * 扫描待定缓冲。
 * 返回 array(safe => 可安全下发的文本, rest => 需保留到下次的未决尾部, calls => 本轮识别出的调用)
 */
function works_tool_scan_angle(string $buf): array
{
    $calls = array();
    $out = '';
    $i = 0;
    $n = strlen($buf);
    $NAMES = works_tool_names_re();   // 含 weather / time，避免新工具标签泄漏

    while ($i < $n) {
        $lt = strpos($buf, '<', $i);
        if ($lt === false) { $out .= substr($buf, $i); break; }
        $out .= substr($buf, $i, $lt - $i);
        $rest = substr($buf, $lt);

        /* A) 完整的标准 JSON 标签 */
        if (preg_match('#^<Works\s*[\-_ ]?\s*check\s*>(.*?)</Works\s*[\-_ ]?\s*check\s*>#is', $rest, $m)) {
            $j = json_decode(trim((string)$m[1]), true);
            if (is_array($j) && isset($j['action'])) { $calls[] = $j; }
            $i = $lt + strlen($m[0]);
            continue;
        }

        /* B) 工具标签：属性部分已闭合（> 或 />）即可判定 */
        if (preg_match('#^<(' . $NAMES . ')\b([^>]*?)/?>#is', $rest, $m)) {
            $name  = strtolower((string)$m[1]);
            $name  = ($name === 'webopen') ? 'web_open' : $name;
            $attrs = works_tool_parse_attrs((string)$m[2]);
            $len   = strlen($m[0]);
            $tail  = substr($rest, $len);

            if (empty($attrs) && ($tail === '' || $tail[0] === '{')) {
                /* 无属性的开放标签，后面可能紧跟 JSON 体：<web_open>{...}</web_open>
                   体未收完时暂缓判定，避免把 JSON 文本漏给用户 */
                if (preg_match('#^(\{.*?\})\s*</' . $m[1] . '\s*>#is', $tail, $m2)) {
                    $j = json_decode((string)$m2[1], true);
                    if (is_array($j)) { $attrs = array_merge($attrs, $j); }
                    $len += strlen($m2[0]);
                } else {
                    return array('safe' => $out, 'rest' => $rest, 'calls' => $calls);
                }
            } elseif (preg_match('#^(\{.*?\})?\s*</' . $m[1] . '\s*>#is', $tail, $m2)) {
                /* 带属性的标签，顺带吃掉尾部可能的 JSON 体与闭合标签 */
                if (isset($m2[1]) && $m2[1] !== '') {
                    $j = json_decode((string)$m2[1], true);
                    if (is_array($j)) { $attrs = array_merge($attrs, $j); }
                }
                $len += strlen($m2[0]);
            }
            $calls[] = empty($attrs) ? array('action' => $name) : array_merge(array('action' => $name), $attrs);
            $i = $lt + $len;
            continue;
        }

        /* C) 孤立的闭合标签：直接丢弃 */
        if (preg_match('#^</(?:' . $NAMES . ')\s*>#i', $rest, $m)) {
            $i = $lt + strlen($m[0]);
            continue;
        }

        /* D) 可能是未完成的标签 → 保留待定（绝不外泄） */
        if (works_tool_maybe_tag($rest)) {
            return array('safe' => $out, 'rest' => $rest, 'calls' => $calls);
        }

        /* E) 普通文本 */
        $out .= '<';
        $i = $lt + 1;
    }

    return array('safe' => $out, 'rest' => '', 'calls' => $calls);
}

/**
 * 判断从 '<' 开始的片段是否可能是（尚未完成的）工具标签。
 * 命中已知标签名或其前缀 → 一律保留，等到标签闭合或流结束再处理。
 */
function works_tool_maybe_tag(string $rest): bool
{
    if (strlen($rest) > 1200) { return false; }
    if (!preg_match('#^<\s*/?\s*([A-Za-z_]*)#', $rest, $m)) { return true; }
    $name = strtolower((string)$m[1]);
    if ($name === '') { return true; }        // 只有 '<' 或 '</'

    $names = array('works', 'web_open', 'webopen', 'search', 'get', 'rank', 'comments', 'weather', 'time');
    foreach ($names as $nm) {
        if ($nm === $name || substr($nm, 0, strlen($name)) === $name) { return true; }
    }
    return false;
}

/* ============================================================
 * 工具：当前时间
 * ============================================================ */
function tool_current_time(array $params): array
{
    $siteTz = (string)cfg('site.timezone', 'Asia/Shanghai');
    $ts = time();
    $week = array('日', '一', '二', '三', '四', '五', '六');

    $utc = new DateTime('now', new DateTimeZone('UTC'));
    $site = null;
    try { $site = new DateTime('now', new DateTimeZone($siteTz)); }
    catch (Exception $e) { $site = $utc; }

    $out = array(
        'ok' => true,
        'action' => 'time',
        'timestamp' => $ts,
        'utc' => $utc->format('Y-m-d H:i:s'),
        'site_timezone' => $siteTz,
        'site_time' => $site->format('Y-m-d H:i:s'),
        'site_weekday' => '星期' . $week[(int)$site->format('w')],
    );

    /* 可选：指定时区（如 Asia/Tokyo、America/New_York） */
    $tz = isset($params['timezone']) ? trim((string)$params['timezone']) : '';
    if ($tz !== '') {
        try {
            $z = new DateTimeZone($tz);
            $l = new DateTime('now', new DateTimeZone('UTC'));
            $l->setTimezone($z);
            $out['query_timezone'] = $tz;
            $out['query_time'] = $l->format('Y-m-d H:i:s');
            $out['query_weekday'] = '星期' . $week[(int)$l->format('w')];
            $out['query_offset'] = $z->getName();
        } catch (Exception $e) {
            $out['timezone_error'] = '未知时区：' . $tz . '（请用 IANA 名称，如 Asia/Shanghai）';
        }
    }
    return $out;
}

/* ============================================================
 * 工具：天气查询（Open-Meteo，无需密钥；失败回退 wttr.in）
 * ============================================================ */
function weather_lookup(string $city): array
{
    $city = mb_substr(trim($city), 0, 40, 'UTF-8');

    $geo = http_fetch('https://geocoding-api.open-meteo.com/v1/search?name=' . urlencode($city)
        . '&count=1&language=zh&format=json', 7);
    if (empty($geo['ok'])) {
        return weather_fallback($city, '地理编码服务不可用');
    }
    $gj = json_decode((string)$geo['body'], true);
    $hit = (is_array($gj) && isset($gj['results'][0])) ? $gj['results'][0] : null;
    if ($hit === null) {
        return array('ok' => false, 'error' => '未找到城市「' . $city . '」，请换一个更常见的名称（如 北京 / 上海 / 广州 / Tokyo）');
    }

    $lat = isset($hit['latitude']) ? (float)$hit['latitude'] : 0.0;
    $lon = isset($hit['longitude']) ? (float)$hit['longitude'] : 0.0;
    $place = (string)(isset($hit['name']) ? $hit['name'] : $city);
    $country = (string)(isset($hit['country']) ? $hit['country'] : '');
    $admin = (string)(isset($hit['admin1']) ? $hit['admin1'] : '');

    $fc = http_fetch('https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon
        . '&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m'
        . '&daily=weather_code,temperature_2m_max,temperature_2m_min'
        . '&timezone=auto&forecast_days=1', 8);
    if (empty($fc['ok'])) {
        return weather_fallback($city, '天气服务不可用');
    }
    $fj = json_decode((string)$fc['body'], true);
    if (!is_array($fj) || !isset($fj['current'])) {
        return weather_fallback($city, '天气数据格式异常');
    }

    $cur = $fj['current'];
    $daily = isset($fj['daily']) ? $fj['daily'] : array();
    $code = (int)(isset($cur['weather_code']) ? $cur['weather_code'] : 0);
    $dcode = (is_array($daily) && isset($daily['weather_code'][0])) ? (int)$daily['weather_code'][0] : $code;

    return array(
        'ok' => true,
        'action' => 'weather',
        'city' => $place,
        'admin' => $admin,
        'country' => $country,
        'latitude' => $lat,
        'longitude' => $lon,
        'observed_at' => (string)(isset($cur['time']) ? $cur['time'] : ''),
        'temperature' => isset($cur['temperature_2m']) ? (float)$cur['temperature_2m'] : null,
        'feels_like' => isset($cur['apparent_temperature']) ? (float)$cur['apparent_temperature'] : null,
        'humidity' => isset($cur['relative_humidity_2m']) ? (int)$cur['relative_humidity_2m'] : null,
        'wind_speed' => isset($cur['wind_speed_10m']) ? (float)$cur['wind_speed_10m'] : null,
        'condition' => wmo_text($code),
        'today' => (is_array($daily) && isset($daily['temperature_2m_max'][0])) ? array(
            'condition' => wmo_text($dcode),
            'max' => (float)$daily['temperature_2m_max'][0],
            'min' => (float)$daily['temperature_2m_min'][0],
        ) : null,
        'source' => 'Open-Meteo',
    );
}

/** 天气服务整体不可用时的轻量回退源 */
function weather_fallback(string $city, string $why): array
{
    $r = http_fetch('https://wttr.in/' . rawurlencode($city) . '?format=j1&lang=zh', 8);
    if (!empty($r['ok'])) {
        $j = json_decode((string)$r['body'], true);
        if (is_array($j) && isset($j['current_condition'][0])) {
            $c = $j['current_condition'][0];
            $desc = '';
            if (isset($c['lang_zh'][0]['value'])) { $desc = (string)$c['lang_zh'][0]['value']; }
            elseif (isset($c['weatherDesc'][0]['value'])) { $desc = (string)$c['weatherDesc'][0]['value']; }
            return array(
                'ok' => true, 'action' => 'weather', 'city' => $city, 'country' => '', 'admin' => '',
                'observed_at' => (string)(isset($c['observation_time']) ? $c['observation_time'] : ''),
                'temperature' => isset($c['temp_C']) ? (float)$c['temp_C'] : null,
                'feels_like' => isset($c['FeelsLikeC']) ? (float)$c['FeelsLikeC'] : null,
                'humidity' => isset($c['humidity']) ? (int)$c['humidity'] : null,
                'wind_speed' => isset($c['windspeedKmph']) ? (float)$c['windspeedKmph'] : null,
                'condition' => $desc,
                'today' => null,
                'source' => 'wttr.in',
            );
        }
    }
    return array('ok' => false, 'error' => '天气查询失败（' . $why . '），请稍后再试或换用其它城市名');
}

/** WMO 天气代码 → 中文描述 */
function wmo_text(int $code): string
{
    static $m = array(
        0 => '晴', 1 => '晴间多云', 2 => '多云', 3 => '阴',
        45 => '有雾', 48 => '冻雾',
        51 => '小毛毛雨', 53 => '毛毛雨', 55 => '大毛毛雨',
        56 => '冻毛毛雨', 57 => '强冻毛毛雨',
        61 => '小雨', 63 => '中雨', 65 => '大雨',
        66 => '冻雨', 67 => '强冻雨',
        71 => '小雪', 73 => '中雪', 75 => '大雪', 77 => '雪粒',
        80 => '阵雨', 81 => '中阵雨', 82 => '强阵雨',
        85 => '小阵雪', 86 => '大阵雪',
        95 => '雷阵雨', 96 => '雷阵雨伴冰雹', 99 => '强雷阵雨伴冰雹',
    );
    return isset($m[$code]) ? $m[$code] : ('未知天气（代码 ' . $code . '）');
}

/* ============================================================
 * 通用 HTTP 抓取（带内网防护、超时与体积限制）
 * ============================================================ */
function http_fetch(string $url, int $timeout = 8, int $maxBytes = 300000): array
{
    $bad = url_is_safe($url);
    if ($bad !== '') { return array('ok' => false, 'error' => $bad); }

    $headers = array();
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,          // 重定向由 http_fetch_follow 逐跳校验后跟随
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'KimiGameRankBot/1.0 (+https://kimi-game-rank.wuaze.com)',
        CURLOPT_SSL_VERIFYPEER => true,
        /* 体积限制改用 WRITEFUNCTION 累积并截断：
           PROGRESSFUNCTION 在部分共享主机上不可用，会让请求直接失败。 */
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, $maxBytes) {
            $body .= $chunk;
            return (strlen($body) > $maxBytes) ? 0 : strlen($chunk);   // 返回 0 即中断传输
        },
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $k = strtolower(trim(substr($line, 0, $p)));
                $headers[$k] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $primaryIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    $err  = curl_error($ch);
    curl_close($ch);

    /* SSRF 兜底：校验真正连上的那个地址。
       这一步不依赖 gethostbynamel / dns_get_record 是否被禁用，因此既安全又可用。 */
    if ($primaryIp !== '' && ip_bad($primaryIp)) {
        return array('ok' => false, 'error' => '该地址不允许访问');
    }
    if ($raw === false) { return array('ok' => false, 'error' => $err !== '' ? $err : '请求失败'); }

    $loc = isset($headers['location']) ? (string)$headers['location'] : '';
    $isRedirect = ($code >= 300 && $code < 400 && $loc !== '');
    $isOk = ($code >= 200 && $code < 300);

    return array(
        'ok'        => ($isOk || $isRedirect),
        'code'      => $code,
        'body'      => (string)$body,
        'headers'   => $headers,
        'location'  => $loc,
        'redirect'  => $isRedirect,
        'error'     => ($isOk || $isRedirect) ? '' : ('HTTP ' . $code),
    );
}

/** url 安全检查：协议、端口、长度、内网/保留地址 */
function url_is_safe(string $url): string
{
    if ($url === '') { return '缺少 url'; }
    if (mb_strlen($url, 'UTF-8') > 600) { return 'url 过长'; }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) { return 'url 格式不正确'; }
    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') { return '仅支持 http/https'; }
    if (ip_is_private_block((string)$parts['host'])) { return '该地址不允许访问'; }
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, array(80, 443, 8080, 8443), true)) { return '端口不允许'; }
    return '';
}

/** 相对 Location 补全为绝对地址 */
function url_absolutize(string $base, string $rel): string
{
    $rel = trim($rel);
    if ($rel === '') { return ''; }
    if (preg_match('#^https?://#i', $rel)) { return $rel; }
    $p = parse_url($base);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) { return ''; }
    $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . (int)$p['port'] : '');
    if ($rel[0] === '/') { return $root . $rel; }
    $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', (string)$p['path']) : '/';
    return $root . $dir . $rel;
}

/**
 * 跟随重定向抓取（最多 $maxHops 跳）。
 * 每一跳都重新做 url_is_safe 校验，因此 302 跳转到内网/保留地址会被拒绝（SSRF 防护）。
 */
function http_fetch_follow(string $url, int $maxHops = 3, int $timeout = 8, int $maxBytes = 400000): array
{
    $current = $url;
    $seen = array();
    $hops = 0;

    for ($i = 0; $i <= $maxHops; $i++) {
        $r = http_fetch($current, $timeout, $maxBytes);
        if (empty($r['ok'])) {
            return array('ok' => false, 'error' => (string)$r['error'], 'final_url' => $current, 'hops' => $hops);
        }
        if (empty($r['redirect'])) {
            $r['final_url'] = $current;
            $r['hops'] = $hops;
            return $r;
        }
        $next = url_absolutize($current, (string)$r['location']);
        if ($next === '') {
            return array('ok' => false, 'error' => '重定向地址无效', 'final_url' => $current, 'hops' => $hops);
        }
        if (isset($seen[$next])) {
            return array('ok' => false, 'error' => '重定向形成环', 'final_url' => $current, 'hops' => $hops);
        }
        $seen[$current] = true;
        $bad = url_is_safe($next);
        if ($bad !== '') {
            return array('ok' => false, 'error' => '重定向目标被拒：' . $bad, 'final_url' => $next, 'hops' => $hops);
        }
        $current = $next;
        $hops++;
    }
    return array('ok' => false, 'error' => '重定向次数过多（超过 ' . $maxHops . ' 跳）', 'final_url' => $current, 'hops' => $hops);
}

/**
 * 阶段一：<...> 标签式扫描（标准 JSON 标签 / 属性式标签 / 缺失闭合标签）
 * 返回 array(safe, rest, calls)；rest 为需要保留到下一段的未决尾部。
 */
/**
 * 裸工具调用扫描：模型有时不写标签，直接输出「工具名 + 换行 + JSON 对象」。
 * 只在「行首工具名 + 紧随含已知 action 的 JSON」时判定，避免误伤正常回答。
 *
 * 返回 array(text, calls, pending)：
 *  - text    ：剥离调用后的安全文本
 *  - calls   ：提取到的调用
 *  - pending ：末尾残缺片段（JSON 未闭合 / 只剩工具名），保留等待后续分片，绝不外泄
 */
function works_tool_scan_bare(string $text, array $calls = array()): array
{
    $NAMES = works_tool_names_re();
    $fence = '(?:\x60{3}[a-zA-Z0-9]*[ \t]*[\r\n]+)?[ \t]*';
    $gap   = '[ \t]*[:：]?[ \t]*(?:[\r\n][ \t]*){0,3}';   // 工具名与 JSON 之间允许空行
    $objRe = '\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}';
        /* 只在确实跟着闭合围栏时才吃掉换行，否则把换行留给下一个调用匹配（避免重叠失配） */
    $tail  = '[ \t]*(?:[\r\n][ \t]*\x60{3})?';

    /* 完整调用：剥掉整段，但保留一个换行，避免把相邻两行粘成一行 */
    $re = '#(?:^|[\r\n])[ \t]*' . $fence . '(' . $NAMES . ')' . $gap . $fence
        . '(' . $objRe . ')' . $tail . '#i';
    if (preg_match_all($re, $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $j = json_decode((string)$x[2], true);
            if (!is_array($j)) { continue; }
            /* 工具名已捕获：JSON 缺 action 时就用它补上。
               此前这里要求 JSON 必须自带 action，否则整段跳过——结果「工具名 + 参数分行」
               的写法既没被执行、也没被剥除，JSON 直接漏成了正文。 */
            $nm = works_tool_guess_action($j, (string)$x[1]);
            if ($nm === '') { continue; }
            $j['action'] = $nm;
            $calls[] = $j;
            $text = str_replace($x[0], "\n", $text);
        }
    }

    /* 纯裸 JSON（连工具名都没有，如 {"city":"北京"} / {"url":"https://…"}）：
       整行只有一个 JSON 对象时，按参数键反推工具；认不出来就原样留给正文。 */
    /* 只要位于行首即认定，不要求行尾——模型常写成 {"city": "北京"}抱歉（JSON 与正文粘连） */
    $bareLine = '#(?:^|[\r\n])[ \t]*(' . $objRe . ')#';
    if (preg_match_all($bareLine, $text, $mb, PREG_SET_ORDER)) {
        foreach ($mb as $x) {
            $j = json_decode((string)$x[1], true);
            if (!is_array($j)) { continue; }
            $nm = works_tool_guess_action($j);
            if ($nm === '') { continue; }
            $j['action'] = $nm;
            $calls[] = $j;
            $text = str_replace($x[0], "\n", $text);
        }
    }

    /* 末尾残缺：JSON 未闭合、或只剩工具名待续 → 保留，绝不当正文输出 */
    $pending = '';
    $pRe = '#(?:^|[\r\n])([ \t]*' . $fence . '(' . $NAMES . ')' . $gap
         . '(?:' . $objRe . '|\{[^{}]*)?)$#i';
    if (preg_match($pRe, $text, $pm)) {
        $seg = (string)$pm[1];
        if ($seg !== '' && strlen($seg) < 2000) {
            $pending = $seg;
            $text = substr($text, 0, strlen($text) - strlen($seg));
        }
    }
    /* 无工具名的纯 JSON 写到一半，同样挂起：否则 {"city": 会中途闪现在正文里 */
    if ($pending === '') {
        $pJson = '#(?:^|[\r\n])([ \t]*\{[^{}]*)$#';
        if (preg_match($pJson, $text, $pj)) {
            $seg = (string)$pj[1];
            if ($seg !== '' && strlen($seg) < 600) {
                $pending = $seg;
                $text = substr($text, 0, strlen($text) - strlen($seg));
            }
        }
    }

    $text = preg_replace("/[ \t]+\n/", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return array('text' => $text, 'calls' => $calls, 'pending' => $pending);
}

/** 流式扫描（对外）：先剥裸调用，再走标签扫描 */
function works_tool_scan(string $buf): array
{
    $pre = works_tool_scan_bare($buf);
    $r = works_tool_scan_angle($pre['text']);
    return array(
        'safe'  => $r['safe'],
        'rest'  => $r['rest'] . $pre['pending'],
        'calls' => array_merge($pre['calls'], $r['calls']),
    );
}

/**
 * 流结束时定性残留片段：
 *  - 整行只有工具名（无 JSON）→ 视为无参调用（如 time / weather 无需参数）
 *  - 工具名 + 未闭合 JSON → 参数残缺无法执行，整段丢弃（绝不作为正文输出）
 * 返回 array(text, calls)
 */
function works_tool_finalize(string $buf): array
{
    $NAMES = works_tool_names_re();
    $calls = array();

    /* 整行工具名 → 无参调用。但若紧跟着 '{"'（参数还没写完），就不能当无参调用，
       否则会以空参数执行并报「缺少 xxx」——这正是「工具名与参数分行」踩过的坑。 */
    $re = '#(?:^|[\r\n])[ \t]*(' . $NAMES . ')[ \t]*[:：]?[ \t]*(?=$|[\r\n])(?![\r\n][ \t]*\{)#i';
    if (preg_match_all($re, $buf, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) { $calls[] = array('action' => works_tool_norm_name((string)$x[1])); }
        $buf = preg_replace($re, "\n", $buf);
    }

    /* 未闭合的 JSON 参数：整段丢弃 */
    $buf = preg_replace('#(?:^|[\r\n])[ \t]*(?:' . $NAMES . ')[ \t]*[:：]?[ \t]*\{[^{}]*$#i', "\n", $buf);
    $buf = preg_replace("/[ \t]+\n/", "\n", (string)$buf);
    $buf = preg_replace("/\n{3,}/", "\n\n", $buf);
    return array('text' => trim((string)$buf), 'calls' => $calls);
}

/** 标签扫描的对外版本（供其它调用点使用） */
function works_tool_scan_tags(string $buf): array
{
    $pre = works_tool_scan_bare($buf);
    $r = works_tool_scan_tags_angle($pre['text']);
    return array(
        'safe'  => $r['safe'],
        'rest'  => $r['rest'] . $pre['pending'],
        'calls' => array_merge($pre['calls'], $r['calls']),
    );
}

function works_tool_scan_tags_angle(string $buf): array
{
    $calls = array();
    $out = '';
    $i = 0;
    $n = strlen($buf);
    $NAMES = works_tool_names_re();

    while ($i < $n) {
        $lt = strpos($buf, '<', $i);
        if ($lt === false) { $out .= substr($buf, $i); break; }
        $out .= substr($buf, $i, $lt - $i);
        $rest = substr($buf, $lt);

        /* A) 完整的标准 JSON 标签 */
        if (preg_match('#^<Works\s*[\-_ ]?\s*check\s*>(.*?)</Works\s*[\-_ ]?\s*check\s*>#is', $rest, $m)) {
            $j = json_decode(trim((string)$m[1]), true);
            if (is_array($j) && isset($j['action'])) { $calls[] = $j; }
            $i = $lt + strlen($m[0]);
            continue;
        }

        /* B) 工具标签：属性部分已闭合（> 或 />）即可判定 */
        if (preg_match('#^<(' . $NAMES . ')\b([^>]*?)/?>#is', $rest, $m)) {
            $name  = works_tool_norm_name((string)$m[1]);
            $attrs = works_tool_parse_attrs((string)$m[2]);
            $len   = strlen($m[0]);
            $tail  = substr($rest, $len);

            if (empty($attrs) && ($tail === '' || $tail[0] === '{')) {
                if (preg_match('#^(\{.*?\})\s*>#is', $tail, $m2)) {
                    $j = json_decode((string)$m2[1], true);
                    if (is_array($j)) { $attrs = array_merge($attrs, $j); }
                    $len += strlen($m2[0]);
                } else {
                    return array('safe' => $out, 'rest' => $rest, 'calls' => $calls);
                }
            } elseif (preg_match('#^(\{.*?\})?\s*</' . $m[1] . '\s*>#is', $tail, $m2)) {
                if (isset($m2[1]) && $m2[1] !== '') {
                    $j = json_decode((string)$m2[1], true);
                    if (is_array($j)) { $attrs = array_merge($attrs, $j); }
                }
                $len += strlen($m2[0]);
            }
            $attrs['action'] = $name;
            $calls[] = $attrs;
            $i = $lt + $len;
            continue;
        }

        /* C) 孤立的闭合标签：直接丢弃 */
        if (preg_match('#^</(?:' . $NAMES . ')\s*>#i', $rest, $m)) {
            $i = $lt + strlen($m[0]);
            continue;
        }

        /* D) 可能是未完成的标签 → 保留待定（绝不外泄） */
        if (works_tool_maybe_tag($rest)) {
            return array('safe' => $out, 'rest' => $rest, 'calls' => $calls);
        }

        /* E) 普通文本 */
        $out .= '<';
        $i = $lt + 1;
    }

    return array('safe' => $out, 'rest' => '', 'calls' => $calls);
}