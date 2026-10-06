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

define('DISC_MIN_DAYS_FOR_COUNT', 7);   // 计入累计次数的封禁天数下限（≥ 该值才算一次）
define('DISC_PURGE_THRESHOLD', 10);      // 累计达到该次数后永久删除账号

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

/** 由封禁天数算出解封时间（UTC 字符串）；0 表示永久，返回 null。
    支持小数天：0.5 = 12 小时，1.5 = 36 小时 —— 短时冷静期不必凑整天。 */
function discipline_ban_until($days)
{
    $d = (float)$days;
    if ($d <= 0) { return null; }
    return gmdate('Y-m-d H:i:s', time() + (int)round($d * 86400));
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
                            int $byUid, $banDays = 0, bool $purge = false): array
{
    $reasons = array_values(array_filter(array_map(function ($r) { return trim((string)$r); }, $reasons)));
    $reasons = array_slice($reasons, 0, 20);
    if (!$reasons) { throw new InvalidArgumentException('请至少选择或填写一条理由'); }
    if (!$userIds) { throw new InvalidArgumentException('请至少选择一个用户'); }
    if (mb_strlen($note, 'UTF-8') > 500) { $note = mb_substr($note, 0, 500, 'UTF-8'); }

    $now = now_utc();
    $banDays = (float)$banDays;                                    // 支持小数天：0.5 = 12 小时
    $banDays = max(0.0, min(36500.0, $banDays));                   // 0 = 永久
    $banUntil = $banAccount ? discipline_ban_until($banDays) : null;
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

        if (col_ok('discipline_reports', 'oid')) {
            $rid = db_insert(
                'INSERT INTO discipline_reports (user_id, username, reasons, note, banned, ban_days, ban_until, purged, ip_banned, by_uid, views, oid, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
                array($uid, (string)$u['username'], json_encode($reasons, JSON_UNESCAPED_UNICODE),
                      $note, $banAccount ? 1 : 0, $banDays, $banUntil, $purge ? 1 : 0,
                      $banIp ? 1 : 0, $byUid, oid_new('discipline_reports'), $now)
            );
        } else {
            $rid = db_insert(
                'INSERT INTO discipline_reports (user_id, username, reasons, note, banned, ban_days, ban_until, purged, ip_banned, by_uid, views, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)',
                array($uid, (string)$u['username'], json_encode($reasons, JSON_UNESCAPED_UNICODE),
                      $note, $banAccount ? 1 : 0, $banDays, $banUntil, $purge ? 1 : 0,
                      $banIp ? 1 : 0, $byUid, $now)
            );
        }
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

    /* 累计达到阈值 → 永久删除账号（不可恢复的终局处理） */
    $deleted = array();
    foreach (array_slice($userIds, 0, 50) as $uidRaw) {
        $uid2 = (int)$uidRaw;
        if ($uid2 <= 0 || in_array($uid2, $deleted, true)) { continue; }
        if (discipline_maybe_purge_account($uid2)) { $deleted[] = $uid2; }
    }

    /* 通报数量超上限时，顺手清掉最旧的已解封通报 */
    discipline_prune();

    return array('created' => $created, 'accounts_banned' => $accBanned,
                 'ips_banned' => $ipBanned, 'no_ip' => $noIp,
                 'purged_comments' => $purged['comments'],
                 'purged_messages' => $purged['messages'],
                 'purged_images'   => $purged['images'],
                 'accounts_deleted' => count($deleted));
}

/**
 * 通报行的对外结构（列表、最新一条、被封者本人共用）。
 * alive 表示「账号封停是否仍在有效期内」——已过期即视为已解封，与读取时顺手解封的口径一致。
 */
