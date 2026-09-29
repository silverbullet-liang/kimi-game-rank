<?php
/**
 * 站点聚合信息（带缓存）
 * ------------------------------------------------------------
 * 分类计数与公告是那种「几乎每个首屏都会问一次、但一分钟内基本不变」的聚合：
 *   · 公告此前由公告接口查一次，分类计数由启动接口再查一次 —— 同一个首屏算两遍；
 *   · 分类计数是整表 GROUP BY，作品越多越贵。
 * 这里统一收口并加 60 秒缓存：一分钟内再多请求也只落到一次查询。
 */
declare(strict_types=1);

define('SITE_INFO_TTL', 60);

/** 分类计数（只统计未下架作品），失败时给零值，绝不拖垮首屏 */
function site_category_counts(): array
{
    $hit = cache_get('sitecats', SITE_INFO_TTL);
    if (is_array($hit) && !empty($hit)) { return $hit; }

    $cat = array('game' => 0, 'tool' => 0, 'literature' => 0, 'fanart' => 0);
    try {
        foreach (db_all('SELECT category, COUNT(*) n FROM works WHERE is_hidden = 0 GROUP BY category') as $r) {
            $cat[(string)$r['category']] = (int)$r['n'];
        }
    } catch (Throwable $e) {
        /* 表还没建好等情况：返回零值即可 */
    }
    cache_set('sitecats', $cat, SITE_INFO_TTL);
    return $cat;
}

/** 站内公告：announcements 表 → settings 键 → 空串 */
function site_announce(): string
{
    $hit = cache_get('siteann', SITE_INFO_TTL);
    if (is_array($hit) && array_key_exists('text', $hit)) { return (string)$hit['text']; }

    $t = '';
    try {
        $t = trim((string)db_val("SELECT content FROM announcements WHERE content <> '' ORDER BY id ASC LIMIT 1"));
        if ($t === '') { $t = trim((string)setting_get('announcement', '')); }
    } catch (Throwable $e) {
        $t = '';
    }
    cache_set('siteann', array('text' => $t), SITE_INFO_TTL);
    return $t;
}
