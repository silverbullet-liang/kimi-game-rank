<?php
declare(strict_types=1);
/**
 * 邮箱验证码 / 绑定 / 验证码登录
 * ------------------------------------------------------------
 * 三条约束（对应需求）：
 *   1. 每个账号只能有一个邮箱，不同账号不能共用同一个邮箱（唯一索引压在 users.email_norm）。
 *   2. 验证码两分钟有效，只用一次；错 5 次作废。
 *   3. 登录支持「UID / 用户名 / 邮箱 + 密码」与「邮箱 + 验证码」两条路。
 *
 * 安全口径：
 *   · 验证码只存 sha256(code|email|站点盐)，不落明文；
 *   · 发码与校验都限速（同邮箱 60 秒一次、每小时 5 次；同 IP 每小时 12 次）；
 *   · 「这个邮箱有没有绑账号」不对外暴露：发码接口无论账号是否存在，回同一句话。
 */

/** 验证码有效期（秒）：需求是两分钟 */
function email_code_ttl(): int
{
    $t = (int)cfg('mail.code_ttl', 120);
    if ($t < 60) { $t = 60; }
    if ($t > 900) { $t = 900; }
    return $t;
}

function email_code_len(): int
{
    return 6;
}

function email_code_hash(string $code, string $emailNorm): string
{
    return hash('sha256', $code . '|' . $emailNorm . '|' . (string)cfg('secrets.cron_key', 'kimgr'));
}

/** 邮箱功能是否就绪（表/列未迁移时优雅降级，不当成致命错误） */
function email_ready(): bool
{
    return col_ok('users', 'email_norm') && col_ok('users', 'email_at') && table_exists('email_codes');
}

/** 邮件正文的三语文案：验证码用途不同，措辞也不同 */
function email_code_body(string $lang, string $purpose, string $code, int $ttlMin): array
{
    $site = email_site_name();
    if ($lang === 'en') {
        if ($purpose === 'login') {
            return array('Sign-in code · ' . $site,
                'Use this code to sign in to ' . $site . '.',
                'The code expires in ' . $ttlMin . ' minutes and can be used once. If you did not request it, ignore this mail — nobody can sign in without it.');
        }
        return array('Confirm your email · ' . $site,
            'Use this code to bind this address to your ' . $site . ' account.',
            'The code expires in ' . $ttlMin . ' minutes and can be used once. If you did not request it, ignore this mail — your account stays unchanged.');
    }
    if ($lang === 'ja') {
        if ($purpose === 'login') {
            return array('ログインコード · ' . $site,
                'このコードで ' . $site . ' にログインします。',
                '有効期限は ' . $ttlMin . ' 分、1 回のみ使用できます。心当たりがない場合はこのメールを破棄してください。コードがなければログインできません。');
        }
        return array('メールアドレスの確認 · ' . $site,
            'このコードで、このアドレスを ' . $site . ' のアカウントに登録します。',
            '有効期限は ' . $ttlMin . ' 分、1 回のみ使用できます。心当たりがない場合はこのメールを破棄してください。アカウントは変更されません。');
    }
    if ($purpose === 'login') {
        return array('登录验证码 · ' . $site,
            '用这组验证码登录' . $site . '。',
            '验证码 ' . $ttlMin . ' 分钟内有效，且只能使用一次。不是你本人操作的话，忽略这封邮件即可——没有验证码就无法登录。');
    }
    return array('邮箱确认 · ' . $site,
        '用这组验证码把这个邮箱绑定到你的' . $site . '账号。',
        '验证码 ' . $ttlMin . ' 分钟内有效，且只能使用一次。不是你本人操作的话，忽略这封邮件即可，账号不会有任何变化。');
}

function email_site_name(): string
{
    return (string)cfg('site.name', 'kimi游戏榜');
}

/**
 * 发验证码。
 * @param string $email   收件邮箱（原样）
 * @param string $purpose bind | login
 * @param int    $uid     绑定时的账号 id（登录时为 0）
 * @param string $lang    邮件语言 zh|en|ja
 * @return array ok|reason|ttl
 */
