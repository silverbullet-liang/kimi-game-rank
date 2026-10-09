<?php
/**
 * AI 工具 · 扩展组（stats / categories / announce / calc / random / lunar / user）
 * ------------------------------------------------------------
 * 与作品工具共用同一套标签协议与执行器：works_tool.php 按名字转发到本文件，
 * 「单一注册表」不被拆散；分文件只为控制单文件长度，不改变对外接口。
 *
 * 隐私边界：user 只回公开字段（昵称 / 身份 / 注册月份 / 收录作品数），
 * 不回邮箱、登录记录、IP、封禁状态；世界对话（公共频道）不开放该工具。
 */
declare(strict_types=1);

/** 本组工具名 */
function works_tool_extra_names(): array
{
    return array('stats', 'categories', 'announce', 'calc', 'random', 'lunar', 'user');
}

/** 世界对话可用的公开工具集（= 全部工具 - user） */
function works_tool_public_names(): array
{
    $all = array_values(array_filter(array_map('trim', explode(',', WORKS_TOOL_NAMES))));
    return array_values(array_diff($all, array('user')));
}

/** 分类中文名（stats / categories / random 共用） */
function works_tool_cat_name(string $c): string
{
    $m = array('game' => '游戏', 'tool' => '工具', 'literature' => '文学', 'fanart' => '二创');
    return isset($m[$c]) ? $m[$c] : $c;
}

function works_tool_extra_execute(array $params): array
{
    $a = isset($params['action']) ? strtolower(trim((string)$params['action'])) : '';
    if ($a === 'stats')      { return extra_stats(); }
    if ($a === 'categories') { return extra_categories(); }
    if ($a === 'announce')   { return extra_announce($params); }
    if ($a === 'calc')       { return extra_calc($params); }
    if ($a === 'random')     { return extra_random($params); }
    if ($a === 'lunar')      { return extra_lunar($params); }
    if ($a === 'user')       { return extra_user($params); }
    return array('ok' => false, 'error' => '未知扩展工具');
}

/* ============================================================
 * stats：站点概览
 * ============================================================ */
function extra_stats(): array
{
    try {
        $w = (int)db_val('SELECT COUNT(*) FROM works WHERE is_hidden = 0');
        $u = (int)db_val('SELECT COUNT(*) FROM users');
        $c = (int)db_val('SELECT COUNT(*) FROM comments WHERE is_deleted = 0');
        $m = (int)db_val('SELECT COUNT(*) FROM messages' . (col_ok('messages', 'is_recalled') ? ' WHERE is_recalled = 0' : ''));
        $today = (int)db_val('SELECT COUNT(*) FROM works WHERE is_hidden = 0 AND created_at >= UTC_DATE()');
    } catch (Throwable $e) {
        return array('ok' => false, 'action' => 'stats', 'error' => '站点数据读取失败');
    }
    return array('ok' => true, 'action' => 'stats',
        'works' => $w, 'users' => $u, 'comments' => $c, 'messages' => $m, 'today_works' => $today);
}

/* ============================================================
 * categories：分类与各分类作品数
 * ============================================================ */
function extra_categories(): array
{
    try {
        $rows = db_all('SELECT category, COUNT(*) AS n FROM works WHERE is_hidden = 0 GROUP BY category ORDER BY n DESC');
    } catch (Throwable $e) {
        return array('ok' => false, 'action' => 'categories', 'error' => '分类统计读取失败');
    }
    $items = array();
    $total = 0;
    foreach ((array)$rows as $r) {
        $n = (int)$r['n'];
        $total += $n;
        $items[] = array('key' => (string)$r['category'], 'name' => works_tool_cat_name((string)$r['category']), 'count' => $n);
    }
    return array('ok' => true, 'action' => 'categories', 'items' => $items, 'total' => $total, 'count' => count($items));
}

/* ============================================================
 * announce：最新公告
 * ============================================================ */
