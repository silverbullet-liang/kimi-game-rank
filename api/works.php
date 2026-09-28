<?php
/**
 * API：作品
 * actions: list | detail | vote
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'list');
$CATS = array('game' => '游戏类', 'tool' => '工具类', 'literature' => '文学类', 'fanart' => '二创类');

switch ($action) {

    /* 榜单列表 */
    case 'list': {
        $ident0 = require_token();   // 所有接口必须带令牌
        $cat   = param_str('category', 'all');
        $board = param_str('board', 'total');
        $q     = trim(param_str('q', ''));
        $page  = max(1, param_int('page', 1));
        $size  = 20;
        $off   = ($page - 1) * $size;

        $where = array('is_hidden = 0');
        $args  = array();
        if (isset($CATS[$cat])) { $where[] = 'category = ?'; $args[] = $cat; }
        if ($q !== '') {
            $where[] = '(title LIKE ? OR author_name LIKE ?)';
            $args[] = '%' . $q . '%';
            $args[] = '%' . $q . '%';
        }
        $w = implode(' AND ', $where);

        /* 排序键优先取物化列（peak_score / vote_count），可走索引。
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
            $sql = "SELECT w.* FROM works w WHERE $w ORDER BY $peakKey DESC, w.total_score DESC LIMIT $size OFFSET $off";
        } elseif ($board === 'vote') {
            $sql = "SELECT w.* FROM works w WHERE $w ORDER BY $voteKey DESC, w.total_score DESC LIMIT $size OFFSET $off";
        } elseif ($board === 'cold') {
            // 冷门榜：热度低但整体不低（沧海遗珠）
            $sql = "SELECT w.* FROM works w WHERE $w
                    ORDER BY w.heat_score ASC, w.total_score DESC
                    LIMIT $size OFFSET $off";
        } else {
            $sql = "SELECT w.* FROM works w WHERE $w ORDER BY w.total_score DESC, w.updated_at DESC LIMIT $size OFFSET $off";
        }
        $ident = current_identity();
        $uid = $ident && $ident['role'] === 'admin' ? admin_uid() : (int)(isset($ident['uid']) ? $ident['uid'] : 0);

        /* 游客结果可复用：45 秒缓存（登录用户含个性化点赞态，不缓存） */
        $cacheKey = 'wlist' . md5(implode('|', array($cat, $board, $q, (string)$page)));
        if ($uid === 0) {
            $hit = cache_get($cacheKey, 45);
            if ($hit !== null) { ok($hit); }
        }

        $rows = db_all($sql, $args);
        $total = (int)db_val("SELECT COUNT(*) FROM works WHERE $w", $args);
        $out = array(
            'items' => array_map(function ($r) use ($CATS, $uid) { return work_public($r, $CATS, $uid); }, $rows),
            'page'  => $page,
            'size'  => $size,
            'total' => $total,
            'total_pages' => (int)ceil($total / $size),
            'has_more' => ($off + count($rows)) < $total,
        );
        if ($uid === 0) { cache_set($cacheKey, $out, 45); }
        ok($out);
        break;
    }

    /* 作品详情 */
    case 'detail': {
        require_token();
        $id = param_int('id', 0);
        $ident = current_identity();
        $uid = $ident && $ident['role'] === 'admin' ? admin_uid() : (int)(isset($ident['uid']) ? $ident['uid'] : 0);

        /* 游客视角不含个性化状态（点赞态恒为未赞），因此可安全缓存。
           作品详情的查询较重：主表 + 评论计数 + 多维聚合，
           而榜单到详情是最高频的跳转，缓存能显著减少重复查询。 */
        $dCacheKey = 'wdet' . (int)$id;
        if ($uid === 0) {
            $hit = cache_get($dCacheKey, 60);
            if ($hit !== null) { ok($hit); }
        }

        $r = db_one('SELECT * FROM works WHERE id = ? AND is_hidden = 0', array($id));
        if ($r === null) { fail(404, '作品不存在或已下架'); }
        $data = work_public($r, $CATS, $uid);
        $data['score_detail'] = json_decode((string)$r['score'], true);
        $data['intro'] = (string)$r['intro'];
        $imgs = json_decode((string)$r['images'], true);
        $data['images'] = is_array($imgs)
            ? array_values(array_map(function ($u) { return img_src((string)$u); }, array_slice($imgs, 0, 9)))
            : array();
        $data['cover']   = !empty($data['images']) ? $data['images'][0] : '';
        $data['link']    = work_link($r);       // cdnUrl 优先
        $data['share']   = (string)$r['share_link'];
        $data['html_url'] = (string)$r['html_url'];
        $data['source_id'] = (string)$r['community_id'];
        $data['added_at'] = to_local((string)$r['created_at']);
        $data['comment_total'] = (int)db_val('SELECT COUNT(*) FROM comments WHERE work_id = ? AND is_deleted = 0', array($id));
        /* 直接读物化列，省掉一次聚合查询 */
        $data['peak_score'] = (int)$r['peak_score'];
        if ($data['peak_score'] <= 0) { $data['peak_score'] = (int)$r['total_score']; }
        if ($uid === 0) { cache_set($dCacheKey, $data, 60); }
        ok($data);
        break;
    }

    /* 点赞 / 取消（单用户唯一） */
    case 'vote': {
        $id = require_member();
        csrf_verify();
        $wid = param_int('id', 0);
        if ($wid <= 0) { fail(400, '参数错误'); }
        $exists = db_one('SELECT id FROM works WHERE id = ? AND is_hidden = 0', array($wid));
        if ($exists === null) { fail(404, '作品不存在'); }
        $uid = actor_uid($id);
        $has = db_val('SELECT id FROM work_votes WHERE work_id = ? AND user_id = ?', array($wid, $uid));
        if ($has) {
            db_exec('DELETE FROM work_votes WHERE work_id = ? AND user_id = ?', array($wid, $uid));
            $voted = false;
        } else {
            cooldown_guard('vote');   // 点赞受限；取消点赞不受限
            // 唯一键兜底：并发下重复插入由 DB 保证
            db_exec('INSERT IGNORE INTO work_votes (work_id, user_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', array($wid, $uid));
            $voted = true;
            stats_bump('votes');
        }
        $cnt = (int)db_val('SELECT COUNT(*) FROM work_votes WHERE work_id = ?', array($wid));
        /* 同步物化列，供人气榜排序走索引；列未就绪则跳过（此时榜单走子查询） */
        if (col_ok('works', 'vote_count')) {
            db_exec('UPDATE works SET vote_count = ? WHERE id = ?', array($cnt, $wid));
        }
        ok(array('voted' => $voted, 'count' => $cnt));
        break;
    }

    default:
        fail(400, '未知操作');
}