function email_code_send(string $email, string $purpose, int $uid = 0, string $lang = 'zh'): array
{
    $email = trim($email);
    if (!mail_valid($email)) { fail(400, '邮箱格式不正确'); }
    if (!email_ready()) { fail(503, '邮箱功能暂未开放'); }
    $purpose = ($purpose === 'login') ? 'login' : 'bind';
    $norm = mail_norm($email);

    /* 绑定：邮箱已被别人占用就直接说清楚（这条不算账号枚举——对方必须已经绑过才成立） */
    if ($purpose === 'bind') {
        $owner = db_one('SELECT id FROM users WHERE email_norm = ? LIMIT 1', array($norm));
        if ($owner !== null && (int)(isset($owner['id']) ? $owner['id'] : 0) !== $uid) { fail(409, '这个邮箱已被其他账号绑定'); }
    } else {
        /* 登录：邮箱没绑过任何账号就不发信，但对外一律回同一句话（不暴露账号是否存在） */
        $owner = db_one('SELECT id FROM users WHERE email_norm = ? LIMIT 1', array($norm));
        if ($owner === null) { return array('ok' => true, 'ttl' => email_code_ttl(), 'sent' => false); }
    }

    /* 同邮箱 60 秒一条，避免被拿来刷他人邮箱 */
    $last = db_one('SELECT created_at FROM email_codes WHERE email_norm = ? ORDER BY id DESC LIMIT 1', array($norm));
    if ($last !== null) {
        $gap = time() - strtotime((string)$last['created_at'] . ' UTC');
        if ($gap >= 0 && $gap < 60) { fail(429, '请求过于频繁，请 ' . (60 - $gap) . ' 秒后再试'); }
    }
    if (!rate_limit('mailbox_' . md5($norm), 5, 3600)) { fail(429, '该邮箱今日请求次数过多，请稍后再试'); }
    if (!rate_limit('mailip_' . ip_hash(client_ip()), 12, 3600)) { fail(429, '请求过于频繁，请稍后再试'); }

    $code = (string)random_int(100000, 999999);
    $now  = now_utc();
    $exp  = gmdate('Y-m-d H:i:s', time() + email_code_ttl());

    /* 一个邮箱每种用途只保留最新一条：旧的立刻作废 */
    db_exec('DELETE FROM email_codes WHERE email_norm = ? AND purpose = ?', array($norm, $purpose));
    db_insert('INSERT INTO email_codes (email_norm, purpose, code_hash, user_id, tries, ip_hash, created_at, expires_at)
               VALUES (?, ?, ?, ?, 0, ?, ?, ?)',
        array($norm, $purpose, email_code_hash($code, $norm), $uid, ip_hash(client_ip()), $now, $exp));

    $ttlMin = (int)ceil(email_code_ttl() / 60);
    list($subject, $lead, $tail) = email_code_body($lang, $purpose, $code, $ttlMin);
    $text = $subject . "\n\n" . $lead . "\n\n    " . $code . "\n\n" . $tail . "\n\n" . email_site_name();
    $html = mail_html(email_site_name(), $subject, $lead, $code, $tail);

    $r = mail_send($email, $subject, $text, $html);
    if (empty($r['ok'])) {
        /* 发不出去就把这枚码作废，免得留下一枚「用户永远收不到」的有效码 */
        db_exec('DELETE FROM email_codes WHERE email_norm = ? AND purpose = ?', array($norm, $purpose));
        app_log('email send failed: ' . $r['reason'] . ' ' . json_encode($r['tried'], JSON_UNESCAPED_UNICODE));
        fail(502, '验证码邮件发送失败，请稍后再试');
    }
    return array('ok' => true, 'ttl' => email_code_ttl());
}

/**
 * 校验验证码（不删除，由调用方在校验成功后决定是否消费）。
 * @return array ok|reason
 */
function email_code_check(string $email, string $purpose, string $code): array
{
    $norm = mail_norm(trim($email));
    $code = preg_replace('/\D/', '', $code);
    if ($norm === '' || strlen($code) !== email_code_len()) { return array('ok' => false, 'reason' => 'bad_code'); }
    if (!email_ready()) { return array('ok' => false, 'reason' => 'not_ready'); }

    $row = db_one('SELECT * FROM email_codes WHERE email_norm = ? AND purpose = ? ORDER BY id DESC LIMIT 1',
        array($norm, $purpose === 'login' ? 'login' : 'bind'));
    if ($row === null) { return array('ok' => false, 'reason' => 'no_code'); }
    if (strtotime((string)$row['expires_at'] . ' UTC') < time()) {
        db_exec('DELETE FROM email_codes WHERE id = ?', array((int)$row['id']));
        return array('ok' => false, 'reason' => 'expired');
    }
    if ((int)$row['tries'] >= 5) {
        db_exec('DELETE FROM email_codes WHERE id = ?', array((int)$row['id']));
        return array('ok' => false, 'reason' => 'too_many_tries');
    }
    if (!hash_equals((string)$row['code_hash'], email_code_hash($code, $norm))) {
        db_exec('UPDATE email_codes SET tries = tries + 1 WHERE id = ?', array((int)$row['id']));
        return array('ok' => false, 'reason' => 'wrong_code');
    }
    return array('ok' => true, 'reason' => 'ok', 'row' => $row);
}