function extra_announce(array $params): array
{
    $limit = min(5, max(1, isset($params['limit']) ? (int)$params['limit'] : 3));
    $items = array();
    try {
        $rows = db_all('SELECT content, updated_at FROM announcements WHERE content <> \'\' ORDER BY id DESC LIMIT ' . $limit);
        foreach ((array)$rows as $r) {
            $items[] = array('content' => trim((string)$r['content']), 'updated_at' => (string)$r['updated_at']);
        }
    } catch (Throwable $e) { $items = array(); }
    if (!$items) {
        $one = function_exists('site_announce') ? trim(site_announce()) : '';
        if ($one !== '') { $items[] = array('content' => $one, 'updated_at' => ''); }
    }
    if (!$items) { return array('ok' => true, 'action' => 'announce', 'items' => array(), 'count' => 0); }
    return array('ok' => true, 'action' => 'announce', 'items' => $items, 'count' => count($items));
}

/* ============================================================
 * calc：精确计算（四则 / 括号 / 幂 / 取模，杜绝模型算错）
 * ============================================================ */
function extra_calc(array $params): array
{
    $expr = isset($params['expr']) ? trim((string)$params['expr'])
          : (isset($params['expression']) ? trim((string)$params['expression'])
          : (isset($params['q']) ? trim((string)$params['q']) : ''));
    if ($expr === '') {
        return array('ok' => false, 'action' => 'calc', 'error' => '缺少 expr 参数',
            'hint' => '例如 {"action":"calc","expr":"(1+2)*3/4"}；支持 + - * / % ^ 与括号。');
    }
    if (mb_strlen($expr, 'UTF-8') > 200) {
        return array('ok' => false, 'action' => 'calc', 'error' => '表达式过长');
    }
    $ev = calc_eval($expr);
    if (empty($ev['ok'])) {
        return array('ok' => false, 'action' => 'calc', 'error' => (string)$ev['error'], 'hint' => isset($ev['hint']) ? (string)$ev['hint'] : '');
    }
    $out = array('ok' => true, 'action' => 'calc', 'expr' => $expr,
        'result' => calc_num_text((float)$ev['value']), 'value' => (float)$ev['value']);
    $fr = calc_fraction((float)$ev['value']);
    if ($fr !== '') { $out['fraction'] = $fr; }
    return $out;
}

/** 记号化：中文/全角运算符归一，非白名单字符直接判非法 */
function calc_tokens(string $expr)
{
    $s = str_replace(array('×', '✕', '＊', '·', '（', '）', '，'), array('*', '*', '*', '*', '(', ')', ','), $expr);
    $s = str_replace(array('÷', '／', '｜'), array('/', '/', '|'), $s);
    $s = str_replace(array('　', ','), array(' ', ' '), $s);
    if (preg_match('#[^0-9+\-*/%^(). ]#', $s)) { return null; }
    if (!preg_match_all('#\d+\.?\d*|\.\d+|[+\-*/%^()]#', $s, $m)) { return null; }
    return $m[0];
}

function calc_eval(string $expr): array
{
    $t = calc_tokens($expr);
    if ($t === null || !$t) { return array('ok' => false, 'error' => '表达式非法（只允许数字与 + - * / % ^ 括号）'); }
    $p = 0;
    $v = calc_p_expr($t, $p);
    if ($v === null) { return array('ok' => false, 'error' => '表达式无法计算（语法错误或除以零）', 'hint' => '检查括号是否配对、除数是否为零。'); }
    if ($p !== count($t)) { return array('ok' => false, 'error' => '表达式末尾有多余字符'); }
    return array('ok' => true, 'value' => $v);
}

function calc_p_expr(array $t, &$p)
{
    $v = calc_p_term($t, $p);
    if ($v === null) { return null; }
    $n = count($t);
    while ($p < $n && ($t[$p] === '+' || $t[$p] === '-')) {
        $op = $t[$p++];
        $r = calc_p_term($t, $p);
        if ($r === null) { return null; }
        $v = ($op === '+') ? $v + $r : $v - $r;
    }
    return $v;
}

