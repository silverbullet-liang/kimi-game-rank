<?php
/**
 * API：管理后台
 * actions: panel_auth | add_work | sync | works | search_works | work_save | work_delete
 *          | refresh_plan | refresh_batch | users | user_delete | visits_top | announce_get | announce_set
 *          | token_set | userscript | subs | sub_add | sub_del
 *          | comments_deleted | comment_restore | comment_purge（评论回收站，仅主管理员）
 *          | backup | backup_list | backup_delete | backup_download（数据库备份，仅主管理员）
 *          | score_mode（读）| score_mode_set（评分方式：收录与更新是否重算评分）
 *          | disc_create | disc_list | disc_delete | disc_reasons | disc_reasons_set
 *          | （违纪通报：一键通报用户、封停账号与 IP，仅主管理员）
 * 权限：主管理员=全部；副管理员=登录凭证即入场（作品搜索/上传/同步 + 只读数据
 *        + 自备社区凭证与其获取脚本），社区 token 各自加密存储、互不共用
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/reclassify.php';

$action = param_str('action', '');

/* ---------- 二次认证：进入控制面板前需再输管理员密钥 ---------- */
if ($action === 'panel_auth') {
    $ident = require_any_admin();
    if ($ident['role'] === 'subadmin') {
        // 副管理员登录即已用密钥验证，无需二次确认
        $_SESSION['panel_ok'] = time();
        ok(array('role' => 'subadmin'), '已进入控制面板');
    }
    csrf_verify();
    if (!admin_verify(param_str('key'))) {
        timing_delay();
        fail(401, '管理员密钥错误');
    }
    $_SESSION['panel_ok'] = time();
    ok(array('role' => 'admin'), '验证通过，已进入控制面板');
}

/* 除二次认证外，均需后台身份 */
$ACTOR = require_any_admin();
$IS_SUB = $ACTOR['role'] === 'subadmin';

/* 作品写操作结束时清空榜单缓存 */
$WRITE_ACTIONS = array('add_work', 'sync', 'work_save', 'work_delete', 'refresh_batch');
if (in_array($action, $WRITE_ACTIONS, true)) { register_shutdown_function('cache_flush'); }

/* 副管理员可用操作白名单：作品搜索 / 上传 / 同步 + 只读数据（访问排行、用户列表）
   + 自备社区凭证（token_set / userscript —— 副管理员抓取依赖自己的 Token，各存各的） */
$SUB_ALLOWED = array('works', 'search_works', 'add_work', 'sync', 'visits_top', 'users', 'token_set', 'userscript',
                     'score_mode', 'score_mode_set', 'link_mode', 'link_mode_set');
if ($IS_SUB && !in_array($action, $SUB_ALLOWED, true)) {
    fail(403, '副管理员无权执行该操作');
}

/* 控制面板写操作需已二次认证（副管理员登录即视为已认证） */
function require_panel()
{
    $ident = current_identity();
    if ($ident && $ident['role'] === 'subadmin') { return; }
    $t = isset($_SESSION['panel_ok']) ? (int)$_SESSION['panel_ok'] : 0;
    if ($t <= 0 || time() - $t > 3600) { fail(403, '请先在控制面板完成密钥验证'); }
}

/** 抓取用社区凭证的存储键：副管理员独立，互不共用 */
function actor_token_key(): string
{
    $ident = current_identity();
    if ($ident && $ident['role'] === 'subadmin') { return 'kimi_token:sub:' . (string)$ident['sub']; }
    return 'kimi_token';
}