function discipline_item(array $r): array
{
    $uid    = (int)($r['user_id'] ?? 0);
    $banned = ((int)($r['banned'] ?? 0) === 1);
    $rid    = (int)($r['id'] ?? 0);
    return array(
        'id'         => $rid,
        'oid'        => (string)($r['oid'] ?? ''),
        'user_id'    => $uid,
        'username'   => (string)($r['username'] ?? ''),
        'username_now' => $uid > 0
            ? (string)(db_val('SELECT username FROM users WHERE id = ? LIMIT 1', array($uid)) ?? '')
            : '',
        'avatar'     => identicon_data_uri((string)($r['username'] ?? '用户'), 40),
        'reasons'    => discipline_reasons_of($r),
        'note'       => (string)($r['note'] ?? ''),
        'banned'     => $banned,
        'alive'      => $banned && discipline_ban_alive($r),
        'ban_days'   => (float)($r['ban_days'] ?? 0),
        'ban_until'  => empty($r['ban_until']) ? '' : to_local((string)$r['ban_until']),
        'ip_banned'  => ((int)($r['ip_banned'] ?? 0) === 1),
        'views'      => (int)($r['views'] ?? 0),
        'comments'   => (table_exists('comments') && $rid > 0)
            ? (int)db_val('SELECT COUNT(*) FROM comments WHERE target_type = ? AND work_id = ? AND is_deleted = 0',
                          array('discipline', $rid))
            : 0,
        'user_count' => $uid > 0 ? discipline_user_count($uid) : 0,
        'user_alive' => $uid > 0 && db_val('SELECT 1 FROM users WHERE id = ? LIMIT 1', array($uid)) !== null,
        'created'    => to_local((string)($r['created_at'] ?? now_utc())),
    );
}

/** 最新一条通报（供全站弹窗）；无记录返回 null */
function discipline_latest()
{
    if (!table_exists('discipline_reports')) { return null; }
    $r = db_one('SELECT * FROM discipline_reports ORDER BY id DESC LIMIT 1');
    return $r === null ? null : discipline_item($r);
}

/**
 * 通报保留上限：超过 $keep 条时，自动删除已解封的通报 —— 越旧越先删。
 * 仍在封停中的通报（含永久封停）永不自动删除；删记录时一并清除它名下的 IP 黑名单。
 */
