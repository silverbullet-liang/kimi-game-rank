<?php
/**
 * 用户头像：GitHub 同款 5×5 identicon（三列镜像成五列）。
 *
 * 三条规则：
 *  1. 图案由「用户名 + UID（+ 注册时间 + UID8）」决定，改名字或换账号都会换头像；
 *  2. 已登录用户自动重算——头像是每次请求按当前信息生成的 SVG，不存旧文件；
 *  3. 绝不撞车：注册时把唯一 key 写进用户设置，撞了就依次加注册时间、UID8、随机串。
 *
 * 色阶（1–4）由头像主人自己在设置页调整，存在 settings.avatar_levels。
 * 别人看到的也是主人设定的阶数，而不是浏览者自己的偏好。
 */
declare(strict_types=1);

/** 色阶取值：只认 1–4，其余回落 2 */
function avatar_levels_of($row): int
{
    $v = 0;
    if (is_array($row) && !empty($row['settings'])) {
        $s = json_decode((string)$row['settings'], true);
        if (is_array($s) && isset($s['avatar_levels'])) { $v = (int)$s['avatar_levels']; }
    }
    return ($v >= 1 && $v <= 4) ? $v : 2;
}

/** 候选种子：名字必带，UID / 注册时间 / UID8 依次作为冲突时的追加料 */
function avatar_seed_parts($name, int $uid = 0, string $reg = '', string $uid8 = ''): array
{
    $parts = array('n' => norm_username((string)$name));
    if ($uid > 0) { $parts['u'] = 'u' . $uid; }
    if ($reg !== '') { $parts['t'] = 't' . $reg; }
    if ($uid8 !== '') { $parts['i'] = 'i' . $uid8; }
    return $parts;
}

/** 把一个种子集合算成稳定 key */
function avatar_hash(array $parts): string
{
    $s = implode('|', $parts);
    return hash('sha256', $s);
}

/**
 * 确定某个用户的头像 key。
 *
 * 已写入 settings.avatar_key 的直接沿用（保证同一账号长期不变）；
 * 没有的走「基础 → 加注册时间 → 加 UID8 → 加随机串」逐级检测，
 * 用一次 LIKE 查询确认全站没占用后写入。
 */
function avatar_key_ensure(array $row): string
{
    static $cache = array();
    $uid = (int)($row['id'] ?? 0);
    if ($uid > 0 && isset($cache[$uid])) { return $cache[$uid]; }

    $st = user_settings($row);
    if (!empty($st['avatar_key'])) {
        $cache[$uid] = (string)$st['avatar_key'];
        return $cache[$uid];
    }

    $base = avatar_seed_parts(
        (string)($row['username'] ?? ''),
        $uid,
        (string)($row['registered_at'] ?? ''),
        (string)($row['uid8'] ?? '')
    );
    $name = array_shift($base);
    $candidates = array(
        $name,
        $name . '|' . (isset($base['u']) ? $base['u'] : ''),
        $name . '|' . (isset($base['u']) ? $base['u'] : '') . '|' . (isset($base['t']) ? $base['t'] : ''),
        $name . '|' . (isset($base['u']) ? $base['u'] : '') . '|' . (isset($base['t']) ? $base['t'] : '') . '|' . (isset($base['i']) ? $base['i'] : ''),
    );
    $key = '';
    for ($i = 0; $i < count($candidates); $i++) {
        $cand = trim(preg_replace('/\|+/', '|', $candidates[$i]), '|');
        if ($cand === '') { continue; }
        $hashed = avatar_hash(array($cand));
        $hit = 0;
        try {
            /* 只按候选 key 精确查找：命中才算撞车，不扫全表 */
            $hit = (int)db_val('SELECT id FROM users WHERE settings LIKE ? LIMIT 1', array('%' . $hashed . '%'));
        } catch (Throwable $e) { $hit = 0; }
        if (!$hit) { $key = $hashed; break; }
    }
    if ($key === '') { $key = hash('sha256', implode('|', $base) . '|' . bin2hex(random_bytes(12))); }

    if ($uid > 0) {
        $st['avatar_key'] = $key;
        try { db_exec('UPDATE users SET settings = ? WHERE id = ?', array(json_encode($st, JSON_UNESCAPED_UNICODE), $uid)); }
        catch (Throwable $e) { /* 写不进去也不影响展示：下次仍按同一输入算出同一 key */ }
    }
    $cache[$uid] = $key;
    return $key;
}

/**
 * 只有名字、没有用户行时的头像 key。
 * 若站内有同名账号，借用它的唯一 key，避免「外部作者」和真人头像撞车；
 * 找不到就纯名字 hash（官方 AI / 游客 / 作品作者用）。
 */