/** 消费一枚码（校验通过后调用） */
function email_code_consume(string $email, string $purpose)
{
    db_exec('DELETE FROM email_codes WHERE email_norm = ? AND purpose = ?',
        array(mail_norm($email), $purpose === 'login' ? 'login' : 'bind'));
}

/**
 * 绑定邮箱：先校验验证码，再写库。
 * 唯一性由两层保证：这里查一次给出友好提示，数据库唯一索引兜底并发。
 */
function email_bind_apply(int $uid, string $email): array
{
    if (!email_ready()) { fail(503, '邮箱功能暂未开放'); }
    $email = trim($email);
    if (!mail_valid($email)) { fail(400, '邮箱格式不正确'); }
    $norm = mail_norm($email);

    $owner = db_one('SELECT id, username FROM users WHERE email_norm = ? LIMIT 1', array($norm));
    if ($owner !== null && (int)$owner['id'] !== $uid) { fail(409, '这个邮箱已被其他账号绑定'); }

    $old = db_one('SELECT email FROM users WHERE id = ? LIMIT 1', array($uid));
    try {
        db_exec('UPDATE users SET email = ?, email_norm = ?, email_at = UTC_TIMESTAMP() WHERE id = ?',
            array($email, $norm, $uid));
    } catch (Throwable $e) {
        /* 唯一索引报冲突 = 并发下被别人抢先绑走 */
        if (stripos($e->getMessage(), 'Duplicate') !== false) { fail(409, '这个邮箱已被其他账号绑定'); }
        throw $e;
    }
    app_log('email bound uid=' . $uid . ' ' . mail_mask($email));
    return array('email' => $email, 'masked' => mail_mask($email),
                 'old' => ($old !== null && !empty($old['email'])) ? mail_mask((string)$old['email']) : '');
}

/** 当前账号的邮箱（空串表示未绑定） */
function user_email_of(int $uid): string
{
    if (!email_ready()) { return ''; }
    $r = db_one('SELECT email FROM users WHERE id = ? LIMIT 1', array($uid));
    return ($r !== null && !empty($r['email'])) ? (string)$r['email'] : '';
}

/**
 * 按「UID / 用户名 / 邮箱」任一种写法取用户行。
 * 顺序：邮箱（含 @）→ 用户名规整值 → 8 位 UID。
 * 先查用户名再查 UID，是因为用户名可能恰好是 8 位，那时人想登的是用户名。
 */
function user_row_by_identifier(string $identifier)
{
    $id = trim($identifier);
    if ($id === '' || mb_strlen($id, 'UTF-8') > 190) { return null; }
    if (strpos($id, '@') !== false) {
        if (!col_ok('users', 'email_norm')) { return null; }
        return db_one('SELECT * FROM users WHERE email_norm = ? LIMIT 1', array(mail_norm($id)));
    }
    $u = db_one('SELECT * FROM users WHERE username_norm = ? LIMIT 1', array(norm_username($id)));
    if ($u !== null) { return $u; }
    if (preg_match('/^[A-Za-z0-9]{8}$/', $id) && col_ok('users', 'uid8')) {
        return db_one('SELECT * FROM users WHERE uid8 = ? LIMIT 1', array($id));
    }
    return null;
}

/**
 * 邮箱验证码登录：校验码 → 取账号 → 发令牌。
 * 账号必须已把该邮箱绑定到名下，否则视同登录失败（不区分「未注册」与「未绑定」）。
 */
function user_login_by_code(string $email, string $code): array
{
    $ip = client_ip();
    $norm = mail_norm(trim($email));
    if (login_locked($norm)) { timing_delay(); fail(429, '登录尝试过于频繁，请稍后再试'); }

    $chk = email_code_check($email, 'login', $code);
    if (empty($chk['ok'])) {
        login_mark($ip, false, $norm);
        timing_delay();
        $map = array('expired' => '验证码已过期，请重新获取',
            'too_many_tries' => '错误次数过多，请重新获取验证码',
            'wrong_code' => '验证码不正确',
            'no_code' => '验证码不正确');
        fail(401, isset($map[$chk['reason']]) ? $map[$chk['reason']] : '验证码不正确');
    }
    $u = email_ready() ? db_one('SELECT * FROM users WHERE email_norm = ? LIMIT 1', array($norm)) : null;
    if ($u === null) {
        login_mark($ip, false, $norm);
        timing_delay();
        fail(401, '验证码不正确');
    }
    email_code_consume($email, 'login');
    login_mark($ip, true, $norm);
    app_log('login by email code uid=' . (int)$u['id'] . ' ' . mail_mask($email));
    return login_finish($u);
}
