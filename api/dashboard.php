<?php
/**
 * API：控制面板统计
 * actions: overview | stats | ai_rank（AI 用量排行，按 GLM / 模型网关分账）
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_any_admin();   // 副管理员也可读总览与趋势（只读聚合数据）
$panel = isset($_SESSION['panel_ok']) ? (int)$_SESSION['panel_ok'] : 0;

/**
 * AI 用量按通道拆分。
 * glm = 本站模型（智谱）；gateway = 模型网关（免费模型）；legacy = 分账列之前的旧账。
 * 统计属于旁路：新列未就位或查询失败都不抛错，最坏也只是全归入 legacy。
 */
function ai_usage_split(): array
{
    $out = array('glm' => array('tokens' => 0, 'calls' => 0),
                 'gateway' => array('tokens' => 0, 'calls' => 0),
                 'legacy' => array('tokens' => 0, 'calls' => 0));
    try {
        if (col_ok('ai_usage', 'provider')) {
            $rows = db_all('SELECT provider, COALESCE(SUM(total_tokens),0) t, COUNT(*) c FROM ai_usage GROUP BY provider');
            foreach ($rows as $r) {
                $k = in_array((string)$r['provider'], array('glm', 'gateway'), true) ? (string)$r['provider'] : 'legacy';
                $out[$k]['tokens'] += (int)$r['t'];
                $out[$k]['calls']  += (int)$r['c'];
            }
        } else {
            $r = db_one('SELECT COALESCE(SUM(total_tokens),0) t, COUNT(*) c FROM ai_usage');
            $out['legacy']['tokens'] = (int)(is_array($r) ? $r['t'] : 0);
            $out['legacy']['calls']  = (int)(is_array($r) ? $r['c'] : 0);
        }
    } catch (Throwable $e) { app_log('ai_usage_split failed: ' . $e->getMessage()); }
    return $out;
}

$action = param_str('action', 'overview');

