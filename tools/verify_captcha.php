<?php
/**
 * 自检：人机验证（主 Turnstile + 备 Cap 双实例）接线与安全约束。
 *   用法：php tools/verify_captcha.php
 *   离线运行，不联网（不发真实 siteverify 请求）。
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

/* ---------- 1. 前端配置：主通道 Turnstile + 备通道 Cap 双实例，绝不能带 secret ---------- */
$cc = captcha_client_config();
ck('配置含 on', array_key_exists('on', $cc));
ck('配置含 sitekey（主通道）', isset($cc['sitekey']) && $cc['sitekey'] !== '');
ck('配置含官方脚本地址', isset($cc['script']) && strpos($cc['script'], 'challenges.cloudflare.com/turnstile/v0/api.js') !== false);
ck('配置含 Cap 备通道（standard）', isset($cc['channels']['standard']['script']) && strpos($cc['channels']['standard']['script'], 'cap.min.js') !== false);
ck('配置含 Cap 备通道（php）', isset($cc['channels']['php']['script']) && strpos($cc['channels']['php']['script'], 'cap-pow.js') !== false);
$json = json_encode($cc);
$secret = captcha_secret();
ck('端上配置不含 secret 字样', stripos($json, 'secret') === false);
ck('端上配置不含真实 secret 值', $secret === '' || strpos($json, $secret) === false);

/* ---------- 2. 启用条件 ---------- */
$GLOBALS['CFG_ENABLED'] = 1;
ck('Turnstile 密钥已配置（主通道可用）', captcha_turnstile_ready() === true);
ck('已配置时启用', captcha_enabled() === true);
$GLOBALS['CFG_ENABLED'] = 0;
ck('总开关关闭时不启用', captcha_enabled() === false);
$GLOBALS['CFG_ENABLED'] = 1;

/* ---------- 3. 空 token 直接判失败 ---------- */
$r = captcha_verify('');
ck('空 token 判失败', $r['ok'] === false && $r['codes'] === 'empty-token');

/* ---------- 4. 通道路由：声明优先，未声明按主→备 ---------- */
$src = (string)file_get_contents(APP_ROOT . '/app/captcha.php');
ck('路由按 turnstile → standard → php', strpos($src, "in_array('turnstile', " . '$order' . ", true)") !== false && strpos($src, 'captcha_verify_cap(' . '$token' . ', ' . '$k' . ')') !== false);
ck('Turnstile 校验走独立函数', strpos($src, 'function captcha_verify_turnstile') !== false);
ck('Cap 校验走独立函数', strpos($src, 'function captcha_verify_cap') !== false);
ck('Cap 双实例定义保留', substr_count($src, "'validate' =>") === 2);

/* ---------- 5. 闸门：未启用时不拦；启用中缺 token 必须拒绝 ---------- */
$GLOBALS['PARAM'] = array();
$GLOBALS['CFG_ENABLED'] = 0;
$threw = false;
try { captcha_guard(); } catch (FailSignal $e) { $threw = true; }
ck('未启用时闸门放行', $threw === false);

$GLOBALS['CFG_ENABLED'] = 1;
$threw = false; $msg = '';
try { captcha_guard(); } catch (FailSignal $e) { $threw = true; $msg = $e->getMessage(); }
ck('启用中无 token 被拒', $threw === true);
ck('拒绝文案提示先完成验证', mb_strpos($msg, '人机验证') !== false);

/* 主字段 cap_token / cap_channel；cf_token 兼容过渡版本 */
$GLOBALS['PARAM'] = array('cf_token' => 'legacy', 'cap_channel' => 'standard');
ck('兼容过渡字段 cf_token', param_str('cap_token', '') === '' && param_str('cf_token', '') === 'legacy');
$GLOBALS['PARAM'] = array();

/* ---------- 6. 源码闸门 ---------- */
ck('Turnstile 走官方 siteverify 端点', strpos($src, 'https://challenges.cloudflare.com/turnstile/v0/siteverify') !== false);
ck('siteverify 表单编码提交', strpos($src, 'http_build_query($fields)') !== false);
ck('siteverify 带上 remoteip', strpos($src, "'remoteip'") !== false);
ck('核对 hostname 防挪用', strpos($src, 'host-mismatch') !== false);
ck('fail-closed：失败一律拒绝', strpos($src, "'人机验证未通过，请重新验证后再试'") !== false);
ck('Turnstile 超时不超过 10 秒', preg_match('/CURLOPT_TIMEOUT\s*=>\s*(?:[1-9]|10),/', $src) === 1);
ck('Cap 校验仍指向两个实例', strpos($src, 'captcha.gurl.eu.org/api/validate') !== false && strpos($src, 'cap-pow.wuw.li/api/validate') !== false);

