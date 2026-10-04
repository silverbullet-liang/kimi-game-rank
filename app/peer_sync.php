<?php
/**
 * 站点互通引擎
 * ------------------------------------------------------------
 * 多站点互为镜像：一键把两边的数据互相补齐，最终「站点和内容完全一样」。
 *
 * 身份：跨站行标识走侧表 sync_ids（table_name + local_id → gid），不改动任何内容表。
 *      有自然键的表（users.username_norm / works.community_id / checkins(user,date) /
 *      banned_ips.ip_hash）直接按自然键匹配，更省事也更稳。
 * 方向：双向。A 点「互通」→ 拉对端较新的、同时把本端较新的推给对端。
 * 取舍：只传「内容型」数据；世界对话 / AI 对话 / 访问记录 / 限速计数 / 密钥等一律排除。
 * 传输：peer_crypto（JSON → gzip → 非对称加密 → base64）。
 */
declare(strict_types=1);

/** 参与互通的表清单（顺序即依赖顺序：被引用的表在前） */
function sync_plan(): array
{
    return array(
        'users' => array('key' => 'natural', 'natural' => array('username_norm'),
            'cols' => array('username', 'username_norm', 'role', 'uid8', 'password_hash', 'salt',
                            'registered_at', 'is_banned', 'ban_until', 'settings', 'created_at')),
        'works' => array('key' => 'natural', 'natural' => array('community_id'),
            'cols' => array('community_id', 'title', 'intro', 'author_name', 'author_avatar', 'category',
                            'share_link', 'html_url', 'like_num', 'comment_num', 'collect_num', 'images',
                            'score', 'total_score', 'heat_score', 'rating', 'has_html', 'is_hidden',
                            'added_by', 'created_at', 'updated_at', 'vote_count', 'peak_score')),
        'announcements' => array('key' => 'gid',
            'cols' => array('content', 'updated_at')),
        'comments' => array('key' => 'gid',
            'refs' => array('user_id' => 'users', 'work_id' => 'works',
                            'parent_id' => 'comments', 'root_id' => 'comments'),
            'cols' => array('work_id', 'target_type', 'user_id', 'parent_id', 'root_id',
                            'content', 'is_deleted', 'deleted_by', 'created_at')),
        'work_votes' => array('key' => 'gid',
            'refs' => array('work_id' => 'works', 'user_id' => 'users'),
            'cols' => array('work_id', 'user_id', 'created_at')),
        'comment_votes' => array('key' => 'gid',
            'refs' => array('comment_id' => 'comments', 'user_id' => 'users'),
            'cols' => array('comment_id', 'user_id', 'created_at')),
        'checkins' => array('key' => 'natural', 'natural' => array('user_id', 'checkin_date'),
            'refs' => array('user_id' => 'users'),
            'cols' => array('user_id', 'checkin_date', 'streak_after', 'created_at')),
        'work_score_history' => array('key' => 'gid',
            'refs' => array('work_id' => 'works'),
            'cols' => array('work_id', 'total_score', 'created_at')),
        'feedback' => array('key' => 'gid',
            'refs' => array('user_id' => 'users'),
            'cols' => array('user_id', 'content', 'is_public', 'is_deleted', 'admin_reply', 'replied_at', 'created_at')),
        'discipline_reports' => array('key' => 'gid',
            'refs' => array('user_id' => 'users', 'by_uid' => 'users'),
            'cols' => array('user_id', 'username', 'reasons', 'note', 'banned', 'ban_days', 'ban_until',
                            'purged', 'ip_banned', 'by_uid', 'views', 'created_at')),
        'banned_ips' => array('key' => 'natural', 'natural' => array('ip_hash'),
            'refs' => array('user_id' => 'users', 'report_id' => 'discipline_reports'),
            'cols' => array('ip_hash', 'ip_masked', 'report_id', 'user_id', 'created_at')),
    );
}

/** 不参与互通的「设置」键：密钥类一律排除（各站不同，传了也解不开） */
function sync_settings_allowed(): array
{
    $keys = cfg('peers.sync_settings', array());
    return is_array($keys) ? array_values(array_filter(array_map('strval', $keys))) : array();
}