function calc_p_term(array $t, &$p)
{
    $v = calc_p_pow($t, $p);
    if ($v === null) { return null; }
    $n = count($t);
    while ($p < $n && ($t[$p] === '*' || $t[$p] === '/' || $t[$p] === '%')) {
        $op = $t[$p++];
        $r = calc_p_pow($t, $p);
        if ($r === null) { return null; }
        if (($op === '/' || $op === '%') && $r == 0.0) { return null; }
        if ($op === '*') { $v = $v * $r; }
        elseif ($op === '/') { $v = $v / $r; }
        else { $v = fmod($v, $r); }
    }
    return $v;
}

function calc_p_pow(array $t, &$p)
{
    $v = calc_p_unary($t, $p);
    if ($v === null) { return null; }
    if ($p < count($t) && $t[$p] === '^') {
        $p++;
        $r = calc_p_pow($t, $p);       // 幂右结合
        if ($r === null) { return null; }
        return pow($v, $r);
    }
    return $v;
}

function calc_p_unary(array $t, &$p)
{
    if ($p < count($t) && ($t[$p] === '-' || $t[$p] === '+')) {
        $op = $t[$p++];
        $v = calc_p_unary($t, $p);
        if ($v === null) { return null; }
        return ($op === '-') ? -$v : $v;
    }
    return calc_p_atom($t, $p);
}

function calc_p_atom(array $t, &$p)
{
    if ($p >= count($t)) { return null; }
    if ($t[$p] === '(') {
        $p++;
        $v = calc_p_expr($t, $p);
        if ($v === null) { return null; }
        if ($p >= count($t) || $t[$p] !== ')') { return null; }
        $p++;
        return $v;
    }
    if (preg_match('#^\d+\.?\d*$|^\.\d+$#', $t[$p])) { return (float)$t[$p++]; }
    return null;
}

/** 数值 → 干净字符串（去掉浮点噪声，如 0.30000000000000004 → 0.3） */
function calc_num_text(float $v): string
{
    if (is_nan($v)) { return '未定义'; }
    if (is_infinite($v)) { return $v > 0 ? '正无穷' : '负无穷'; }
    $s = rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}

/** 连分数逼近：能用一个简单分数表达时给出（如 0.25 → 1/4，0.333… → 1/3） */
function calc_fraction(float $v, int $maxDen = 100000): string
{
    if (!is_finite($v) || $v == (int)$v) { return ''; }
    $neg = $v < 0; $x = abs($v);
    $h1 = 1; $h0 = 0; $k1 = 0; $k0 = 1; $b = $x;
    for ($i = 0; $i < 40; $i++) {
        $a = (int)floor($b);
        $h2 = $a * $h1 + $h0; $k2 = $a * $k1 + $k0;
        $h0 = $h1; $h1 = $h2; $k0 = $k1; $k1 = $k2;
        if ($k1 > $maxDen) { return ''; }
        if ($k1 > 1 && abs($x - $h1 / $k1) < 1e-12) { break; }
        $d = $b - $a;
        if ($d == 0.0) { break; }
        $b = 1 / $d;
    }
    if ($k1 <= 1 || $k1 > $maxDen) { return ''; }
    return ($neg ? '-' : '') . $h1 . '/' . $k1;
}

/* ============================================================
 * random：随机推荐作品（可选按分类）
 * ============================================================ */
