<?php
declare(strict_types=1);
/**
 * 输出 Cloudflare 挑战规则清单
 * ------------------------------------------------------------
 * 规则定义在 config/config.php 的 'cf_challenge' 段，本脚本按那一份配置生成清单：
 *   php tools/cf_rules.php          文本清单（可照着在 Cloudflare 控制台里手工建）
 *   php tools/cf_rules.php --json   JSON（与 Cloudflare API 规则结构一致，可直接 POST）
 *
 * 站点的挑战规则只有这一份口径：改配置 → 重新生成清单 → 同步到 Cloudflare。
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$host = '';
$hosts = cfg('cf_shield.hosts', array());
if (is_array($hosts) && !empty($hosts)) { $host = (string)$hosts[0]; }
if ($host === '') { $host = (string)cfg('site.url', ''); }
$host = preg_replace('#^https?://#', '', $host);
$host = preg_replace('#/.*$#', '', $host);

$s    = cf_challenge_settings(cfg('cf_challenge', array()));
$rules = cf_challenge_rules($s, $host);

if (in_array('--json', $argv, true)) {
    echo json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$sum = cf_challenge_summary($s);
echo "Cloudflare 挑战层规则清单\n";
echo "目标主机：", ($host !== '' ? $host : '(未配置)'), "\n";
echo "状态：", ($s['enabled'] ? '启用' : '停用（CF 侧应删除对应规则）'), "\n";
echo "机器人跳过：", ($s['bots_skip'] ? '是' : '否'), "　注册登录：", $sum['auth'],
     "　探针路径：", $sum['probe'], "　写接口：", $sum['write_api'], "\n";
echo "不套挑战：", ($s['exclude'] ? implode('、', $s['exclude']) : '（无）'), "\n";
echo "限速：", $sum['rate_limit'], "\n\n";

if (!$rules['custom'] && !$rules['rate']) {
    echo "（没有需要创建的规则）\n";
    exit(0);
}

echo cf_challenge_text($rules), "\n\n";
echo "写入位置：Cloudflare 控制台 → Security → WAF / Security Rules（自定义规则）\n";
echo "API 写法：规则集 phase = http_request_firewall_custom（自定义规则）、http_rate_limit（限速）\n";
echo "私有头注入（Transform Rules，phase = http_request_late_transform）：\n";
echo "  条件：(http.host eq \"", $host, "\")\n";
echo "  动作：Set request header  X-Origin-Auth = ", (string)cfg('cf_shield.secret', ''), "\n";
