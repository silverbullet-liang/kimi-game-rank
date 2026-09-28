<?php
/**
 * 认证：令牌签发/校验、登录注册、游客、管理员验证
 * ------------------------------------------------------------
 * 信任边界：所有规整/校验只在此层执行，前端不可绕过。
 * 侧信道：用户不存在亦执行等价哈希；失败文案统一；恒定时间比较。
 */
declare(strict_types=1);

/* ============================================================
 * 令牌
 * ============================================================ */
function bearer_token(): string
{
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) { $h = $_SERVER['HTTP_AUTHORIZATION']; }
    elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) { $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; }
    if ($h !== '' && stripos($h, 'bearer ') === 0) { return trim(substr($h, 7)); }
    // 兼容：部分主机不转发 Authorization，用 X-Token 兜底
    if (!empty($_SERVER['HTTP_X_TOKEN'])) { return trim((string)$_SERVER['HTTP_X_TOKEN']); }
    return '';
}

function issue_token(int $uid, string $role, int $ttl): string
{
    return token_encode(array('uid' => $uid, 'role' => $role, 'iat' => time(), 'ttl' => $ttl, 'n' => rand_hex(8)));
}

function issue_user_token(int $uid): string { return issue_token($uid, 'user', (int)cfg('security.token_ttl', 604800)); }
function issue_guest_token(): string
{
    /* 游客令牌有效期下限 30 天：短期令牌会频繁触发续期，
       在受限主机上形成请求放大（表现为「网络连接不稳定」）。 */
    $ttl = (int)cfg('security.guest_token_ttl', 86400);
    if ($ttl < 2592000) { $ttl = 2592000; }
    return issue_token(0, 'guest', $ttl);
}
function issue_admin_token(): string     { return issue_token(0, 'admin', (int)cfg('security.token_ttl', 604800)); }

function issue_subadmin_token(string $username, int $userId): string
{
    return token_encode(array('uid' => $userId, 'role' => 'subadmin', 'sub' => $username, 'iat' => time(),
        'ttl' => (int)cfg('security.token_ttl', 604800), 'n' => rand_hex(8)));
}

function token_blacklist(string $token)
{
    $exp = gmdate('Y-m-d H:i:s', time() + (int)cfg('security.token_ttl', 604800));
    db_exec('INSERT INTO token_blacklist (token_hash, expires_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)',
        array(hash('sha256', $token), $exp));
}

/**
 * 解析并校验当前请求令牌；失败返回 null。
 */
function current_identity()
{
    static $cache = false;
    if ($cache !== false) { return $cache; }

    $tok = bearer_token();
    if ($tok === '') { return $cache = null; }
    try {
        $p = token_decode($tok);
    } catch (Exception $e) {
        return $cache = null;
    }
    if (!isset($p['iat'], $p['ttl'], $p['role'])) { return $cache = null; }
    if ((int)$p['iat'] + (int)$p['ttl'] < time()) { return $cache = null; }

    // 黑名单（容错：库表异常时按「未拉黑」放行，绝不因此让全部接口 500）
    try {
        $blocked = db_val('SELECT 1 FROM token_blacklist WHERE token_hash = ? LIMIT 1', array(hash('sha256', $tok)));
        if ($blocked) { return $cache = null; }
    } catch (Throwable $e) {
        app_log('token_blacklist check failed: ' . $e->getMessage());
    }

    $p['token'] = $tok;
    return $cache = $p;
}

/** 所有 API 必须携带有效令牌，否则拒绝 */
function require_token(): array
{
    $id = current_identity();
    if ($id === null) { fail(401, '未携带有效登录态，请重新进入'); }
    return $id;
}

/** 必须登录用户（游客拒绝） */
function require_user(): array
{
    $id = require_token();
    if ($id['role'] !== 'user') { fail(403, '请登录后再操作'); }
    return $id;
}

function require_admin(): array
{
    $id = require_token();
    if ($id['role'] !== 'admin') { fail(403, '无管理员权限'); }
    return $id;
}

