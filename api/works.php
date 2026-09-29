<?php
/**
 * API：作品
 * actions: list | detail | vote
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'list');
$CATS = works_cats();

switch ($action) {

    /* 榜单列表 */
    case 'list': {
        require_token();             // 所有接口必须带令牌
        $cat   = param_str('category', 'all');
        $board = param_str('board', 'total');
        $q     = trim(param_str('q', ''));
        $page  = max(1, param_int('page', 1));
        /* 每页条数按调用方给的来（此前硬编码 20，与前端 12 不一致，
           导致第二页起的名次整体错位）；与榜单查询共享同一段逻辑与缓存。 */
        $size  = max(1, min(50, param_int('size', 20)));

        $ident = current_identity();
        $uid = $ident && $ident['role'] === 'admin' ? admin_uid() : (int)(isset($ident['uid']) ? $ident['uid'] : 0);

        ok(works_list_page($cat, $board, $q, $page, $size, $uid, true));
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

/* 输出整形 work_public() 已迁至 app/works_list.php —— 与首屏载荷共用同一份 */