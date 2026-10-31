<?php
/**
 * AI 模型降级自检 —— 离线，桩件驱动，不联网。
 * 跑法：php tools/verify_ai_fallback.php
 *
 * 背景（2026-10-31 实测）：本站智谱 key 池只有 glm-4-flash 有额度，
 * glm-4.5-air / glm-4-air / glm-4-plus / glm-4.5 / glm-4.6 一律 429 + code 1113。
 * 思考模型或配置写错时若不降级，整条个人对话就只剩「AI 接口 HTTP 429」。
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
$GLOBALS['C'] = array('ai_limits.fallback_model' => 'glm-4-flash');
function cfg($k, $d = null) { return array_key_exists($k, $GLOBALS['C']) ? $GLOBALS['C'][$k] : $d; }
function app_log($m) { }
require_once APP_ROOT . '/app/zhipu.php';

$pass = 0; $fail = 0;
function ck(string $n, bool $c): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? 'OK   ' : 'FAIL ') . $n . "\n"; }

$body1113 = '{"error":{"code":"1113","message":"余额不足或无可用资源包,请充值。"}}';
ck('识别智谱 1113（余额不足/无资源包）', zhipu_model_exhausted($body1113, 429));
ck('识别 429 本身', zhipu_model_exhausted('{}', 429));
ck('识别 400（模型不收参数）', zhipu_model_exhausted('{}', 400));
ck('正常响应不算耗尽', !zhipu_model_exhausted('{"choices":[]}', 200));
ck('中文「余额不足」也能识别', zhipu_model_exhausted('余额不足', 403));
ck('兜底模型默认 glm-4-flash', zhipu_fallback_model() === 'glm-4-flash');
$GLOBALS['C']['ai_limits.fallback_model'] = '';
ck('兜底模型为空时仍然给出可用值', zhipu_fallback_model() === 'glm-4-flash');
$GLOBALS['C']['ai_limits.fallback_model'] = 'glm-4-flash';

$z = (string)file_get_contents(APP_ROOT . '/app/zhipu.php');
ck('非流式接入降级', strpos($z, 'zhipu: model ') !== false);
ck('流式接入降级', strpos($z, 'zhipu_stream: model ') !== false);
ck('降级只发生一次（有 $fell 闸门）', substr_count($z, '$fell = true;') === 2);
ck('降级时关闭 thinking', strpos($z, '$curModel = $fbModel; $noThink = true;') !== false);
ck('流式降级仅在未产出内容时（避免正文重复）', strpos($z, '$httpCode !== 200 && zhipu_model_exhausted') !== false);
$ai = (string)file_get_contents(APP_ROOT . '/api/ai.php');
ck('不再硬编码无额度的思考模型', strpos($ai, "'glm-4.5-air'") === false);
$cfgS = (string)file_get_contents(APP_ROOT . '/config/config.sample.php');
ck('示例配置含 fallback_model', strpos($cfgS, 'fallback_model') !== false);
$cfgR = (string)file_get_contents(APP_ROOT . '/config/config.php');
ck('线上配置含 fallback_model', strpos($cfgR, 'fallback_model') !== false);

echo "----------------------------------------------------\n通过 {$pass} / 失败 {$fail}\n";
exit($fail > 0 ? 1 : 0);
