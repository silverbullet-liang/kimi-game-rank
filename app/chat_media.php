<?php
/**
 * 世界对话图片的生命周期
 * ------------------------------------------------------------
 * 原则：图片必须「活」在一条仍然存在、且前端仍然看得到的对话消息上。
 * 因此三类图片会被清掉 —— 一切都是为了省空间：
 *   1) 消息已撤回 / 已删除 → 立即删（撤回时同步删，不留到后台清理）；
 *   2) 孤儿图：已经没有任何消息引用它（消息被整条删除、或账号被清空）；
 *   3) 沉底图：所属消息已落到「最近 N 条」之外，前端再也翻不到 → 删图留文，
 *      消息正文通常还在，只是图片失效（读取接口会给一张「已清理」占位图）。
 *
 * 上传目录只服务世界对话（其余功能不使用 media.php 落盘），因此可整目录核对：
 * 不在保留集合里的文件即为孤儿或沉底，直接删除。
 */
declare(strict_types=1);

define('CHAT_MEDIA_KEEP', 200);       // 保留最近多少条消息的图片

/** 从 media_url 里取出「YYYYMMDD/hash.ext」；取不到返回空串 */
function chat_media_id(string $url): string
{
    if ($url === '' || strpos($url, 'media.php') === false) { return ''; }
    if (!preg_match('#media\.php\?id=([^&\s]+)#i', $url, $m)) { return ''; }
    $id = rawurldecode($m[1]);
    return preg_match('#^\d{8}/[a-f0-9]{16}\.(?:jpg|jpeg|png|gif|webp)$#i', $id) ? $id : '';
}

/** 由 id 得到磁盘绝对路径；格式不合法返回空串 */
function chat_media_path(string $id): string
{
    if (!preg_match('#^(\d{8})/([a-f0-9]{16})\.(jpg|jpeg|png|gif|webp)$#i', $id, $m)) { return ''; }
    return APP_ROOT . '/storage/uploads/' . $m[1] . '/' . $m[2] . '.' . strtolower($m[3]);
}

/** 删除一条消息对应的图片文件（撤回 / 删号时调用） */
function chat_media_drop(string $url): bool
{
    $id = chat_media_id($url);
    if ($id === '') { return false; }
    $p = chat_media_path($id);
    return ($p !== '' && is_file($p)) ? (bool)@unlink($p) : false;
}

/**
 * 后台清理：孤儿图 + 沉底图。返回删除的文件数。
 * 一次 SQL 取「仍在保留窗口内的图片 id 集合」，再一次目录扫描比对 —— 不做 N+1 查询。
 */
function chat_media_gc(): int
{
    if (!table_exists('messages') || !col_ok('messages', 'media_url')) { return 0; }

    $keep = array();
    $rows = db_all('SELECT media_url FROM messages WHERE media_url <> \'\' ORDER BY id DESC LIMIT ' . (int)CHAT_MEDIA_KEEP);
    foreach ($rows as $r) {
        $id = chat_media_id((string)$r['media_url']);
        if ($id !== '') { $keep[$id] = true; }
    }

    $n = 0;
    foreach ((array)@glob(APP_ROOT . '/storage/uploads/*/*') as $f) {
        if (!is_file($f)) { continue; }
        $base = basename($f);
        $day  = basename(dirname($f));
        if (!preg_match('#^\d{8}$#', $day)) { continue; }
        if (!preg_match('#^[a-f0-9]{16}\.(jpg|jpeg|png|gif|webp)$#i', $base)) { continue; }
        if (isset($keep[$day . '/' . $base])) { continue; }
        if (@unlink($f)) { $n++; }
    }
    if ($n > 0) { app_log('chat_media_gc removed ' . $n . ' file(s)'); }
    return $n;
}
