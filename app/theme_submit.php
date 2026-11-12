<?php
/**
 * 主题投稿目录（theme/1）
 * ------------------------------------------------------------
 * 规则：
 *   ① 每人默认 1 个投稿额度；在审与已通过都占额度，被拒的不算。
 *   ② 管理员可在控制面板把某个账号的额度提到 3（上限 3）。
 *   ③ 目录里同一 theme_id 只能出现一次，避免两份同名主题互相覆盖。
 *   ④ 投稿走与云同步相同的 theme_json_check 校验，危险模式一律拒收。
 * 页面：#/theme（独立页，从菜单进入）
 */
declare(strict_types=1);

/** 该账号的投稿额度（1~3） */
function ts_quota(int $uid): int
{
    $q = 1;
    if (col_ok('users', 'theme_quota')) {
        $v = db_val('SELECT theme_quota FROM `users` WHERE id = ?', array($uid));
        if ($v !== null) { $q = (int)$v; }
    }
    if ($q < 1) { $q = 1; }
    if ($q > 3) { $q = 3; }
    return $q;
}

/** 已占用的额度（在审 + 已通过） */
function ts_used(int $uid): int
{
    return (int)db_val("SELECT COUNT(*) FROM `theme_submissions`
                        WHERE user_id = ? AND status IN ('pending','approved')", array($uid));
}

/** 剩余额度 */
function ts_remaining(int $uid): int
{
    $left = ts_quota($uid) - ts_used($uid);
    return $left > 0 ? $left : 0;
}

/** 从主题 JSON 里取主色（#RRGGBB），取不到给空串 */
function ts_accent(string $raw): string
{
    $d = json_decode($raw, true);
    if (!is_array($d) || !isset($d['colour'])) { return ''; }
    $c = $d['colour'];
    $hex = is_string($c) ? $c : (is_array($c) && isset($c['accent']) ? (string)$c['accent'] : '');
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? strtoupper($hex) : '';
}

/** LIKE 用的转义（_ 与 % 是通配符，用户搜这两个字符时要按字面匹配） */
function ts_like(string $q): string
{
    return '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
}

/** 列表行（不含 body：一次拉 20 份各 1MB 的正文没有意义，点开再看） */
function ts_row(array $r, bool $withBody = false): array
{
    $out = array(
        'id'        => (int)$r['id'],
        'oid'       => (string)$r['oid'],
        'name'      => (string)$r['theme_name'],
        'theme_id'  => (string)$r['theme_id'],
        'accent'    => (string)$r['accent'],
        'author'    => (string)$r['username'],
        'bytes'     => (int)$r['bytes'],
        'status'    => (string)$r['status'],
        'featured'  => (int)($r['featured'] ?? 0),
        'created'   => (string)$r['created_at'],
        'reason'    => (string)$r['reject_reason'],
    );
    if ($withBody) { $out['json'] = (string)$r['body']; }
    return $out;
}

/** 提交一份主题 */
function ts_create(array $ident, string $raw): array
{
    $uid = (int)$ident['uid'];
    if (!table_exists('theme_submissions')) { fail(503, '主题目录暂不可用'); }

    list($ok, $errs, $meta) = theme_json_check($raw);
    if (!$ok) {
        $why = isset($errs[0]) ? $errs[0] : '主题未通过校验';
        fail(400, '主题未通过校验：' . $why);
    }
    $left = ts_remaining($uid);
    if ($left <= 0) {
        $q = ts_quota($uid);
        fail(429, $q > 1
            ? '你的投稿额度已用完（' . ts_used($uid) . '/' . $q . '），撤回一份后可再提交'
            : '每个账号只能提交 1 个主题，撤回后可再提交');
    }

    $tid = (string)$meta['id'];
    $dup = db_val("SELECT id FROM `theme_submissions`
                   WHERE theme_id = ? AND status IN ('pending','approved') LIMIT 1", array($tid));
    if ($dup !== null) {
        fail(409, '主题 id「' . $tid . '」已被占用，请换一个 id');
    }

    $name = (string)$meta['name'];
    $row = array(
        'oid'         => oid_new('theme_submissions'),
        'user_id'     => $uid,
        'username'    => (string)($ident['username'] ?? ''),
        'theme_id'    => $tid,
        'theme_name'  => mb_substr($name !== '' ? $name : $tid, 0, 64, 'UTF-8'),
        'accent'      => ts_accent($raw),
        'bytes'       => (int)$meta['bytes'],
        'body'        => $raw,
        'status'      => 'pending',
        'created_at'  => now_utc(),
    );
    $id = db_insert('INSERT INTO `theme_submissions`
        (oid, user_id, username, theme_id, theme_name, accent, bytes, body, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array($row['oid'], $row['user_id'], $row['username'], $row['theme_id'], $row['theme_name'],
              $row['accent'], $row['bytes'], $row['body'], $row['status'], $row['created_at']));

    return array(
        'id'        => (int)$id,
        'oid'       => $row['oid'],
        'status'    => 'pending',
        'quota'     => ts_quota($uid),
        'used'      => ts_used($uid),
        'remaining' => ts_remaining($uid),
    );
}

/** 公开目录：只列已通过的主题 */
function ts_public_list(string $q, int $page, int $size): array
{
    if (!table_exists('theme_submissions')) {
        return array('items' => array(), 'total' => 0, 'page' => 1, 'pages' => 0);
    }
    $page = $page < 1 ? 1 : $page;
    $size = $size < 1 ? 20 : ($size > 50 ? 50 : $size);
    $where = "status = 'approved'";
    $args = array();
    if ($q !== '') {
        $where .= ' AND (theme_name LIKE ? OR username LIKE ? OR theme_id LIKE ?)';
        $like = ts_like($q);
        $args = array($like, $like, $like);
    }
    $total = (int)db_val('SELECT COUNT(*) FROM `theme_submissions` WHERE ' . $where, $args);
    /* 推荐位永远在最前，其次按提交时间倒序 */
    $rows = db_all('SELECT id, oid, theme_name, theme_id, accent, username, bytes, status, featured,
                           created_at, reject_reason
                    FROM `theme_submissions` WHERE ' . $where . '
                    ORDER BY featured DESC, id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size), $args);
    $items = array();
    foreach ($rows as $r) { $items[] = ts_row($r); }
    return array(
        'items' => $items,
        'total' => $total,
        'page'  => $page,
        'pages' => $total > 0 ? (int)ceil($total / $size) : 0,
    );
}

/** 单条详情：已通过的谁都能取；本人的任何状态都能取；管理员全都能取 */
function ts_one(int $id, array $ident): array
{
    if (!table_exists('theme_submissions')) { fail(503, '主题目录暂不可用'); }
    $r = db_one('SELECT * FROM `theme_submissions` WHERE id = ? LIMIT 1', array($id));
    if ($r === null) { fail(404, '主题不存在'); }
    $isOwner = (int)$r['user_id'] === (int)$ident['uid'];
    $isAdmin = in_array((string)$ident['role'], array('admin', 'subadmin'), true);
    if ((string)$r['status'] !== 'approved' && !$isOwner && !$isAdmin) {
        fail(403, '主题未通过审核');
    }
    return ts_row($r, true);
}

/** 我的投稿（含被拒原因） */
function ts_mine(int $uid): array
{
    if (!table_exists('theme_submissions')) { return array(); }
    $rows = db_all('SELECT id, oid, theme_name, theme_id, accent, username, bytes, status, created_at,
                           reject_reason, reviewed_at
                    FROM `theme_submissions` WHERE user_id = ? ORDER BY id DESC LIMIT 20', array($uid));
    $out = array();
    foreach ($rows as $r) {
        $x = ts_row($r);
        $x['reviewed'] = (string)($r['reviewed_at'] ?? '');
        $out[] = $x;
    }
    return $out;
}

/** 撤回（删）自己的一条投稿：在审或已通过都可以，删掉就腾出额度 */
function ts_withdraw(int $uid, int $id): array
{
    if (!table_exists('theme_submissions')) { fail(503, '主题目录暂不可用'); }
    $r = db_one('SELECT id, user_id FROM `theme_submissions` WHERE id = ? LIMIT 1', array($id));
    if ($r === null) { fail(404, '主题不存在'); }
    if ((int)$r['user_id'] !== $uid) { fail(403, '只能撤回自己提交的主题'); }
    db_exec('DELETE FROM `theme_submissions` WHERE id = ? AND user_id = ?', array($id, $uid));
    return array('deleted' => 1, 'quota' => ts_quota($uid), 'used' => ts_used($uid), 'remaining' => ts_remaining($uid));
}

/** 管理端：待审与全部 */
function ts_admin_list(string $status, string $q, int $page, int $size): array
{
    if (!table_exists('theme_submissions')) {
        return array('items' => array(), 'total' => 0, 'page' => 1, 'pages' => 0);
    }
    $page = $page < 1 ? 1 : $page;
    $size = $size < 1 ? 20 : ($size > 100 ? 100 : $size);
    $where = '1=1';
    $args = array();
    if (in_array($status, array('pending', 'approved', 'rejected'), true)) {
        $where .= ' AND status = ?';
        $args[] = $status;
    }
    if ($q !== '') {
        $where .= ' AND (theme_name LIKE ? OR username LIKE ? OR theme_id LIKE ?)';
        $like = ts_like($q);
        $args[] = $like; $args[] = $like; $args[] = $like;
    }
    $total = (int)db_val('SELECT COUNT(*) FROM `theme_submissions` WHERE ' . $where, $args);
    $rows = db_all('SELECT id, oid, theme_name, theme_id, accent, username, bytes, status, featured,
                           created_at, reject_reason, user_id
                    FROM `theme_submissions` WHERE ' . $where . '
                    ORDER BY featured DESC, id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size), $args);

    /* 一页里的作者配额与已用额度一次算完，避免每行两次查询 */
    $uids = array();
    foreach ($rows as $r) { $uids[(int)$r['user_id']] = 1; }
    $ids = array_keys($uids);
    $quotaMap = array();
    $usedMap = array();
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if (col_ok('users', 'theme_quota')) {
            foreach (db_all('SELECT id, theme_quota FROM `users` WHERE id IN (' . $ph . ')', $ids) as $u) {
                $q = (int)$u['theme_quota'];
                $quotaMap[(int)$u['id']] = ($q < 1 ? 1 : ($q > 3 ? 3 : $q));
            }
        }
        foreach (db_all("SELECT user_id, COUNT(*) AS c FROM `theme_submissions`
                         WHERE user_id IN (" . $ph . ") AND status IN ('pending','approved')
                         GROUP BY user_id", $ids) as $u) {
            $usedMap[(int)$u['user_id']] = (int)$u['c'];
        }
    }

    $items = array();
    foreach ($rows as $r) {
        $x = ts_row($r);
        $uid = (int)$r['user_id'];
        $x['uid'] = $uid;
        $x['quota'] = isset($quotaMap[$uid]) ? $quotaMap[$uid] : 1;
        $x['used'] = isset($usedMap[$uid]) ? $usedMap[$uid] : 0;
        $items[] = $x;
    }
    return array('items' => $items, 'total' => $total, 'page' => $page,
                 'pages' => $total > 0 ? (int)ceil($total / $size) : 0);
}

/** 审核：通过 / 拒绝 / 删除 */
function ts_review(int $id, string $verdict, string $reason, int $by): array
{
    if (!table_exists('theme_submissions')) { fail(503, '主题目录暂不可用'); }
    $r = db_one('SELECT id, status, featured FROM `theme_submissions` WHERE id = ? LIMIT 1', array($id));
    if ($r === null) { fail(404, '主题不存在'); }

    if ($verdict === 'delete') {
        db_exec('DELETE FROM `theme_submissions` WHERE id = ?', array($id));
        return array('deleted' => 1);
    }
    if ($verdict === 'feature' || $verdict === 'unfeature') {
        if (!col_ok('theme_submissions', 'featured')) { fail(503, '推荐位暂不可用（需要先完成数据库升级）'); }
        if ((string)$r['status'] !== 'approved') { fail(400, '只有已通过的主题才能设为推荐'); }
        db_exec('UPDATE `theme_submissions` SET featured = ? WHERE id = ?',
            array($verdict === 'feature' ? 1 : 0, $id));
        return array('id' => $id, 'featured' => $verdict === 'feature' ? 1 : 0);
    }
    if ($verdict !== 'approve' && $verdict !== 'reject') { fail(400, '未知审核动作'); }
    $status = $verdict === 'approve' ? 'approved' : 'rejected';
    db_exec('UPDATE `theme_submissions` SET status = ?, reject_reason = ?, reviewed_at = ?, reviewed_by = ?
             WHERE id = ?',
        array($status, $verdict === 'reject' ? mb_substr($reason, 0, 200, 'UTF-8') : '', now_utc(), $by, $id));
    return array('id' => $id, 'status' => $status);
}

/** 设置某账号的投稿额度（1~3） */
function ts_set_quota(int $uid, int $q): array
{
    if (!col_ok('users', 'theme_quota')) { fail(503, '额度功能暂不可用（需要先完成数据库升级）'); }
    $q = $q < 1 ? 1 : ($q > 3 ? 3 : $q);
    $exists = db_val('SELECT id FROM `users` WHERE id = ? LIMIT 1', array($uid));
    if ($exists === null) { fail(404, '用户不存在'); }
    db_exec('UPDATE `users` SET theme_quota = ? WHERE id = ?', array($q, $uid));
    return array('uid' => $uid, 'quota' => $q, 'used' => ts_used($uid));
}