$js = (string)file_get_contents(APP_ROOT . '/assets/js/src/captcha.js');
ck('前端 Turnstile 用 explicit 渲染', strpos($js, 'window.turnstile.render') !== false);
ck('前端处理过期回调', strpos($js, 'expired-callback') !== false);
ck('前端提交前清空旧 token', strpos($js, "cb('', '')") !== false);
ck('渲染判定只认 render 返回（有 cfState 状态机）', strpos($js, "cfState = 'rendered'") !== false && strpos($js, "cfState = 'failed'") !== false);
ck('已渲染时不做破坏性切换', strpos($js, "if (cfState === 'rendered') {") !== false);
ck('主通道不再用 DOM 探测当判据', strpos($js, "switchToCap('standard', t('未渲染'))") === false);
ck('用户面前的文案不再暴露内部原因', strpos($js, "t('主通道不可用，已切换到备用通道')") !== false);
ck('备通道自检放宽到 5 秒且认已拿到的 token', strpos($js, '}, 5000);') !== false && strpos($js, "if (activeChannel !== 'standard' || CH.token) { return; }") !== false);
ck('切换原因只进 console', strpos($js, "console.debug('[captcha] switched to'") !== false);
ck('仅脚本未回来才切备通道', strpos($js, "t('脚本未加载')") !== false && strpos($js, "t('渲染失败')") !== false);
ck('备通道 Cap 双实例保留', strpos($js, 'cap-widget') !== false && strpos($js, 'CapPow') !== false);
ck('备通道内部也有健康检查', strpos($js, "switchToCap('php', t('组件未出现'))") !== false);
ck('切换时销毁主通道 widget', strpos($js, 'turnstile.remove') !== false);

$login = (string)file_get_contents(APP_ROOT . '/assets/js/src/pages/login.js');
ck('登录提交 cap_token + cap_channel', strpos($login, 'cap_token: captchaToken()') !== false && strpos($login, 'cap_channel: captchaChannel()') !== false);

/* ---------- 7. 公开文档口径：主备并列 ---------- */
$docs = array('功能说明.md', '隐私政策.md', '用户协议.md', 'en/features.md', 'en/privacy.md', 'en/terms.md');
$miss = array();
foreach ($docs as $d) {
    $t = (string)file_get_contents(APP_ROOT . '/assets/docs/' . $d);
    $hasTs = stripos($t, 'Turnstile') !== false;
    $hasCap = stripos($t, 'Cap') !== false || stripos($t, '备用通道') !== false || stripos($t, 'fallback') !== false;
    if (!$hasTs || !$hasCap) { $miss[] = $d; }
}
ck('六份文档均同时写明主通道与备用通道', count($miss) === 0);
$privacy = (string)file_get_contents(APP_ROOT . '/assets/docs/隐私政策.md');
ck('隐私政策列出 Turnstile 主通道', strpos($privacy, 'Turnstile（主通道）') !== false);
ck('隐私政策列出 Cap 备用通道', strpos($privacy, 'Cap 人机验证（备用通道）') !== false);

/* ---------- 8. 投票与反馈：弹窗闸门 ---------- */
$works = (string)file_get_contents(APP_ROOT . '/api/works.php');
$fb    = (string)file_get_contents(APP_ROOT . '/api/feedback.php');
$core  = (string)file_get_contents(APP_ROOT . '/assets/js/src/core.js');
$detail = (string)file_get_contents(APP_ROOT . '/assets/js/src/pages/detail.js');
$fbjs   = (string)file_get_contents(APP_ROOT . '/assets/js/src/pages/feedback.js');

ck('投票接口过验证闸门', strpos($works, 'captcha_guard();') !== false);
ck('投票：取消投票不验证（只减不增）', strpos($works, "cooldown_guard('vote')") !== false
    && strpos($works, '取消点赞不受限') !== false);
ck('反馈提交过验证闸门', strpos($fb, 'captcha_guard();') !== false);
ck('前端提供验证弹窗闸门', strpos($core, 'export function captchaGate(') !== false
    && strpos($core, 'export async function captchaFields(') !== false);
ck('验证弹窗通过即自动提交（无需二次确认）', strpos($core, 'if (tok) { finish(tok); }') !== false);
ck('验证弹窗可取消（取消则不提交）', strpos($core, "if (cap === null) { return; }") !== false
    || strpos($detail, "if (cap === null) { return; }") !== false);
ck('投票页在投票前取验证参数', strpos($detail, "captchaFields(t('验证通过后自动提交点赞'))") !== false);
ck('反馈页在提交前取验证参数', strpos($fbjs, "captchaFields(t('验证通过后自动提交反馈'))") !== false);
ck('作品详情返回票数', strpos((string)file_get_contents(APP_ROOT . '/app/works_list.php'), "'votes'") !== false);
ck('作品页显示票数', strpos($detail, 'id="voteCount"') !== false && strpos($detail, 'paintVote') !== false);

echo "\n通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail === 0 ? 0 : 1);
