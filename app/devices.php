<?php
/**
 * 登录设备管理
 * ------------------------------------------------------------
 * 一个账号同时最多 3 台设备在线；超出时踢掉最久未活动的那台（令牌一并拉黑）。
 * 「我的」页列出设备，可单台登出（等同远程下线）或登出后切号。
 *
 * 风险标记三条判据，命中几条就是几档：
 *   1  同一设备用过 ≥3 个不同 /16 网段，或跨国（IP 段跨度过大）
 *   2  登录 10 分钟以上仍没有任何前端痕迹（没上报过浏览器指纹）
 *   3  登录 24 小时以上没有任何业务操作
 *   1 条=黄，2 条=橙，3 条=红。
 */

define('DEV_MAX_ACTIVE', 3);

/** 设备归属的 user_id：管理员令牌里 uid 是 0，设备行仍挂在 users 那一行上 */
function dev_owner_uid(array $id): int
{
    $uid = (int)(isset($id['uid']) ? $id['uid'] : 0);
    if ($uid > 0) { return $uid; }
    if ($id['role'] === 'admin' || $id['role'] === 'subadmin') {
        $r = db_one('SELECT id FROM users WHERE username_norm = ? LIMIT 1',
            array(function_exists('norm_username') ? norm_username('admin') : 'admin'));
        if ($r === null) { $r = db_one("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1"); }
        return $r === null ? 0 : (int)$r['id'];
    }
    return 0;
}

/**
 * 设备指纹（与封停体系同一套哈希，换浏览器标签不换设备）。
 * 少数环境（部分 App 内 WebView、隐私模式、被网关过滤自定义头）不带 X-Device-Id，
 * 此时退化成「UA + 语言 + /24 网段」的软指纹 —— 原则是「只要登录了就算一台」，
 * 不能让这些设备在列表里整个消失。两套指纹哈希域不同，不会互相撞车。
 */
function dev_hash_of_request(): string
{
    $h = function_exists('discipline_dev_of_request') ? discipline_dev_of_request() : '';
    if ($h !== '') { return $h; }

    $ua   = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string)$_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    $ip   = function_exists('client_ip') ? client_ip() : '';
    $seg  = $ip;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        $seg = $p[0] . '.' . $p[1] . '.' . $p[2];
    }
    $seed = $ua . '|' . $lang . '|' . $seg;
    if (trim($seed, '|') === '') { return ''; }
    return hash('sha256', (string)cfg('secrets.cron_key', 'kimgr') . '|devsoft|' . $seed);
}

/**
 * 解析 UA → 类型、浏览器、内核、品牌。
 * 顺序很重要：Kimi / 车机 / 电视这类特例必须先判，否则会被当成普通 Chrome。
 */