switch ($action) {

    /* 顶部汇总卡片 */
    case 'overview': {
        $data = array(
            'panel_verified' => ($panel > 0 && time() - $panel <= 3600),
            'users_total'    => (int)db_val('SELECT COUNT(*) FROM users'),
            'users_today'    => (int)db_val('SELECT COUNT(*) FROM users WHERE created_at >= ?', array(gmdate('Y-m-d') . ' 00:00:00')),
            'works_total'    => (int)db_val('SELECT COUNT(*) FROM works WHERE is_hidden = 0'),
            'votes_total'    => (int)db_val('SELECT (SELECT COUNT(*) FROM work_votes) + (SELECT COUNT(*) FROM comment_votes)'),
            'comments_total' => (int)db_val('SELECT COUNT(*) FROM comments WHERE is_deleted = 0'),
            'messages_total' => (int)db_val('SELECT COUNT(*) FROM messages WHERE is_recalled = 0'),
            'feedback_total' => (int)db_val('SELECT COUNT(*) FROM feedback WHERE is_deleted = 0'),
            'ai_tokens_total'=> (int)db_val('SELECT COALESCE(SUM(total_tokens),0) FROM ai_usage'),
            'ai_calls_total' => (int)db_val('SELECT COUNT(*) FROM ai_usage'),
            'logins_total'   => (int)db_val('SELECT COALESCE(SUM(logins),0) FROM stats_daily'),
            'updates_total'  => (int)db_val('SELECT COALESCE(SUM(work_updates),0) FROM stats_daily'),
        );
        /* 两种通道分开报：控制面板据此区分「本站模型」与「模型网关」的消耗 */
        $split = ai_usage_split();
        foreach (array('glm', 'gateway', 'legacy') as $k) {
            $data['ai_tokens_' . $k] = (int)$split[$k]['tokens'];
            $data['ai_calls_'  . $k] = (int)$split[$k]['calls'];
        }
        ok($data);
        break;
    }

    /* AI 用量排行（按通道分账） */
    case 'ai_rank': {
        $ch  = param_str('ch', '');                        // '' 全部 | glm | gateway | legacy
        $size = max(5, min(100, param_int('size', 20)));   // 每页条数
        $page = max(1, param_int('page', 1));
        $off  = ($page - 1) * $size;
        $byChannel = col_ok('ai_usage', 'provider');

        $where = ''; $args = array();
        if ($byChannel && in_array($ch, array('glm', 'gateway'), true)) { $where = ' WHERE provider = ?'; $args[] = $ch; }
        elseif ($byChannel && $ch === 'legacy') { $where = " WHERE provider = ''"; }

        /* 次序带上 user_id：用量并列时结果稳定，翻页才不会重复或漏行 */
        $rows = db_all('SELECT user_id, COALESCE(SUM(total_tokens),0) t, COUNT(*) c FROM ai_usage'
                     . $where . ' GROUP BY user_id ORDER BY t DESC, user_id ASC LIMIT ' . $size . ' OFFSET ' . $off, $args);
        $total = (int)db_val('SELECT COUNT(DISTINCT user_id) FROM ai_usage' . $where, $args);

        /* 用户名回填（uid<=0 是站点级用量，如搜索总结） */
        $ids = array();
        foreach ($rows as $r) { $u = (int)$r['user_id']; if ($u > 0) { $ids[] = $u; } }
        $names = array();
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));         // 全为整数，拼接安全
            foreach (db_all('SELECT id, username FROM users WHERE id IN (' . $in . ')') as $u) {
                $names[(int)$u['id']] = (string)$u['username'];
            }
        }

        $items = array();
        foreach ($rows as $r) {
            $u = (int)$r['user_id'];
            $items[] = array(
                'uid'    => $u,
                'name'   => $u <= 0 ? '站点（搜索总结等）' : (isset($names[$u]) ? $names[$u] : ('用户 #' . $u)),
                'tokens' => (int)$r['t'],
                'calls'  => (int)$r['c'],
            );
        }
        ok(array('items' => $items, 'channel' => $ch, 'split' => ai_usage_split(), 'by_channel' => $byChannel,
            'total' => $total, 'page' => $page, 'size' => $size,
            'total_pages' => (int)ceil($total / $size), 'has_more' => ($off + count($rows)) < $total));
        break;
    }

    /* 时间序列（条形/折线） */
    case 'stats': {
        $range = param_str('range', '7');
        if ($range === '30') { $days = 30; }
        elseif ($range === 'all') { $days = 180; }
        else { $days = 7; }

        $from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $rows = db_all('SELECT * FROM stats_daily WHERE day >= ? ORDER BY day ASC', array($from));
        $byDay = array();
        foreach ($rows as $r) { $byDay[(string)$r['day']] = $r; }

        $series = array();
        $labels = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $labels[] = substr($d, 5);
            $r = isset($byDay[$d]) ? $byDay[$d] : array();
            $series[] = array(
                'day'         => $d,
                'signups'     => isset($r['signups']) ? (int)$r['signups'] : 0,
                'logins'      => isset($r['logins']) ? (int)$r['logins'] : 0,
                'votes'       => isset($r['votes']) ? (int)$r['votes'] : 0,
                'work_updates'=> isset($r['work_updates']) ? (int)$r['work_updates'] : 0,
                'ai_calls'    => isset($r['ai_calls']) ? (int)$r['ai_calls'] : 0,
                'ai_tokens'   => isset($r['ai_tokens']) ? (int)$r['ai_tokens'] : 0,
                'comments'    => isset($r['comment_count']) ? (int)$r['comment_count'] : 0,
                'messages'    => isset($r['message_count']) ? (int)$r['message_count'] : 0,
                'feedback'    => isset($r['feedback_count']) ? (int)$r['feedback_count'] : 0,
            );
        }

        // 分类分布（条形图）
        $cat = db_all('SELECT category, COUNT(*) n, COALESCE(AVG(total_score),0) avg_score FROM works WHERE is_hidden = 0 GROUP BY category');
        // 评级分布
        $rating = db_all('SELECT rating, COUNT(*) n FROM works WHERE is_hidden = 0 GROUP BY rating');

        ok(array(
            'range'  => $range,
            'labels' => $labels,
            'series' => $series,
            'category_dist' => $cat,
            'rating_dist'   => $rating,
        ));
        break;
    }

    default:
        fail(400, '未知操作');
}
