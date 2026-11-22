<?php
/**
 * 世界对话 @ 提及
 * ------------------------------------------------------------
 * 两条铁律：
 *  1. 只有「真实存在且未被封停」的用户名才算提及——@随便打的字不高亮、不生效；
 *  2. 检索必须带查询串，绝不提供「空查询返回全站用户」的入口，
 *     也不提供分页游标，避免有人拿它把成员表整批拖走。
 * 命中方式只做「前缀」，可走 uk_username_norm 索引，不做 %xxx% 模糊枚举。
 */
declare(strict_types=1);

/** 可提及用户检索：$q 必须非空；返回 [{name, role}]，最多 $limit 条 */
function lobby_mention_search(string $q, int $limit = 6): array
{
    $q = trim(strip_invisible(nfc_normalize($q)));
    if ($q === '') { return array(); }
    if (mb_strlen($q, 'UTF-8') > 32) { return array(); }
    $limit = max(1, min(10, $limit));

    $norm = norm_username($q);
    if ($norm === '') { return array(); }

    /* LIKE 通配符转义：不允许用户用 _ % 探出全表 */
    $esc = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $norm);
    $rows = db_all(
        'SELECT username, role FROM users
          WHERE is_banned = 0 AND username_norm = username AND username_norm LIKE ?
          ORDER BY (username_norm = ?) DESC, CHAR_LENGTH(username) ASC, username ASC
          LIMIT ' . $limit,
        array($esc . '%', $norm)
    );

    $out = array();
    foreach ($rows as $r) {
        $name = (string)($r['username'] ?? '');
        if ($name === '') { continue; }
        $out[] = array('name' => $name, 'role' => (string)($r['role'] ?? 'user'));
    }
    return $out;
}

/** 从正文提取形如 @名字 的候选串（去重、去尾部标点、最多 8 个） */
function lobby_mention_tokens(string $text): array
{
    if ($text === '' || strpos($text, '@') === false) { return array(); }
    $out = array();
    if (preg_match_all('/(?:^|[\s（(【\[>，。！？、,:;])@([^\s@]{1,32})/u', $text, $m)) {
        foreach ($m[1] as $raw) {
            /* 在标点处截断：@张三，你好 → 张三。只认明确边界，
               不做「最长前缀猜测」，避免把 @张三丰 误标成 @张三。 */
            $parts = preg_split('/[\s，。！？、；：,;:!?()（）【】\[\]"\'‘’“”…—]/u', $raw);
            $raw = trim((string)($parts[0] ?? ''));
            if ($raw === '') { continue; }
            $key = norm_username($raw);
            if ($key === '') { continue; }
            $out[$key] = $raw;
            if (count($out) >= 8) { break; }
        }
    }
    return $out;
}

/** 批量：一批正文 → 其中真实存在的用户名集合（norm => true），一次 SQL 搞定 */
function lobby_mention_index(array $texts): array
{
    $cand = array();
    foreach ($texts as $t) {
        foreach (lobby_mention_tokens((string)$t) as $k => $raw) {
            $cand[$k] = true;
            if (count($cand) >= 60) { break 2; }
        }
    }
    if (!$cand) { return array(); }
    $keys = array_keys($cand);
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $rows = db_all('SELECT username_norm FROM users WHERE is_banned = 0 AND username_norm IN (' . $ph . ')', $keys);
    $set = array();
    foreach ($rows as $r) { $set[(string)$r['username_norm']] = true; }
    return $set;
}

/** 单条正文 → 真实存在的提及名（供前端只高亮这些） */
function lobby_mention_pick(string $text, array $set): array
{
    if (!$set) { return array(); }
    $out = array();
    foreach (lobby_mention_tokens($text) as $k => $raw) {
        if (isset($set[$k])) { $out[] = $raw; }
    }
    return $out;
}