function dev_parse_ua(string $ua): array
{
    $u = $ua;
    $low = strtolower($u);

    /* 应用内 WebView：UA 里带 Kimi / Moonshot 字样，或典型 App 壳 */
    $isKimi = (strpos($low, 'kimi') !== false || strpos($low, 'moonshot') !== false
        || strpos($low, 'micromessenger') !== false);

    /* 设备类型 */
    $type = 'desktop';
    if ($isKimi) {
        $type = 'kimi';
    } elseif (preg_match('/tesla|car ?browser|android.*automotive|harmonyos.*car|geely|nio |xpeng|byd/i', $u)) {
        $type = 'car';
    } elseif (preg_match('/smart-?tv|smarttv|appletv|android ?tv|googletv|hbbtv|netcast|webos|tizen|bravia|roku|firetv/i', $u)) {
        $type = 'tv';
    } elseif (preg_match('/ipad|tablet|playbook|silk|kindle|matepad|\btab\b|sm-[txp]\d/i', $u)
        || (strpos($low, 'android') !== false && strpos($low, 'mobile') === false)) {
        $type = 'tablet';
    } elseif (preg_match('/mobile|iphone|ipod|android|harmonyos|miui|hmd|phone/i', $u)) {
        $type = 'mobile';
    }

    /* 浏览器（App 内 WebView 单独成一类，信息量更大） */
    $browser = 'Other';
    if ($isKimi) {
        $browser = 'Kimi App WebView';
    } elseif (preg_match('/EdgA?\//i', $u)) {
        $browser = 'Edge';
    } elseif (preg_match('/OPR\/|Opera/i', $u)) {
        $browser = 'Opera';
    } elseif (preg_match('/Firefox|FxiOS/i', $u)) {
        $browser = 'Firefox';
    } elseif (preg_match('/MicroMessenger/i', $u)) {
        $browser = 'WeChat';
    } elseif (preg_match('/Chrome|CriOS|Chromium/i', $u)) {
        /* 国内常见套壳：保留可辨识的名字，别一律叫 Chrome */
        if (preg_match('/HuaweiBrowser|HuaWeiBrowser/i', $u)) { $browser = 'Huawei Browser'; }
        elseif (preg_match('/MiuiBrowser|XiaoMi/i', $u)) { $browser = 'Mi Browser'; }
        elseif (preg_match('/VivoBrowser/i', $u)) { $browser = 'Vivo Browser'; }
        elseif (preg_match('/HeyTapBrowser|OppoBrowser/i', $u)) { $browser = 'Oppo Browser'; }
        elseif (preg_match('/Quark/i', $u)) { $browser = 'Quark'; }
        elseif (preg_match('/UCBrowser|UCWEB/i', $u)) { $browser = 'UC Browser'; }
        elseif (preg_match('/QQBrowser/i', $u)) { $browser = 'QQ Browser'; }
        elseif (preg_match('/baidubrowser|BIDUBrowser/i', $u)) { $browser = 'Baidu Browser'; }
        else { $browser = 'Chrome'; }
    } elseif (preg_match('/Safari/i', $u)) {
        $browser = 'Safari';
    }

    /* 内核。注意 Safari 的 UA 里也写着 "like Gecko)"，所以 Gecko 只认带版本号的
       "Gecko/20100101"（Firefox）或 Firefox/FxiOS 字样，否则一律判成 WebKit。 */
    $kernel = 'Other';
    if (preg_match('/Edg|Chrome|CriOS|Chromium|OPR|Quark|UCBrowser|QQBrowser/i', $u) || $isKimi) {
        $kernel = 'Blink';
    } elseif (preg_match('/Gecko\/\d{6,}/i', $u) || preg_match('/Firefox|FxiOS/i', $u)) {
        $kernel = 'Gecko';
    } elseif (preg_match('/Safari|iPhone|iPad|Mac OS X/i', $u)) {
        $kernel = 'WebKit';
    }

    /* 品牌：只认厂商关键词，顺序即优先级（具体厂商 → Apple → 系统名）。
     * 不再解析括号里的机型段——那一段在不同 UA 里是 "CPU iPhone OS…"、"Win64" 这类噪声。 */
    $brand = '';
    $map = array(
        'iphone' => 'Apple', 'ipad' => 'Apple', 'macintosh' => 'Apple', 'mac os x' => 'Apple',
        'huawei' => 'Huawei', 'matepad' => 'Huawei', 'honor' => 'HONOR',
        'xiaomi' => 'Xiaomi', 'redmi' => 'Xiaomi', 'poco' => 'Xiaomi',
        'oppo' => 'OPPO', 'vivo' => 'vivo', 'samsung' => 'Samsung', 'sm-' => 'Samsung',
        'oneplus' => 'OnePlus', 'realme' => 'realme', 'pixel' => 'Google',
        'windows' => 'Windows', 'harmonyos' => 'HarmonyOS', 'android' => 'Android', 'linux' => 'Linux',
    );
    foreach ($map as $k => $v) {
        if (stripos($u, $k) !== false) { $brand = $v; break; }
    }
    if (mb_strlen($brand, 'UTF-8') > 32) { $brand = mb_substr($brand, 0, 32, 'UTF-8'); }

    return array('type' => $type, 'browser' => $browser, 'kernel' => $kernel, 'brand' => $brand);
}

