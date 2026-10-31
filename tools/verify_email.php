<?php
/**
 * 邮箱子系统 + 分类判定自检 —— 离线，桩件驱动，不联网、不连库。
 * 跑法：php tools/verify_email.php
 *
 * 覆盖：邮箱格式/归一/脱敏、验证码有效期与哈希、验证码错误/过期/次数上限、
 *       绑定唯一性、三种登录标识解析、Jev 四分类的输入整形与来源标记。
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

/* ---------- 桩件 ---------- */
$GLOBALS['CFG'] = array(
    'site.name' => 'kimi游戏榜',
    'secrets.cron_key' => 'test-cron-key',
    'mail.code_ttl' => 120,
    'feed.auto_cat' => 1,
    'scoring.cat_min_conf' => 0.40,
);
function cfg($k, $d = null) { return array_key_exists($k, $GLOBALS['CFG']) ? $GLOBALS['CFG'][$k] : $d; }
function app_log($m) { }
function now_utc() { return gmdate('Y-m-d H:i:s'); }
function ip_hash($ip) { return hash('sha256', 'salt|' . $ip); }
function client_ip() { return '127.0.0.1'; }
function norm_username($u) { return strtolower(preg_replace('/[\s\x{200B}-\x{200D}]+/u', '', (string)$u)); }

$GLOBALS['SQL'] = array();
$GLOBALS['ONE_USER'] = null;      // users 表查询的返回值
$GLOBALS['ONE_CODE'] = null;      // email_codes 表查询的返回值
function db_one($sql, $args = array())
{
    $GLOBALS['SQL'][] = array('one', $sql, $args);
    if (strpos($sql, 'FROM users') !== false) { return $GLOBALS['ONE_USER']; }
    if (strpos($sql, 'FROM email_codes') !== false) { return $GLOBALS['ONE_CODE']; }
    return null;
}
function db_all($sql, $args = array()) { $GLOBALS['SQL'][] = array('all', $sql, $args); return isset($GLOBALS['ALL']) ? $GLOBALS['ALL'] : array(); }
function db_exec($sql, $args = array()) { $GLOBALS['SQL'][] = array('exec', $sql, $args); return 1; }
function db_insert($sql, $args = array()) { $GLOBALS['SQL'][] = array('ins', $sql, $args); return 1; }
function col_ok($t, $c) { return true; }
function table_exists($t) { return true; }
function cache_get($k) { return isset($GLOBALS['CACHE'][$k]) ? $GLOBALS['CACHE'][$k] : null; }
function cache_set($k, $v, $t = 0) { $GLOBALS['CACHE'][$k] = $v; }
function rate_limit($k, $n, $w) { return !isset($GLOBALS['RL'][$k]) || $GLOBALS['RL'][$k]; }
function net_channel_cooling($n) { return false; }
function net_channel_cool($n, $s) { }
function setting_get($k, $d = '') { return isset($GLOBALS['SET'][$k]) ? $GLOBALS['SET'][$k] : $d; }
function setting_set($k, $v) { $GLOBALS['SET'][$k] = $v; }
function works_cats() { return array('game' => '游戏类', 'tool' => '工具类', 'literature' => '文学类', 'fanart' => '二创类'); }

class FailEx extends Exception { public $code; public function __construct($m, $c) { parent::__construct($m); $this->code = $c; } }
function fail($code, $msg) { throw new FailEx($msg, $code); }
function ok($d = null, $m = '') { return array('ok' => true, 'data' => $d); }

/* 发信：真实走 mail_send，只把最底层的 HTTP 换掉（不联网）。
   quiet-mail 的两个端点按真实返回结构模拟，其余地址一律 500，用来测失败回滚。 */