function discipline_prune(int $keep = 10): int
{
    if ($keep < 1 || !table_exists('discipline_reports')) { return 0; }
    $total = (int)db_val('SELECT COUNT(*) FROM discipline_reports');
    if ($total <= $keep) { return 0; }

    $rows = db_all('SELECT id, user_id FROM discipline_reports
                    WHERE banned = 0 OR (ban_until IS NOT NULL AND ban_until <= UTC_TIMESTAMP())
                    ORDER BY id ASC LIMIT ' . (int)($total - $keep));
    $n = 0;
    foreach ($rows as $r) {
        $id = (int)$r['id']; $uid = (int)$r['user_id'];
        try {
            db_exec('DELETE FROM banned_ips WHERE report_id = ?', array($id));
            db_exec('DELETE FROM discipline_reports WHERE id = ?', array($id));
            if ($uid > 0) {
                $still = db_one('SELECT id FROM discipline_reports WHERE user_id = ? AND banned = 1 LIMIT 1', array($uid));
                if ($still === null) { db_exec('UPDATE users SET is_banned = 0, ban_until = NULL WHERE id = ?', array($uid)); }
            }
            $n++;
        } catch (Throwable $e) { app_log('discipline prune failed #' . $id . ': ' . $e->getMessage()); }
    }
    if ($n > 0) { app_log('discipline prune: 删除 ' . $n . ' 条已解封通报（上限 ' . $keep . '）'); }
    return $n;
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
    foreach ($rows as $r) { $items[] = discipline_item($r); }
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

/**
 * 当前访问者是否处于被封禁状态（管理员与副管理员豁免）。
 * 用于评论等接口的服务端拦截 —— 前端做了限制不算数，这里才是真正的门。
 */
function discipline_visitor_blocked(): bool
{
    try {
        $ident = current_identity();
        if (is_array($ident) && in_array((string)($ident['role'] ?? ''), array('admin', 'subadmin'), true)) {
            return false;
        }
        $uid = is_array($ident) ? (int)($ident['uid'] ?? 0) : 0;
        return discipline_hit($uid, discipline_ip_hash(discipline_client_ip())) !== null;
    } catch (Throwable $e) {
        return false;      // 判断本身出错时放行，绝不因拦截逻辑故障而挡人
    }
}

/** 通报列表的统一评论区目标：挂在「违纪通报」目录上（work_id 固定 0） */
function discipline_list_target(): array
{
    return array('target_type' => 'discipline_list', 'work_id' => 0);
}

/**
 * 该用户计入累计的通报次数。
 * 只统计「单次封禁天数 ≥ DISC_MIN_DAYS_FOR_COUNT」的通报 —— 短期封禁只是提醒，不该累积成重罚。
 */
function discipline_user_count(int $uid): int
{
    if ($uid <= 0 || !table_exists('discipline_reports')) { return 0; }
    return (int)db_val('SELECT COUNT(*) FROM discipline_reports WHERE user_id = ? AND ban_days >= ?',
                       array($uid, DISC_MIN_DAYS_FOR_COUNT));
}

/** 该用户全部通报数（含短期） */
function discipline_user_count_all(int $uid): int
{
    if ($uid <= 0 || !table_exists('discipline_reports')) { return 0; }
    return (int)db_val('SELECT COUNT(*) FROM discipline_reports WHERE user_id = ?', array($uid));
}

/**
 * 永久删除账号及其全部数据。
 * 与「通报」不同：这是不可恢复的终局处理，只在该用户累计达到阈值时自动触发。
 * 通报记录保留（作为违规历史），用户行与个人数据一并清除。
 */
function discipline_account_purge(int $uid): bool
{
    if ($uid <= 0) { return false; }
    $u = db_one('SELECT id, username, role FROM users WHERE id = ? LIMIT 1', array($uid));
    if ($u === null) { return false; }
    /* 管理员与副管理员绝不在此处置范围内 */
    if ((string)$u['role'] !== 'user') { return false; }

    try {
        /* 账号被永久删除 → 针对它的 IP 封禁同时失去意义，立刻取消并清出黑名单，
           否则同一网络的好人会被一条已不存在的账号连坐。 */
        db_exec('DELETE FROM banned_ips WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM user_visits WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM comments WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM comment_votes WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM work_votes WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM messages WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM ai_messages WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM feedback WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM users WHERE id = ?', array($uid));
        app_log('discipline: user #' . $uid . ' permanently deleted (reached ' . DISC_PURGE_THRESHOLD . ' reports)');
        return true;
    } catch (Throwable $e) {
        app_log('discipline account purge failed #' . $uid . ': ' . $e->getMessage());
        return false;
    }
}

/** 累计达阈值就永久删号；返回是否已删除 */
function discipline_maybe_purge_account(int $uid): bool
{
    if (discipline_user_count($uid) < DISC_PURGE_THRESHOLD) { return false; }
    return discipline_account_purge($uid);
}

/**
 * 通报二次设置。$patch 支持：
 *   reasons（字符串数组）、note、ban_days（0=永久）、unban（true 立即解封）、ip_banned（bool）
 * 返回更新后的通报行。
 */
function discipline_update(int $id, array $patch)
{
    $row = discipline_get($id);
    if ($row === null) { throw new InvalidArgumentException('通报不存在'); }
    $uid = (int)$row['user_id'];

    $set = array(); $args = array();

    if (array_key_exists('reasons', $patch)) {
        $rs = is_array($patch['reasons']) ? $patch['reasons'] : array();
        $rs = array_slice(array_values(array_filter(array_map(function ($r) { return trim((string)$r); }, $rs))), 0, 20);
        if (!$rs) { throw new InvalidArgumentException('请至少保留一条理由'); }
        $set[] = 'reasons = ?'; $args[] = json_encode($rs, JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('note', $patch)) {
        $set[] = 'note = ?';
        $args[] = mb_substr(trim((string)$patch['note']), 0, 500, 'UTF-8');
    }

    $unban    = !empty($patch['unban']);
    $banDays  = array_key_exists('ban_days', $patch) ? max(0.0, min(36500.0, (float)$patch['ban_days'])) : null;

    if ($unban) {
        $set[] = 'banned = 0'; $set[] = 'ban_days = 0'; $set[] = 'ban_until = NULL';
        db_exec('UPDATE users SET is_banned = 0, ban_until = NULL WHERE id = ?', array($uid));
    } elseif ($banDays !== null) {
        $until = discipline_ban_until($banDays);
        $set[] = 'banned = 1'; $set[] = 'ban_days = ?'; $set[] = 'ban_until = ?';
        $args[] = $banDays; $args[] = $until;
        db_exec('UPDATE users SET is_banned = 1, ban_until = ? WHERE id = ?', array($until, $uid));
    }

    if (array_key_exists('ip_banned', $patch)) {
        $on = !empty($patch['ip_banned']);
        $set[] = 'ip_banned = ?'; $args[] = $on ? 1 : 0;
        if (!$on) { db_exec('DELETE FROM banned_ips WHERE report_id = ?', array($id)); }
    }

    if ($set) {
        $args[] = $id;
        db_exec('UPDATE discipline_reports SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
        app_log('discipline: report #' . $id . ' updated');
    }
    return discipline_get($id);
}

/**
 * 反篡改上报：前端发现有人试图绕过全屏封禁说明（例如用脚本删掉它）时调用。
 * 前三次只提醒，第四次起每次把封禁时间延长 0.05 天（72 分钟）。
 * 「同一会话不重复增加」由前端按会话去重，这里再做一层按通报的频率限制。
 */
function discipline_tamper(int $id): array
{
    $row = discipline_get($id);
    if ($row === null) { return array('count' => 0, 'added' => false, 'reason' => 'not_found'); }

    $cnt = (int)($row['tamper_count'] ?? 0) + 1;
    try {
        db_exec('UPDATE discipline_reports SET tamper_count = ?, tamper_at = UTC_TIMESTAMP() WHERE id = ?', array($cnt, $id));
    } catch (Throwable $e) { app_log('discipline tamper write failed: ' . $e->getMessage()); }

    $base = array('count' => $cnt, 'added' => false,
                  'left' => max(0, 3 - $cnt),
                  'until' => isset($row['ban_until']) && $row['ban_until'] !== null ? to_local((string)$row['ban_until']) : '');

    /* 前三次放过 */
    if ($cnt <= 3) { return $base; }
    /* 未封停 / 永久封停：没有可延长的期限，只计数 */
    if ((int)$row['banned'] !== 1 || $row['ban_until'] === null) { return $base; }

    $ts = strtotime((string)$row['ban_until'] . ' UTC');
    if ($ts === false) { return $base; }
    $newUntil = gmdate('Y-m-d H:i:s', $ts + (int)round(0.05 * 86400));
    $newDays  = (float)$row['ban_days'] + 0.05;
    try {
        db_exec('UPDATE discipline_reports SET ban_until = ?, ban_days = ? WHERE id = ?', array($newUntil, $newDays, $id));
        if ((int)$row['user_id'] > 0) {
            db_exec('UPDATE users SET ban_until = ? WHERE id = ?', array($newUntil, (int)$row['user_id']));
        }
        app_log('discipline: report #' . $id . ' tamper #' . $cnt . ' → 封禁延长 0.05 天');
        $base['added'] = true;
        $base['until'] = to_local($newUntil);
    } catch (Throwable $e) { app_log('discipline tamper extend failed: ' . $e->getMessage()); }
    return $base;
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
