<?php
/**
 * 自检：访问准入闸门（app/gate.php）
 * 纯桩件驱动，不连库、不联网： php tools/verify_gate.php
 */

define('APP_ROOT', dirname(__DIR__));
define('GATE_TEST', 1);

$GLOBALS['SETTING'] = array();
$GLOBALS['BANNED']  = array();
$GLOBALS['POOL_FILE'] = '';
$GLOBALS['IP'] = '203.0.113.7';

function cfg(string $p, $d = null)
{
    if ($p === 'secrets.cron_key') { return 'test-key'; }
    if ($p === 'captcha.enabled')  { return isset($GLOBALS['CAP_ON']) ? $GLOBALS['CAP_ON'] : 1; }
    return $d;
}
function setting_get(string $k, $d = '')
{
    return array_key_exists($k, $GLOBALS['SETTING']) ? $GLOBALS['SETTING'][$k] : $d;
}
function cache_get(string $k, int $ttl) { return null; }
function cache_set(string $k, array $v, int $ttl) {}
function db_all(string $sql, array $args = array()) { return $GLOBALS['BANNED']; }
function app_log(string $m) {}
function client_ip(): string { return $GLOBALS['IP']; }
function discipline_ip_hash(string $ip): string
{
    return $ip === '' ? '' : hash('sha256', 'test-key|' . $ip);
}
function captcha_enabled(): bool
{
    return (int)(isset($GLOBALS['CAP_ON']) ? $GLOBALS['CAP_ON'] : 1) === 1;
}
function captcha_client_config(): array
{
    return array(
        'on'      => captcha_enabled(),
        'sitekey' => 'test-sitekey',
        'script'  => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
        'channels' => array(
            'standard' => array('label' => 'captcha.gurl.eu.org', 'script' => 'https://a/cap.min.js', 'api' => 'https://a/api/', 'css' => ''),
            'php'      => array('label' => 'cap-pow.wuw.li', 'script' => 'https://b/cap-pow.js', 'api' => '', 'css' => 'https://b/cap-pow.css'),
        ),
    );
}

require APP_ROOT . '/app/gate.php';

$pass = 0; $fail = 0;
function chk($cond, $name)
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name\n"; }
}
function reset_state()
{
    unset($GLOBALS['__GATE_POOL'], $GLOBALS['__GATE_BANNED']);
    $GLOBALS['SETTING'] = array();
    $GLOBALS['BANNED']  = array();
    $_COOKIE = array();
    $GLOBALS['IP'] = '203.0.113.7';
    $GLOBALS['CAP_ON'] = 1;
}

$ipA = '203.0.113.7';
$ipB = '198.51.100.9';
$hA  = discipline_ip_hash($ipA);
$hB  = discipline_ip_hash($ipB);

echo "== 池为空：闸门不生效 ==\n";
reset_state();
chk(gate_required() === false, '空池时不要求验证');

echo "\n== 三种写法都能命中 ==\n";
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = $ipA;
chk(gate_required() === true, '明文 IP 命中');
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = $hA;
chk(gate_required() === true, '64 位指纹命中');
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = substr($hA, 0, 8);
chk(gate_required() === true, '8 位前缀命中');
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = substr($hA, 0, 7);
chk(gate_required() === false, '7 位十六进制不当前缀用（避免误伤）');
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = "# 注释\n\n" . $ipA . "\n";
chk(gate_required() === true, '注释与空行被忽略、其余照常命中');
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = $ipB;
chk(gate_required() === false, '池里是别的 IP 时不误伤');

echo "\n== 票：绑定指纹、会过期、签名不可篡改 ==\n";
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = $ipA;
$exp = time() + 600;
$_COOKIE[GATE_COOKIE] = gate_ticket_make($hA, $exp);
chk(gate_required() === false, '有效票放行');
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = $ipA;
$_COOKIE[GATE_COOKIE] = gate_ticket_make($hA, time() - 10);
chk(gate_required() === true, '过期票失效');
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = $ipA;          /* 本机在池里 */
$_COOKIE[GATE_COOKIE] = gate_ticket_make($hB, time() + 600);   /* 拿的是别人 IP 的票 */
chk(gate_required() === true, '别人 IP 的票在本机无效（票绑指纹）');
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = $ipA;
$bad = gate_ticket_make($hA, time() + 600);
$bad = substr($bad, 0, -1) . ($bad[strlen($bad) - 1] === 'a' ? 'b' : 'a');
$_COOKIE[GATE_COOKIE] = $bad;
chk(gate_required() === true, '签名被改一位即失效');
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = $ipA;
$_COOKIE[GATE_COOKIE] = 'not-a-ticket';
chk(gate_required() === true, '格式不对的票按无票处理');

echo "\n== 违纪封禁来源默认也进池 ==\n";
reset_state();
$GLOBALS['BANNED'] = array(array('ip_hash' => $hA));
chk(gate_required() === true, 'banned_ips 命中即要求验证');
reset_state();
$GLOBALS['BANNED'] = array(array('ip_hash' => $hA));
$GLOBALS['SETTING']['gate.include_banned'] = '0';
chk(gate_required() === false, 'include_banned=0 时不再纳入');

echo "\n== 通道关掉时闸门整体失效（不能把人挡在门外） ==\n";
reset_state();
$GLOBALS['SETTING']['gate.ip_pool'] = $ipA;
$GLOBALS['CAP_ON'] = 0;
chk(gate_required() === false, 'captcha 关闭时不要求验证');

echo "\n== 有效期钳制 ==\n";
reset_state();
$GLOBALS['SETTING']['gate.ttl'] = '10';
chk(gate_ttl() === 300, '下限 300 秒');
$GLOBALS['SETTING']['gate.ttl'] = '999999';
chk(gate_ttl() === 86400, '上限 86400 秒');
$GLOBALS['SETTING']['gate.ttl'] = '3600';
chk(gate_ttl() === 3600, '正常值原样生效');

echo "\n== 验证页 ==\n";
reset_state();
$html = gate_page_html();
chk(strpos($html, '__GATE_CFG') !== false, '页面内嵌通道配置');
chk(strpos($html, '/api/gate.php') !== false, '页面提交到 api/gate.php');
chk(strpos($html, 'turnstile.render') !== false, '主通道是 Turnstile');
chk(strpos($html, 'cap-widget') !== false, '备通道 1 是 Cap standard');
chk(strpos($html, 'CapPow') !== false, '备通道 2 是 Cap php');
chk(strpos($html, '</script>') !== false && substr_count($html, '<script') === 2, '只有两段脚本、无提前闭合');

echo "\n----------------------------------------\n";
echo "通过 $pass 项，失败 $fail 项\n";
exit($fail > 0 ? 1 : 0);
