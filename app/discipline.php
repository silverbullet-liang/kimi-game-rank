<?php
/**
 * 违纪通报
 * ------------------------------------------------------------
 * 管理员在用户列表里对用户一键通报：可勾选多条理由、补充说明，并可同时
 * 封停账号与访问 IP。被通报者再访问站点时，页面请求会被 302 到违纪界面，
 * 界面下方逐条列出理由，并像作品一样带评论区。
 *
 * 隐私：IP 只保留 sha256(盐 + IP) 指纹用于匹配与封禁，不存明文；
 *       展示一律用脱敏形式。管理员与副管理员永不因此被拦。
 */
declare(strict_types=1);

/** 预置理由（面板可增删，存 settings；这里只给默认值） */
function discipline_reasons(): array
{
    $raw = setting_get('discipline_reasons', '');
    if ($raw !== '') {
        $list = json_decode($raw, true);
        if (is_array($list)) {
            $out = array();
            foreach ($list as $r) {
                $r = trim((string)$r);
                if ($r !== '') { $out[] = $r; }
            }
            if ($out) { return $out; }
        }
    }
    return array(
        '辱骂、人身攻击或歧视他人',
        '发布色情、暴力或违法内容',
        '广告引流、刷屏或恶意骚扰',
        '盗用他人作品或冒充他人',
        '违反社区公约的其他行为',
    );
}

function discipline_reasons_set(array $list)
{
    $out = array();
    foreach ($list as $r) {
        $r = trim((string)$r);
        if ($r !== '' && mb_strlen($r, 'UTF-8') <= 60) { $out[] = $r; }
        if (count($out) >= 30) { break; }
    }
    setting_set('discipline_reasons', json_encode($out, JSON_UNESCAPED_UNICODE));
}

/** IP 指纹：加盐单向哈希，够用来匹配，不足以反推 */
function discipline_ip_hash(string $ip): string
{
    if ($ip === '') { return ''; }
    return hash('sha256', (string)cfg('secrets.cron_key', 'kimgr') . '|' . $ip);
}

