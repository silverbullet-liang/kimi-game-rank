<?php
declare(strict_types=1);
/**
 * 扫码登录
 * ------------------------------------------------------------
 * 形态参考 Nextcloud 的 Login Flow v2 与 OAuth Device Flow：
 * 桌面端建一张一次性短票据，二维码里只带公开 id；手机确认后，
 * 桌面端凭「只下发过一次的 secret」把令牌领走。
 *
 * 这个接口默认会被刷，所以按「可被滥用」来设计：
 *   · 创建按 IP（8/小时）与全站（400/小时）双限速；
 *   · 票据 2 分钟过期、轮询 90 次即作废，过期行顺手删；
 *   · 轮询凭证只下发一次，库里只存哈希，凭证错也计一次尝试；
 *   · 全站同时存活的待确认票据有上限，超过直接拒绝，不往库里堆；
 *   · 领取令牌后票据立即删除（一次性），不会二次兑换。
 */

function qr_ttl_seconds(): int { return 120; }
function qr_poll_interval(): int { return 2; }
function qr_max_polls(): int { return 90; }
function qr_live_cap(): int { return 500; }

/** 每 IP 的限速桶（不保存明文 IP） */
function qr_ip_bucket(): string
{
    $ip = client_ip();
    return substr(hash('sha256', (string)cfg('secrets.cron_key', 'kimgr') . '|qrip|' . $ip), 0, 16);
}

function qr_table_ready(): bool
{
    return table_exists('qr_logins');
}

/** 建一张票：返回 qr_id / secret（只此一次）/ 有效期 / 建议轮询间隔 */
function qr_create(): array
{
    if (!qr_table_ready()) { fail(503, '扫码登录暂不可用'); }

    $bucket = qr_ip_bucket();
    if (!rate_limit('qr_c_' . $bucket, 8, 3600)) {
        fail(429, '二维码开得太频繁，请稍后再试');
    }
    if (!rate_limit('qr_c_all', 400, 3600)) {
        fail(429, '当前尝试扫码登录的人较多，请稍后再试');
    }

    /* 低概率清一次历史票据，避免每次创建都做统计 */
    if (random_int(1, 10) === 1) {
        db_exec('DELETE FROM qr_logins WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
    }
    $live = (int)db_val('SELECT COUNT(*) FROM qr_logins WHERE expires_at > UTC_TIMESTAMP()');
    if ($live >= qr_live_cap()) {
        fail(503, '扫码登录繁忙，请稍后再试');
    }

    $qrId   = bin2hex(random_bytes(8));    // 16 hex：二维码里带的就是它
    $secret = bin2hex(random_bytes(16));   // 32 hex：轮询凭证，明文不落库
    $ttl    = qr_ttl_seconds();

    db_insert('INSERT INTO qr_logins (qr_id, secret_hash, status, ip_hash, created_at, expires_at)
               VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)',
        array($qrId, hash('sha256', $secret), 'pending', $bucket, gmdate('Y-m-d H:i:s', time() + $ttl)));

    return array(
        'qr_id'      => $qrId,
        'secret'     => $secret,
        'expires_in' => $ttl,
        'interval'   => qr_poll_interval(),
    );
}

/**
 * 轮询：pending / confirmed（带令牌）/ expired。
 * confirmed 是一次性的——领到令牌即删票，第二次来只会拿到 expired。
 */
