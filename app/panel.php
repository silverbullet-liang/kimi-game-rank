<?php
/**
 * 控制面板二次认证（面板密钥授权）
 * ------------------------------------------------------------
 * 凭证不放 $_SESSION：共享主机的 session 回收默认只有 24 分钟，管理员盯着日志
 * 看一会儿再操作就会被告知「请先完成密钥验证」，而登录态是本地令牌（7 天），
 * 于是表现成「明明没退出，却要重新验证」。
 *
 * 改为落在 storage/cache 下的独立文件，规则：
 *   · 空闲 2 小时失效，绝对上限 12 小时（重新验证即重新计时）；
 *   · 每次通过校验滑动续期 —— 只要在用就不会掉；
 *   · 指纹只绑账号与浏览器家族，不绑 IP：移动网络的 IP 漂移是常态，
 *     拿 IP 当指纹只会误伤真正的管理员；而会话本身不可跨设备复制，
 *     这道指纹的作用是「同一个人的另一个浏览器不该继承授权」。
 */
if (!defined('PANEL_IDLE')) { define('PANEL_IDLE', 7200); }    // 空闲上限 2 小时
if (!defined('PANEL_MAX'))  { define('PANEL_MAX', 43200); }    // 绝对上限 12 小时

/** 浏览器家族：只取名字，不取版本（版本会随更新变化） */
function panel_ua_family(): string
{
    $ua = strtolower(isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '');
    if ($ua === '') { return 'none'; }
    /* 顺序即优先级：Edge 的 UA 里同时含 Chrome，Opera 里同时含 Chrome 与 Safari */
    foreach (array('edg' => 'edge', 'opr' => 'opera', 'firefox' => 'firefox', 'chrome' => 'chrome', 'safari' => 'safari') as $k => $v) {
        if (strpos($ua, $k) !== false) { return $v; }
    }
    return 'other';
}

/** 授权指纹：账号 + 浏览器家族（不含 IP 与版本号） */
function panel_fp(): string
{
    $uid = 0;
    $ident = function_exists('current_identity') ? current_identity() : null;
    if (is_array($ident)) { $uid = (int)(isset($ident['uid']) ? $ident['uid'] : 0); }
    $salt = (string)cfg('secrets.cron_key', 'kimgr');
    return substr(hash('sha256', $salt . '|panel|' . $uid . '|' . panel_ua_family()), 0, 32);
}

function panel_file(string $fp): string
{
    $dir = APP_ROOT . '/storage/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir . '/panel_' . preg_replace('/[^a-z0-9]/i', '', $fp) . '.json';
}

/**
 * 当前授权状态。
 * 返回 array('ok' => bool, 'reason' => 'none'|'expired'|'idle')，
 * reason 用于前端给出一句说得清的话，而不是笼统的「请重新验证」。
 */
function panel_state(): array
{
    $f = panel_file(panel_fp());
    if (!is_file($f)) { return array('ok' => false, 'reason' => 'none'); }
    $d = json_decode((string)@file_get_contents($f), true);
    if (!is_array($d)) { @unlink($f); return array('ok' => false, 'reason' => 'none'); }
    $birth = (int)(isset($d['birth']) ? $d['birth'] : 0);
    $last  = (int)(isset($d['last'])  ? $d['last']  : 0);
    if ($birth <= 0 || time() - $birth > PANEL_MAX) { @unlink($f); return array('ok' => false, 'reason' => 'expired'); }
    if ($last  <= 0 || time() - $last  > PANEL_IDLE) { @unlink($f); return array('ok' => false, 'reason' => 'idle'); }
    return array('ok' => true, 'reason' => '');
}

/** 滑动续期：任何一次通过校验的操作都把空闲计时推后 */
function panel_touch()
{
    $f = panel_file(panel_fp());
    if (!is_file($f)) { return; }
    $d = json_decode((string)@file_get_contents($f), true);
    if (!is_array($d)) { return; }
    $d['last'] = time();
    @file_put_contents($f, json_encode($d), LOCK_EX);
}

/** 验证通过（或副管理员登录）：写一张新凭证，重新计时 */
function panel_grant()
{
    $now = time();
    @file_put_contents(panel_file(panel_fp()), json_encode(array('birth' => $now, 'last' => $now)), LOCK_EX);
}

/** 主动撤销（用于验证失败、降权等） */
function panel_drop()
{
    @unlink(panel_file(panel_fp()));
}