/** settings 表是否排除某键 */
function sync_setting_denied(string $k): bool
{
    return (bool)preg_match('/(key|secret|token|pass|salt|dsn|db_|_db)/i', $k);
}

/* ---------- 跨站标识 ---------- */

/** 取本地行的 gid，没有就现生成并落库 */
function sync_gid_of(string $table, string $localId): string
{
    $row = db_one('SELECT gid FROM sync_ids WHERE table_name = ? AND local_id = ? LIMIT 1',
        array($table, (string)$localId));
    if ($row !== null) { return (string)$row['gid']; }
    $gid = bin2hex(random_bytes(16));
    try { db_exec('INSERT INTO sync_ids (table_name, local_id, gid, at) VALUES (?, ?, ?, ?)',
        array($table, (string)$localId, $gid, now_utc())); }
    catch (Throwable $e) {
        $row = db_one('SELECT gid FROM sync_ids WHERE table_name = ? AND local_id = ? LIMIT 1',
            array($table, (string)$localId));
        if ($row !== null) { return (string)$row['gid']; }
    }
    return $gid;
}

/** gid → 本地主键；不存在返回 0 */
function sync_local_of(string $table, string $gid): int
{
    if ($gid === '') { return 0; }
    $row = db_one('SELECT local_id FROM sync_ids WHERE table_name = ? AND gid = ? LIMIT 1',
        array($table, $gid));
    return $row !== null ? (int)$row['local_id'] : 0;
}

/** 记录映射（导入时用），忽略重复 */
function sync_bind(string $table, string $localId, string $gid)
{
    if ($gid === '' || $localId === '') { return; }
    try { db_exec('INSERT INTO sync_ids (table_name, local_id, gid, at) VALUES (?, ?, ?, ?)',
        array($table, (string)$localId, $gid, now_utc())); }
    catch (Throwable $e) { /* 已存在 */ }
}

/* ---------- 导出 / 导入 ---------- */

/** 表是否存在于本库 */
function sync_table_ok(string $table): bool
{
    return (bool)preg_match('/^[a-z_]+$/', $table) && table_exists($table);
}

/** 导出：把行转成「跨站文档」（本地外键 → gid） */
function sync_export(string $table, int $offset, int $limit): array
{
    $p = sync_plan()[$table];
    $order = isset($p['natural']) ? implode(',', $p['natural']) : 'id';
    $rows = db_all('SELECT * FROM `' . $table . '` ORDER BY ' . $order . ' LIMIT ' . (int)$offset . ',' . (int)$limit);
    $refs = isset($p['refs']) ? $p['refs'] : array();
    $out = array();
    foreach ($rows as $r) {
        $doc = array();
        foreach ($p['cols'] as $c) {
            if (!array_key_exists($c, $r)) { continue; }
            $v = $r[$c];
            if (isset($refs[$c]) && (int)$v > 0) { $v = sync_gid_of($refs[$c], (string)(int)$v); }
            $doc[$c] = $v;
        }
        /* gid 身份表：随行带出 gid，导入端据此落位 */
        if ($p['key'] === 'gid') { $doc['_gid'] = sync_gid_of($table, (string)$r['id']); }
        $out[] = $doc;
    }
    return $out;
}