$GLOBALS['MAILED'] = array();
$GLOBALS['MAIL_FAIL'] = false;
$GLOBALS['MAIL_HTTP_STUB'] = function ($url, $method, $headers, $body, $timeout) {
    if (strpos($url, '/agents/') !== false && substr($url, -5) === '/send') {
        if ($GLOBALS['MAIL_FAIL']) { return array(500, '{}'); }
        $j = json_decode($body, true);
        $GLOBALS['MAILED'][] = array('to' => isset($j['to']) ? $j['to'] : '', 'subject' => isset($j['subject']) ? $j['subject'] : '',
            'text' => isset($j['text']) ? $j['text'] : '', 'html' => isset($j['html']) ? $j['html'] : '');
        return array(200, '{"ok":true}');
    }
    if (substr($url, -7) === '/agents' && $method === 'POST') {
        return array(200, '{"agent":{"id":"kimgr-test","email":"kimgr-test@quiet-mail.com"},"apiKey":"qmail_test"}');
    }
    return array(500, '{}');
};
function jev_labels_classify($text, $labels, $channel = 'jev') {
    $GLOBALS['JEV_IN'] = $text;
    return isset($GLOBALS['JEV_RET']) ? $GLOBALS['JEV_RET'] : array('ok' => false, 'reason' => 'stub');
}

require_once APP_ROOT . '/app/email.php';
require_once APP_ROOT . '/app/email_code.php';
require_once APP_ROOT . '/app/works_cat.php';