/** 具备管理后台访问资格（主管理员或副管理员） */
function require_any_admin(): array
{
    $id = require_token();
    if ($id['role'] !== 'admin' && $id['role'] !== 'subadmin') { fail(403, '无后台权限'); }
    return $id;
}

/** 已登录成员（普通用户或管理员）——用于评论/对话等社交功能，游客不通过 */
function require_member(): array
{
    $id = require_token();
    if ($id['role'] !== 'user' && $id['role'] !== 'admin' && $id['role'] !== 'subadmin') { fail(403, '请登录后再操作'); }
    return $id;
}

/**
 * 公开管理人员名单（「关于」页实时展示用）。
 * 只输出用户名与角色标签——不含 UID、凭证、登录记录或任何运营数据，
 * 这条链路专供公开页面，与控制面板的接口互不相干。
 */
function admin_public_list(): array
{
    $out = array();
    $rows = db_all("SELECT username, role FROM users WHERE role IN ('admin','subadmin')
                    ORDER BY (role = 'admin') DESC, id ASC LIMIT 50");
    foreach ($rows as $r) {
        $name = trim((string)$r['username']);
        if ($name === '') { continue; }
        $isAdmin = (string)$r['role'] === 'admin';
        /* 只信 role 字段：查询以外混进来的普通用户不会被当成管理人员展示 */
        if (!$isAdmin && (string)$r['role'] !== 'subadmin') { continue; }
        $out[] = array(
            'name'  => $name,
            'role'  => $isAdmin ? 'admin' : 'subadmin',
            'label' => $isAdmin ? '站长 / 管理员' : '副管理员',
        );
    }
    return $out;
}

/** 副管理员在其列表中的序号（用于分配豹子号；非副管理员返回 0） */
function subadmin_seq_of(int $userId): int
{
    if ($userId <= 0) { return 0; }
    try {
        $row = db_one('SELECT id FROM users WHERE id = ? AND role = ? LIMIT 1', array($userId, 'subadmin'));
        if ($row === null) { return 0; }
        $all = db_admin_all('SELECT user_id FROM sub_admins ORDER BY id ASC');
        foreach ($all as $i => $r) { if ((int)$r['user_id'] === $userId) { return $i + 1; } }
    } catch (Throwable $e) { return 0; }
    return 0;
}

/** 当前操作者在 users 表中的落库 ID（管理员换算为 admin 用户行） */
function actor_uid(array $id): int
{
    if ($id['role'] === 'admin') { return admin_uid(); }
    return (int)$id['uid'];
}

/** 管理员在 users 表中的用户行 ID（迁移时创建） */
function admin_uid(): int
{
    static $uid = null;
    if ($uid !== null) { return $uid; }
    $uid = (int)setting_get('admin_user_id', '0');
    if ($uid <= 0) {
        try {
            $row = db_one('SELECT id FROM users WHERE username_norm = ? LIMIT 1', array('admin'));
            $uid = $row === null ? 0 : (int)$row['id'];
            if ($uid > 0) { setting_set('admin_user_id', (string)$uid); }
        } catch (Throwable $e) { $uid = 0; }
    }
    return $uid;
}

function current_user_row()
{
    $id = current_identity();
    if ($id === null) { return null; }
    if ($id['role'] === 'user' || $id['role'] === 'subadmin') {
        $uid = (int)$id['uid'];
    } elseif ($id['role'] === 'admin') {
        $uid = admin_uid();          // 管理员亦有真实用户行（供评论/对话落库）
    } else {
        return null;
    }
    if ($uid <= 0) { return null; }
    $u = db_one('SELECT * FROM users WHERE id = ? AND is_banned = 0', array($uid));
    return $u === null ? null : $u;
}

/* ============================================================
 * 登录限速
 * ============================================================ */
function login_locked(): bool
{
    $h = ip_hash(client_ip());
    $since = gmdate('Y-m-d H:i:s', time() - (int)cfg('security.login_lock_time', 600));
    $n = (int)db_val('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND success = 0 AND created_at > ?', array($h, $since));
    return $n >= (int)cfg('security.login_max_fails', 5);
}

function login_mark(string $ip, bool $success)
{
    db_exec('INSERT INTO login_attempts (ip_hash, success, created_at) VALUES (?, ?, UTC_TIMESTAMP())',
        array(ip_hash($ip), $success ? 1 : 0));
    if ($success) {
        db_exec('DELETE FROM login_attempts WHERE ip_hash = ? AND success = 0', array(ip_hash($ip)));
    }
}

/* ============================================================
 * 注册 / 登录 / 游客 / 退出
 * ============================================================ */
function user_register(string $username, string $password): array
{
    $raw = $username;
    $nfc = nfc_normalize($raw);
    $clean = strip_invisible($nfc);
    $norm  = norm_username($raw);

    $len = mb_strlen($clean, 'UTF-8');
    if ($len < 2 || $len > 64) { fail(400, '用户名长度需为 2-64 个字符'); }
    $plen = mb_strlen($password, 'UTF-8');
    if ($plen < 8 || $plen > 64) { fail(400, '密码长度需为 8-64 个字符'); }
    if (strpbrk($password, "\0\r\n") !== false) { fail(400, '密码包含非法字符'); }
    if ($norm === '') { fail(400, '用户名不能为空白'); }
    if (is_reserved_name($norm)) { fail(400, '该用户名为系统保留名，不可注册'); }
    if (subadmin_row($norm) !== null) { fail(409, '该用户名为系统保留名，不可注册'); }

    $exists = db_val('SELECT id FROM users WHERE username_norm = ? LIMIT 1', array($norm));
    if ($exists) { fail(409, '该用户名已被使用'); }

    $registeredAt = now_utc();
    $salt = password_make_salt();
    $hash = password_chain($password, $registeredAt, $salt);

    $uid = db_insert(
        'INSERT INTO users (username, username_norm, password_hash, salt, registered_at, settings, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        array($clean, $norm, $hash, $salt, $registeredAt, json_encode(default_user_settings()), $registeredAt)
    );
    uid_of_user(array('id' => $uid, 'role' => 'user', 'uid8' => ''), 0);   // 注册即分配 8 位 UID
    stats_bump('signups');
    record_visit($uid);
    return array('uid' => $uid, 'token' => issue_user_token($uid), 'username' => $clean);
}

function user_login(string $username, string $password): array
{
    if (login_locked()) {
        timing_delay();
        fail(429, '尝试次数过多，请稍后再试');
    }
    $norm = norm_username($username);
    $ip = client_ip();

    // 管理员统一通道：用户名规整后为 admin 时走独立库验证
    if ($norm === 'admin') {
        $ok = admin_verify($password);
        login_mark($ip, $ok);
        if (!$ok) { timing_delay(); fail(401, '用户名或密码错误'); }
        stats_bump('logins');
        return array('uid' => 0, 'role' => 'admin', 'token' => issue_admin_token(), 'username' => 'admin');
    }

    /* 普通用户与副管理员同一通道：同一张表、同一套密码链路。
       副管理员仅是 users.role 标记，登录凭证与其原密码完全一致；
       只有总管理员（admin）的凭证存放在独立数据库。 */
    $u = db_one('SELECT * FROM users WHERE username_norm = ? LIMIT 1', array($norm));
    if ($u === null) {
        // 恒定路径：用哑值跑完整链路，避免用户存在性时序泄露
        password_chain($password, '1970-01-01 00:00:00', str_repeat('0', 32));
        login_mark($ip, false);
        timing_delay();
        fail(401, '用户名或密码错误');
    }
    $ok = password_verify_chain($password, (string)$u['registered_at'], (string)$u['salt'], (string)$u['password_hash']);
    login_mark($ip, $ok);
    if (!$ok) { timing_delay(); fail(401, '用户名或密码错误'); }
    if ((int)$u['is_banned'] === 1) { fail(403, '账号已被封禁'); }

    db_exec('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', array((int)$u['id']));
    stats_bump('logins');
    record_visit((int)$u['id']);
    if ((string)$u['role'] === 'subadmin') {
        return array('uid' => (int)$u['id'], 'role' => 'subadmin',
            'token' => issue_subadmin_token((string)$u['username'], (int)$u['id']),
            'username' => (string)$u['username']);
    }
    return array('uid' => (int)$u['id'], 'role' => 'user', 'token' => issue_user_token((int)$u['id']), 'username' => (string)$u['username']);
}

function guest_enter(): array
{
    return array('uid' => 0, 'role' => 'guest', 'token' => issue_guest_token(), 'username' => '游客');
}

function user_logout()
{
    $tok = bearer_token();
    if ($tok !== '') { token_blacklist($tok); }
}

/* ============================================================
 * 管理员验证（独立库 + 加盐慢哈希 + 恒定时间）
 * ------------------------------------------------------------
 * 凭据不是密钥原文，而是原文的 sha256（64 位小写 hex）——它才是真正的密码。
 * 存储层再对该凭据做加盐慢哈希，因此：
 *   · 库里不再有明文，被拖库也只能看到 bcrypt 串
 *   · 输入仍是那串 64 位 hex，与改造前完全一致，升级无感
 * 若表里还是老结构（只有明文列），自动退回旧逻辑，不会把人挡在门外。
 * ============================================================ */
function admin_verify(string $inputKey): bool
{
    $given = strtolower(trim($inputKey));
    if ($given === '') { return false; }

    $dummy = '$2y$10$' . str_repeat('.', 53);

    /* 慢哈希列可能尚未就绪（迁移未跑完）：先按新结构查，失败再按旧结构查。
       绝不能因为一个列不存在，就把管理员挡在门外。 */
    $row = null;
    try {
        $row = db_admin_one(
            'SELECT secret_hash, secret_plaintext FROM admin_credentials WHERE username = ? LIMIT 1',
            array('admin')
        );
        $hasHashCol = true;
    } catch (Throwable $e) {
        $hasHashCol = false;
        try {
            $row = db_admin_one(
                'SELECT secret_plaintext FROM admin_credentials WHERE username = ? LIMIT 1',
                array('admin')
            );
        } catch (Throwable $e2) {
            app_log('admin_verify DB error: ' . $e2->getMessage());
            password_verify($given, $dummy);   // 走满链路，保持时序一致
            return false;
        }
    }

    if ($row === null) {
        password_verify($given, $dummy);
        return false;
    }

    /* ① 新格式：对「凭据」的加盐慢哈希 */
    if ($hasHashCol && !empty($row['secret_hash'])) {
        return password_verify($given, (string)$row['secret_hash']);
    }

    /* ② 过渡：结构未升级，仍按旧口径比对（恒定时间） */
    $plain = (string)$row['secret_plaintext'];
    if ($plain === '') {
        /* 明文已被清空、哈希列却读不到：说明结构处于中间态，拒绝并留痕 */
        app_log('admin_verify: credential in transitional state');
        password_verify($given, $dummy);
        return false;
    }
    return hash_equals(hash('sha256', $plain), $given);
}

/** 凭据 = 密钥原文的 sha256（64 位小写十六进制），即真正的管理员密码 */
function admin_credential_of(string $secretRaw): string
{
    return hash('sha256', $secretRaw);
}

/* ============================================================
 * 副管理员
 * ============================================================ */
function subadmin_row(string $normName)
{
    try {
        return db_admin_one('SELECT * FROM sub_admins WHERE username_norm = ? LIMIT 1', array($normName));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * 设立副管理员：支持「升级现有用户」与「新建账号」两种路径。
 * - 升级现有用户：**不改密码**，原密码继续可用；role 改为 subadmin，UID 换豹子号，数据全部保留
 * - 新建账号：需设置初始密码（走普通用户密码链路存储，同样用密码登录）
 * - 若传入 $secret 且目标已有密码 → 视为「重置密码」（用于修复早期密钥模式账号）
 * 登录方式：统一「用户名 + 密码」；只有总管理员的凭证存于独立数据库。
 */
function subadmin_create(string $username, string $secret = ''): array
{
    $secret = trim($secret);
    if ($secret !== '') {
        if (mb_strlen($secret, 'UTF-8') < 8) { fail(400, '密码至少 8 位'); }
        if (mb_strlen($secret, 'UTF-8') > 64) { fail(400, '密码最长 64 位'); }
    }

    $clean = trim(nfc_normalize($username));
    if ($clean === '') { fail(400, '用户名不能为空'); }
    if (mb_strlen($clean, 'UTF-8') > 64) { fail(400, '用户名最长 64 字符'); }
    $norm = norm_username($clean);
    if ($norm === '') { fail(400, '用户名无效（仅含空格或不可打印字符）'); }
    if ($norm === 'admin' || is_reserved_name($norm)) { fail(400, '该名称为系统保留名'); }

    $ts = now_utc();
    $existing = db_one('SELECT * FROM users WHERE username_norm = ? LIMIT 1', array($norm));

    if ($existing !== null) {
        /* ---------- 升级现有用户：密码保持不变 ---------- */
        if ((string)$existing['role'] === 'admin') { fail(400, '管理员账号不可转为副管理员'); }
        $userId = (int)$existing['id'];
        $mode = 'promoted';
        $clean = (string)$existing['username'];

        if ($secret !== '') {
            /* 仅当显式提供密码时才重置（用于修复早期密钥模式账号） */
            $salt = password_make_salt();
            db_exec('UPDATE users SET password_hash = ?, salt = ? WHERE id = ?',
                array(password_chain($secret, (string)$existing['registered_at'], $salt), $salt, $userId));
            $mode = 'promoted_resetpwd';
        }
    } else {
        /* ---------- 新建账号：必须设置初始密码 ---------- */
        if ($secret === '') { fail(400, '该用户名尚未注册，请为其设置一个初始密码（至少 8 位）'); }
        $userId = db_insert(
            'INSERT INTO users (username, username_norm, role, password_hash, salt, registered_at, settings, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            array($clean, $norm, 'subadmin', password_chain($secret, $ts, password_make_salt()), password_make_salt(),
                  $ts, json_encode(default_user_settings()), $ts)
        );
        $mode = 'created';
    }

    /* 设立登记（独立库仅作记录，不再存登录凭证；凭证统一在 users 表） */
    if (subadmin_row($norm) === null) {
        db_admin_exec(
            'INSERT INTO sub_admins (username, username_norm, user_id, secret_plaintext, created_at) VALUES (?,?,?,?,?)',
            array($clean, $norm, $userId, '', $ts)
        );
    }

    /* 角色 + 豹子号 UID */
    db_exec("UPDATE users SET role = 'subadmin' WHERE id = ?", array($userId));
    $seq = 1;
    try { $seq = max(1, count(db_admin_all('SELECT id FROM sub_admins'))); } catch (Throwable $e) { $seq = 1; }
    $pool = uid_babao_pool();
    $uid8 = '';
    foreach ($pool as $cand) {
        if (!uid_taken($cand, $userId)) { $uid8 = $cand; break; }
    }
    if ($uid8 === '') { $uid8 = uid_assign($userId, 'subadmin', $seq); }
    db_exec('UPDATE users SET uid8 = ? WHERE id = ?', array($uid8, $userId));

    app_log('subadmin ' . $mode . ': ' . $norm . ' (uid=' . $userId . ', uid8=' . $uid8 . ')');
    return array(
        'id' => $userId, 'user_id' => $userId, 'username' => $clean,
        'uid8' => $uid8, 'mode' => $mode,
    );
}

/** 取消副管理员身份（降级为普通用户，**保留全部数据**） */
function subadmin_demote(int $subId): bool
{
    $row = db_admin_one('SELECT * FROM sub_admins WHERE id = ? LIMIT 1', array($subId));
    if ($row === null) { return false; }
    $uid = (int)$row['user_id'];
    db_admin_exec('DELETE FROM sub_admins WHERE id = ?', array($subId));

    if ($uid > 0) {
        db_exec("UPDATE users SET role = 'user' WHERE id = ?", array($uid));
        /* UID 换回普通计算值（豹子号归还池中） */
        $fallback = '';
        for ($k = 0; $k < 12; $k++) {
            $cand = uid_encode($uid + $k * 50000000);
            if (!uid_is_babao($cand) && !uid_taken($cand, $uid)) { $fallback = $cand; break; }
        }
        if ($fallback === '') { $fallback = uid_encode($uid); }
        db_exec('UPDATE users SET uid8 = ? WHERE id = ?', array($fallback, $uid));
    }
    app_log('subadmin demoted: ' . (string)$row['username_norm']);
    return true;
}

/** 删除副管理员（连同其主库用户行） */
function subadmin_delete(int $id): bool
{
    $row = db_admin_one('SELECT * FROM sub_admins WHERE id = ? LIMIT 1', array($id));
    if ($row === null) { return false; }
    db_admin_exec('DELETE FROM sub_admins WHERE id = ?', array($id));
    $uid = (int)$row['user_id'];
    if ($uid > 0) {
        db_exec('DELETE FROM comments WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM comment_votes WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM work_votes WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM messages WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM ai_messages WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM ai_usage WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM feedback WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM users WHERE id = ?', array($uid));
    }
    app_log('subadmin deleted: ' . $row['username_norm']);
    return true;
}

/* ============================================================
 * 访问记录 / 用户设置
 * ============================================================ */
function default_user_settings(): array
{
    return array(
        'notify'     => 1,
        'ip_record'  => 1,
        'glass_mode' => cfg('glass.default_mode', 'css'),
        'theme'      => 'light',
        'accent'     => cfg('glass.default_accent', 'blue-purple'),
        'search_ai'  => 1,     // 搜索结果页的 AI 总结卡片（站点承担额度，与个人额度无关）
    );
}

function user_settings($u): array
{
    $base = default_user_settings();
    if ($u === null || empty($u['settings'])) { return $base; }
    $s = json_decode((string)$u['settings'], true);
    return is_array($s) ? array_merge($base, $s) : $base;
}

function record_visit(int $uid)
{
    if ($uid <= 0) { return; }
    $u = db_one('SELECT settings FROM users WHERE id = ?', array($uid));
    $st = user_settings($u);
    if (empty($st['ip_record'])) { return; }

    try {
        $ip = client_ip();
        $info = ip_lookup($ip);                 // 走缓存
        $loc = isset($info['location']) ? (string)$info['location'] : '';
        list($co, $pr, $ci) = ip_fields($info);
        $masked = mask_ip($ip);
        try {
            /* 结构化列：统计直接按列分组，不再从拼接串里猜省市 */
            db_exec('INSERT INTO user_visits (user_id, ip_masked, location, country, province, city, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                array($uid, $masked, mb_substr($loc, 0, 128, 'UTF-8'),
                      mb_substr($co, 0, 32, 'UTF-8'), mb_substr($pr, 0, 32, 'UTF-8'), mb_substr($ci, 0, 32, 'UTF-8')));
        } catch (Throwable $e2) {
            /* 结构尚未升级时退回旧写法，保证访问记录不丢 */
            db_exec('INSERT INTO user_visits (user_id, ip_masked, location, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
                array($uid, $masked, mb_substr($loc, 0, 128, 'UTF-8')));
        }
    } catch (Throwable $e) {
        app_log('record_visit failed: ' . $e->getMessage());   // 访问记录失败不影响登录
    }
}