/** 导入：把跨站文档写回本库（gid → 本地外键，缺失的引用置 0 并跳过该行） */
function sync_apply(string $table, array $docs): array
{
    if (!sync_table_ok($table)) { return array('applied' => 0, 'skipped' => count($docs)); }
    $p = sync_plan()[$table];
    $refs = isset($p['refs']) ? $p['refs'] : array();
    $applied = 0; $skipped = 0;

    foreach ($docs as $doc) {
        if (!is_array($doc)) { continue; }
        $data = array();
        $missing = false;
        foreach ($p['cols'] as $c) {
            if (!array_key_exists($c, $doc)) { continue; }
            $v = $doc[$c];
            if (isset($refs[$c])) {
                $gid = is_string($v) ? trim($v) : '';
                if ($gid === '' || (int)$v === 0) { $v = 0; }
                else { $v = sync_local_of($refs[$c], $gid); if ($v === 0) { $missing = true; } }
            }
            $data[$c] = $v;
        }
        if ($missing) { $skipped++; continue; }   // 引用尚未同步过来，留待下一轮

        /* 找目标行 */
        $targetId = 0;
        if ($p['key'] === 'gid') {
            $targetId = sync_local_of($table, isset($doc['_gid']) ? (string)$doc['_gid'] : '');
        } else {
            $where = array(); $args = array();
            foreach ($p['natural'] as $c) { $where[] = '`' . $c . '` = ?'; $args[] = isset($data[$c]) ? $data[$c] : ''; }
            $row = db_one('SELECT id FROM `' . $table . '` WHERE ' . implode(' AND ', $where) . ' LIMIT 1', $args);
            if ($row !== null) { $targetId = (int)$row['id']; }
        }

        $cols = array_keys($data);
        if ($targetId > 0) {
            $set = array(); $args = array();
            foreach ($cols as $c) { $set[] = '`' . $c . '` = ?'; $args[] = $data[$c]; }
            $args[] = $targetId;
            db_exec('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
        } else {
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            $targetId = (int)db_insert('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . $ph . ')',
                array_values($data));
            if ($p['key'] === 'gid' && isset($doc['_gid'])) { sync_bind($table, (string)$targetId, (string)$doc['_gid']); }
        }
        $applied++;
    }
    return array('applied' => $applied, 'skipped' => $skipped);
}

/** 清单：逐表的行数 + 时间戳，用于「谁更新」的快速比对 */
function sync_manifest(): array
{
    $m = array();
    foreach (sync_plan() as $t => $p) {
        if (!sync_table_ok($t)) { continue; }
        $cnt = (int)db_val('SELECT COUNT(*) FROM `' . $t . '`');
        $stamp = 0;
        foreach (array('updated_at', 'created_at') as $c) {
            if (column_exists($t, $c)) {
                $v = db_val('SELECT UNIX_TIMESTAMP(MAX(`' . $c . '`)) FROM `' . $t . '`');
                $stamp = max($stamp, (int)$v);
            }
        }
        $m[$t] = array('n' => $cnt, 'stamp' => $stamp);
    }
    return $m;
}

/* ---------- 传输（对端调用） ---------- */

function peer_my_private(): string
{
    return trim((string)cfg('peers.private_key', ''));
}

/** 本站公钥（供对方配置） */
function peer_my_public(): string
{
    return peer_public_from_private(peer_my_private());
}

/** 调对端端点：打包 → POST → 解包。任何失败抛异常。 */
function peer_call(array $peer, string $action, array $payload = array(), int $timeout = 30): array
{
    $mySk = peer_my_private();
    $peerPk = trim((string)$peer['pubkey']);
    if (!peer_key_valid($mySk)) { throw new RuntimeException('本站私钥未配置或格式错误（需 64 位十六进制）'); }
    if (!peer_key_valid($peerPk)) { throw new RuntimeException('对端「' . $peer['name'] . '」公钥未配置或格式错误'); }

    $body = peer_pack(array('action' => $action, 'ts' => time(), 'data' => $payload), $mySk, $peerPk);
    $url = rtrim((string)$peer['base_url'], '/') . '/api/peer.php';

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => array('Content-Type: text/plain'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'KimiGameRank/' . APP_VERSION . ' (peer-sync)',
    ));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if (!is_string($resp) || $resp === '') { throw new RuntimeException('连接对端失败：' . $err); }
    if ($code !== 200) { throw new RuntimeException('对端返回 HTTP ' . $code); }
    return peer_unpack($resp, $mySk, $peerPk);   // 解不开即密钥不符
}

/* ---------- 一键互通 ---------- */

/**
 * 与单个站点双向补齐。返回统计。
 */