$pass = 0; $fail = 0;
function ck(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "OK   $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
function last_sql(string $kind) {
    for ($i = count($GLOBALS['SQL']) - 1; $i >= 0; $i--) {
        if ($GLOBALS['SQL'][$i][0] === $kind) { return $GLOBALS['SQL'][$i]; }
    }
    return null;
}

/* ---------- 一、地址格式 ---------- */
foreach (array('a@b.co', 'user.name+tag@sub.example.com', 'x_y-z@a-b.cn') as $v) {
    ck('合法邮箱：' . $v, mail_valid($v));
}
foreach (array('', 'a@b', 'a b@c.com', "a@b.com\r\nBcc: x@y.com", '@b.com', 'a@.com', 'a@b..com', 'a@b.c', str_repeat('a', 190) . '@b.com') as $v) {
    ck('拒绝邮箱：' . str_replace(array("\r", "\n"), '⏎', mb_substr($v, 0, 24)), !mail_valid($v));
}
ck('归一：大小写与空白', mail_norm("  User@Example.COM ") === 'user@example.com');
ck('脱敏：ab***@x.com', mail_mask('abcd@x.com') === 'ab***@x.com');
ck('脱敏：短名 a***@x.com', mail_mask('a@x.com') === 'a***@x.com');
ck('脱敏：无 @ 原样返回', mail_mask('nobody') === 'nobody');
ck('头字段净化：剥掉换行', mail_hdr("标题\r\nBcc: x@y.com") === '标题 Bcc: x@y.com');

/* ---------- 二、通道与验证码基础 ---------- */
ck('默认通道顺序含 quietmail 且不含未配置的 api 通道',
    in_array('quietmail', mail_channels(), true) && !in_array('resend', mail_channels(), true));
ck('验证码 6 位', email_code_len() === 6);
ck('验证码有效期 120 秒（两分钟）', email_code_ttl() === 120);
ck('有效期越界会被夹回 60..900', (function () {
    $GLOBALS['CFG']['mail.code_ttl'] = 5;  $a = email_code_ttl();
    $GLOBALS['CFG']['mail.code_ttl'] = 9999; $b = email_code_ttl();
    $GLOBALS['CFG']['mail.code_ttl'] = 120;
    return $a === 60 && $b === 900;
})());
ck('同一码同一邮箱哈希稳定', email_code_hash('123456', 'a@b.com') === email_code_hash('123456', 'a@b.com'));
ck('不同邮箱哈希不同', email_code_hash('123456', 'a@b.com') !== email_code_hash('123456', 'c@b.com'));
ck('不同码哈希不同', email_code_hash('123456', 'a@b.com') !== email_code_hash('654321', 'a@b.com'));
ck('哈希不落明文（长度 64 且不含原码）', (function () {
    $h = email_code_hash('987654', 'a@b.com');
    return strlen($h) === 64 && strpos($h, '987654') === false;
})());

/* ---------- 三、发码 ---------- */
$GLOBALS['MAILED'] = array(); $GLOBALS['SQL'] = array();
$GLOBALS['ONE_USER'] = null; $GLOBALS['ONE_CODE'] = null;   // 无历史码、邮箱未被占用
$r = email_code_send('User@Example.com', 'bind', 7, 'zh');
ck('发码成功返回 ttl', !empty($r['ok']) && (int)$r['ttl'] === 120);
ck('记录一次发信', count($GLOBALS['MAILED']) === 1 && $GLOBALS['MAILED'][0]['to'] === 'User@Example.com');
ck('邮件正文含 6 位验证码', (bool)preg_match('/\b\d{6}\b/', $GLOBALS['MAILED'][0]['text']));
ck('邮件正文含有效期说明', strpos($GLOBALS['MAILED'][0]['text'], '分钟') !== false || strpos($GLOBALS['MAILED'][0]['text'], 'minute') !== false);
$ins = last_sql('ins');
ck('落库按归一邮箱、用途、哈希、uid 写入',
    $ins !== null && $ins[2][0] === 'user@example.com' && $ins[2][1] === 'bind' && $ins[2][3] === 7
    && strlen($ins[2][2]) === 64);
$del = last_sql('exec');
ck('发码前清掉同邮箱同用途的旧码', $del !== null && strpos($del[1], 'DELETE FROM email_codes') !== false);

/* 60 秒内重复请求被拒 */
$GLOBALS['ONE_USER'] = null; $GLOBALS['ONE_CODE'] = array('created_at' => gmdate('Y-m-d H:i:s', time() - 10));
try { email_code_send('user@example.com', 'bind', 7, 'zh'); ck('60 秒内重复发码被拒', false); }
catch (FailEx $e) { ck('60 秒内重复发码被拒（429）', (int)$e->code === 429); }

/* 绑定到别人已占用的邮箱 → 409 */
$GLOBALS['ONE_USER'] = array('id' => 99);
try { email_code_send('taken@example.com', 'bind', 7, 'zh'); ck('邮箱已被占用时报 409', false); }
catch (FailEx $e) { ck('邮箱已被占用时报 409', (int)$e->code === 409); }

/* 绑自己的邮箱（owner 就是自己）→ 允许 */
$GLOBALS['ONE_USER'] = array('id' => 7); $GLOBALS['ONE_CODE'] = null;
$GLOBALS['MAILED'] = array();
try { email_code_send('mine@example.com', 'bind', 7, 'zh'); ck('重发到自己的邮箱允许', count($GLOBALS['MAILED']) === 1); }
catch (FailEx $e) { ck('重发到自己的邮箱允许', false); }

/* 登录用途：邮箱没绑过账号 → 不发信但返回成功 */
$GLOBALS['MAILED'] = array(); $GLOBALS['ONE_USER'] = null; $GLOBALS['ONE_CODE'] = null;
$r = email_code_send('nobody@example.com', 'login', 0, 'zh');
ck('登录发码：账号不存在时不发信', count($GLOBALS['MAILED']) === 0 && !empty($r['ok']));
ck('登录发码：仍返回 ttl（不暴露账号是否存在）', (int)$r['ttl'] === 120);

/* 发信失败 → 清掉刚写的码并报 502 */
$GLOBALS['MAIL_FAIL'] = true; $GLOBALS['SQL'] = array(); $GLOBALS['ONE_USER'] = array('id' => 7); $GLOBALS['ONE_CODE'] = null;
try { email_code_send('mine2@example.com', 'bind', 7, 'zh'); ck('发信失败报 502', false); }
catch (FailEx $e) {
    $had = false;
    foreach ($GLOBALS['SQL'] as $q) { if ($q[0] === 'exec' && strpos($q[1], 'DELETE FROM email_codes') !== false) { $had = true; } }
    ck('发信失败报 502 且回滚已写入的码', (int)$e->code === 502 && $had);
}
$GLOBALS['MAIL_FAIL'] = false;

/* ---------- 四、校验 ---------- */
function code_row(string $code, string $email, string $purpose = 'bind', int $tries = 0, int $ttl = 120) {
    return array(
        'id' => 5, 'email_norm' => $email, 'purpose' => $purpose,
        'code_hash' => email_code_hash($code, $email), 'tries' => $tries,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
    );
}
$GLOBALS['ONE_CODE'] = code_row('123456', 'a@b.com');
ck('正确验证码通过', !empty(email_code_check('a@b.com', 'bind', '123456')['ok']));
ck('大小写与空格不影响邮箱比对', !empty(email_code_check(' A@B.com ', 'bind', '123456')['ok']));
ck('验证码中的分隔符被忽略', !empty(email_code_check('a@b.com', 'bind', '12-34 56')['ok']));

$GLOBALS['ONE_CODE'] = code_row('123456', 'a@b.com');
$r = email_code_check('a@b.com', 'bind', '000000');
ck('错误验证码不通过且 reason=wrong_code', empty($r['ok']) && $r['reason'] === 'wrong_code');
$up = last_sql('exec');
ck('错误一次即累加 tries', $up !== null && strpos($up[1], 'tries = tries + 1') !== false);

$GLOBALS['SQL'] = array();
$GLOBALS['ONE_CODE'] = code_row('123456', 'a@b.com', 'bind', 0, -10);
$r = email_code_check('a@b.com', 'bind', '123456');
ck('过期验证码不通过且 reason=expired', empty($r['ok']) && $r['reason'] === 'expired');
ck('过期即删除记录', (function () {
    $d = last_sql('exec');
    return $d !== null && strpos($d[1], 'DELETE FROM email_codes') !== false;
})());

$GLOBALS['ONE_CODE'] = code_row('123456', 'a@b.com', 'bind', 5);
$r = email_code_check('a@b.com', 'bind', '123456');
ck('错满 5 次后正确码也不通过', empty($r['ok']) && $r['reason'] === 'too_many_tries');

$GLOBALS['ONE_CODE'] = null;
ck('没有验证码时 reason=no_code', email_code_check('a@b.com', 'bind', '123456')['reason'] === 'no_code');
ck('码长不对直接拒（bad_code）', email_code_check('a@b.com', 'bind', '123')['reason'] === 'bad_code');

/* ---------- 五、绑定唯一性 ---------- */
$GLOBALS['ONE_USER'] = array('id' => 99, 'username' => 'someone');
try { email_bind_apply(7, 'taken@example.com'); ck('绑定他人邮箱被拒', false); }
catch (FailEx $e) { ck('绑定他人邮箱被拒（409）', (int)$e->code === 409); }

$GLOBALS['ONE_USER'] = array('id' => 7, 'email' => 'old@example.com');
$GLOBALS['SQL'] = array();
$r = email_bind_apply(7, 'New@Example.com');
$up = last_sql('exec');
ck('换绑：写归一邮箱与验证时间',
    $up !== null && strpos($up[1], 'email_norm = ?') !== false && $up[2][1] === 'new@example.com' && $up[2][2] === 7);
ck('换绑返回旧邮箱脱敏值', isset($r['old']) && $r['old'] === 'ol***@example.com');
ck('换绑返回新邮箱脱敏值', $r['masked'] === 'Ne***@Example.com');

/* ---------- 六、三种登录标识 ---------- */
function id_sql(string $id) {
    $GLOBALS['SQL'] = array(); $GLOBALS['ONE_USER'] = null; $GLOBALS['ONE_CODE'] = null;
    user_row_by_identifier($id);
    return last_sql('one');
}
$q = id_sql('a@b.com');
ck('邮箱走 email_norm 查询', $q !== null && strpos($q[1], 'email_norm') !== false && $q[2][0] === 'a@b.com');
$q = id_sql('Alice');
ck('用户名走 username_norm 查询', $q !== null && strpos($q[1], 'username_norm') !== false && $q[2][0] === 'alice');
$GLOBALS['SQL'] = array(); $GLOBALS['ONE_USER'] = null; $GLOBALS['ONE_CODE'] = null;
user_row_by_identifier('Ab3x9Kp2');           // 用户名查不到（桩返回 null）→ 回落 uid8
$qs = $GLOBALS['SQL'];
$seenUid = false;
foreach ($qs as $x) { if (strpos($x[1], 'uid8') !== false && $x[2][0] === 'Ab3x9Kp2') { $seenUid = true; } }
ck('用户名查不到时回落 8 位 UID', $seenUid);
ck('超长标识直接拒绝（不查库）', (function () {
    $GLOBALS['SQL'] = array();
    user_row_by_identifier(str_repeat('a', 200));
    return count($GLOBALS['SQL']) === 0;
})());

/* ---------- 七、Jev 四分类 ---------- */
ck('四分类标签齐全且互不相同', count(works_cat_labels()) === 4 && count(array_unique(works_cat_labels())) === 4);
ck('分类 key 与 works_cats 完全对齐', array_keys(works_cat_labels()) === array_keys(works_cats()));
ck('简介去标签与实体', works_cat_plain('<p>一段<b>正文</b> &amp; 说明</p>') === '一段正文 & 说明');
ck('简介去脚本内容', strpos(works_cat_plain('<script>alert(1)</script>正文'), 'alert') === false);
ck('超长简介截到 600 字', mb_strlen(works_cat_plain(str_repeat('字', 900)), 'UTF-8') === 600);
ck('送审文本含标题与简介两行',
    works_cat_input('标题甲', '简介乙') === "标题：标题甲\n简介：简介乙");
ck('空标题空简介返回空串', works_cat_input('', '') === '');
$GLOBALS['JEV_RET'] = array('ok' => true, 'label' => works_cat_labels()['tool'], 'conf' => 0, 'scores' => array());
$r = works_cat_classify('番茄钟', '25 分钟专注计时器');
ck('Jev 判为工具类', $r['cat'] === 'tool' && $r['via'] === 'jev');
ck('送审文本包含标题与简介', strpos((string)$GLOBALS['JEV_IN'], '番茄钟') !== false && strpos((string)$GLOBALS['JEV_IN'], '计时器') !== false);
$GLOBALS['JEV_RET'] = array('ok' => true, 'label' => works_cat_labels()['fanart'], 'conf' => 0, 'scores' => array());
ck('Jev 判为二创类', works_cat_classify('原神同人', '画了十二张')['cat'] === 'fanart');
$GLOBALS['JEV_RET'] = array('ok' => false, 'reason' => 'jev_unavailable:0');
$r = works_cat_classify('随便', '随便');
ck('服务不可用时不改判（cat 为空、via=offline）', $r['cat'] === '' && $r['via'] === 'offline');
$GLOBALS['JEV_RET'] = null;
ck('接口给了合法分类就沿用（src=api）', works_cat_for_new_pair('tool', '标题', '简介') === array('tool', 'api'));
ck('接口给了非法分类则判定（未知值不直传）', works_cat_for_new_pair('unknown', '标题', '简介')[0] === 'game');
$GLOBALS['CFG']['feed.auto_cat'] = 0;
ck('关闭自动判定时回落 game 且来源为空', works_cat_for_new_pair('', '标题', '简介') === array('game', ''));
$GLOBALS['CFG']['feed.auto_cat'] = 1;

/* ---------- 八、双重验证票据 ---------- */
ck('票据摘要与码摘要不同（不会互相冒充）',
    email_code_ticket_hash('abc') !== email_code_hash('123456', 'abc'));
ck('同一票据摘要稳定', email_code_ticket_hash('t1') === email_code_ticket_hash('t1'));
ck('不同票据摘要不同', email_code_ticket_hash('t1') !== email_code_ticket_hash('t2'));

$GLOBALS['MAILED'] = array(); $GLOBALS['SQL'] = array();
$GLOBALS['ONE_CODE'] = null;
try {
    $tk = email_code_issue_ticket(7, 'a@b.com', 'zh');
    ck('票据 32 位十六进制', (bool)preg_match('/^[0-9a-f]{32}$/', $tk['ticket']));
    ck('票据下发 ttl 与脱敏邮箱', (int)$tk['ttl'] === 120 && $tk['masked'] === 'a***@b.com');
    ck('双重验证码邮件已发出且含 6 位码',
        count($GLOBALS['MAILED']) === 1 && (bool)preg_match('/\b\d{6}\b/', $GLOBALS['MAILED'][0]['text']));
    $ins = last_sql('ins');
    ck('同时写入码摘要与票据摘要（两者都不为 NULL）',
        $ins !== null && strlen($ins[2][2]) === 64 && strlen($ins[2][3]) === 64 && $ins[2][1] === '2fa');
} catch (FailEx $e) {
    ck('双重验证票据签发', false);
}

$GLOBALS['ONE_CODE'] = null;
ck('没有票据时 reason=no_ticket', email_code_check_ticket('deadbeef', '123456')['reason'] === 'no_ticket');

$tkRow = array('id' => 9, 'email_norm' => 'a@b.com', 'purpose' => '2fa',
    'code_hash' => email_code_hash('112233', 'a@b.com'),
    'ticket_hash' => email_code_ticket_hash('ffff'),
    'tries' => 0, 'user_id' => 7,
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 120));
$GLOBALS['ONE_CODE'] = $tkRow;
ck('票据 + 正确码通过', !empty(email_code_check_ticket('ffff', '112233')['ok']));
$GLOBALS['ONE_CODE'] = $tkRow;
ck('票据 + 错误码不通过', email_code_check_ticket('ffff', '000000')['reason'] === 'wrong_code');
$GLOBALS['ONE_CODE'] = array_merge($tkRow, array('expires_at' => gmdate('Y-m-d H:i:s', time() - 5)));
ck('过期票据被拒', email_code_check_ticket('ffff', '112233')['reason'] === 'expired');
$GLOBALS['ONE_CODE'] = array_merge($tkRow, array('tries' => 5));
ck('错满 5 次的票据被拒', email_code_check_ticket('ffff', '112233')['reason'] === 'too_many_tries');
$GLOBALS['ONE_CODE'] = $tkRow;
ck('码长不足直接拒', email_code_check_ticket('ffff', '11')['reason'] === 'bad_code');