function avatar_key_by_name(string $name): string
{
    static $cache = array();
    if (isset($cache[$name])) { return $cache[$name]; }
    $key = '';
    try {
        $row = db_one('SELECT * FROM users WHERE username_norm = ? LIMIT 1', array(norm_username($name)));
        if (is_array($row)) { $key = avatar_key_ensure($row); }
    } catch (Throwable $e) { $key = ''; }
    if ($key === '') { $key = avatar_hash(array(norm_username($name))); }
    $cache[$name] = $key;
    return $key;
}

/** 5×5 网格：sha256 前 15 位 → 左三列，再左右镜像 */
function avatar_grid(string $key): array
{
    $bin = '';
    $hex = substr(hash('sha256', $key), 0, 16);   // 16 hex = 64 bit，够取 15 位
    for ($i = 0; $i < strlen($hex); $i++) { $bin .= str_pad(decbin(hexdec($hex[$i])), 4, '0', STR_PAD_LEFT); }
    $grid = array();
    for ($y = 0; $y < 5; $y++) {
        for ($x = 0; $x < 5; $x++) {
            $src = $x < 3 ? $x : 4 - $x;
            $grid[$y][$x] = $bin[$y * 3 + $src] === '1';
        }
    }
    return $grid;
}

/** 前景色：按用户设定的阶数取 1–4 档，色相由 key 决定 */
function avatar_colours(string $key, int $levels): array
{
    $h = hexdec(substr(hash('sha256', $key), 16, 4)) / 65535 * 360;
    $out = array();
    for ($i = 0; $i < $levels; $i++) {
        $t = $levels === 1 ? 0 : $i / ($levels - 1);
        $out[] = 'hsl(' . round(fmod($h + $t * 26, 360)) . ',' . round(56 + $t * 12) . '%,' . round(44 + $t * 16) . '%)';
    }
    return $out;
}

/** 生成 identicon SVG（viewBox 固定 5×5，size 只控制显示尺寸） */
function avatar_svg(string $key, int $size = 80, int $levels = 2): string
{
    $levels = ($levels >= 1 && $levels <= 4) ? $levels : 2;
    $size = max(16, min(512, $size));
    $grid = avatar_grid($key);
    $colours = avatar_colours($key, $levels);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
        . '" viewBox="0 0 5 5" role="img" aria-label="用户头像"><rect width="5" height="5" fill="#f0f2f5"/>';
    for ($y = 0; $y < 5; $y++) {
        for ($x = 0; $x < 5; $x++) {
            if (!$grid[$y][$x]) { continue; }
            $ci = $levels === 1 ? 0 : (($x + $y) % $levels);
            $svg .= '<rect x="' . $x . '" y="' . $y . '" width="1" height="1" fill="' . $colours[$ci] . '"/>';
        }
    }
    return $svg . '</svg>';
}

/** 用户行 → data URI */
function avatar_uri(array $row, int $size = 80): string
{
    static $cache = array();
    static $full = array();

    /* 评论 / 世界对话的列表只 JOIN 了用户名，没带 settings 与注册时间。
       这里按 uid 补全一次（每请求每用户一次），否则同一个人在不同页面
       会算出不同的头像 key 与色阶——头像必须全站一致。 */
    $uid = (int)($row['id'] ?? 0);
    if ($uid > 0 && (!array_key_exists('settings', $row) || !isset($row['registered_at']) || !isset($row['uid8']))) {
        if (!isset($full[$uid])) {
            try {
                $f = db_one('SELECT id, username, registered_at, uid8, settings FROM users WHERE id = ?', array($uid));
            } catch (Throwable $e) { $f = null; }
            $full[$uid] = is_array($f) ? $f : $row;
        }
        $row = $full[$uid];
    }

    $key = avatar_key_ensure($row);
    $lv = avatar_levels_of($row);
    $ck = $key . '|' . $size . '|' . $lv;
    if (isset($cache[$ck])) { return $cache[$ck]; }
    $uri = 'data:image/svg+xml;base64,' . base64_encode(avatar_svg($key, $size, $lv));
    $cache[$ck] = $uri;
    return $uri;
}

/** 只有名字 → data URI（兼容旧调用点） */
function avatar_uri_by_name(string $name, int $size = 80): string
{
    static $cache = array();
    $key = avatar_key_by_name($name);
    $ck = $key . '|' . $size;
    if (isset($cache[$ck])) { return $cache[$ck]; }
    $uri = 'data:image/svg+xml;base64,' . base64_encode(avatar_svg($key, $size, 2));
    $cache[$ck] = $uri;
    return $uri;
}