/* ---------- 输出整形 ---------- */
function work_public(array $r, array $CATS, int $uid): array
{
    $score = json_decode((string)$r['score'], true);
    $voted = false;
    if ($uid > 0) {
        $voted = (bool)db_val('SELECT 1 FROM work_votes WHERE work_id = ? AND user_id = ? LIMIT 1', array((int)$r['id'], $uid));
    }
    $peak = isset($r['peak_score']) ? (int)$r['peak_score'] : (int)$r['total_score'];
    $imgs = json_decode((string)(isset($r['images']) ? $r['images'] : ''), true);
    $cover = '';
    if (is_array($imgs) && !empty($imgs)) { $cover = img_src((string)$imgs[0]); }
    return array(
        'id'         => (int)$r['id'],
        'title'      => (string)$r['title'],
        'author'     => (string)$r['author_name'],
        'avatar'     => identicon_data_uri((string)$r['author_name'], 48),
        'category'   => (string)$r['category'],
        'category_name' => isset($CATS[$r['category']]) ? $CATS[$r['category']] : '游戏类',
        'total'      => (int)$r['total_score'],
        'peak'       => $peak,
        'rating'     => (string)$r['rating'],
        'heat'       => (int)$r['heat_score'],
        'like'       => (int)$r['like_num'],
        'comment'    => (int)$r['comment_num'],
        'collect'    => (int)$r['collect_num'],
        'votes'      => isset($r['vote_count']) ? (int)$r['vote_count'] : null,
        'voted'      => $voted,
        'link'       => work_link($r),
        'share'      => isset($r['share_link']) ? (string)$r['share_link'] : '',
        'cover'      => $cover,
        'intro'      => isset($r['intro']) ? trim((string)$r['intro']) : '',
        'source_id'  => isset($r['community_id']) ? (string)$r['community_id'] : '',
        'has_html'   => isset($r['has_html']) ? (int)$r['has_html'] === 1 : false,
        'updated_at' => to_local((string)$r['updated_at']),
    );
}
