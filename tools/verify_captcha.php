<?php
/**
 * 自检：人机验证只有 Cloudflare Turnstile 一条路，没有备用通道、没有自动切换。
 *   用法：php tools/verify_captcha.php
 *   离线运行，不联网。
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$pass = 0; $fail = 0;
function ck($name, $ok) { global $pass, $fail; if ($ok) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name\n"; } }

/* ---------- 桩件 ---------- */
function cfg($k, $d = null) {
    if ($k === 'captcha.enabled') { return isset($GLOBALS['CFG_ENABLED']) ? $GLOBALS['CFG_ENABLED'] : 1; }
    return $d;
}
function app_log($m) { $GLOBALS['LOGS'][] = $m; }
function client_ip(): string { return '203.0.113.7'; }
function param_str($k, $d = '') { return isset($GLOBALS['PARAM'][$k]) ? (string)$GLOBALS['PARAM'][$k] : $d; }
class FailSignal extends Exception {}
function fail($code, $msg) { throw new FailSignal($msg); }

require APP_ROOT . '/app/captcha.php';

/* ---------- 1. 端上配置 ---------- */
$cc = captcha_client_config();
ck('配置含 on', array_key_exists('on', $cc));
ck('配置含 sitekey', isset($cc['sitekey']) && $cc['sitekey'] !== '');
ck('配置含官方脚本地址', isset($cc['script']) && strpos($cc['script'], 'challenges.cloudflare.com/turnstile/v0/api.js') !== false);
ck('配置不含备用通道', !isset($cc['channels']));
$json = json_encode($cc);
$secret = captcha_secret();
ck('端上配置不含 secret 字样', stripos($json, 'secret') === false);
ck('端上配置不含真实 secret 值', $secret === '' || strpos($json, $secret) === false);

/* ---------- 2. 启用条件 ---------- */
$GLOBALS['CFG_ENABLED'] = 1;
ck('Turnstile 密钥已配置', captcha_turnstile_ready() === true);
ck('已配置时启用', captcha_enabled() === true);
$GLOBALS['CFG_ENABLED'] = 0;
ck('总开关关闭时不启用', captcha_enabled() === false);
$GLOBALS['CFG_ENABLED'] = 1;

/* ---------- 3. 空 token ---------- */
$r = captcha_verify('');
ck('空 token 判失败', $r['ok'] === false && $r['codes'] === 'empty-token');

/* ---------- 4. 源码级：备通道彻底不存在 ---------- */
$src = (string)file_get_contents(APP_ROOT . '/app/captcha.php');
ck('没有 captcha_channels()', strpos($src, 'function captcha_channels') === false);
ck('没有 captcha_verify_cap()', strpos($src, 'function captcha_verify_cap') === false);
ck('没有降级判定 transport_failed', strpos($src, 'function captcha_transport_failed') === false);
ck('没有 gurl 备通道域名', strpos($src, 'gurl.eu.org') === false);
ck('没有 cap-pow 备通道域名', strpos($src, 'cap-pow') === false);
ck('没有通道遍历 $order', strpos($src, '$order') === false);
ck('Turnstile 校验走独立函数', strpos($src, 'function captcha_verify_turnstile') !== false);
ck('拒绝即 fail-closed', strpos($src, "return array('ok' => false, 'via' => 'turnstile'") !== false);

/* ---------- 5. 前端源码级：没有切换逻辑 ---------- */
$js = (string)file_get_contents(APP_ROOT . '/assets/js/src/captcha.js');
ck('前端没有 switchToCap', strpos($js, 'switchToCap') === false);
ck('前端没有 cap-pow.js', strpos($js, 'cap-pow') === false);
ck('前端没有 cap.min.js', strpos($js, 'cap.min.js') === false);
ck('前端没有切换提示文案', strpos($js, '已切换到备用通道') === false && strpos($js, '备用通道加载中') === false);
ck('前端没有 cap-widget（Cap 组件）', strpos($js, '<cap-widget') === false);
ck('前端只渲染 Turnstile', strpos($js, 'window.turnstile.render') !== false);

/* ---------- 6. 闸门 ---------- */
$GLOBALS['PARAM'] = array('cap_token' => '');
$thrown = false;
try { captcha_guard(); } catch (FailSignal $e) { $thrown = true; }
ck('缺 token 时拒绝', $thrown === true);

$GLOBALS['PARAM'] = array('cap_token' => 'x');
$GLOBALS['LOGS'] = array();
$GLOBALS['CFG_ENABLED'] = 0;
$thrown = false;
try { captcha_guard(); } catch (FailSignal $e) { $thrown = true; }
ck('总开关关闭时放行', $thrown === false);
$GLOBALS['CFG_ENABLED'] = 1;

echo "\n结果：通过 {$pass}，失败 {$fail}\n";
exit($fail === 0 ? 0 : 1);