function extra_random(array $params): array
{
    $n = min(5, max(1, isset($params['n']) ? (int)$params['n'] : 3));
    $cat = isset($params['category']) ? strtolower(trim((string)$params['category'])) : '';
    $ok = array('game', 'tool', 'literature', 'fanart');
    $args = array();
    $where = 'is_hidden = 0';
    if ($cat !== '') {
        if (!in_array($cat, $ok, true)) {
            return array('ok' => false, 'action' => 'random', 'error' => '未知分类：' . $cat,
                'hint' => '分类取值：game 游戏 / tool 工具 / literature 文学 / fanart 二创。');
        }
        $where .= ' AND category = ?';
        $args[] = $cat;
    }
    try {
        $rows = db_all('SELECT id, community_id, title, author_name, category, total_score, rating, like_num, html_url, share_link
                        FROM works WHERE ' . $where . ' ORDER BY RAND() LIMIT ' . $n, $args);
    } catch (Throwable $e) {
        return array('ok' => false, 'action' => 'random', 'error' => '随机推荐失败');
    }
    foreach ($rows as $i => $r) { $rows[$i]['link'] = work_link($r); }
    return array('ok' => true, 'action' => 'random', 'category' => $cat, 'count' => count($rows), 'works' => $rows);
}

/* ============================================================
 * lunar：公历↔农历换算 + 节日判定
 * ============================================================ */
function extra_lunar(array $params): array
{
    $siteTz = (string)cfg('site.timezone', 'Asia/Shanghai');
    try { $now = new DateTime('now', new DateTimeZone($siteTz)); }
    catch (Exception $e) { $now = new DateTime('now', new DateTimeZone('UTC')); }

    /* 反向：给农历日期求公历 */
    $lu = isset($params['lunar']) ? trim((string)$params['lunar']) : '';
    if ($lu !== '') {
        $p = preg_split('#[^0-9]+#', $lu);
        $p = array_values(array_filter((array)$p, function ($x) { return $x !== ''; }));
        if (count($p) < 2) {
            return array('ok' => false, 'action' => 'lunar', 'error' => '农历日期格式应为「年-月-日」或「月-日」', 'hint' => '例如 {"action":"lunar","lunar":"2026-9-9"}。');
        }
        if (count($p) === 2) { $ly = (int)$now->format('Y'); $lm = (int)$p[0]; $ld = (int)$p[1]; }
        else { $ly = (int)$p[0]; $lm = (int)$p[1]; $ld = (int)$p[2]; }
        $ts = lunar_to_solar($ly, $lm, $ld);
        if ($ts === null) {
            return array('ok' => false, 'action' => 'lunar', 'error' => '农历日期超出可换算范围（1900–2099）');
        }
        $sol = gmdate('Y-m-d', $ts);
        $out = array('ok' => true, 'action' => 'lunar', 'mode' => 'lunar2solar',
            'lunar_text' => lunar_text($ly, $lm, $ld, false), 'solar' => $sol);
        $out['festival'] = extra_lunar_festival($ts);
        return $out;
    }

    /* 正向：公历 → 农历 + 节日 */
    $ds = isset($params['date']) ? trim((string)$params['date']) : '';
    if ($ds === '' || strtolower($ds) === 'today' || $ds === '今天') { $ds = $now->format('Y-m-d'); }
    $ds = str_replace(array('/', '.', '年', '月'), '-', $ds);
    $ds = rtrim($ds, '日-');
    $p = array_values(array_filter(explode('-', $ds), function ($x) { return $x !== ''; }));
    if (!$p) { return array('ok' => false, 'action' => 'lunar', 'error' => '无法识别日期'); }
    $y = (int)$p[0];
    $mo = isset($p[1]) ? (int)$p[1] : 1;
    $da = isset($p[2]) ? (int)$p[2] : 1;
    if (!checkdate($mo, $da, $y)) {
        return array('ok' => false, 'action' => 'lunar', 'error' => '日期不存在：' . $ds, 'hint' => '格式 YYYY-MM-DD。');
    }
    $l = lunar_from_solar($y, $mo, $da);
    if (!$l) { return array('ok' => false, 'action' => 'lunar', 'error' => '该日期超出可换算范围（1900–2099）'); }
    $week = array('日', '一', '二', '三', '四', '五', '六');
    $ts = gmmktime(0, 0, 0, $mo, $da, $y);
    return array('ok' => true, 'action' => 'lunar', 'mode' => 'solar2lunar',
        'solar' => sprintf('%04d-%02d-%02d', $y, $mo, $da),
        'weekday' => '星期' . $week[(int)gmdate('w', $ts)],
        'lunar' => array('year' => $l['y'], 'month' => $l['m'], 'day' => $l['d'], 'leap' => (bool)$l['leap']),
        'lunar_text' => lunar_text($l['y'], $l['m'], $l['d'], (bool)$l['leap']),
        'ganzhi' => lunar_ganzhi($l['y']), 'zodiac' => lunar_zodiac($l['y']),
        'festival' => extra_lunar_festival($ts));
}

/** 公历时间戳 → 站点节日（key/name），无则 null */
function extra_lunar_festival(int $ts)
{
    if (!function_exists('festival_key_for_date')) { return null; }
    try {
        $k = festival_key_for_date((int)gmdate('Y', $ts), (int)gmdate('n', $ts), (int)gmdate('j', $ts));
        if ($k === '') { return null; }
        $cat = festival_catalog();
        return array('key' => $k, 'name' => isset($cat[$k]['name']) ? (string)$cat[$k]['name'] : $k);
    } catch (Throwable $e) { return null; }
}

/** 公历 → 农历（返回 array(y,m,d,leap) 或空数组） */
function lunar_from_solar(int $y, int $m, int $d): array
{
    if ($y < LUNAR_FIRST_YEAR || $y > LUNAR_LAST_YEAR || !checkdate($m, $d, $y)) { return array(); }
    $base = gmmktime(0, 0, 0, 1, 31, 1900);
    $offset = (int)round((gmmktime(0, 0, 0, $m, $d, $y) - $base) / 86400);
    if ($offset < 0) { return array(); }

    $ly = LUNAR_FIRST_YEAR;
    while ($ly <= LUNAR_LAST_YEAR) {
        $yd = lunar_year_days($ly);
        if ($offset < $yd) { break; }
        $offset -= $yd;
        $ly++;
    }
    if ($ly > LUNAR_LAST_YEAR) { return array(); }

    $leap = lunar_leap_month($ly);
    $lm = 1; $isLeap = false;
    for ($i = 0; $i < 14; $i++) {
        $md = $isLeap ? lunar_leap_days($ly) : lunar_month_days($ly, $lm);
        if ($offset < $md) { break; }
        $offset -= $md;
        if ($isLeap) { $isLeap = false; $lm++; }
        elseif ($lm === $leap && $leap > 0) { $isLeap = true; }
        else { $lm++; }
        if ($lm > 12) { break; }
    }
    return array('y' => $ly, 'm' => $lm, 'd' => $offset + 1, 'leap' => $isLeap);
}

function lunar_ganzhi(int $y): string
{
    $gan = array('甲', '乙', '丙', '丁', '戊', '己', '庚', '辛', '壬', '癸');
    $zhi = array('子', '丑', '寅', '卯', '辰', '巳', '午', '未', '申', '酉', '戌', '亥');
    $i = (($y - 4) % 10 + 10) % 10;
    $j = (($y - 4) % 12 + 12) % 12;
    return $gan[$i] . $zhi[$j];
}

function lunar_zodiac(int $y): string
{
    $a = array('鼠', '牛', '虎', '兔', '龙', '蛇', '马', '羊', '猴', '鸡', '狗', '猪');
    return $a[(($y - 4) % 12 + 12) % 12];
}

function lunar_month_text(int $m): string
{
    $a = array(1 => '正月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '冬月', '腊月');
    return isset($a[$m]) ? $a[$m] : ($m . '月');
}

function lunar_day_text(int $d): string
{
    if ($d === 10) { return '初十'; }
    if ($d === 20) { return '二十'; }
    if ($d === 30) { return '三十'; }
    $tens = array(0 => '初', 1 => '十', 2 => '廿');
    $nums = array('', '一', '二', '三', '四', '五', '六', '七', '八', '九');
    $t = intdiv($d, 10);
    return $tens[$t] . $nums[$d % 10];
}

function lunar_text(int $y, int $m, int $d, bool $leap): string
{
    return lunar_ganzhi($y) . '年' . ($leap ? '闰' : '') . lunar_month_text($m) . lunar_day_text($d);
}

/* ============================================================
 * user：查用户公开资料（隐私最小化）
 * ============================================================ */
function extra_user(array $params): array
{
    $name = isset($params['name']) ? trim((string)$params['name'])
          : (isset($params['username']) ? trim((string)$params['username'])
          : (isset($params['user']) ? trim((string)$params['user']) : ''));
    $id = isset($params['id']) ? (int)$params['id'] : 0;
    if ($name === '' && $id <= 0) {
        return array('ok' => false, 'action' => 'user', 'error' => '缺少 name 或 id',
            'hint' => '例如 {"action":"user","name":"某某"}。');
    }
    try {
        if ($name !== '') {
            $row = db_one('SELECT id, username, role, registered_at FROM users WHERE username_norm = ? OR username = ? LIMIT 1',
                array(norm_username($name), $name));
        } else {
            $row = db_one('SELECT id, username, role, registered_at FROM users WHERE id = ? LIMIT 1', array($id));
        }
    } catch (Throwable $e) {
        return array('ok' => false, 'action' => 'user', 'error' => '用户查询失败');
    }
    if ($row === null) { return array('ok' => false, 'action' => 'user', 'error' => '没有找到该用户'); }

    $roleMap = array('user' => '普通用户', 'admin' => '管理员', 'subadmin' => '副管理员');
    $uname = (string)$row['username'];
    $works = 0;
    try { $works = (int)db_val('SELECT COUNT(*) FROM works WHERE is_hidden = 0 AND author_name = ?', array($uname)); }
    catch (Throwable $e) { $works = 0; }

    return array('ok' => true, 'action' => 'user', 'user' => array(
        'name'   => $uname,
        'role'   => isset($roleMap[(string)$row['role']]) ? $roleMap[(string)$row['role']] : (string)$row['role'],
        'joined' => substr((string)$row['registered_at'], 0, 7),
        'works'  => $works,
    ));
}

/* ============================================================
 * 结果文本 / 摘要（由 works_tool.php 转发）
 * ============================================================ */
function works_tool_extra_result_text(array $r): string
{
    if (empty($r['ok'])) {
        $t = "<tool_result>\n执行失败\naction=" . (isset($r['action']) ? (string)$r['action'] : '') . "\n"
           . "error=" . (isset($r['error']) ? (string)$r['error'] : '未知错误') . "\n";
        if (!empty($r['hint'])) { $t .= "hint=" . (string)$r['hint'] . "\n"; }
        $t .= "请据此修正参数后重试；两次仍失败就如实说明，不得编造。\n</tool_result>";
        return $t;
    }
    $a = (string)$r['action'];
    $head = 'action=' . $a;
    $lines = array();

    if ($a === 'stats') {
        $head .= ' 作品数=' . $r['works'] . ' 用户数=' . $r['users'] . ' 评论数=' . $r['comments']
               . ' 对话消息数=' . $r['messages'] . ' 今日新增作品=' . $r['today_works'];
    } elseif ($a === 'categories') {
        $head .= ' 共' . $r['count'] . '类 / 收录' . $r['total'] . '件';
        foreach ((array)$r['items'] as $it) { $lines[] = $it['name'] . '(' . $it['key'] . ')：' . $it['count'] . ' 件'; }
    } elseif ($a === 'announce') {
        $head .= ' 公告数=' . $r['count'];
        foreach ((array)$r['items'] as $it) {
            $lines[] = ($it['updated_at'] !== '' ? '[' . $it['updated_at'] . '] ' : '') . str_replace("\n", ' ', $it['content']);
        }
    } elseif ($a === 'calc') {
        $head .= ' expr=' . $r['expr'] . ' 结果=' . $r['result'];
        if (!empty($r['fraction'])) { $head .= ' （约等于 ' . $r['fraction'] . '）'; }
    } elseif ($a === 'random') {
        $head .= ' 随机' . ($r['category'] !== '' ? works_tool_cat_name($r['category']) : '') . '作品 结果数=' . $r['count'];
        foreach ((array)$r['works'] as $w) {
            $lines[] = '《' . $w['title'] . '》作者:' . $w['author_name'] . ' 分类:' . works_tool_cat_name((string)$w['category'])
                     . ' 总分:' . $w['total_score'] . '(' . $w['rating'] . ') 社区ID:' . $w['community_id']
                     . (empty($w['link']) ? '' : ' 链接:' . $w['link']);
        }
    } elseif ($a === 'lunar') {
        if ($r['mode'] === 'solar2lunar') {
            $head .= ' 公历=' . $r['solar'] . '(' . $r['weekday'] . ') 农历=' . $r['lunar_text']
                   . ' 干支=' . $r['ganzhi'] . ' 生肖=' . $r['zodiac'];
            if (!empty($r['festival'])) { $head .= ' 站点节日=' . $r['festival']['name']; }
            $lines[] = '农历月日=' . $r['lunar']['month'] . '月' . $r['lunar']['day'] . '日' . ($r['lunar']['leap'] ? '（闰月）' : '');
        } else {
            $head .= ' 农历=' . $r['lunar_text'] . ' 对应公历=' . $r['solar'];
            if (!empty($r['festival'])) { $head .= ' 站点节日=' . $r['festival']['name']; }
        }
    } elseif ($a === 'user') {
        $u = $r['user'];
        $head .= ' 用户=' . $u['name'] . ' 身份=' . $u['role'] . ' 注册于=' . $u['joined'] . ' 收录作品数=' . $u['works'];
    }

    return "<tool_result>\n" . $head . ($lines ? "\n" . implode("\n", $lines) : '') . "\n</tool_result>";
}

function works_tool_extra_summary(array $r): string
{
    if (empty($r['ok'])) { return '失败：' . (isset($r['error']) ? (string)$r['error'] : '未知错误'); }
    $a = (string)$r['action'];
    if ($a === 'stats')      { return '站点共 ' . $r['works'] . ' 件作品 / ' . $r['users'] . ' 位用户'; }
    if ($a === 'categories') { return '分类统计：' . $r['count'] . ' 类 / ' . $r['total'] . ' 件'; }
    if ($a === 'announce')   { return '读取公告 ' . $r['count'] . ' 条'; }
    if ($a === 'calc')       { return $r['expr'] . ' = ' . $r['result'] . (empty($r['fraction']) ? '' : '（' . $r['fraction'] . '）'); }
    if ($a === 'random')     { return '随机推荐 ' . $r['count'] . ' 件作品'; }
    if ($a === 'lunar') {
        return $r['mode'] === 'solar2lunar'
            ? $r['solar'] . ' → ' . $r['lunar_text']
            : $r['lunar_text'] . ' → ' . $r['solar'];
    }
    if ($a === 'user') { return $r['user']['name'] . '：' . $r['user']['role'] . '，' . $r['user']['works'] . ' 件作品'; }
    return '已完成';
}

/** 工具名 → 中文标签（前端 TOOL_META 外的后端副本，供世界对话下发） */
function works_tool_label(string $a): string
{
    $m = array(
        'search' => '检索站内作品', 'get' => '读取作品详情', 'rank' => '查询榜单排名', 'comments' => '读取评论区',
        'web_open' => '联网读取网页', 'weather' => '查询天气', 'time' => '获取当前时间', 'docs' => '查阅站内文档',
        'stats' => '查看站点概览', 'categories' => '统计作品分类', 'announce' => '读取站点公告',
        'calc' => '精确计算', 'random' => '随机推荐作品', 'lunar' => '查询农历节日', 'user' => '查询用户资料',
    );
    return isset($m[$a]) ? $m[$a] : $a;
}

function works_tool_labels(array $tools): array
{
    $out = array();
    foreach ($tools as $t) {
        $a = isset($t['action']) ? (string)$t['action'] : '';
        if ($a !== '') { $out[] = works_tool_label($a); }
    }
    return array_values(array_unique($out));
}