function peer_sync_one(array $peer): array
{
    $limit = 200;
    $hello = peer_call($peer, 'hello');
    $remote = isset($hello['manifest']) && is_array($hello['manifest']) ? $hello['manifest'] : array();
    $local  = sync_manifest();

    $pulled = 0; $pushed = 0; $tables = 0;
    foreach (sync_plan() as $t => $p) {
        $rn = isset($remote[$t]['n']) ? (int)$remote[$t]['n'] : 0;
        $rs = isset($remote[$t]['stamp']) ? (int)$remote[$t]['stamp'] : 0;
        $ln = isset($local[$t]['n']) ? (int)$local[$t]['n'] : 0;
        $ls = isset($local[$t]['stamp']) ? (int)$local[$t]['stamp'] : 0;
        if ($rn === $ln && $rs === $ls) { continue; }      // 两边一致，跳过
        $tables++;

        /* 拉：把对端较新的取回来 */
        for ($off = 0; ; $off += $limit) {
            $res = peer_call($peer, 'fetch', array('table' => $t, 'offset' => $off, 'limit' => $limit));
            $docs = isset($res['rows']) && is_array($res['rows']) ? $res['rows'] : array();
            if (!$docs) { break; }
            $r = sync_apply($t, $docs);
            $pulled += (int)$r['applied'];
            if (count($docs) < $limit) { break; }
        }
        /* 推：把本端较新的送过去 */
        for ($off = 0; ; $off += $limit) {
            $docs = sync_export($t, $off, $limit);
            if (!$docs) { break; }
            $res = peer_call($peer, 'apply', array('table' => $t, 'rows' => $docs));
            $pushed += (int)(isset($res['applied']) ? $res['applied'] : 0);
            if (count($docs) < $limit) { break; }
        }
    }

    db_exec('UPDATE peers SET last_sync_at = ?, last_status = ? WHERE id = ?',
        array(now_utc(), 'ok', (int)$peer['id']));
    return array('pulled' => $pulled, 'pushed' => $pushed, 'tables' => $tables);
}

/** 一键互通：遍历启用中的站点列表 */
function peer_sync_all(): array
{
    $out = array();
    foreach (db_all('SELECT * FROM peers WHERE enabled = 1 ORDER BY id') as $peer) {
        try {
            $r = peer_sync_one($peer);
            $out[] = array('name' => (string)$peer['name'], 'ok' => true,
                           'pulled' => $r['pulled'], 'pushed' => $r['pushed'], 'tables' => $r['tables']);
        } catch (Throwable $e) {
            db_exec('UPDATE peers SET last_status = ? WHERE id = ?', array('fail: ' . $e->getMessage(), (int)$peer['id']));
            $out[] = array('name' => (string)$peer['name'], 'ok' => false, 'msg' => $e->getMessage());
        }
    }
    return $out;
}

/**
 * 把 config 的 peers.sites 里「已填好」的对端登记进站点列表（幂等，只补不删）。
 * 允许「一条填好、其余留空」——空条目或格式不符的直接跳过，不报错。
 */
function peer_seed_from_config()
{
    if (!table_exists('peers')) { return; }
    $sites = cfg('peers.sites', array());
    if (!is_array($sites)) { return; }
    foreach ($sites as $s) {
        if (!is_array($s)) { continue; }
        $url = isset($s['base_url']) ? rtrim(trim((string)$s['base_url']), '/') : '';
        $pk  = isset($s['pubkey']) ? strtolower(trim((string)$s['pubkey'])) : '';
        if ($url === '' || !preg_match('#^https?://#i', $url)) { continue; }   // 留空 → 跳过
        if (!peer_key_valid($pk)) { continue; }                              // 公钥缺失 → 跳过
        if (db_one('SELECT id FROM peers WHERE base_url = ? LIMIT 1', array($url)) !== null) { continue; }
        $name = isset($s['name']) ? trim((string)$s['name']) : '';
        db_exec('INSERT INTO peers (name, base_url, pubkey, enabled, created_at) VALUES (?, ?, ?, 1, ?)',
            array($name, $url, $pk, now_utc()));
    }
}