/** 当前访问者 IP（与访问记录同源，保证指纹一致） */
function discipline_client_ip(): string
{
    return function_exists('client_ip') ? (string)client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** 该 IP 指纹是否在黑名单里 */
function discipline_ip_banned(string $hash): bool
{
    if ($hash === '' || !table_exists('banned_ips')) { return false; }
    return db_val('SELECT 1 FROM banned_ips WHERE ip_hash = ? LIMIT 1', array($hash)) !== null;
}

/** 封禁是否仍在有效期内（NULL 表示永久） */
function discipline_ban_alive($row): bool
{
    $until = isset($row['ban_until']) ? (string)$row['ban_until'] : '';
    if ($until === '') { return true; }
    $ts = strtotime($until . ' UTC');
    return $ts !== false && $ts > time();
}

/**
 * 该用户是否处于「已通报且封停」状态；返回通报记录或 null。
 * 到期即视为解封 —— 不需要定时任务，读取时顺手把 users 上的标记也清掉。
 */
function discipline_user_block(int $uid)
{
    if ($uid <= 0 || !table_exists('discipline_reports')) { return null; }
    $row = db_one('SELECT * FROM discipline_reports WHERE user_id = ? AND banned = 1 ORDER BY id DESC LIMIT 1', array($uid));
    if ($row === null) { return null; }
    if (!discipline_ban_alive($row)) {
        try { db_exec('UPDATE users SET is_banned = 0, ban_until = NULL WHERE id = ? AND is_banned = 1', array($uid)); } catch (Throwable $e) { }
        return null;
    }
    return $row;
}

/** 当前访问者命中的通报（IP 优先，其次账号）；无则 null */
function discipline_hit(int $uid, string $ipHash)
{
    if (!table_exists('discipline_reports')) { return null; }
    if (discipline_ip_banned($ipHash)) {
        $row = db_one('SELECT * FROM discipline_reports WHERE ip_banned = 1 ORDER BY id DESC LIMIT 1');
        if ($row !== null) { return $row; }
        /* 黑名单有记录但找不到对应通报：仍然拦截，只是没有理由可列 */
        return array('id' => 0, 'user_id' => 0, 'username' => '', 'reasons' => '[]',
                     'note' => '', 'views' => 0, 'created_at' => now_utc(), 'ip_banned' => 1, 'banned' => 0);
    }
    return discipline_user_block($uid);
}

/** 从 media.php?id=YYYYMMDD/hash.ext 的地址里取出文件并删除 */
function discipline_unlink_media(string $url): bool
{
    if (!preg_match('#media\.php\?id=([^&\s]+)#i', $url, $m)) { return false; }
    $idv = rawurldecode($m[1]);
    if (!preg_match('#^(\d{8})/([a-f0-9]{16})\.(jpg|jpeg|png|gif|webp)$#i', $idv, $mm)) { return false; }
    $path = APP_ROOT . '/storage/uploads/' . $mm[1] . '/' . $mm[2] . '.' . strtolower($mm[3]);
    return is_file($path) ? (bool)@unlink($path) : false;
}

/**
 * 清理一个用户的全部内容：评论、对话，以及对话里发过的图片文件。
 * 评论与对话走「软删除」——对用户与其他读者完全不可见，但保留可追溯与误删恢复的余地；
 * 图片文件则直接从磁盘删掉（它们没有别的归属记录，留着只会占空间）。
 */
function discipline_purge_user(int $uid): array
{
    $out = array('comments' => 0, 'messages' => 0, 'images' => 0);
    if ($uid <= 0) { return $out; }

    /* 评论 → 入回收站（标记为管理员删除） */
    try {
        $out['comments'] = (int)db_exec('UPDATE comments SET is_deleted = 1, deleted_by = 2, is_blocked = 0
                                         WHERE user_id = ? AND is_deleted = 0', array($uid));
    } catch (Throwable $e) { app_log('purge comments failed: ' . $e->getMessage()); }

    /* 对话 → 撤回；先删掉其中的图片文件再改标记 */
    try {
        foreach (db_all('SELECT media_url FROM messages WHERE user_id = ? AND media_url <> ?', array($uid, '')) as $r) {
            if (discipline_unlink_media((string)$r['media_url'])) { $out['images']++; }
        }
        $out['messages'] = (int)db_exec('UPDATE messages SET is_recalled = 1
                                         WHERE user_id = ? AND is_recalled = 0', array($uid));
    } catch (Throwable $e) { app_log('purge messages failed: ' . $e->getMessage()); }

    app_log('discipline purge user #' . $uid . ': comments=' . $out['comments']
          . ' messages=' . $out['messages'] . ' images=' . $out['images']);
    return $out;
}

/**
 * 到期自动解封：登录与鉴权时顺手检查一次，避免「早该放出来了却还挡着」。
 * 只在确实有到期封禁时才写库。
 */
function discipline_auto_unban(int $uid)
{
    if ($uid <= 0 || !table_exists('users')) { return; }
    try {
        $u = db_one('SELECT is_banned, ban_until FROM users WHERE id = ? LIMIT 1', array($uid));
        if ($u === null || (int)$u['is_banned'] !== 1) { return; }
        $until = isset($u['ban_until']) ? (string)$u['ban_until'] : '';
        if ($until !== '' && strtotime($until . ' UTC') <= time()) {
            db_exec('UPDATE users SET is_banned = 0, ban_until = NULL WHERE id = ?', array($uid));
            app_log('discipline: user #' . $uid . ' auto-unbanned (term expired)');
        }
    } catch (Throwable $e) { }
}

/** 理由数组 → 可读文本 */
function discipline_reasons_of(array $row): array
{
    $list = json_decode((string)$row['reasons'], true);
    return is_array($list) ? array_values(array_filter(array_map('strval', $list))) : array();
}

/**
 * 一键通报。$userIds 可多个；$reasons 可多条。
 * 返回 array(created, accounts_banned, ips_banned, no_ip)
 */
function discipline_create(array $userIds, array $reasons, string $note, bool $banAccount, bool $banIp,
                            int $byUid, int $banDays = 0, bool $purge = false): array
{
    $reasons = array_values(array_filter(array_map(function ($r) { return trim((string)$r); }, $reasons)));
    $reasons = array_slice($reasons, 0, 20);
    if (!$reasons) { throw new InvalidArgumentException('请至少选择或填写一条理由'); }
    if (!$userIds) { throw new InvalidArgumentException('请至少选择一个用户'); }
    if (mb_strlen($note, 'UTF-8') > 500) { $note = mb_substr($note, 0, 500, 'UTF-8'); }

    $now = now_utc();
    $banDays = max(0, min(36500, $banDays));                       // 0 = 永久
    $banUntil = ($banAccount && $banDays > 0) ? gmdate('Y-m-d H:i:s', time() + $banDays * 86400) : null;
    $created = 0; $accBanned = 0; $ipBanned = 0; $noIp = 0;
    $purged = array('comments' => 0, 'messages' => 0, 'images' => 0);

    foreach (array_slice($userIds, 0, 50) as $uidRaw) {
        $uid = (int)$uidRaw;
        if ($uid <= 0) { continue; }
        $u = db_one('SELECT id, username, role FROM users WHERE id = ? LIMIT 1', array($uid));
        if ($u === null) { continue; }
        /* 管理员与副管理员不可被通报，避免自锁 */
        if ((string)$u['role'] !== 'user') { continue; }

        /* 该用户最近的几个 IP 指纹（可能换过网络） */
        $ips = array();
        if ($banIp) {
            $ips = db_all("SELECT ip_hash, ip_masked FROM user_visits
                           WHERE user_id = ? AND ip_hash <> '' GROUP BY ip_hash ORDER BY MAX(id) DESC LIMIT 5",
                          array($uid));
        }

        $rid = db_insert(
            'INSERT INTO discipline_reports (user_id, username, reasons, note, banned, ban_days, ban_until, purged, ip_banned, by_uid, views, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)',
            array($uid, (string)$u['username'], json_encode($reasons, JSON_UNESCAPED_UNICODE),
                  $note, $banAccount ? 1 : 0, $banDays, $banUntil, $purge ? 1 : 0,
                  $banIp ? 1 : 0, $byUid, $now)
        );
        $created++;

        if ($banAccount) {
            db_exec('UPDATE users SET is_banned = 1, ban_until = ? WHERE id = ?', array($banUntil, $uid));
            $accBanned++;
        }
        if ($purge) {
            $p = discipline_purge_user($uid);
            $purged['comments'] += (int)$p['comments'];
            $purged['messages'] += (int)$p['messages'];
            $purged['images']   += (int)$p['images'];
        }
        if ($banIp) {
            if (!$ips) { $noIp++; }
            foreach ($ips as $ip) {
                try {
                    db_exec('INSERT INTO banned_ips (ip_hash, ip_masked, report_id, user_id, created_at)
                             VALUES (?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE report_id = VALUES(report_id), user_id = VALUES(user_id)',
                            array((string)$ip['ip_hash'], (string)$ip['ip_masked'], (int)$rid, $uid, $now));
                    $ipBanned++;
                } catch (Throwable $e) { /* 单条失败不影响整体 */ }
            }
        }
        app_log('discipline: user #' . $uid . ' reported by #' . $byUid . ' (report #' . $rid . ')');
    }

    return array('created' => $created, 'accounts_banned' => $accBanned,
                 'ips_banned' => $ipBanned, 'no_ip' => $noIp,
                 'purged_comments' => $purged['comments'],
                 'purged_messages' => $purged['messages'],
                 'purged_images'   => $purged['images']);
}

/** 通报列表（含被通报者当前状态与命中数） */
function discipline_list(int $page, int $size = 20): array
{
    $page = max(1, $page);
    $off  = ($page - 1) * $size;
    if (!table_exists('discipline_reports')) {
        return array('items' => array(), 'total' => 0, 'page' => $page, 'size' => $size, 'total_pages' => 0, 'has_more' => false);
    }
    $total = (int)db_val('SELECT COUNT(*) FROM discipline_reports');
    $rows  = db_all("SELECT * FROM discipline_reports ORDER BY id DESC LIMIT $size OFFSET $off");
    $items = array();
    foreach ($rows as $r) {
        $items[] = array(
            'id'         => (int)$r['id'],
            'user_id'    => (int)$r['user_id'],
            'username'   => (string)$r['username'],
            'avatar'     => identicon_data_uri((string)$r['username'], 40),
            'reasons'    => discipline_reasons_of($r),
            'note'       => (string)($r['note'] ?? ''),
            'banned'     => (int)$r['banned'] === 1,
            'ip_banned'  => (int)$r['ip_banned'] === 1,
            'views'      => (int)$r['views'],
            'comments'   => (int)db_val('SELECT COUNT(*) FROM comments WHERE target_type = ? AND work_id = ? AND is_deleted = 0',
                                        array('discipline', (int)$r['id'])),
            'created'    => to_local((string)$r['created_at']),
        );
    }
    return array('items' => $items, 'total' => $total, 'page' => $page, 'size' => $size,
                 'total_pages' => $size > 0 ? (int)ceil($total / $size) : 0,
                 'has_more' => ($off + count($rows)) < $total);
}

/** 单条通报（供违纪界面使用；不计浏览） */
function discipline_get(int $id)
{
    if ($id <= 0 || !table_exists('discipline_reports')) { return null; }
    return db_one('SELECT * FROM discipline_reports WHERE id = ? LIMIT 1', array($id));
}

function discipline_view_count(int $id)
{
    if ($id <= 0) { return; }
    try { db_exec('UPDATE discipline_reports SET views = views + 1 WHERE id = ?', array($id)); } catch (Throwable $e) { }
}

/** 撤销：删记录并解除账号封停与 IP 黑名单 */
function discipline_delete(int $id): bool
{
    $row = discipline_get($id);
    if ($row === null) { return false; }
    db_exec('DELETE FROM banned_ips WHERE report_id = ?', array($id));
    db_exec('DELETE FROM discipline_reports WHERE id = ?', array($id));
    /* 该用户若还有其他未撤销的「封停」通报，保持封禁 */
    $uid = (int)$row['user_id'];
    $still = db_one('SELECT id FROM discipline_reports WHERE user_id = ? AND banned = 1 LIMIT 1', array($uid));
    if ($still === null) { db_exec('UPDATE users SET is_banned = 0, ban_until = NULL WHERE id = ?', array($uid)); }
    app_log('discipline: report #' . $id . ' revoked');
    return true;
}
