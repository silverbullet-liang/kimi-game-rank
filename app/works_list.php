<?php
/**
 * 榜单查询与输出整形
 * ------------------------------------------------------------
 * 抽成独立模块，给 api/works.php 与 api/start.php（首屏一次性载荷）共用。
 * 首屏此前要把同一段查询逻辑走两遍（分类计数 + 列表），这里收口成一处，
 * 顺手做三件降耗的事：
 *   1) 总数聚合按「分类 + 榜别」缓存 60 秒（仅在无搜索词时，避免搜索词撑爆缓存目录）；
 *   2) 登录用户的点赞态改为一次批量取回，不再逐行查询（12 行 = 12 次查询 → 1 次）；
 *   3) 列表模式只整形卡片真正用到的字段，省掉头像 data-URI 与简介等大字段。
 */
declare(strict_types=1);

/** 分类字典：接口层与整形共用同一份，避免两处定义漂移 */
function works_cats(): array
{
    return array('game' => '游戏类', 'tool' => '工具类', 'literature' => '文学类', 'fanart' => '二创类');
}

/**
 * 榜单分页查询。
 *
 * @param string $cat   分类（all 或 game/tool/literature/fanart）
 * @param string $board total|vote|gods|cold
 * @param string $q     搜索词
 * @param int    $page  页码（1 起）
 * @param int    $size  每页条数
 * @param int    $uid   当前用户（0 = 游客）
 * @param bool   $lite  精简整形：列表只回传卡片真正用到的字段
 */
function works_list_page(string $cat, string $board, string $q, int $page, int $size, int $uid, bool $lite = true): array
{
    $CATS = works_cats();
    $page = max(1, $page);
    $size = max(1, min(50, $size));
    $off  = ($page - 1) * $size;

    /* 游客结果与个性化无关（点赞态恒为未赞），可整段复用 45 秒 */
    $cacheKey = 'wlist' . md5(implode('|', array($cat, $board, $q, (string)$page, (string)$size, (string)(int)$lite)));
    if ($uid === 0) {
        $hit = cache_get($cacheKey, 45);
        if (is_array($hit) && isset($hit['items'])) { return $hit; }
    }

    $where = array('is_hidden = 0');
    $args  = array();
    if (isset($CATS[$cat])) { $where[] = 'category = ?'; $args[] = $cat; }
    if ($q !== '') {
        $where[] = '(title LIKE ? OR author_name LIKE ?)';
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
    }
    $w = implode(' AND ', $where);

    /* 副排序键统一用 id：
       · InnoDB 的二级索引末尾隐含主键，因此「排序键 + id」与索引顺序完全一致，
         实测可消除文件排序（此前配 total_score / updated_at 都会退回 filesort）；
       · id 单调，翻页不会因为并列分值而重复或漏掉作品。
       排序键优先取物化列（peak_score / vote_count），可走索引；
       物化列是迁移新增的，若尚未就绪就退回子查询——慢一些，但不能因此 500。 */
    $peakKey = col_ok('works', 'peak_score')
        ? 'w.peak_score'
        : (table_exists('work_score_history')
            ? '(SELECT COALESCE(MAX(h.total_score),0) FROM work_score_history h WHERE h.work_id = w.id)'
            : 'w.total_score');
    $voteKey = col_ok('works', 'vote_count')
        ? 'w.vote_count'
        : (table_exists('work_votes')
            ? '(SELECT COUNT(*) FROM work_votes v WHERE v.work_id = w.id)'
            : 'w.total_score');

    if ($board === 'gods') {
        $sql = "SELECT w.* FROM works w WHERE $w ORDER BY $peakKey DESC, w.id DESC LIMIT $size OFFSET $off";
    } elseif ($board === 'vote') {
        $sql = "SELECT w.* FROM works w WHERE $w ORDER BY $voteKey DESC, w.id DESC LIMIT $size OFFSET $off";
    } elseif ($board === 'cold') {
        $sql = "SELECT w.* FROM works w WHERE $w ORDER BY w.heat_score ASC, w.id ASC LIMIT $size OFFSET $off";
    } else {
        $sql = "SELECT w.* FROM works w WHERE $w ORDER BY w.total_score DESC, w.id DESC LIMIT $size OFFSET $off";
    }
    $rows = db_all($sql, $args);

    /* 总数：无搜索词时缓存 60 秒；搜索词组合无限，缓存它们只会撑大缓存目录 */
    $tKey  = 'wcnt' . md5($cat . '|' . $board . '|' . (string)$size);
    $total = null;
    if ($q === '') {
        $hit = cache_get($tKey, 60);
        if (is_array($hit) && isset($hit['n'])) { $total = (int)$hit['n']; }
    }
    if ($total === null) {
        $total = (int)db_val("SELECT COUNT(*) FROM works WHERE $w", $args);
        if ($q === '') { cache_set($tKey, array('n' => $total), 60); }
    }

    /* 点赞态：一次取回本页全部作品的点赞状态，避免逐行查询 */
    $votedIds = array();
    if ($uid > 0 && !empty($rows)) {
        $ids = array();
        foreach ($rows as $r) { $ids[] = (int)$r['id']; }
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            try {
                foreach (db_all('SELECT work_id FROM work_votes WHERE user_id = ? AND work_id IN (' . $ph . ')',
                                array_merge(array($uid), $ids)) as $v) {
                    $votedIds[(int)$v['work_id']] = true;
                }
            } catch (Throwable $e) {
                /* 点赞表未就绪：按未点赞处理 */
            }
        }
    }

    $items = array();
    foreach ($rows as $r) {
        $items[] = work_public($r, $CATS, $uid, $lite, $votedIds);
    }

    $out = array(
        'items'       => $items,
        'page'        => $page,
        'size'        => $size,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $size),
        'has_more'    => ($off + count($rows)) < $total,
    );
    if ($uid === 0) { cache_set($cacheKey, $out, 45); }
    return $out;
}