/* 源码闸门：这几处改动必须真的落在代码里 */
$authSrc = (string)file_get_contents(APP_ROOT . '/app/auth.php');
ck('登录收尾接入双重验证（login_finish 检查 user_twofa_enabled）',
    strpos($authSrc, 'user_twofa_enabled($u)') !== false && strpos($authSrc, "purpose = '2fa'") === false);
ck('注册支持可选邮箱（user_register 带 email 与 code）',
    strpos($authSrc, 'function user_register(string $username, string $password, string $email') !== false);
/* 账号接口不能用角色白名单把管理员挡在外面（admin token 的 uid 是 0，
   必须用 actor_uid() 映射到 users 里那一行）—— 曾因此让站长绑邮箱时收到「请登录」。 */
$acctApi = (string)file_get_contents(APP_ROOT . '/api/account.php');
ck('账号接口用 actor_uid 映射身份（不再按角色白名单拦 admin）',
    strpos($acctApi, 'actor_uid($id)') !== false && strpos($acctApi, "!== 'subadmin'") === false);
ck('账号接口只拦游客', strpos($acctApi, "=== 'guest'") !== false);
$auth2 = (string)file_get_contents(APP_ROOT . '/app/auth.php');
ck('管理员登录也走 login_finish（双重验证对管理员生效）',
    strpos($auth2, "if ((string)\$u['role'] === 'admin')") !== false
    && strpos($auth2, "\$aRow['role'] = 'admin';") !== false);
