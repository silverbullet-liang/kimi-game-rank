<?php
/**
 * UID 体系：把账号唯一映射为 8 位数字
 * ------------------------------------------------------------
 * 设计要求：
 *   1) 8 位数字，全局唯一；
 *   2) 由账号原始值经「复杂计算」得出，且**可逆推**回原始值；
 *   3) 管理员与副管理员使用 8 位「豹子号」（如 88888888），唯一不重复。
 *
 * 计算链路（可逆）：
 *   n  = (id * A + B) mod 10^8        ← 仿射置换（A 与 10^8 互质 ⇒ 双射）
 *   s  = 固定位置置换(n 的 8 位十进制)  ← 又一重双射
 *   uid = s
 * 逆推：
 *   s  = 逆位置置换(uid)
 *   id = (s - B) * A⁻¹ mod 10^8
 *
 * 豹子号不走仿射（由管理员/副管理员专属），此时逆推以 uid_resolve() 查表为准，
 * 两种路径都能唯一还原账号。
 */
declare(strict_types=1);

define('UID_MOD', 100000000);        // 10^8
define('UID_A', 91234567);           // 与 10^8 互质（奇数、非 5 的倍数）
define('UID_B', 20260913);
define('UID_PERM', '37160425');      // 位置置换：第 i 位取自明文第 PERM[i] 位

/** 主管理员专属豹子号 */
define('UID_ADMIN', '88888888');

/** 副管理员豹子号池（0 起头可读性差、8 归主管理员，故排除） */
function uid_babao_pool(): array
{
    return array('11111111', '22222222', '33333333', '44444444',
                 '55555555', '66666666', '77777777', '99999999');
}

function uid_is_babao(string $uid): bool
{
    return strlen($uid) === 8 && $uid === str_repeat($uid[0], 8);
}

/* ============================================================
 * 可逆计算
 * ============================================================ */
function uid_encode(int $id): string
{
    $n = (($id % UID_MOD) * UID_A + UID_B) % UID_MOD;
    $s = str_pad((string)$n, 8, '0', STR_PAD_LEFT);
    $out = '';
    $perm = UID_PERM;
    for ($i = 0; $i < 8; $i++) { $out .= $s[(int)$perm[$i]]; }
    return $out;
}

function uid_decode(string $uid): int
{
    $u = preg_replace('/\D/', '', $uid);
    if (!is_string($u) || strlen($u) !== 8) { return 0; }
    $buf = array_fill(0, 8, '0');
    $perm = UID_PERM;
    for ($i = 0; $i < 8; $i++) { $buf[(int)$perm[$i]] = $u[$i]; }
    $n = (int)implode('', $buf);
    $inv = uid_modinv(UID_A);
    if ($inv === 0) { return 0; }
    $d = ((($n - UID_B) % UID_MOD) + UID_MOD) % UID_MOD;
    return (int)($d * $inv % UID_MOD);
}

/** 模逆元（扩展欧几里得） */
function uid_modinv(int $a, int $m = UID_MOD): int
{
    $x = 0; $y = 0;
    $g = uid_egcd($a % $m, $m, $x, $y);
    if ($g !== 1) { return 0; }
    return (($x % $m) + $m) % $m;
}

function uid_egcd(int $a, int $b, &$x, &$y): int
{
    if ($b === 0) { $x = 1; $y = 0; return $a; }
    $g = uid_egcd($b, $a % $b, $y, $x);
    $y -= intdiv($a, $b) * $x;
    return $g;
}

/* ============================================================
 * 分配与反查
 * ============================================================ */
/** uid 是否已被占用（可指定排除某用户） */
function uid_taken(string $uid, int $exceptUserId = 0): bool
{
    try {
        return db_val('SELECT id FROM users WHERE uid8 = ? AND id <> ? LIMIT 1', array($uid, $exceptUserId)) !== null;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 为账号分配 8 位 UID。
 * 普通用户：仿射计算值；若恰为豹子号或被占用，按固定步长顺延（唯一性由唯一索引兜底）。
 * 管理员：88888888；副管理员：豹子号池中第一个未占用的。
 */
function uid_assign(int $userId, string $role, int $subSeq = 0): string
{
    if ($userId <= 0) { return ''; }
    if ($role === 'admin') { return UID_ADMIN; }

    if ($role === 'subadmin') {
        $pool = uid_babao_pool();
        if ($subSeq > 0 && isset($pool[$subSeq - 1]) && !uid_taken($pool[$subSeq - 1], $userId)) {
            return $pool[$subSeq - 1];
        }
        foreach ($pool as $cand) {
            if (!uid_taken($cand, $userId)) { return $cand; }   // 豹子号优先
        }
        // 池已用尽（9 位以上副管理员）→ 回退仿射值
    }

    for ($k = 0; $k < 12; $k++) {
        $cand = uid_encode($userId + $k * 50000000);
        if (!uid_is_babao($cand) && !uid_taken($cand, $userId)) { return $cand; }
    }
    // 极端兜底：仍冲突则取仿射值本身（唯一索引会拒重复）
    return uid_encode($userId);
}

/** 读取（必要时补分配）某账号的 UID */
function uid_of_user(array $userRow, int $subSeq = 0): string
{
    $cur = isset($userRow['uid8']) ? (string)$userRow['uid8'] : '';
    if ($cur !== '' && strlen($cur) === 8) { return $cur; }

    $uid = uid_assign((int)$userRow['id'], isset($userRow['role']) ? (string)$userRow['role'] : 'user', $subSeq);
    if ($uid !== '') {
        try { db_exec('UPDATE users SET uid8 = ? WHERE id = ?', array($uid, (int)$userRow['id'])); }
        catch (Throwable $e) { app_log('uid assign failed id=' . $userRow['id'] . ': ' . $e->getMessage()); }
    }
    return $uid;
}

/** 当前登录者的 UID（游客返回空） */
function uid_current(): string
{
    $id = current_identity();
    if ($id === null || $id['role'] === 'guest') { return ''; }
    $row = current_user_row();
    if ($row === null) { return ''; }
    $seq = 0;
    if ($id['role'] === 'subadmin') {
        $sub = subadmin_row(norm_username((string)$row['username']));
        $seq = 0;
        if ($sub !== null) {
            $all = db_admin_all('SELECT id FROM sub_admins ORDER BY id ASC');
            foreach ($all as $i => $r) { if ((int)$r['id'] === (int)$sub['id']) { $seq = $i + 1; break; } }
        }
    }
    return uid_of_user($row, $seq);
}

/** 反查：8 位 UID → 账号信息（可逆推的权威入口） */
function uid_resolve(string $uid): array
{
    $uid = preg_replace('/\D/', '', $uid);
    if (!is_string($uid) || strlen($uid) !== 8) { return array('ok' => false, 'error' => 'UID 必须为 8 位数字'); }

    $row = db_one('SELECT id, username, role, created_at FROM users WHERE uid8 = ? LIMIT 1', array($uid));
    if ($row === null) {
        // 退化路径：豹子号之外的普通 UID 可纯算术还原
        if (uid_is_babao($uid)) { return array('ok' => false, 'error' => '该 UID 未分配'); }
        return array('ok' => true, 'method' => 'arithmetic', 'user_id' => uid_decode($uid));
    }
    return array('ok' => true, 'method' => 'table', 'user_id' => (int)$row['id'],
        'username' => (string)$row['username'], 'role' => (string)$row['role']);
}
