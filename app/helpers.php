<?php
/**
 * 通用工具函数
 */
declare(strict_types=1);

/* ============================================================
 * 配置访问
 * ============================================================ */
function cfg(string $path, $default = null)
{
    $node = isset($GLOBALS['APP_CONFIG']) ? $GLOBALS['APP_CONFIG'] : array();
    foreach (explode('.', $path) as $seg) {
        if (!is_array($node) || !array_key_exists($seg, $node)) { return $default; }
        $node = $node[$seg];
    }
    return $node;
}

/* ============================================================
 * 响应
 * ============================================================ */
function is_api_request(): bool
{
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    return strpos($uri, '/api/') !== false;
}

function json_out(int $code, string $msg, $data = null)
{
    /* 状态码必须与业务码一致。此处原先不设置状态码，导致每一次失败都以 HTTP 200 返回：
       ① DevTools 里看到「200 却显示请求失败」，网络面板完全无法用来排查；
       ② 前端 core.js 里凭 resp.status === 401 做「游客令牌静默续期并重放」的分支成了死代码，
          令牌一过期，游客只会看到报错，而不会自动恢复。
       实测确认：Apache 不会用 ErrorDocument 覆盖 PHP 自己输出的响应体，因此可以安全设置。 */
    if (!headers_sent() && $code > 0) { http_response_code($code); }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    echo json_encode(array('code' => $code, 'msg' => $msg, 'data' => $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/** 成功响应简写 */
function ok($data = null, string $msg = 'ok') { json_out(0, $msg, $data); }
/** 失败响应简写 */
function fail(int $code, string $msg)   { json_out($code, $msg); }

/** HTML 转义（全局输出红线） */
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 文件名净化：任何来自用户或外部的文件名，在落盘或展示前都必须经过它。
 * 去路径、去控制字符、只保留安全字符，并强制白名单扩展名。
 * 传入 $allowExt 时，扩展名不在白名单内一律丢弃。
 */
function safe_filename(string $name, array $allowExt = array()): string
{
    $name = str_replace(array("\0", "\r", "\n", "\t"), '', $name);
    $name = str_replace('\\', '/', $name);
    $name = basename($name);                                   // 去掉任何目录部分
    $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (!empty($allowExt) && !in_array($ext, $allowExt, true)) { $ext = ''; }

    $base = (string)pathinfo($name, PATHINFO_FILENAME);
    $base = (string)preg_replace('/[^A-Za-z0-9_\-\.\x{4e00}-\x{9fa5}]+/u', '_', $base);
    $base = trim($base, '._-');
    if ($base === '') { $base = 'file'; }
    $base = mb_substr($base, 0, 80, 'UTF-8');
    return $ext === '' ? $base : ($base . '.' . $ext);
}

/* ============================================================
 * 请求参数
 * ============================================================ */
function req_json(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    $cache = is_array($data) ? $data : array();
    return $cache;
}

/** 取参（JSON 或 POST） */
function param(string $key, $default = null)
{
    $j = req_json();
    if (array_key_exists($key, $j)) { return $j[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    if (isset($_GET[$key])) { return $_GET[$key]; }
    return $default;
}

function param_str(string $key, string $default = ''): string
{
    $v = param($key, $default);
    return is_scalar($v) ? (string)$v : $default;
}

function param_int(string $key, int $default = 0): int
{
    $v = param($key, $default);
    return is_numeric($v) ? (int)$v : $default;
}

/* ============================================================
 * 字符规整（信任边界：全部在后端执行）
 * ============================================================ */

/** 去掉不可见字符：Cc 控制符、零宽空格/连接符、BOM、软连字符 */
function strip_invisible(string $s): string
{
    static $map = null;
    if ($map === null) {
        $map = array(
            "\xE2\x80\x8B" => '', // U+200B ZWSP
            "\xE2\x80\x8C" => '', // U+200C ZWNJ
            "\xE2\x80\x8D" => '', // U+200D ZWJ
            "\xE2\x80\x8E" => '', // U+200E LRM
            "\xE2\x80\x8F" => '', // U+200F RLM
            "\xE2\x80\xA8" => '', // U+2028
            "\xE2\x80\xA9" => '', // U+2029
            "\xEF\xBB\xBF" => '', // U+FEFF BOM
            "\xC2\xAD"     => '', // U+00AD 软连字符
            "\xE2\x80\x90" => '', "\xE2\x80\x91" => '', "\xE2\x80\x92" => '',
            "\xE2\x80\x93" => '-', "\xE2\x80\x94" => '-',
        );
    }
    $s = strtr($s, $map);
    // 剔除其余 C0/C1 控制符（保留 \t\n\r 可选，这里一并剔除以统一）
    $r = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return $r === null ? $s : $r;
}

/** 用户名唯一性规整值：NFC → 去所有空白、不可见、不可打印字符 */
function norm_username(string $s): string
{
    $s = nfc_normalize($s);
    $s = strip_invisible($s);
    $s = (string)preg_replace('/\s+/u', '', $s);          // 空白字符（含全角空格）
    $s = (string)preg_replace('/\p{Z}/u', '', $s);        // Unicode 分隔符
    $s = (string)preg_replace('/\p{C}/u', '', $s);        // 其余控制/格式/私用字符
    return mb_strtolower($s, 'UTF-8');
}

/** 保留名黑名单（含规整后的比较） */
function is_reserved_name(string $normName): bool
{
    static $black = array('admin','administrator','root','official','system','moderator','官方','管理','管理员','客服','版主','站长','kimi','kimi官方');
    return in_array($normName, $black, true);
}

/* ============================================================
 * IP
 * ============================================================ */
/**
 * 取客户端 IP。
 * ------------------------------------------------------------
 * 默认只信 REMOTE_ADDR。转发头（CF-Connecting-IP / X-Forwarded-For / X-Real-IP）
 * 全部由客户端自由伪造，直接采信等于把限速与封禁交给攻击者——每换一个请求头
 * 就是一个新身份，登录防爆破与 IP 封禁同时失效。
 * 站点确实在 CDN / 反向代理之后时，把代理网段写进 security.trusted_proxies，
 * 此时才解析转发链，且从「最右」取第一个非受信地址：整条链只有最右端可信，
 * 左侧全部可伪造，取左起第一个等于没做校验。
 */
function client_ip(): string
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $trusted = cfg('security.trusted_proxies', array());
    if (!is_array($trusted) || !$trusted || !ip_in_ranges($remote, $trusted)) {
        return $remote !== '' ? $remote : '0.0.0.0';
    }
    $chain = array();
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP') as $k) {
        if (empty($_SERVER[$k])) { continue; }
        foreach (explode(',', (string)$_SERVER[$k]) as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) { $chain[] = $ip; }
        }
        if ($chain) { break; }   // 只认最先出现的那个头，避免多源头拼出假链
    }
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        if (!ip_in_ranges($chain[$i], $trusted)) { return $chain[$i]; }
    }
    return $remote !== '' ? $remote : '0.0.0.0';
}

/** IP 是否落在任一网段内；支持单地址与 CIDR（IPv4 / IPv6 均可） */
function ip_in_ranges(string $ip, array $ranges): bool
{
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) { return false; }
    $ipBin = inet_pton($ip);
    if ($ipBin === false) { return false; }
    foreach ($ranges as $r) {
        $r = trim((string)$r);
        if ($r === '') { continue; }
        if (strpos($r, '/') === false) {
            if ($r === $ip) { return true; }
            continue;
        }
        list($net, $bits) = explode('/', $r, 2);
        $netBin = @inet_pton(trim($net));
        if ($netBin === false || strlen($netBin) !== strlen($ipBin)) { continue; }
        $bits = (int)$bits;
        $len = strlen($netBin) * 8;
        if ($bits < 0 || $bits > $len) { continue; }
        $full = intdiv($bits, 8);
        if ($full > 0 && substr($netBin, 0, $full) !== substr($ipBin, 0, $full)) { continue; }
        $rest = $bits % 8;
        if ($rest === 0) { return true; }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        if ((substr($netBin, $full, 1) & $mask) === (substr($ipBin, $full, 1) & $mask)) { return true; }
    }
    return false;
}

