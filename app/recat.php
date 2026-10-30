<?php
declare(strict_types=1);
/**
 * 存量作品的分类重判（Jev 零样本四分类）
 * ------------------------------------------------------------
 * 只处理「来源不是 jev」的行：接口给的分类（api）与从未判定过的空来源。
 * 游标走 settings.recat_cursor 按 id 升序推进，扫完一轮归零 —— 不能拿 category
 * 当游标，那正是要被改写的字段（会漏行或重复处理）。
 *
 * 入口：cron.php?task=recat（每次处理一批）、面板「分类重判」按钮。
 * Jev 不可用时不改动任何行，游标照常推进，下一轮再试。
 */

function works_recat_batch(int $limit = 3): array
{
    if (!works_cat_enabled()) { return array('ok' => false, 'reason' => 'disabled'); }
    if (!col_ok('works', 'cat_src')) { return array('ok' => false, 'reason' => 'not_ready'); }
    $limit = max(1, min(20, $limit));

    $cur  = (int)setting_get('recat_cursor', '0');
    $rows = db_all('SELECT id, title, intro FROM works
                    WHERE is_hidden = 0 AND id > ? AND (cat_src = \'\' OR cat_src = \'api\')
                    ORDER BY id ASC LIMIT ' . $limit, array($cur));
    if (!$rows) {
        if ($cur > 0) { setting_set('recat_cursor', '0'); return array('ok' => true, 'done' => 0, 'round' => 'wrapped'); }
        return array('ok' => true, 'done' => 0, 'round' => 'done');
    }

    $judged = 0; $changed = 0; $last = $cur;
    foreach ($rows as $r) {
        $last = (int)$r['id'];
        $res  = works_cat_classify((string)$r['title'], (string)$r['intro']);
        if ($res['cat'] === '') { continue; }
        $judged++;
        $old = db_one('SELECT category FROM works WHERE id = ? LIMIT 1', array((int)$r['id']));
        db_exec('UPDATE works SET category = ?, cat_src = ? WHERE id = ?',
            array($res['cat'], 'jev', (int)$r['id']));
        if ($old === null || (string)$old['category'] !== $res['cat']) { $changed++; }
    }
    setting_set('recat_cursor', (string)$last);
    return array('ok' => true, 'done' => count($rows), 'judged' => $judged,
                 'changed' => $changed, 'cursor' => $last);
}

/** 还剩多少件待判（面板显示用） */
function works_recat_pending(): int
{
    if (!col_ok('works', 'cat_src')) { return 0; }
    $r = db_one('SELECT COUNT(*) AS c FROM works WHERE is_hidden = 0 AND (cat_src = \'\' OR cat_src = \'api\')');
    return $r === null ? 0 : (int)$r['c'];
}