ck('账号接口提供解绑邮箱（且不需要验证码）',
    strpos($acctApi, "case 'email_unbind'") !== false
    && strpos(substr($acctApi, (int)strpos($acctApi, "case 'email_unbind'"), 1200), 'email_code_check') === false);
ck('解绑会一并关闭双重验证（避免没有第二因子还留着开关）',
    strpos($acctApi, "\$st['twofa'] = 0;") !== false);
/* 前端闸门：解绑按钮的监听必须落在 bindEmailDialog 里。
   曾经因为用「同款片段」做 replace，把这段监听插进了 twofaDialog ——
   于是解绑按钮渲染出来了却没有监听，点下去毫无反应。 */
$mineJs = (string)file_get_contents(APP_ROOT . '/assets/js/src/pages/mine.js');
$bdPos  = strpos($mineJs, 'function bindEmailDialog');
$bdBody = $bdPos === false ? '' : substr($mineJs, $bdPos, 5000);
ck('解绑监听挂在 bindEmailDialog 内（而非别的弹窗）',
    strpos($bdBody, 'email_unbind') !== false && strpos($bdBody, 'data-r="2"') !== false);
$tfPos  = strpos($mineJs, 'function twofaDialog');
$tfBody = $tfPos === false ? '' : substr($mineJs, $tfPos, (int)$bdPos - (int)$tfPos);
ck('双重验证弹窗里没有误插的 unBtn 代码', strpos($tfBody, 'unBtn') === false);
$acctSrc = (string)file_get_contents(APP_ROOT . '/app/email_code.php');
ck('票据校验按摘要等值查询（不扫表）',
    strpos($acctSrc, "WHERE purpose = '2fa' AND ticket_hash = ?") !== false);

echo "----------------------------------------------------\n";
echo '通过 ' . $pass . ' / 失败 ' . $fail . "\n";
exit($fail > 0 ? 1 : 0);