/** 该请求的网段与国别（用于「IP 跨度」判据） */
function dev_net_of_request(): array
{
    $ip = client_ip();
    $net = '';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        $net = $p[0] . '.' . $p[1] . '.0.0/16';
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        /* 取前 32 位，用二进制前缀换算，避免字符串截断切在冒号中间 */
        $bin = @inet_pton($ip);
        $net = ($bin !== false && strlen($bin) === 16)
            ? inet_ntop(substr($bin, 0, 4) . str_repeat("\0", 12)) . '/32'
            : '';
    }
    $cc = isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? strtoupper(substr((string)$_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2)) : '';
    if (!preg_match('/^[A-Z]{2}$/', $cc)) { $cc = ''; }
    return array('ip_hash' => function_exists('ip_hash') ? ip_hash($ip) : '', 'net' => $net, 'country' => $cc);
}

/**
 * 登录成功后登记设备。返回 array(kicked => 被踢掉的设备名数组)。
 * 超过上限时踢「最久没活动」的那台，并把它的令牌拉黑——它下一次请求就是 401。
 */
function dev_register(int $uid, string $token, string $ua = ''): array
{
    if ($uid <= 0 || !table_exists('login_devices')) { return array('kicked' => array()); }
    $dh = dev_hash_of_request();
    if ($dh === '') { return array('kicked' => array()); }

    if ($ua === '') { $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : ''; }
    $meta = dev_parse_ua($ua);
    $net = dev_net_of_request();
    $th = hash('sha256', $token);

    try {
        db_exec('INSERT INTO login_devices
                 (user_id, dev_hash, ua, type, browser, kernel, brand, ip_hash, ip_net, country, token_hash,
                  first_at, last_at, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1)
                 ON DUPLICATE KEY UPDATE
                   ua = VALUES(ua), type = VALUES(type), browser = VALUES(browser), kernel = VALUES(kernel),
                   brand = VALUES(brand), ip_hash = VALUES(ip_hash), country = VALUES(country),
                   token_hash = VALUES(token_hash), last_at = UTC_TIMESTAMP(), active = 1, kicked_at = NULL',
            array($uid, $dh, mb_substr($ua, 0, 255, 'UTF-8'), $meta['type'], $meta['browser'],
                  $meta['kernel'], $meta['brand'], $net['ip_hash'], $net['net'], $net['country'], $th));

        /* 网段跨度：把这次用到的段并进历史集合（最多留 8 个，够判跨度又不长胖） */
        if ($net['net'] !== '') {
            $cur = (string)db_val('SELECT ip_nets FROM login_devices WHERE user_id = ? AND dev_hash = ? LIMIT 1',
                array($uid, $dh));
            $set = array_filter(array_map('trim', explode(',', $cur)));
            if (!in_array($net['net'], $set, true)) {
                $set[] = $net['net'];
                if (count($set) > 8) { $set = array_slice($set, -8); }
                db_exec('UPDATE login_devices SET ip_nets = ? WHERE user_id = ? AND dev_hash = ?',
                    array(implode(',', $set), $uid, $dh));
            }
        }
    } catch (Throwable $e) {
        app_log('dev_register failed: ' . $e->getMessage());
        return array('kicked' => array());
    }

    /* 超限：只数活跃设备，踢最旧的一台（刚登录的这台除外） */
    $myId = (int)db_val('SELECT id FROM login_devices WHERE user_id = ? AND dev_hash = ? LIMIT 1', array($uid, $dh));
    return array('kicked' => dev_enforce_limit($uid, $myId));
}

/**
 * 活跃设备超限时踢掉最久没活动的那台（$exceptId 为刚登录 / 刚切过来的行）。
 * 返回被踢设备名，供登录结果提示「已挤掉某设备」。
 */
