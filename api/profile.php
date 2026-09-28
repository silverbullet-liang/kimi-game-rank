<?php
/**
 * API：我的
 * actions: info | settings | visits | usage
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'info');

switch ($action) {

    /* 我的主页数据 */
    case 'info': {
        $id = require_token();
        if ($id['role'] === 'guest') {
            ok(array('role' => 'guest', 'username' => '游客', 'avatar' => identicon_data_uri('guest', 96)));
        }
        /* 管理员与副管理员均有真实用户行，统一走同一分支 */
        $u = current_user_row();
        if ($u === null) { fail(401, '登录态已失效'); }
        $isAdmin = $id['role'] === 'admin';
        $st = user_settings($u);
        $uid8 = uid_of_user($u, subadmin_seq_of((int)$u['id']));
        ok(array(
            'role' => (string)$id['role'],
            'is_admin' => $isAdmin || $id['role'] === 'subadmin',
            'uid' => (int)$u['id'],
            'uid8' => $uid8,
            'uid8_babao' => $uid8 !== '' && uid_is_babao($uid8),
            'username' => (string)$u['username'],
            'avatar' => identicon_data_uri((string)$u['username'], 96),
            'settings' => $st,
            'ip' => ip_public((int)$u['id']),
            'usage' => $isAdmin ? ai_usage_summary(0) : ai_usage_summary((int)$u['id']),
            'quota' => $isAdmin ? null : ai_quota_check((int)$u['id']),
            /* 签到对所有登录身份开放（管理员同样可签到、记录连续天数） */
            'checkin' => array(
                'today' => checkin_today_done((int)$u['id']),
                'streak' => checkin_streak((int)$u['id']),
                'unlimited' => $isAdmin,
            ),
            'registered_at' => to_local((string)$u['registered_at']),
        ));
        break;
    }

    /* 保存设置 */
    case 'settings': {
        $id = require_token();
        if ($id['role'] === 'guest') { fail(403, '请登录后再操作'); }
        csrf_verify();
        $u = current_user_row();
        if ($u === null) { fail(401, '登录态已失效'); }
        $st = user_settings($u);

        if (param('notify') !== null)     { $st['notify'] = param_int('notify', 1) === 1 ? 1 : 0; }
        if (param('ip_record') !== null)  { $st['ip_record'] = param_int('ip_record', 1) === 1 ? 1 : 0; }
        if (param('theme') !== null) {
            $t = param_str('theme', 'light');
            $st['theme'] = in_array($t, array('light', 'dark', 'system'), true) ? $t : 'light';
        }
        if (param('skin') !== null) {
            $sk = param_str('skin', 'glass');
            $allow = array('glass', 'md3', 'pixel', 'sketch', 'brutal');
            $st['skin'] = in_array($sk, $allow, true) ? $sk : 'glass';
        }
        if (param('accent') !== null) {
            $a = param_str('accent', 'blue-purple');
            $st['accent'] = ($a === 'ios-colorful' || $a === 'custom' || $a === 'blue-purple') ? $a : 'blue-purple';
        }
        if (param('block_words') !== null) {
            $st['block_words'] = sanitize_block_words((string)param_str('block_words', ''));
        }
        if (param('search_ai') !== null)  { $st['search_ai'] = param_int('search_ai', 1) === 1 ? 1 : 0; }
        if (param('accent_custom') !== null) {
            $c = preg_replace('/[^0-9a-fA-F#]/', '', param_str('accent_custom'));
            $st['accent_custom'] = mb_substr($c, 0, 7, 'UTF-8');
        }
        db_exec('UPDATE users SET settings = ? WHERE id = ?', array(json_encode($st, JSON_UNESCAPED_UNICODE), (int)$u['id']));
        ok($st, '已保存');
        break;
    }

    /* 访问记录（从新到旧） */
    case 'visits': {
        $id = require_token();
        if ($id['role'] === 'guest') { ok(array('items' => array())); }
        $page = max(1, param_int('page', 1));
        $size = 30;
        $off = ($page - 1) * $size;
        $rows = db_all('SELECT ip_masked, location, created_at FROM user_visits WHERE user_id = ? ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $off,
            array(actor_uid($id)));
        $items = array_map(function ($r) {
            return array('ip' => (string)$r['ip_masked'], 'location' => (string)$r['location'], 'time' => to_local((string)$r['created_at']));
        }, $rows);
        ok(array('items' => $items, 'page' => $page, 'has_more' => count($rows) === $size));
        break;
    }

    /* AI 用量 */
    case 'usage': {
        $id = require_token();
        if ($id['role'] === 'admin') {
            ok(array('unlimited' => true, 'summary' => ai_usage_summary(0), 'top3' => ai_top3()));
        }
        if ($id['role'] === 'guest') { fail(403, '请登录'); }
        $uid = actor_uid($id);
        ok(array('unlimited' => false, 'summary' => ai_usage_summary($uid), 'quota' => ai_quota_check($uid),
            'checkin' => array('today' => checkin_today_done($uid), 'streak' => checkin_streak($uid), 'weeks' => checkin_month_weeks($uid))));
        break;
    }

    default:
        fail(400, '未知操作');
}

/* ---------- 辅助 ---------- */
function ip_public(int $uid = 0): array
{
    $ip = client_ip();
    $info = ip_lookup($ip);
    return array(
        'masked'   => mask_ip($ip),
        'location' => isset($info['location']) ? (string)$info['location'] : '',
        'isp'      => isset($info['isp']) ? (string)$info['isp'] : '',
        'time'     => now_utc(),
    );
}

function ai_usage_summary(int $uid): array
{
    $row = db_one('SELECT COALESCE(SUM(total_tokens),0) tokens, COUNT(*) calls FROM ai_usage WHERE user_id = ?', array($uid));
    return array(
        'tokens' => $row === null ? 0 : (int)$row['tokens'],
        'calls'  => $row === null ? 0 : (int)$row['calls'],
        'today'  => ai_usage_today($uid),
        'month'  => ai_usage_month($uid),
    );
}

/** 全站用量 Top3（排除管理员 uid=0） */
function ai_top3(): array
{
    $rows = db_all('SELECT a.user_id, SUM(a.total_tokens) t, COUNT(*) c, u.username
                    FROM ai_usage a LEFT JOIN users u ON u.id = a.user_id
                    WHERE a.user_id > 0 GROUP BY a.user_id ORDER BY t DESC LIMIT 3');
    return array_map(function ($r) {
        return array(
            'username' => $r['username'] === null ? '已注销用户' : (string)$r['username'],
            'tokens'   => (int)$r['t'],
            'calls'    => (int)$r['c'],
        );
    }, $rows);
}