switch ($action) {

    /* 保存 / 更新社区 cookie（用于抓取） */
    case 'token_set': {
        require_panel();
        csrf_verify();
        $tok = trim(param_str('kimi_token'));
        if ($tok === '') { fail(400, 'cookie/token 不能为空'); }
        setting_set(actor_token_key(), aes_encrypt($tok));
        ok(null, '社区凭证已加密保存');
        break;
    }

    /* 添加作品：手动（按 ID）/ 自动（按 cookie 同步） */
    case 'add_work': {
        require_panel();
        csrf_verify();
        $token = '';
        $saved = setting_get(actor_token_key(), '');
        if ($saved !== '') {
            try { $token = aes_decrypt((string)$saved); } catch (Exception $e) { $token = ''; }
        }
        $inputTok = trim(param_str('kimi_token'));
        if ($inputTok !== '') {
            $token = $inputTok;
            setting_set(actor_token_key(), aes_encrypt($token));
        }
        if ($token === '') { fail(400, '请先填写社区 cookie/token'); }

        $mode = param_str('mode', 'manual');
        try {
            if ($mode === 'auto') {
                $r = sync_from_feeds($token, 'recommend', 3, 60, true);
                ok($r, '自动同步完成：新增 ' . $r['inserted'] . '，更新 ' . $r['updated']);
            }
            $workId = trim(param_str('work_id'));
            if ($workId === '') { fail(400, '请填写作品 ID'); }
            $item = find_work_by_id($token, $workId);
            $res = work_upsert($item);
            $msg = ($res['status'] === 'inserted' ? '已收录新作品' : '作品已存在，已更新');
            $msg .= !empty($res['scored']) ? '并重算评分' : '（未改动评分）';
            if (!empty($res['link_real'])) { $msg .= '，已改用真实作品地址'; }
            ok(array('status' => $res['status'], 'scores' => $res['scores'], 'scored' => !empty($res['scored']),
                'link_real' => isset($res['link_real']) ? (string)$res['link_real'] : ''), $msg);
        } catch (Exception $e) {
            fail(400, $e->getMessage());
        }
        break;
    }

    /* 作品管理列表 */
    case 'works': {
        $q = trim(param_str('q', ''));
        $page = max(1, param_int('page', 1));
        $size = max(5, min(100, param_int('size', 20)));
        $off = ($page - 1) * $size;
        $args = array();
        $where = '1=1';
        if ($q !== '') { $where .= ' AND (title LIKE ? OR author_name LIKE ? OR community_id LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%"; }
        $rows = db_all("SELECT id, community_id, title, author_name, category, total_score, rating, is_hidden, updated_at
                        FROM works WHERE $where ORDER BY updated_at DESC LIMIT $size OFFSET $off", $args);
        $total = (int)db_val("SELECT COUNT(*) FROM works WHERE $where", $args);
        ok(array('items' => $rows, 'total' => $total, 'page' => $page, 'size' => $size,
            'total_pages' => (int)ceil($total / $size), 'has_more' => ($off + count($rows)) < $total));
        break;
    }

    /* 作品快捷搜索（含社区 ID / 标题 / 作者），供上传与编辑选择 */
    case 'search_works': {
        $q = trim(nfc_normalize(param_str('q', '')));
        if ($q === '') { ok(array('items' => array())); }
        $like = '%' . $q . '%';
        $rows = db_all('SELECT id, community_id, title, author_name, category, total_score, rating, is_hidden
                        FROM works WHERE title LIKE ? OR author_name LIKE ? OR community_id LIKE ?
                        ORDER BY total_score DESC LIMIT 30', array($like, $like, $like));
        ok(array('items' => array_map(function ($r) {
            return array(
                'id' => (int)$r['id'], 'community_id' => (string)$r['community_id'],
                'title' => (string)$r['title'], 'author' => (string)$r['author_name'],
                'category' => (string)$r['category'], 'total' => (int)$r['total_score'],
                'rating' => (string)$r['rating'], 'hidden' => (int)$r['is_hidden'] === 1,
            );
        }, $rows)));
        break;
    }

    /* 读取作品全字段（供编辑表单回填，含下架作品） */
    case 'work_get': {
        $wid = param_int('id', 0);
        $w = db_one('SELECT * FROM works WHERE id = ?', array($wid));
        if ($w === null) { fail(404, '作品不存在'); }
        $imgs = json_decode((string)$w['images'], true);
        $sc   = json_decode((string)$w['score'], true);
        ok(array(
            'id' => (int)$w['id'], 'community_id' => (string)$w['community_id'],
            'title' => (string)$w['title'], 'author' => (string)$w['author_name'],
            'category' => (string)$w['category'], 'intro' => (string)$w['intro'],
            'share_link' => (string)$w['share_link'], 'html_url' => (string)$w['html_url'],
            'cover' => (is_array($imgs) && !empty($imgs)) ? (string)$imgs[0] : '',
            'gallery' => is_array($imgs) ? implode("\n", array_slice($imgs, 1)) : '',
            'hidden' => (int)$w['is_hidden'] === 1,
            'score' => is_array($sc) ? $sc : array(),
        ));
        break;
    }

    /* 编辑 / 下架 / 恢复（主管理员可改任意字段，含六维明细） */
    case 'work_save': {
        require_panel();
        csrf_verify();
        $wid = param_int('id', 0);
        $w = db_one('SELECT * FROM works WHERE id = ?', array($wid));
        if ($w === null) { fail(404, '作品不存在'); }
        $sets = array(); $args = array();

        if (param('title') !== null) {
            $t = trim(nfc_normalize(param_str('title')));
            if ($t === '') { fail(400, '标题不能为空'); }
            $sets[] = 'title = ?'; $args[] = mb_substr($t, 0, 255, 'UTF-8');
        }
        if (param('author') !== null) {
            $a = trim(strip_invisible(nfc_normalize(param_str('author'))));
            $sets[] = 'author_name = ?'; $args[] = mb_substr($a, 0, 64, 'UTF-8');
        }
        if (param('intro') !== null) {
            $sets[] = 'intro = ?'; $args[] = mb_substr(trim(nfc_normalize(param_str('intro'))), 0, 5000, 'UTF-8');
        }
        if (param('category') !== null) {
            $c = param_str('category', 'game');
            if (!in_array($c, array('game', 'tool', 'literature', 'fanart'), true)) { fail(400, '分类无效'); }
            $sets[] = 'category = ?'; $args[] = $c;
        }
        if (param('share_link') !== null) {
            $sets[] = 'share_link = ?'; $args[] = mb_substr(trim(param_str('share_link')), 0, 255, 'UTF-8');
        }
        if (param('html_url') !== null) {
            $sets[] = 'html_url = ?'; $args[] = mb_substr(trim(param_str('html_url')), 0, 255, 'UTF-8');
        }
        if (param('cover') !== null || param('gallery') !== null) {
            $imgs = array();
            if (param('cover') !== null) {
                $c0 = trim(param_str('cover'));
                if ($c0 !== '') { $imgs[] = mb_substr($c0, 0, 500, 'UTF-8'); }
            }
            if (param('gallery') !== null) {
                foreach (preg_split('/\r\n|\r|\n/', param_str('gallery')) as $line) {
                    $line = trim($line);
                    if ($line !== '' && count($imgs) < 9) { $imgs[] = mb_substr($line, 0, 500, 'UTF-8'); }
                }
            }
            $sets[] = 'images = ?'; $args[] = json_encode($imgs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (param('hidden') !== null) { $sets[] = 'is_hidden = ?'; $args[] = param_int('hidden', 0) === 1 ? 1 : 0; }

        /* 六维明细（任意一项给定即整体重算总分与评级） */
        $dims = array('creativity', 'experience', 'depth', 'cost', 'attitude', 'heat');
        $score = json_decode((string)$w['score'], true);
        if (!is_array($score)) { $score = array(); }
        $hasDim = false;
        foreach ($dims as $d) {
            if (param('dim_' . $d) !== null) {
                /* 每维上限与评分口径一致（config/scoring.php：单维满分 200、总分 1200）。
                   早先这里钳到 100，手工编辑六维时会被静默截断。 */
                $score[$d] = max(0, min(200, param_int('dim_' . $d, 0)));
                $hasDim = true;
            }
        }
        if ($hasDim) {
            $total = 0;
            foreach ($dims as $d) { $total += (int)(isset($score[$d]) ? $score[$d] : 0); }
            $score['total']  = $total;
            $score['rating'] = rating_of($total);
            $sets[] = 'score = ?';       $args[] = json_encode($score);
            $sets[] = 'total_score = ?'; $args[] = $total;
            $sets[] = 'rating = ?';      $args[] = rating_of($total);
            $sets[] = 'heat_score = ?';  $args[] = (int)(isset($score['heat']) ? $score['heat'] : 0);
        }

        if (empty($sets)) { fail(400, '无可更新字段'); }
        $args[] = $wid;
        db_exec('UPDATE works SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP() WHERE id = ?', $args);
        /* 手工改分后同步物化列：peak_score 取历史最高与当前总分的较大者（列未就绪则跳过） */
        if (col_ok('works', 'peak_score')) {
            db_exec('UPDATE works SET peak_score = GREATEST(peak_score, total_score) WHERE id = ?', array($wid));
        }
        ok(null, '已保存');
        break;
    }

    /* 删除作品 */
    case 'work_delete': {
        require_panel();
        csrf_verify();
        $wid = param_int('id', 0);
        db_exec('DELETE FROM comments WHERE work_id = ?', array($wid));
        db_exec('DELETE FROM work_votes WHERE work_id = ?', array($wid));
        db_exec('DELETE FROM work_score_history WHERE work_id = ?', array($wid));
        $n = db_exec('DELETE FROM works WHERE id = ?', array($wid));
        if ($n === 0) { fail(404, '作品不存在'); }
        ok(null, '已删除');
        break;
    }

    /* 一键更新全部作品：分批 5 条，前端据返回进度绘制进度条 */
    /* ---------- 一键更新全部作品 ----------
       分两步，免得「边更新边变」把顺序搅乱：
         refresh_plan   一次性取回按排名（总分降序、并列按 id）排好的作品 id 名单；
         refresh_batch  按名单一批一批更新（每批最多 5 件）。
       名单在开始时固定，中途分数再怎么变也不会漏掉或重复某件作品。 */
    case 'refresh_plan': {
        require_panel();
        csrf_verify();
        if (setting_get(actor_token_key(), '') === '') { fail(400, '请先保存社区 cookie/token'); }

        $total = (int)db_val('SELECT COUNT(*) FROM works');
        $rows  = db_all('SELECT id FROM works ORDER BY total_score DESC, id ASC LIMIT 20000');
        $ids = array();
        foreach ($rows as $r) { $ids[] = (int)$r['id']; }
        ok(array(
            'total'  => $total,
            'ids'    => $ids,
            'capped' => count($ids) < $total,      // 作品数超过单次上限时为 true
            'scored' => score_auto_enabled(),
        ));
        break;
    }

    case 'refresh_batch': {
        require_panel();
        csrf_verify();
        $saved = setting_get(actor_token_key(), '');
        if ($saved === '') { fail(400, '请先保存社区 cookie/token'); }
        try { $token = aes_decrypt((string)$saved); } catch (Exception $e) { fail(400, '社区凭证已失效，请重新填写'); }

        $raw = param('ids', array());
        if (!is_array($raw)) { fail(400, '参数格式错误'); }
        $clean = array();
        foreach ($raw as $v) {
            $n = (int)$v;
            if ($n > 0 && !in_array($n, $clean, true)) { $clean[] = $n; }
        }
        $clean = array_slice($clean, 0, 5);        // 每批最多 5 件
        if (empty($clean)) { fail(400, '缺少待更新的作品'); }

        $byId = array();
        foreach (db_all('SELECT id, community_id FROM works WHERE id IN (' . implode(',', $clean) . ')') as $r) {
            $byId[(int)$r['id']] = (string)$r['community_id'];
        }

        $okN = 0; $failN = 0; $links = 0; $errors = array();
        foreach ($clean as $wid) {                 // 按名单给的顺序处理，IN 不保证顺序
            if (!isset($byId[$wid])) { continue; }  // 名单生成之后被删掉的作品
            try {
                $item = find_work_by_id($token, $byId[$wid]);
                $r = work_upsert($item);
                if (!empty($r['link_real'])) { $links++; }
                $okN++;
            } catch (Throwable $e) {
                $failN++;
                if (count($errors) < 5) { $errors[] = $byId[$wid] . ': ' . $e->getMessage(); }
            }
        }
        ok(array(
            'processed' => $okN + $failN,
            'ok'        => $okN,
            'fail'      => $failN,
            'links'     => $links,
            'errors'    => $errors,
            'scored'    => score_auto_enabled(),
        ));
        break;
    }

    /* ---------- 分区：预览自动判定结果 ----------
       只读不写。返回统计与样例，管理员看过之后再决定是否执行。
       这样「点错了」不会直接改动线上数据。 */
    case 'reclassify_preview': {
        require_panel();
        $plan = reclassify_plan();
        $sample = array_slice($plan['items'], 0, 40);
        ok(array(
            'counts' => $plan['counts'],
            'sample' => $sample,
            'rules'  => array(
                '二创类'   => '标题或简介出现「改编自 / 原作者是 / 同人 / 二创 / 番外 / 衍生 / 平行世界 / 设定集」等从属或二次创作表述',
                '文学类'   => '出现「小说 / 文学 / 散文 / 诗歌 / 连载 / 短篇 / 章节 / 文集 / 随笔」等以文字叙事为主体的表述',
                '工具类'   => '出现「工具 / 插件 / 脚本 / 助手 / 自动化 / 生成器 / 面板 / 一键 / 软件」等实用向表述',
                '游戏类'   => '不单独判定，作为兜底：以上三类都不命中即归游戏',
                '优先级'   => '二创 ＞ 文学 ＞ 工具 ＞ 其余归游戏（「改编自某小说的同人游戏」会归入二创）',
            ),
        ));
        break;
    }

    /* ---------- 分区：按判定结果执行变更（分批，每批 60 条） ---------- */
    case 'reclassify_apply': {
        require_panel();
        csrf_verify();
        $plan = reclassify_plan();
        $total = count($plan['items']);
        $size  = 60;
        $offset = max(0, param_int('offset', 0));
        $batch = array_slice($plan['items'], $offset, $size);

        $n = 0;
        foreach ($batch as $it) {
            db_exec('UPDATE works SET category = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                array($it['to'], (int)$it['id']));
            $n++;
        }
        $doneN = $offset + count($batch);
        ok(array(
            'total'    => $total,
            'done'     => $doneN,
            'applied'  => $n,
            'finished' => $doneN >= $total,
        ));
        break;
    }

    /* ---------- 分区：手工批量更换 ----------
       from 可为 'all'，即把所有作品整体迁移到目标分区。
       用于「这一整个分区不想要了，全部并入另一个」。 */
    case 'move_category': {
        require_panel();
        csrf_verify();
        $from = param_str('from', '');
        $to   = param_str('to', '');
        $VALID = array('game', 'tool', 'literature', 'fanart');
        if (!in_array($to, $VALID, true)) { fail(400, '目标分区无效'); }
        if ($from !== 'all' && !in_array($from, $VALID, true)) { fail(400, '来源分区无效'); }
        if ($from === $to) { fail(400, '来源与目标相同，无需迁移'); }

        if ($from === 'all') {
            $n = db_exec('UPDATE works SET category = ?, updated_at = UTC_TIMESTAMP() WHERE category <> ?', array($to, $to));
        } else {
            $n = db_exec('UPDATE works SET category = ?, updated_at = UTC_TIMESTAMP() WHERE category = ?', array($to, $from));
        }
        ok(array('moved' => (int)$n), '已迁移 ' . (int)$n . ' 件作品到' . reclassify_label($to));
        break;
    }

    /* 用户列表 / 搜索 */
    /* ---------- 数据库：状态体检（只读） ----------
       让管理员在动手之前，先看清数据库到底缺什么。 */
    case 'db_status': {
        require_admin();          // 涉及库结构，仅总管理员
        ok(db_report());
        break;
    }

    /* ---------- 数据库：一键更新 ----------
       幂等：全部按「列/表在不在」判断，可反复执行。
       前后各取一次报告，好让界面显示这次究竟补了什么。 */
    case 'db_update': {
        require_admin();          // 涉及库结构，仅总管理员
        csrf_verify();

        $before = db_report();
        $t0 = microtime(true);

        try {
            ensure_schema(true);      // 补列（绕过 10 分钟限流）
            run_migrations(true);     // 补表、补索引、写版本号
            $error = '';
        } catch (Throwable $e) {
            $error = $e->getMessage();
            app_log('db_update error: ' . $error);
        }

        $after = db_report();
        $fixed = array_values(array_diff($before['missing'], $after['missing']));
        $left  = array_values($after['missing']);

        ok(array(
            'before'  => $before,
            'after'   => $after,
            'fixed'   => $fixed,                       // 本次补上的
            'left'    => $left,                        // 仍然缺的
            'error'   => $error,
            'elapsed' => round(microtime(true) - $t0, 2),
        ));
        break;
    }

    /* ---------- 评分方式 ----------
       关闭后，收录与「全部更新」只更新作品信息，不再重算评分——
       管理员按实际情况手动改过的分数不会被覆盖。随时可切换回来。 */
    case 'score_mode': {
        ok(array('auto' => score_auto_enabled()));
        break;
    }

    case 'score_mode_set': {
        require_panel();       // 副管理员同样可切换：他们也要收录与更新作品
        csrf_verify();
        $auto = param_int('auto', 1) === 1;
        setting_set('score_auto', $auto ? '1' : '0');
        app_log('score_mode set to ' . ($auto ? 'auto' : 'manual') . ' by admin');
        ok(array('auto' => $auto), $auto
            ? '已恢复自动评分：收录与更新都会重算'
            : '已关闭自动评分：收录与更新不再改动评分');
        break;
    }

    /* ---------- 智能链接识别 ----------
       原页面若只是个跳转页，收录/更新时改用其中的真实地址。默认开启。 */
    case 'link_mode': {
        ok(array('on' => smart_link_enabled()));
        break;
    }

    case 'link_mode_set': {
        require_panel();       // 副管理员同样可切换
        csrf_verify();
        $on = param_int('on', 0) === 1;
        setting_set('smart_link', $on ? '1' : '0');
        app_log('smart_link set to ' . ($on ? 'on' : 'off') . ' by admin');
        ok(array('on' => $on), $on
            ? '已开启智能链接识别：收录与更新时会尝试改用真实作品地址'
            : '已关闭智能链接识别：作品页一律按原地址收录');
        break;
    }

    /* ---------- 数据库备份 ----------
       备份含全站数据（账号、评论、对话、配置），只给主管理员。
       backup_download 直接吐文件流（不是 JSON），故内部 exit。 */
    case 'backup': {
        require_admin();
        require_panel();
        csrf_verify();
        @set_time_limit(0);          // 大库导出可能超过默认 30 秒
        try { ok(backup_run(), '备份完成'); }
        catch (Throwable $e) { fail(500, $e->getMessage()); }
        break;
    }

    case 'backup_list': {
        require_admin();
        ok(array(
            'items'    => backup_list(),
            'writable' => backup_writable(),
            'limit_text' => backup_size_text(BACKUP_MAX_BYTES),
        ));
        break;
    }

    case 'backup_delete': {
        require_admin();
        require_panel();
        csrf_verify();
        try { backup_delete(param_str('name')); ok(null, '已删除该备份'); }
        catch (Throwable $e) { fail(400, $e->getMessage()); }
        break;
    }

    case 'backup_download': {
        require_admin();
        require_panel();
        backup_stream(param_str('name'));   // 内部 exit
        break;
    }

    case 'users': {
        $q = trim(param_str('q', ''));
        $page = max(1, param_int('page', 1));
        $size = 30;
        $off = ($page - 1) * $size;
        $args = array(); $where = '1=1';
        if ($q !== '') {
            $where .= ' AND (username LIKE ? OR username_norm LIKE ? OR uid8 LIKE ?)';
            $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%";
        }
        $rows = db_all("SELECT id, username, role, uid8, is_banned, ban_until, created_at, last_login_at,
                        (SELECT COUNT(*) FROM discipline_reports d WHERE d.user_id = users.id AND d.ban_days >= 7) reports,
                        (SELECT COUNT(*) FROM ai_usage a WHERE a.user_id = users.id) ai_calls,
                        (SELECT COALESCE(SUM(total_tokens),0) FROM ai_usage a WHERE a.user_id = users.id) ai_tokens
                        FROM users WHERE $where ORDER BY id DESC LIMIT $size OFFSET $off", $args);
        $total = (int)db_val("SELECT COUNT(*) FROM users WHERE $where", $args);
        $adminView = !$IS_SUB;   // 用户 IP / 归属地仅主管理员可见
        $items = array_map(function ($r) use ($adminView) {
            $out = array(
                'id' => (int)$r['id'], 'username' => (string)$r['username'],
                'role' => (string)$r['role'], 'uid8' => (string)$r['uid8'],
                'avatar' => identicon_data_uri((string)$r['username'], 40),
                'banned' => (int)$r['is_banned'] === 1,
                'ban_until' => $r['ban_until'] === null ? '' : to_local((string)$r['ban_until']),
                'reports' => (int)$r['reports'],
                'created' => to_local((string)$r['created_at'], 'Y-m-d'),
                'last_login' => $r['last_login_at'] === null ? '—' : to_local((string)$r['last_login_at']),
                'ai_calls' => (int)$r['ai_calls'], 'ai_tokens' => (int)$r['ai_tokens'],
            );
            if ($adminView) {
                $v = db_one('SELECT ip_masked, location FROM user_visits WHERE user_id = ? ORDER BY id DESC LIMIT 1', array((int)$r['id']));
                $out['ip_masked'] = $v === null ? '' : (string)$v['ip_masked'];
                $out['location'] = $v === null ? '' : (string)$v['location'];
            }
            return $out;
        }, $rows);
        ok(array('items' => $items, 'total' => $total, 'page' => $page, 'size' => $size,
            'total_pages' => (int)ceil($total / $size), 'has_more' => ($off + count($rows)) < $total,
            'ip_visible' => $adminView));
        break;
    }

    /* 访问排行（国家 / 省份 / 城市 各前十五）：管理员与副管理员均可查看 */
    case 'visits_top': {
        /* 直接按三个结构化列分组。
           早先是对拼接好的 location 串做正则解析，字段边界已丢失，
           于是出现「省份里是中国[CN]」「城市里是四川省」这类错位；
           历史记录已在迁移时回填到这三列，新旧数据同样适用。 */
        $rows = db_all('SELECT country, province, city, COUNT(*) c FROM user_visits GROUP BY country, province, city');
        $B = array('co' => array(), 'pr' => array(), 'ci' => array());   // 国家 / 省份 / 城市
        $located = 0; $unknown = 0;
        $bump = function (array &$m, $k, $n) {
            if ($k === '') { return; }
            $m[$k] = (isset($m[$k]) ? $m[$k] : 0) + $n;
        };
        foreach ($rows as $r) {
            $n  = (int)$r['c'];
            $co = trim((string)$r['country']);
            $pr = trim((string)$r['province']);
            $ci = trim((string)$r['city']);
            if ($co === '' && $pr === '' && $ci === '') { $unknown += $n; continue; }
            $located += $n;
            $bump($B['co'], $co, $n);
            $bump($B['pr'], $pr, $n);
            $bump($B['ci'], $ci, $n);
        }
        $top = function ($m) {
            arsort($m);
            $out = array(); $i = 0;
            foreach ($m as $k => $v) {
                $out[] = array('name' => (string)$k, 'count' => (int)$v);
                if (++$i >= 15) { break; }
            }
            return $out;
        };
        if ($unknown > 0) { $B['co']['未知（归属地服务未返回）'] = $unknown; }
        ok(array(
            'country' => $top($B['co']), 'province' => $top($B['pr']), 'city' => $top($B['ci']),
            'total' => (int)db_val('SELECT COUNT(*) FROM user_visits'),
            'located' => $located, 'unknown' => $unknown,
        ));
        break;
    }

    /* 删除用户（连同其数据） */
    case 'user_delete': {
        require_panel();
        csrf_verify();
        $uid = param_int('id', 0);
        if ($uid <= 0) { fail(400, '参数错误'); }
        $u = db_one('SELECT id, username, role FROM users WHERE id = ?', array($uid));
        if ($u === null) { fail(404, '用户不存在'); }
        if ((string)$u['role'] === 'admin') { fail(400, '管理员账号不可删除'); }
        if ((string)$u['role'] === 'subadmin') { fail(400, '该账号是副管理员，请在上方「副管理员」中取消身份或删除'); }
        db_exec('DELETE FROM user_visits WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM comments WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM comment_votes WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM work_votes WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM messages WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM ai_messages WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM feedback WHERE user_id = ?', array($uid));
        db_exec('DELETE FROM users WHERE id = ?', array($uid));
        ok(null, '用户「' . $u['username'] . '」已删除');
        break;
    }

    /* 公告读取 */
    /* ---------- 评论回收站（仅主管理员） ----------
       删除 = 软删除入回收站：前端不再展示，但内容仍在库里，
       可在此查看、恢复，或彻底清空。 */

    /* 列出回收站中的评论 */
    case 'comments_deleted': {
        require_panel();
        $page = max(1, param_int('page', 1));
        $size = 30;
        $off  = ($page - 1) * $size;
        $rows = db_all("SELECT c.id, c.work_id, c.content, c.deleted_by, c.created_at,
                               u.username, w.title
                        FROM comments c
                        LEFT JOIN users u ON u.id = c.user_id
                        LEFT JOIN works w ON w.id = c.work_id
                        WHERE c.is_deleted = 1
                        ORDER BY c.id DESC LIMIT $size OFFSET $off");
        $items = array_map(function ($r) {
            return array(
                'id'       => (int)$r['id'],
                'work_id'  => (int)$r['work_id'],
                'title'    => $r['title'] === null ? '（作品已移除）' : (string)$r['title'],
                'username' => $r['username'] === null ? '（已注销）' : (string)$r['username'],
                'content'  => (string)$r['content'],
                'by_admin' => (int)$r['deleted_by'] === 2,
                'time'     => to_local((string)$r['created_at'], 'Y-m-d H:i'),
            );
        }, $rows);
        ok(array(
            'items'    => $items,
            'total'    => (int)db_val('SELECT COUNT(*) FROM comments WHERE is_deleted = 1'),
            'page'     => $page,
            'has_more' => count($rows) === $size,
        ));
        break;
    }

    /* 从回收站恢复一条评论 */
    case 'comment_restore': {
        require_panel();
        csrf_verify();
        $cid = param_int('id', 0);
        if ($cid <= 0) { fail(400, '参数错误'); }
        db_exec('UPDATE comments SET is_deleted = 0, deleted_by = 0 WHERE id = ?', array($cid));
        ok(null, '已恢复');
        break;
    }

    /* 从回收站彻底删除：指定 id 删单条，不带 id 则清空整个回收站 */
    case 'comment_purge': {
        require_panel();
        csrf_verify();
        $cid = param_int('id', 0);
        if ($cid > 0) {
            db_exec('DELETE FROM comment_votes WHERE comment_id = ?', array($cid));
            db_exec('DELETE FROM comments WHERE id = ? AND is_deleted = 1', array($cid));
            ok(null, '已彻底删除');
        }
        $n = (int)db_val('SELECT COUNT(*) FROM comments WHERE is_deleted = 1');
        db_exec('DELETE FROM comment_votes WHERE comment_id IN (SELECT id FROM comments WHERE is_deleted = 1)');
        db_exec('DELETE FROM comments WHERE is_deleted = 1');
        ok(array('purged' => $n), '回收站已清空');
        break;
    }

    /* 「Token 获取」用户脚本：直接 302 到源站，由浏览器自己取。
       以前由服务端取回再原样下发（因源站无 CORS，跨域下 download 属性会失效）——
       但「替访客取第三方内容再转发」正是代理行为，在共享主机上属高风险特征，故改为跳转。 */
    case 'userscript': {
        require_panel();
        header('Location: https://harbor-ljmr.upma.site/Token_acquisition.js', true, 302);
        exit;
    }

    /* ---------- 站点互通 ---------- */
    case 'peers_state': {
        require_panel();
        ok(array(
            'my_pubkey'  => peer_my_public(),
            'my_key_set' => peer_key_valid(peer_my_private()),
            'sodium'     => peer_crypto_ready(),
            'peers'      => db_all('SELECT id, name, base_url, pubkey, enabled, last_sync_at, last_status FROM peers ORDER BY id'),
        ));
        break;
    }
    case 'peer_save': {
        require_panel();
        $id   = (int)param_str('id');
        $name = trim(param_str('name'));
        $url  = rtrim(trim(param_str('base_url')), '/');
        $pk   = strtolower(trim(param_str('pubkey')));
        $en   = ((int)param_str('enabled', '1')) ? 1 : 0;
        if ($url === '' || !preg_match('#^https?://#i', $url)) { fail(400, '请填写 http(s) 开头的站点地址'); }
        if (!peer_key_valid($pk)) { fail(400, '公钥须为 64 位十六进制'); }
        if ($id > 0) {
            db_exec('UPDATE peers SET name = ?, base_url = ?, pubkey = ?, enabled = ? WHERE id = ?',
                array($name, $url, $pk, $en, $id));
        } else {
            db_exec('INSERT INTO peers (name, base_url, pubkey, enabled, created_at) VALUES (?, ?, ?, ?, ?)',
                array($name, $url, $pk, $en, now_utc()));
        }
        ok(array(), '已保存');
        break;
    }
    case 'peer_del': {
        require_panel();
        db_exec('DELETE FROM peers WHERE id = ?', array((int)param_str('id')));
        ok(array(), '已删除');
        break;
    }
    case 'peer_test': {
        require_panel();
        $peer = db_one('SELECT * FROM peers WHERE id = ? LIMIT 1', array((int)param_str('id')));
        if ($peer === null) { fail(404, '站点不存在'); }
        $r = peer_call($peer, 'hello');
        $m = isset($r['manifest']) && is_array($r['manifest']) ? $r['manifest'] : array();
        ok(array('tables' => count($m)), '连接正常，密钥校验通过');
        break;
    }
    case 'peer_sync': {
        require_panel();
        @set_time_limit(300);
        $res = peer_sync_all();
        ok(array('results' => $res), '互通完成');
        break;
    }

    case 'announce_get': {
        ok(array('content' => (string)db_val('SELECT content FROM announcements ORDER BY id ASC LIMIT 1')));
        break;
    }

    /* 公告保存 */
    case 'announce_set': {
        require_panel();
        csrf_verify();
        $c = trim(strip_invisible(nfc_normalize(param_str('content'))));
        if (mb_strlen($c, 'UTF-8') < 1) { fail(400, '公告不能为空'); }
        $c = mb_substr($c, 0, 512, 'UTF-8');
        $id = db_val('SELECT id FROM announcements ORDER BY id ASC LIMIT 1');
        if ($id === null) {
            db_insert('INSERT INTO announcements (content, updated_at) VALUES (?, UTC_TIMESTAMP())', array($c));
        } else {
            db_exec('UPDATE announcements SET content = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', array($c, (int)$id));
            db_exec('DELETE FROM announcements WHERE id <> ?', array((int)$id));   // 只保留一条，避免读取歧义
        }
        setting_set('announcement', $c);
        ok(null, '公告已更新');
        break;
    }

    /* UID 反查：8 位 UID → 账号（可逆推校验） */
    case 'uid_resolve': {
        $r = uid_resolve(param_str('uid'));
        if (empty($r['ok'])) { fail(404, isset($r['error']) ? $r['error'] : '未找到'); }
        ok($r);
        break;
    }

    /* ---------- 副管理员（仅主管理员） ---------- */
    case 'subs': {
        $rows = db_admin_all('SELECT id, username, user_id, created_at FROM sub_admins ORDER BY id ASC');
        $items = array_map(function ($r) {
            $uid8 = '';
            try { $uid8 = (string)db_val('SELECT uid8 FROM users WHERE id = ? LIMIT 1', array((int)$r['user_id'])); } catch (Throwable $e) {}
            return array('id' => (int)$r['id'], 'username' => (string)$r['username'],
                'user_id' => (int)$r['user_id'], 'uid8' => $uid8,
                'created' => to_local((string)$r['created_at'], 'Y-m-d'));
        }, $rows);
        /* 豹子号池余量：池满后新副管理员自动改用普通 8 位 UID（身份与权限不受影响），
           这里只是把「还剩几个」如实报出来，免得加人时莫名其妙。 */
        $pool = uid_babao_pool();
        $used = 0;
        foreach ($pool as $cand) { if (uid_taken($cand, 0)) { $used++; } }
        ok(array(
            'items'       => $items,
            'babao_total' => count($pool),
            'babao_used'  => $used,
            'babao_left'  => max(0, count($pool) - $used),
        ));
        break;
    }

    case 'sub_add': {
        csrf_verify();
        $r = subadmin_create(param_str('username'), param_str('secret'));
        $msg = (isset($r['mode']) && $r['mode'] === 'promoted')
            ? '已将现有用户「' . $r['username'] . '」升级为副管理员'
            : '副管理员「' . $r['username'] . '」已创建';
        ok($r, $msg);
        break;
    }

    /* 取消副管理员身份（降级为普通用户，保留全部数据） */
    case 'sub_demote': {
        csrf_verify();
        $sid = param_int('id', 0);
        if (!subadmin_demote($sid)) { fail(404, '副管理员不存在'); }
        ok(null, '已取消其副管理员身份，账号与数据保留');
        break;
    }

    case 'sub_del': {
        csrf_verify();
        $sid = param_int('id', 0);
        if (!subadmin_delete($sid)) { fail(404, '副管理员不存在'); }
        ok(null, '已删除该副管理员');
        break;
    }

    /* ---------- 违纪通报（仅主管理员）----------
       对用户一键通报：可勾选多条理由、补充说明，并可同时封停账号与访问 IP。
       被封者访问站点会被 302 到违纪界面，界面下方逐条列出理由，并带评论区。 */
    case 'disc_create': {
        require_admin();
        require_panel();
        csrf_verify();
        $ids = json_decode(param_str('user_ids', '[]'), true);
        $rs  = json_decode(param_str('reasons', '[]'), true);
        if (!is_array($ids)) { $ids = array(); }
        if (!is_array($rs))  { $rs = array(); }
        $note    = trim(strip_invisible(nfc_normalize(param_str('note', ''))));
        $banAcc  = param_int('ban_account', 1) === 1;
        $banIp   = param_int('ban_ip', 1) === 1;
        $banDays = (float)param_str('ban_days', '0');     // 支持小数天（如 0.5、1.5）；0 = 永久
        $purge   = param_int('purge', 0) === 1;           // 是否清理其评论 / 对话 / 图片
        try {
            $r = discipline_create($ids, $rs, $note, $banAcc, $banIp, (int)admin_uid(), $banDays, $purge);
        } catch (Throwable $e) {
            fail(400, $e->getMessage());
        }
        $msg = '已通报 ' . (int)$r['created'] . ' 个用户';
        if ((int)$r['accounts_banned'] > 0) {
            $msg .= '，封停账号 ' . (int)$r['accounts_banned'] . ' 个（'
                  . ($banDays > 0 ? $banDays . ' 天后自动解除' : '永久') . '）';
        }
        if ((int)$r['ips_banned'] > 0) { $msg .= '，封禁 IP ' . (int)$r['ips_banned'] . ' 个'; }
        if ((int)$r['no_ip'] > 0)      { $msg .= '；其中 ' . (int)$r['no_ip'] . ' 个暂无可封的 IP，等其下次访问后再通报即可'; }
        if ($purge) {
            $msg .= '；已清理评论 ' . (int)$r['purged_comments'] . ' 条、对话 '
                  . (int)$r['purged_messages'] . ' 条、图片 ' . (int)$r['purged_images'] . ' 张';
        }
        ok($r, $msg);
        break;
    }

    case 'disc_list': {
        require_admin();
        require_panel();
        ok(discipline_list(max(1, param_int('page', 1)), 20));
        break;
    }

    /* 通报的二次设置：改理由 / 说明 / 封禁天数（重算解封时间）/ 解封 / IP 封禁开关 */
    case 'disc_update': {
        require_admin();
        require_panel();
        csrf_verify();
        $id = param_int('id', 0);
        $patch = array();
        if (param_str('set_reasons', '') === '1') {
            $rs = json_decode(param_str('reasons', '[]'), true);
            $patch['reasons'] = is_array($rs) ? $rs : array();
        }
        if (param_str('set_note', '') === '1')    { $patch['note'] = param_str('note', ''); }
        if (param_str('set_unban', '') === '1')   { $patch['unban'] = param_int('unban', 1) === 1; }
        if (param_str('set_days', '') === '1')    { $patch['ban_days'] = (float)param_str('ban_days', '0'); }
        if (param_str('set_ip', '') === '1')      { $patch['ip_banned'] = param_int('ip_banned', 1) === 1; }
        if (!$patch) { fail(400, '没有要修改的内容'); }
        try {
            $row = discipline_update($id, $patch);
        } catch (Throwable $e) {
            fail(400, $e->getMessage());
        }
        ok(array(
            'id'        => (int)$row['id'],
            'reasons'   => discipline_reasons_of($row),
            'note'      => (string)($row['note'] ?? ''),
            'banned'    => (int)$row['banned'] === 1,
            'ban_days'  => (float)$row['ban_days'],
            'ban_until' => $row['ban_until'] === null ? '' : to_local((string)$row['ban_until']),
            'ip_banned' => (int)$row['ip_banned'] === 1,
            'user_count' => discipline_user_count((int)$row['user_id']),
        ), '已更新该通报');
        break;
    }

    case 'disc_delete': {
        require_admin();
        require_panel();
        csrf_verify();
        if (!discipline_delete(param_int('id', 0))) { fail(404, '通报不存在'); }
        ok(null, '已撤销该通报，账号与 IP 一并解封');
        break;
    }

    case 'disc_reasons': {
        require_admin();
        require_panel();
        ok(array('items' => discipline_reasons()));
        break;
    }

    case 'disc_reasons_set': {
        require_admin();
        require_panel();
        csrf_verify();
        $list = json_decode(param_str('items', '[]'), true);
        discipline_reasons_set(is_array($list) ? $list : array());
        ok(array('items' => discipline_reasons()), '理由清单已保存');
        break;
    }

    default:
        fail(400, '未知操作');
}