function qr_poll(string $qrId, string $secret): array
{
    if (!qr_table_ready()) { fail(503, '扫码登录暂不可用'); }
    $qrId = preg_replace('/[^a-f0-9]/i', '', $qrId);
    $secret = trim($secret);
    if (strlen($qrId) !== 16 || $secret === '') { fail(400, '参数错误'); }

    if (!rate_limit('qr_p_' . qr_ip_bucket(), 150, 60)) {
        fail(429, '轮询过于频繁');
    }

    $row = db_one('SELECT * FROM qr_logins WHERE qr_id = ? LIMIT 1', array($qrId));
    if ($row === null) { fail(404, '二维码已失效，请刷新'); }

    /* 先记一次再判定：凭证错也算尝试，防止拿 id 枚举 */
    db_exec('UPDATE qr_logins SET polls = polls + 1 WHERE id = ?', array((int)$row['id']));
    if ((int)$row['polls'] + 1 > qr_max_polls()) {
        db_exec('DELETE FROM qr_logins WHERE id = ?', array((int)$row['id']));
        fail(410, '二维码已失效，请刷新');
    }
    if (!hash_equals((string)$row['secret_hash'], hash('sha256', $secret))) {
        fail(403, '凭证不正确');
    }
    $expTs = strtotime((string)$row['expires_at'] . ' UTC');
    if ($expTs === false || $expTs <= time()) {
        db_exec('DELETE FROM qr_logins WHERE id = ?', array((int)$row['id']));
        return array('status' => 'expired');
    }
    if ((string)$row['status'] === 'done') {
        fail(410, '二维码已使用，请刷新');
    }
    if ((string)$row['status'] === 'pending') {
        return array('status' => 'pending', 'expires_in' => max(1, $expTs - time()));
    }

    /* confirmed：当场签发令牌，票即作废 */
    $uid  = (int)$row['uid'];
    $role = (string)$row['role'];
    $u = $uid > 0 ? db_one('SELECT id, username, role, is_banned FROM users WHERE id = ? LIMIT 1', array($uid)) : null;
    if ($u === null || (int)$u['is_banned'] === 1) {
        db_exec('DELETE FROM qr_logins WHERE id = ?', array((int)$row['id']));
        return array('status' => 'expired');
    }
    db_exec('DELETE FROM qr_logins WHERE id = ?', array((int)$row['id']));

    if ($role === 'admin') {
        $tok = issue_admin_token();
        $outUid = 0;
        $name = 'admin';
    } elseif ($role === 'subadmin') {
        $tok = issue_subadmin_token((string)$u['username'], $uid);
        $outUid = $uid;
        $name = (string)$u['username'];
    } else {
        $role = 'user';
        $tok = issue_user_token($uid);
        $outUid = $uid;
        $name = (string)$u['username'];
    }
    db_exec('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', array($uid));

    return array('status' => 'confirmed', 'token' => $tok, 'uid' => $outUid, 'role' => $role, 'username' => $name);
}

/** 手机端确认：把当前账号授权给这枚二维码（需登录 + CSRF） */
function qr_confirm(array $ident, string $qrId): array
{
    if (!qr_table_ready()) { fail(503, '扫码登录暂不可用'); }
    $qrId = preg_replace('/[^a-f0-9]/i', '', $qrId);
    if (strlen($qrId) !== 16) { fail(400, '参数错误'); }

    $role = (string)(isset($ident['role']) ? $ident['role'] : '');
    if (!in_array($role, array('user', 'subadmin', 'admin'), true)) {
        fail(403, '请先登录账号');
    }
    /* 落库用 users 表里的行 id（管理员是 admin 那一行），与设备管理同一套换算 */
    $uid = function_exists('actor_uid') ? (int)actor_uid($ident) : (int)(isset($ident['uid']) ? $ident['uid'] : 0);
    if ($uid <= 0) { fail(403, '账号不可用'); }
    if (!rate_limit('qr_ok_' . $uid, 20, 3600)) { fail(429, '确认过于频繁，请稍后再试'); }

    $row = db_one('SELECT * FROM qr_logins WHERE qr_id = ? LIMIT 1', array($qrId));
    if ($row === null) { fail(404, '二维码已失效，请刷新后重试'); }
    $expTs = strtotime((string)$row['expires_at'] . ' UTC');
    if ($expTs === false || $expTs <= time()) {
        db_exec('DELETE FROM qr_logins WHERE id = ?', array((int)$row['id']));
        fail(410, '二维码已过期，请刷新后重试');
    }
    if ((string)$row['status'] !== 'pending') {
        fail(409, '这枚二维码已经用过了');
    }

    db_exec('UPDATE qr_logins SET status = ?, uid = ?, role = ?, confirmed_at = UTC_TIMESTAMP(),
                expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 90 SECOND) WHERE id = ?',
        array('confirmed', $uid, $role, (int)$row['id']));

    return array('ok' => true, 'username' => (string)(isset($ident['username']) ? $ident['username'] : ''));
}