/** 脱敏：保留前 3 字符 + 后 2 字符，中间打码 */
function mask_ip(string $ip): string
{
    $len = mb_strlen($ip, 'UTF-8');
    if ($len <= 5) { return str_repeat('*', $len); }
    return mb_substr($ip, 0, 3, 'UTF-8') . str_repeat('*', $len - 5) . mb_substr($ip, -2, null, 'UTF-8');
}

function ip_hash(string $ip): string { return hash('sha256', $ip); }

/* ============================================================
 * CSRF
 * ============================================================ */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_verify()
{
    $sent = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : param_str('csrf_token');
    $have = isset($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
    if ($have === '' || $sent === '' || !hash_equals($have, (string)$sent)) {
        fail(419, '页面令牌失效，请刷新后重试');
    }
}

/* ============================================================
 * 时间 / 日志 / 设置
 * ============================================================ */
function now_utc(): string { return gmdate('Y-m-d H:i:s'); }

function app_log(string $msg)
{
    $line = '[' . now_utc() . '] ' . $msg . "\n";
    @file_put_contents(APP_ROOT . '/storage/logs/app.log', $line, FILE_APPEND);
}

function setting_get(string $k, $default = '')
{
    try {
        $v = db_val('SELECT v FROM settings WHERE k = ?', array($k));
        return $v === null ? $default : $v;
    } catch (Exception $e) {
        return $default;
    }
}

function setting_set(string $k, $v)
{
    $sql = 'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)';
    db_exec($sql, array($k, (string)$v));
}

/* ============================================================
 * 用户头像：仿 GitHub identicon（4×4 = 16 像素，左右镜像）
 * 后端确定性 SVG 生成，无需存储
 * ============================================================ */
function identicon_svg(string $seed, int $size = 80): string
{
    $hash = md5($seed);
    $hue = hexdec(substr($hash, 0, 2)) / 255 * 360;
    $hue2 = fmod($hue + 40, 360);

    $cells = array();
    $bits = hexdec(substr($hash, 2, 8));  // 32 位 → 16 格（左半 2×4 = 8 格）
    for ($i = 0; $i < 8; $i++) {
        $cells[$i] = (($bits >> $i) & 1) === 1;
    }
    // 左 2 列，镜像到右 2 列
    $grid = array();
    for ($y = 0; $y < 4; $y++) {
        for ($x = 0; $x < 4; $x++) {
            $srcCol = $x < 2 ? $x : 3 - $x;
            $grid[$y][$x] = $cells[$y * 2 + $srcCol];
        }
    }

    $cellPx = $size / 4;
    $c1 = 'hsl(' . round($hue) . ',68%,52%)';
    $c2 = 'hsl(' . round($hue2) . ',72%,62%)';

    $svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="用户头像">';
    $svg .= '<rect width="' . $size . '" height="' . $size . '" fill="#eceef2"/>';
    for ($y = 0; $y < 4; $y++) {
        for ($x = 0; $x < 4; $x++) {
            if ($grid[$y][$x]) {
                $fill = (($x + $y) % 2 === 0) ? $c1 : $c2;
                $svg .= '<rect x="' . ($x * $cellPx) . '" y="' . ($y * $cellPx) . '" width="' . $cellPx . '" height="' . $cellPx . '" fill="' . $fill . '"/>';
            }
        }
    }
    $svg .= '</svg>';
    return $svg;
}

/** 纯数据版 identicon（供前端或列表），返回 data URI */
function identicon_data_uri(string $seed, int $size = 80): string
{
    /* 同一请求内相同用户名 + 尺寸复用：消息/评论列表逐条生成会明显拖慢响应 */
    static $cache = array();
    $ck = $seed . '|' . $size;
    if (isset($cache[$ck])) { return $cache[$ck]; }
    $uri = 'data:image/svg+xml;base64,' . base64_encode(identicon_svg($seed, $size));
    $cache[$ck] = $uri;
    return $uri;
}

/* ============================================================
 * 其它
 * ============================================================ */
/* ============================================================
 * 操作频次闸门（滑动窗口）
 * ------------------------------------------------------------
 * cooldown_guard() 只做「窗口内最多 N 次」的滑动计数，**正常使用不受任何间隔限制**：
 * 只有超阈值（默认每分钟 40 次受限操作）才会锁定一段时间。
 * 单类操作的更细额度由各接口自己的 rate_limit() 负责（如发言 60 秒 15 条）。
 * 主管理员不受限制；副管理员与普通用户同等待遇。
 *
 * 注意：前端 core.js 的 CD_LIMITS 必须与此处口径同构，否则会出现「前端误拦」——
 * 前端若按固定间隔拦截，会在网络面板表现为「根本没发请求」。
 * ============================================================ */
define('ACTION_COOLDOWN_SEC', 60);   // 兼容保留（已不再作为每次操作的间隔）
define('ACTION_RATE_PER_MIN', 40);   // 频次阈值：每分钟最多 40 次受限操作（滑动窗口）
define('ACTION_LOCK_SEC', 60);       // 超出阈值后的锁定时间：60 秒

/** 各操作的频次阈值；如需单独调整某类操作，在 config.php 增加
 *  'cooldown' => array('ai' => 10)  即可，未配置的走全局默认。
 *  注意：前端 core.js 的 CD_LIMITS 需同步，否则会出现「前端误拦」。 */
function cooldown_seconds(string $action): int
{
    $map = cfg('cooldown', array());
    return (is_array($map) && isset($map[$action])) ? (int)$map[$action] : ACTION_COOLDOWN_SEC;
}

/**
 * 全局操作频次闸门：滑动窗口内最多 N 次（默认 60 秒 / 50 次）。
 * 与 cooldown_guard 的「同类操作间隔」互补：后者防同一操作刷屏，
 * 前者兜底防「多种操作轮换」绕过间隔限制。
 * 存储不可用时不阻断业务（共享主机偶发不可写时应保可用性）。
 */
function op_window_guard(string $who, int $max = 0, int $window = 60, int $lock = 0)
{
    if ($max <= 0) { $max = (int)cfg('rate_limits.per_minute', ACTION_RATE_PER_MIN); }
    if ($lock <= 0) { $lock = ACTION_LOCK_SEC; }
    $dir = APP_ROOT . '/storage/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $f = @fopen($dir . '/op_' . preg_replace('/[^a-z0-9_]/i', '', $who) . '.txt', 'c+');
    if ($f === false) { return; }
    @flock($f, LOCK_EX);
    rewind($f);
    $now = time();
    $ts = array();
    foreach (explode(',', (string)stream_get_contents($f)) as $v) {
        $v = (int)$v;
        if ($v > $now - $window) { $ts[] = $v; }
    }
    if (count($ts) >= $max) {
        /* 超过阈值 → 锁定一段时间（默认 60 秒），并清空窗口计数，
           否则解锁瞬间又立刻超限，等于永久锁死。 */
        op_lock_set($who, $lock);
        @ftruncate($f, 0);
        $left = $lock;
        @flock($f, LOCK_UN); @fclose($f);
        if (!headers_sent()) { http_response_code(429); header('Retry-After: ' . $left); }
        fail(429, '操作过于频繁：每分钟超过 ' . $max . ' 次，已被限制 ' . $left . ' 秒，请稍后再试');
    }
    $ts[] = $now;
    @ftruncate($f, 0); rewind($f); @fwrite($f, implode(',', $ts)); @fflush($f);
    @flock($f, LOCK_UN); @fclose($f);
}

function cooldown_file(string $bucket): string
{
    $dir = APP_ROOT . '/storage/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir . '/cd_' . preg_replace('/[^a-z0-9_]/i', '', $bucket) . '.txt';
}

/** 剩余冷却秒数（0 = 可立即操作） */
function cooldown_remaining(string $bucket, int $seconds = ACTION_COOLDOWN_SEC): int
{
    $f = cooldown_file($bucket);
    if (!is_file($f)) { return 0; }
    $last = (int)@file_get_contents($f);
    if ($last <= 0) { return 0; }
    $left = $seconds - (time() - $last);
    return $left > 0 ? $left : 0;
}

/** 锁定文件：超过每分钟阈值后写入解锁时间戳 */
function op_lock_file(string $who): string
{
    return APP_ROOT . '/storage/cache/lock_' . preg_replace('/[^a-z0-9_]/i', '', $who) . '.txt';
}

function op_lock_set(string $who, int $seconds)
{
    $dir = APP_ROOT . '/storage/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents(op_lock_file($who), (string)(time() + $seconds), LOCK_EX);
}

/** 剩余锁定秒数；已到期则顺手清理 */
function op_lock_remaining(string $who): int
{
    $f = op_lock_file($who);
    if (!is_file($f)) { return 0; }
    $until = (int)@file_get_contents($f);
    $left = $until - time();
    if ($left <= 0) { @unlink($f); return 0; }
    return $left;
}

/**
 * 内容规整指纹：与用户名唯一性使用**完全相同**的规整逻辑
 * （NFC → 去不可见字符 → 去空白 → 去控制/格式字符 → 转小写），再做 sha256。
 * 于是「同一句话中间插了空格或零宽字符」也会被认成同一条，
 * 与用户名「去空格后不能重合」的判定口径保持一致。
 */
function dup_content_norm(string $content): string
{
    return hash('sha256', norm_username($content));
}

/**
 * 防重复提交：同一用户提交过**完全相同**（按上述口径规整后）的内容即判定重复。
 * 不限时间窗口——只要历史上发过，就不再允许重复发。
 *
 * 关键：指纹列可能尚未就绪（迁移未完成或被跳过）。
 * 此时退回按原文比对——判定精度略降，但接口绝不能因此 500。
 */
function dup_guard(string $table, string $uid, string $content): bool
{
    $t = str_replace('`', '', $table);
    if ($t === '' || $uid === '') { return false; }

    if (!has_content_norm($t)) {
        $row = db_val(
            'SELECT id FROM `' . $t . '` WHERE user_id = ? AND content = ? LIMIT 1',
            array($uid, $content)
        );
        return $row !== null;
    }

    $norm = dup_content_norm($content);
    $row = db_val(
        'SELECT id FROM `' . $t . '` WHERE user_id = ? AND content_norm = ? LIMIT 1',
        array($uid, $norm)
    );
    return $row !== null;
}

/**
 * 列是否存在（带缓存）。
 * 迁移新增的列可能尚未就绪，凡是会用到它们的语句都应先问一句，
 * 拿不到就走不影响功能的退路——绝不因为一个列把整站打成 500。
 */
function col_ok(string $table, string $column): bool
{
    static $cache = array();
    $k = $table . '.' . $column;
    if (!array_key_exists($k, $cache)) {
        $cache[$k] = column_exists($table, $column);
    }
    return $cache[$k];
}

/** content_norm 列是否可用 */
function has_content_norm(string $table): bool
{
    return col_ok($table, 'content_norm');
}

/**
 * 写入一条带内容指纹的记录（评论 / 反馈共用）。
 * 指纹列就绪则一并写入，否则跳过该列——列缺失不该让提交失败。
 */
function db_insert_norm(string $table, array $cols, array $vals, string $content)
{
    $t = str_replace('`', '', $table);
    if (has_content_norm($t)) {
        $cols[] = 'content_norm';
        $vals[] = dup_content_norm($content);
    }
    $ph = array_fill(0, count($cols), '?');
    return db_insert(
        'INSERT INTO `' . $t . '` (`' . implode('`, `', $cols) . '`, created_at) VALUES ('
        . implode(', ', $ph) . ', UTC_TIMESTAMP())',
        $vals
    );
}

/** 冷却时长的可读描述（90 → "1.5 分钟"） */
function cooldown_human(int $seconds): string
{
    if ($seconds % 60 === 0) { return ($seconds / 60) . ' 分钟'; }
    return round($seconds / 60, 1) . ' 分钟';
}

/** 记录一次成功操作（开始冷却计时） */
function cooldown_touch(string $bucket)
{
    @file_put_contents(cooldown_file($bucket), (string)time(), LOCK_EX);
}

/** 当前操作者的冷却标识（登录用户按 uid，其余按 IP 哈希） */
function cooldown_who(): string
{
    $id = current_identity();
    if ($id !== null && (int)$id['uid'] > 0 && $id['role'] !== 'guest') {
        return 'u' . (int)$id['uid'];
    }
    return 'ip' . substr(ip_hash(client_ip()), 0, 12);
}

/**
 * 冷却闸门：检查通过则**立即计时**并返回 true；冷却中直接以 429 结束请求。
 * 注意：必须放在参数校验之后、真正写库之前，避免校验失败也消耗冷却。
 * 主管理员直接放行。
 */
function cooldown_guard(string $action, int $seconds = 0)
{
    $id = current_identity();
    if ($id !== null && $id['role'] === 'admin') { return true; }

    $who = cooldown_who();

    /* 已被锁定：直接拒绝 */
    $left = op_lock_remaining($who);
    if ($left > 0) {
        if (!headers_sent()) { http_response_code(429); header('Retry-After: ' . $left); }
        fail(429, '操作过于频繁，已被限制 ' . $left . ' 秒，请稍后再试');
    }

    /* 按分钟计数；只有超过阈值才会锁定，正常使用不受任何间隔限制 */
    op_window_guard($who);
    return true;
}

/* ============================================================
 * 轻量文件缓存（降低共享主机并发压力）
 * ============================================================ */
function cache_dir(): string
{
    $d = APP_ROOT . '/storage/cache';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}

function cache_get(string $key, int $ttl)
{
    $f = cache_dir() . '/' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json';
    if (!is_file($f)) { return null; }
    if (time() - (int)@filemtime($f) > $ttl) { return null; }
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') { return null; }
    $v = json_decode($raw, true);
    return is_array($v) ? $v : null;
}

function cache_set(string $key, array $val, int $ttl)
{
    $f = cache_dir() . '/' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json';
    @file_put_contents($f, json_encode($val, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return true;
}

/**
 * 存储清理：共享主机没有 cron，改为按请求抽样触发。
 * ------------------------------------------------------------
 * cache 目录里有两类文件会持续累积：
 *   rl_*   限流计数器，按「分钟」新建文件，一天最多 1440 个
 *   op_*   操作窗口计数，按用户 / IP 新建
 * 日志同样是只增不减。免费主机的空间与 inode 都有上限，
 * 攒满后会直接写不进去，表现为全站报错——所以必须自清理。
 * 约 2% 的请求执行一次，单次开销可控。
 */
function storage_gc(bool $force = false): int
{
    if (!$force && mt_rand(1, 100) > 2) { return 0; }

    $now = time();
    $n = 0;
    $dir = APP_ROOT . '/storage/cache';

    /* 限流计数文件：只服务于「每分钟」窗口，留 2 小时已足够 */
    foreach ((array)@glob($dir . '/rl_*.txt') as $f) {
        if ($now - (int)@filemtime($f) > 7200) { if (@unlink($f)) { $n++; } }
    }
    /* 操作窗口文件：只服务于 60 秒窗口 */
    foreach ((array)@glob($dir . '/op_*.txt') as $f) {
        if ($now - (int)@filemtime($f) > 1800) { if (@unlink($f)) { $n++; } }
    }
    /* 锁定文件：内容即解锁时间戳，已过期即可删 */
    foreach ((array)@glob($dir . '/lock_*.txt') as $f) {
        $until = (int)@file_get_contents($f);
        if ($until > 0 && $until <= $now) { if (@unlink($f)) { $n++; } }
    }
    /* 通用缓存：缓存项自带 ttl，超过一天一律清掉，避免失效文件堆积 */
    foreach ((array)@glob($dir . '/*.json') as $f) {
        if ($now - (int)@filemtime($f) > 86400) { if (@unlink($f)) { $n++; } }
    }

    /* 日志：超过 512KB 时只保留尾部 200KB，避免无限增长 */
    $log = APP_ROOT . '/storage/logs/app.log';
    if (is_file($log) && (int)@filesize($log) > 524288) {
        $tail = @file_get_contents($log, false, null, -204800);
        if ($tail !== false) { @file_put_contents($log, "[日志已截断，仅保留最近部分]\n" . $tail, LOCK_EX); $n++; }
    }

    /* 世界对话图片：孤儿图与沉底图一并清掉（省存储；消息本身保留） */
    if (function_exists('chat_media_gc')) { $n += chat_media_gc(); }

    return $n;
}

/**
 * AI 对话记录裁剪：每用户保留最近 $keep 条。
 * 历史只用于回放最近 20~40 条，更早的记录没有读取价值，
 * 但表会随使用无限增长，最终拖慢查询。
 */
function ai_trim_history(int $uid, int $keep = 200)
{
    try {
        $count = (int)db_val('SELECT COUNT(*) FROM ai_messages WHERE user_id = ?', array($uid));
        if ($count <= $keep) { return; }
        db_exec('DELETE FROM ai_messages WHERE user_id = ? ORDER BY id ASC LIMIT ' . ($count - $keep), array($uid));
    } catch (Throwable $e) { /* 裁剪失败不影响主流程 */ }
}

function cache_flush()
{
    $d = cache_dir();
    foreach ((array)@glob($d . '/*.json') as $f) { @unlink($f); }
}

/**
 * 站外图片 → 决定「浏览器直连」还是「本站代理」。
 *
 * 为什么默认直连：服务端每代理一张图，都要在本机抓取再输出一次，在免费主机上
 * 会迅速吃光请求数（hits）与流量额度。绝大多数图床（各家公有 CDN）并不校验
 * Referer，浏览器直连既更快也不消耗主机资源。因此这里只对**确知有 Referer
 * 防盗链**的站点走代理，其余一律返回原地址直连；万一某个站点直连失败，
 * 前端会自动回退到本站代理（见 assets/js 的全局 error 捕获），
 * 因此不需要「为了以防万一」对全量图片预先代理。
 */
function img_src(string $u): string
{
    $u = trim($u);
    if ($u === '') { return ''; }
    if (preg_match('~^(?:api/|/|#)~', $u)) { return $u; }
    if (!preg_match('#^https?://#i', $u)) { return $u; }
    $host = strtolower((string)parse_url($u, PHP_URL_HOST));
    if ($host === '') { return $u; }
    return img_needs_proxy($host) ? 'api/img.php?u=' . urlencode($u) : $u;
}

/**
 * 确知有 Referer 防盗链、必须由服务端带 Referer 抓取的域名。
 * 维护原则：**宜短不宜长** —— 每多一个域名，就多一份服务端流量与请求数；
 * 拿不准确切行为的域名不要加进来，交给「直连失败自动回退」兜底即可。
 */
function img_needs_proxy(string $host): bool
{
    static $need = array('hdslb.com', 'bilibili.com', 'b23.tv');
    foreach ($need as $d) {
        if ($host === $d || substr($host, -strlen($d) - 1) === '.' . $d) { return true; }
    }
    return false;
}

/** 作品访问链接：cdnUrl（html_url）优先，回退分享链接 */
function work_link(array $r): string
{
    $h = isset($r['html_url']) ? trim((string)$r['html_url']) : '';
    if ($h !== '') { return $h; }
    return isset($r['share_link']) ? trim((string)$r['share_link']) : '';
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { return true; }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') { return true; }
    return false;
}

/** 本地时区展示（库内 UTC → 配置时区） */
function to_local(string $utc, string $format = 'Y-m-d H:i'): string
{
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(cfg('site.timezone', 'Asia/Shanghai')));
        return $dt->format($format);
    } catch (Exception $e) {
        return $utc;
    }
}

/**
 * 站点本地「今日 / 本月」起点，转成 UTC 字符串（库内全 UTC，需同基准比较）。
 * 必须用本地时区：直接 gmdate('Y-m') 会在月初前 8 小时（UTC+8）仍算上月，
 * 表现为「月初额度没自动重置」。
 */
function local_period_start(string $kind = 'day'): string
{
    try {
        $tz = new DateTimeZone((string)cfg('site.timezone', 'Asia/Shanghai'));
        $d = new DateTime('now', $tz);
        $d->setTime(0, 0, 0);
        if ($kind === 'month') { $d->setDate((int)$d->format('Y'), (int)$d->format('n'), 1); }
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return ($kind === 'month' ? gmdate('Y-m-01') : gmdate('Y-m-d')) . ' 00:00:00';
    }
}

/**
 * 归属地文本拆分为 国家 / 省份 / 城市。
 * 第三方返回格式不固定（"中国 广东省 深圳市" / "广东省深圳市" / "广东 深圳" / 英文国家名），
 * 故用启发式 + 兜底：识别不出国家时归入「其他」，绝不丢数据。
 */
function location_parts(string $loc): array
{
    $loc = trim(preg_replace('/\s+/u', ' ', (string)$loc));
    /* 去掉第三方拼串里的国家/地区代码后缀（「中国[CN]」→「中国」）。
       否则它既不等于「中国」而被过滤掉，又会被兜底逻辑当成省份，出现「省份=中国[CN]」。 */
    $loc = trim((string)preg_replace('/\[[A-Za-z]{2,3}\]/u', '', $loc));
    if ($loc === '') { return array('', '', ''); }

    /* 国家/地区名：只在没有更细层级时才作为兜底使用 */
    $isCountryToken = function ($x) {
        return (bool)preg_match('/^(中国|China|其他|保留地址|未知)$/i', (string)$x);
    };

    $country = ''; $prov = ''; $city = '';

    if (preg_match('/中国|China/i', $loc)) { $country = '中国'; }

    if (preg_match('/([\x{4e00}-\x{9fa5}]{2,7}?(?:省|自治区))/u', $loc, $m)) {
        $prov = $m[1];
    } elseif (preg_match('/(北京|上海|天津|重庆|香港|澳门|台湾)/u', $loc, $m)) {
        $prov = $m[1];
        if ($country === '') { $country = '中国'; }
    } elseif (preg_match('/([\x{4e00}-\x{9fa5}]{2,7}?(?:州|岛))/u', $loc, $m)) {
        $prov = $m[1];
    }

    if (preg_match('/([\x{4e00}-\x{9fa5}]{2,7}?市)/u', $loc, $m)) { $city = $m[1]; }

    /* 兜底：无「省 / 市」字样的写法，按分隔符逐段认领。
       国家名与已被认作省份的那一段都不得再当作城市 ——
       「省份=四川省、城市=四川省」正是这里之前把同一段用了两次导致的。 */
    if ($prov === '' || $city === '') {
        $segs = preg_split('/[\s·\/,，]+/u', $loc, -1, PREG_SPLIT_NO_EMPTY);
        if ($segs === false) { $segs = array(); }
        $segs = array_values(array_filter($segs, function ($x) use ($isCountryToken) {
            return !$isCountryToken($x);
        }));
        if ($prov === '' && isset($segs[0])) { $prov = $segs[0]; }
        if ($city === '') {
            /* 城市应当紧跟在省份之后；找不到省份时跳过首段（那通常是国家名）。
               若只写「跳过首段」，境外地址会把「美国」当成城市。 */
            $pi = ($prov === '') ? false : array_search($prov, $segs, true);
            if ($pi !== false && isset($segs[$pi + 1])) {
                $city = $segs[$pi + 1];
            } else {
                foreach ($segs as $i => $s) {
                    if ($i === 0 || $s === $prov) { continue; }
                    $city = $s;
                    break;
                }
            }
        }
    }

    if ($country === '') { $country = '其他'; }
    return array($country, $prov, $city);
}

/**
 * 计算「不可见评论」集合：被删除的评论**及其全部后代**。
 * 返回 array(id => true)。
 *
 * 删除 = 入回收站：前端完全不显示，内容仍保留在库中供管理员恢复或清空。
 * 子评论随父评论一起隐藏 —— 否则会留下挂在已删评论下的孤儿回复。
 *
 * @param array $rows 该作品的全部评论行，需含 id / parent_id / is_deleted
 */
function comment_hidden_set(array $rows): array
{
    $hidden = array();
    $exists = array();
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $exists[$id] = true;
        if ((int)$r['is_deleted'] === 1) { $hidden[$id] = true; }
    }
    /* 逐层扩散，直到不再增长（深度最多等于链长，40 轮足够） */
    for ($pass = 0; $pass < 40; $pass++) {
        $grew = false;
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $pid = (int)$r['parent_id'];
            if (isset($hidden[$id]) || $pid === 0 || !isset($exists[$pid])) { continue; }
            if (isset($hidden[$pid])) { $hidden[$id] = true; $grew = true; }
        }
        if (!$grew) { break; }
    }
    return $hidden;
}

/**
 * 清洗用户提交的评论屏蔽规则：每行一条，最多 50 条、单条 ≤ 100 字符。
 * 单条长度限制同时是 ReDoS 防护（前端会编译为正则执行）。
 */
function sanitize_block_words(string $raw): string
{
    $out = array();
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim(strip_invisible($line));
        if ($line === '' || mb_strlen($line, 'UTF-8') > 100) { continue; }
        $out[] = $line;
        if (count($out) >= 50) { break; }
    }
    return implode("\n", $out);
}

function clamp01(float $x): float { return max(0.0, min(1.0, $x)); }

/** 请求速率限制（通用，基于文件计数） */
function rate_limit(string $bucket, int $max, int $windowSec): bool
{
    $dir = APP_ROOT . '/storage/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $file = $dir . '/rl_' . preg_replace('/[^a-z0-9_]/i', '', $bucket) . '_' . floor(time() / $windowSec) . '.txt';
    $count = file_exists($file) ? (int)file_get_contents($file) : 0;
    if ($count >= $max) { return false; }
    @file_put_contents($file, (string)($count + 1), LOCK_EX);
    return true;
}