/**
 * 输出整形。
 *
 * @param bool  $lite     列表模式：只回传卡片用得到的字段
 * @param array $votedIds 已批量取回的点赞集合；为空数组表示「本页都未点过」，
 *                        传 null 则按需单查（详情页这类单条场景）
 */
function work_public(array $r, array $CATS, int $uid, bool $lite = false, $votedIds = null): array
{
    if ($votedIds !== null) {
        $voted = isset($votedIds[(int)$r['id']]);
    } else {
        $voted = false;
        if ($uid > 0) {
            $voted = (bool)db_val('SELECT 1 FROM work_votes WHERE work_id = ? AND user_id = ? LIMIT 1', array((int)$r['id'], $uid));
        }
    }

    $peak = isset($r['peak_score']) ? (int)$r['peak_score'] : (int)$r['total_score'];
    $imgs = json_decode((string)(isset($r['images']) ? $r['images'] : ''), true);
    $cover = '';
    if (is_array($imgs) && !empty($imgs)) { $cover = img_src((string)$imgs[0]); }

    $out = array(
        'id'            => (int)$r['id'],
        'title'         => (string)$r['title'],
        'author'        => (string)$r['author_name'],
        'category'      => (string)$r['category'],
        'category_name' => isset($CATS[$r['category']]) ? $CATS[$r['category']] : '游戏类',
        'total'         => (int)$r['total_score'],
        'peak'          => $peak,
        'rating'        => (string)$r['rating'],
        'heat'          => (int)$r['heat_score'],
        'cover'         => $cover,
        'voted'         => $voted,
    );
    if ($lite) { return $out; }        // 列表卡片只用到上面这些

    /* 详情页所需的其余字段 */
    $out['avatar']     = identicon_data_uri((string)$r['author_name'], 48);
    $out['like']       = (int)$r['like_num'];
    $out['comment']    = (int)$r['comment_num'];
    $out['collect']    = (int)$r['collect_num'];
    $out['votes']      = isset($r['vote_count']) ? (int)$r['vote_count'] : null;
    $out['link']       = work_link($r);
    $out['share']      = isset($r['share_link']) ? (string)$r['share_link'] : '';
    $out['intro']      = isset($r['intro']) ? trim((string)$r['intro']) : '';
    $out['source_id']  = isset($r['community_id']) ? (string)$r['community_id'] : '';
    $out['has_html']   = isset($r['has_html']) ? (int)$r['has_html'] === 1 : false;
    $out['updated_at'] = to_local((string)$r['updated_at']);
    return $out;
}