function dev_enforce_limit(int $uid, int $exceptId = 0): array
{
    $kicked = array();
    if ($uid <= 0 || !table_exists('login_devices')) { return $kicked; }
    try {
        $rows = db_all('SELECT id, browser, brand, token_hash FROM login_devices
                        WHERE user_id = ? AND active = 1 ORDER BY last_at DESC', array($uid));
        while (count($rows) > DEV_MAX_ACTIVE) {
            $old = array_pop($rows);
            if ((int)$old['id'] === $exceptId) { continue; }   // 绝不踢掉刚进来的这台
            db_exec('UPDATE login_devices SET active = 0, kicked_at = UTC_TIMESTAMP(), kick_reason = ? WHERE id = ?',
                array('limit', (int)$old['id']));
            if (!empty($old['token_hash'])) {
                dev_blacklist_hash((string)$old['token_hash']);
            }
            $kicked[] = trim((string)$old['browser'] . ' ' . (string)$old['brand']);
        }
    } catch (Throwable $e) {
        app_log('dev_limit failed: ' . $e->getMessage());
    }
    return $kicked;
}

/**
 * 惰性补登记：只要这台设备当前持有有效令牌，就算「登录着」。
 * 用来兜住两类历史缺口 —— 升级前就已经登录的会话、登录时没带上设备标识的会话。
 * 10 分钟节流一次，避免每个请求都写库。
 */
function dev_ensure(int $uid, string $token)
{
    if ($uid <= 0 || $token === '' || !table_exists('login_devices')) { return; }
    $dh = dev_hash_of_request();
    if ($dh === '') { return; }
    $k = 'devens_' . substr($dh, 0, 16);
    if (function_exists('cache_get') && cache_get($k, 600) !== null) { return; }
    try {
        $r = db_one('SELECT id, token_hash, active FROM login_devices WHERE user_id = ? AND dev_hash = ? LIMIT 1',
            array($uid, $dh));
        if ($r === null) {
            dev_register($uid, $token);   // 走同一套类型解析与 3 台上限
        } elseif ((string)$r['token_hash'] !== hash('sha256', $token) || (int)$r['active'] !== 1) {
            /* 同一台设备重新登录过（令牌变了）：继续算在线。
               被登出 / 被挤下线的设备令牌已进黑名单，根本走不到这里，不会「复活」。 */
            db_exec('UPDATE login_devices SET active = 1, token_hash = ?, last_at = UTC_TIMESTAMP(),
                        kicked_at = NULL, kick_reason = \'\' WHERE id = ?',
                array(hash('sha256', $token), (int)$r['id']));
            dev_enforce_limit($uid, (int)$r['id']);
        }
        if (function_exists('cache_set')) { cache_set($k, array(1), 600); }
    } catch (Throwable $e) {
        app_log('dev_ensure failed: ' . $e->getMessage());
    }
}

/** 前端痕迹上报（canvas 指纹 + 屏幕信息），同时刷新 last_at */
function dev_fp_report(int $uid, string $fp): bool
{
    if ($uid <= 0 || !table_exists('login_devices')) { return false; }
    $dh = dev_hash_of_request();
    if ($dh === '') { return false; }
    try {
        db_exec('UPDATE login_devices SET fp = ?, pages = pages + 1, last_at = UTC_TIMESTAMP()
                 WHERE user_id = ? AND dev_hash = ?', array(substr($fp, 0, 32), $uid, $dh));
        return true;
    } catch (Throwable $e) { return false; }
}

/** 业务操作痕迹（写接口调用一次），节流 5 分钟，避免每个请求都写库 */
function dev_op_touch(int $uid)
{
    if ($uid <= 0 || !table_exists('login_devices')) { return; }
    $dh = dev_hash_of_request();
    if ($dh === '') { return; }
    $k = 'devop_' . substr($dh, 0, 16);
    if (function_exists('cache_get') && cache_get($k, 300) !== null) { return; }
    try {
        db_exec('UPDATE login_devices SET last_op_at = UTC_TIMESTAMP(), ops = ops + 1
                 WHERE user_id = ? AND dev_hash = ? AND active = 1', array($uid, $dh));
        if (function_exists('cache_set')) { cache_set($k, array(1), 300); }
    } catch (Throwable $e) { }
}

/**
 * 风险评级：三条判据各自独立，命中几条就是几档。
 * 返回 array(score, level, why[])，level = ok / yellow / orange / red
 */
function dev_risk(array $r): array
{
    $why = array();
    $nets = array_filter(array_map('trim', explode(',', (string)(isset($r['ip_nets']) ? $r['ip_nets'] : ''))));
    if (count($nets) >= 3) {
        $why[] = '跨 ' . count($nets) . ' 个网段';
    }
    $age = time() - (int)strtotime((string)$r['first_at'] . ' UTC');
    if ((int)(isset($r['pages']) ? $r['pages'] : 0) === 0 && $age > 600) {
        $why[] = '无前端痕迹';
    }
    $lastOp = isset($r['last_op_at']) ? (string)$r['last_op_at'] : '';
    if ($lastOp === '' && $age > 86400) {
        $why[] = '无任何操作';
    }
    $n = count($why);
    $lv = $n >= 3 ? 'red' : ($n === 2 ? 'orange' : ($n === 1 ? 'yellow' : 'ok'));
    return array('score' => $n, 'level' => $lv, 'why' => $why);
}

/** 设备列表（含风险），当前设备打 is_current */
function dev_list(int $uid): array
{
    if ($uid <= 0 || !table_exists('login_devices')) { return array(); }
    $cur = dev_hash_of_request();
    $rows = db_all('SELECT id, dev_hash, ua, type, browser, kernel, brand, fp, ip_nets, country,
                           first_at, last_at, last_op_at, pages, ops, active, kick_reason
                    FROM login_devices WHERE user_id = ? AND active = 1
                    ORDER BY last_at DESC LIMIT 10', array($uid));
    $out = array();
    foreach ($rows as $r) {
        $risk = dev_risk($r);
        $out[] = array(
            'id' => (int)$r['id'],
            'type' => (string)$r['type'],
            'browser' => (string)$r['browser'],
            'kernel' => (string)$r['kernel'],
            'brand' => (string)$r['brand'],
            'fp' => (string)$r['fp'],
            'country' => (string)$r['country'],
            'first_at' => (string)$r['first_at'],
            'last_at' => (string)$r['last_at'],
            'is_current' => ($cur !== '' && $r['dev_hash'] === $cur),
            'risk' => $risk['level'],
            'risk_why' => $risk['why'],
        );
    }
    return $out;
}

/**
 * 把某个令牌哈希写进黑名单。表列名是 expires_at（DATETIME），
 * 取与全站令牌同寿命再加 30 天，确保这条拉黑不会先于令牌过期失效。
 */
function dev_blacklist_hash(string $tokenHash)
{
    if ($tokenHash === '') { return; }
    $ttl = (int)cfg('security.token_ttl', 604800) + 86400 * 30;
    db_exec('INSERT INTO token_blacklist (token_hash, expires_at) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)',
        array($tokenHash, gmdate('Y-m-d H:i:s', time() + $ttl)));
}

/** 单台登出（远程下线）：令牌拉黑 + 标记失效 */
function dev_logout(int $uid, int $id): bool
{
    if ($uid <= 0 || $id <= 0 || !table_exists('login_devices')) { return false; }
    $r = db_one('SELECT id, dev_hash, token_hash FROM login_devices WHERE id = ? AND user_id = ? LIMIT 1',
        array($id, $uid));
    if ($r === null) { return false; }
    try {
        db_exec('UPDATE login_devices SET active = 0, kicked_at = UTC_TIMESTAMP(), kick_reason = ? WHERE id = ?',
            array('logout', (int)$r['id']));
        if (!empty($r['token_hash'])) {
            dev_blacklist_hash((string)$r['token_hash']);
        }
        return true;
    } catch (Throwable $e) {
        app_log('dev_logout failed: ' . $e->getMessage());
        return false;
    }
}

/* ============================================================
 * 远程切换账号
 * ------------------------------------------------------------
 * 站主要的是「替那台设备换号」，不是把自己踢下线。所以密码在服务端校验
 * （与正常登录同一条链路、同一套限速与恒定时延），通过后：
 *   ① 该设备旧令牌立刻进黑名单 —— 它下一次请求必然 401；
 *   ② 这一行挂上「待领取」的目标身份；
 *   ③ 那台设备用旧令牌换走新令牌（dev_claim_switch），当场变成目标账号。
 * 旧令牌就是领取凭证：伪造设备号拿不到，别人也拿不到——它只在原设备本地存储里。
 * 不存任何令牌明文，领取时才现场签发。
 * ============================================================ */

/** 给某台设备换号。成功返回目标账号信息；失败直接 fail()。 */
function dev_switch_account(int $ownerUid, int $rowId, string $raw, string $password): array
{
    if ($ownerUid <= 0 || !table_exists('login_devices')) { fail(500, '设备管理未就绪'); }
    if ($rowId <= 0) { fail(400, '参数错误'); }
    $row = db_one('SELECT * FROM login_devices WHERE id = ? AND user_id = ? LIMIT 1', array($rowId, $ownerUid));
    if ($row === null || (int)$row['active'] !== 1) { fail(404, '设备不存在或已下线'); }

    $raw = trim($raw);
    if ($raw === '' || $password === '') { fail(400, '请填写目标账号与密码'); }

    $ip  = client_ip();
    $key = (strpos($raw, '@') !== false) ? mail_norm($raw) : norm_username($raw);
    if (login_locked($key)) { timing_delay(); fail(429, '尝试过于频繁，请稍后再试'); }

    /* 目标账号定位：与正常登录完全同一条链路（含恒定时延，不泄露账号是否存在） */
    $isAdmin = (norm_username($raw) === 'admin');
    $tRow = null;
    if ($isAdmin) {
        $ok = admin_verify($password);
        login_mark($ip, $ok, 'admin');
        if (!$ok) { timing_delay(); fail(401, '用户名或密码错误'); }
        $tUid  = admin_uid();
        $tRole = 'admin';
        $tName = 'admin';
        if ($tUid > 0) { $tRow = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', array($tUid)); }
    } else {
        $tRow = user_row_by_identifier($raw);
        if ($tRow === null) {
            password_chain($password, '1970-01-01 00:00:00', str_repeat('0', 32));
            login_mark($ip, false, $key);
            timing_delay();
            fail(401, '用户名或密码错误');
        }
        $ok = password_verify_chain($password, (string)(isset($tRow['registered_at']) ? $tRow['registered_at'] : ''),
            (string)(isset($tRow['salt']) ? $tRow['salt'] : ''), (string)(isset($tRow['password_hash']) ? $tRow['password_hash'] : ''));
        login_mark($ip, $ok, $key);
        if (!$ok) { timing_delay(); fail(401, '用户名或密码错误'); }
        $tUid  = (int)$tRow['id'];
        $tRole = ((string)$tRow['role'] === 'subadmin') ? 'subadmin' : 'user';
        $tName = (string)$tRow['username'];
    }

    if ($tUid <= 0) { fail(400, '目标账号不可用'); }
    if ((int)$row['user_id'] === $tUid) { fail(400, '目标账号与这台设备当前的账号相同'); }

    /* 这两关只能由账号本人过，代登越不过去，直接说清楚 */
    if ($tRow !== null && user_twofa_enabled($tRow)) {
        fail(400, '该账号已开启邮件双重验证，无法远程切换');
    }
    if ($tRow !== null && (int)$tRow['is_banned'] === 1) {
        fail(403, '目标账号已被封停，无法切换');
    }

    try {
        /* 目标账号下这台设备的旧行先清掉，避免唯一键 (user_id, dev_hash) 冲突 */
        db_exec('DELETE FROM login_devices WHERE user_id = ? AND dev_hash = ? AND id <> ?',
            array($tUid, (string)$row['dev_hash'], $rowId));
        /* 归到目标账号，并把「这台设备在新账号下的痕迹」清零重算 */
        db_exec('UPDATE login_devices
                    SET user_id = ?, switch_uid = ?, switch_role = ?, switch_name = ?, switch_at = UTC_TIMESTAMP(),
                        active = 1, kick_reason = \'\', kicked_at = NULL,
                        ip_nets = \'\', fp = \'\', pages = 0, ops = 0, last_op_at = NULL,
                        first_at = UTC_TIMESTAMP(), last_at = UTC_TIMESTAMP()
                  WHERE id = ?',
            array($tUid, $tUid, $tRole, mb_substr($tName, 0, 64, 'UTF-8'), $rowId));
        /* 旧令牌立刻作废：那台设备下一次请求就进领取流程 */
        if (!empty($row['token_hash'])) { dev_blacklist_hash((string)$row['token_hash']); }
    } catch (Throwable $e) {
        app_log('dev_switch failed: ' . $e->getMessage());
        fail(500, '切换失败，请稍后再试');
    }

    return array('ok' => true, 'to' => $tName, 'role' => $tRole);
}

/** 用旧令牌领取待切换的新身份；没有待领取记录返回空数组 */
function dev_claim_switch(string $oldToken): array
{
    if ($oldToken === '' || !table_exists('login_devices')) { return array(); }
    $row = db_one('SELECT id, token_hash, switch_uid, switch_role, switch_at FROM login_devices
                    WHERE token_hash = ? AND switch_uid > 0 LIMIT 1', array(hash('sha256', $oldToken)));
    if ($row === null) { return array(); }

    $uid = (int)$row['switch_uid'];
    /* 10 分钟没来领就作废：不留长期可用的待领取凭据 */
    if ($uid <= 0 || $row['switch_at'] === null
        || strtotime((string)$row['switch_at'] . ' UTC') < time() - 600) {
        dev_switch_clear((int)$row['id']);
        return array();
    }
    $u = db_one('SELECT id, username, role, is_banned FROM users WHERE id = ? LIMIT 1', array($uid));
    if ($u === null || (int)$u['is_banned'] === 1) {
        dev_switch_clear((int)$row['id']);
        return array();
    }

    $role = (string)$row['switch_role'];
    if ($role === 'admin') {
        $tok = issue_admin_token(); $outUid = 0; $name = 'admin';
    } elseif ($role === 'subadmin') {
        $tok = issue_subadmin_token((string)$u['username'], $uid); $outUid = $uid; $name = (string)$u['username'];
    } else {
        $role = 'user';
        $tok = issue_user_token($uid); $outUid = $uid; $name = (string)$u['username'];
    }

    try {
        db_exec('UPDATE login_devices
                    SET token_hash = ?, switch_uid = 0, switch_role = \'\', switch_name = \'\', switch_at = NULL,
                        active = 1, kick_reason = \'\', kicked_at = NULL, last_at = UTC_TIMESTAMP()
                  WHERE id = ?', array(hash('sha256', $tok), (int)$row['id']));
    } catch (Throwable $e) {
        app_log('dev_claim failed: ' . $e->getMessage());
        return array();
    }
    /* 目标账号可能已经满 3 台：按同一套规则挤掉最旧的一台 */
    dev_enforce_limit($uid, (int)$row['id']);

    return array('token' => $tok, 'uid' => $outUid, 'role' => $role, 'username' => $name);
}

/** 这台设备是否存在待领取的账号切换（start 载荷用，命中才置位） */
function dev_switch_pending(string $token): bool
{
    if ($token === '' || !table_exists('login_devices')) { return false; }
    $r = db_one('SELECT id FROM login_devices WHERE token_hash = ? AND switch_uid > 0 LIMIT 1',
        array(hash('sha256', $token)));
    return $r !== null;
}

/** 撤掉一条待领取记录 */
function dev_switch_clear(int $id)
{
    if ($id <= 0) { return; }
    try {
        db_exec('UPDATE login_devices SET switch_uid = 0, switch_role = \'\', switch_name = \'\', switch_at = NULL WHERE id = ?',
            array($id));
    } catch (Throwable $e) { }
}
