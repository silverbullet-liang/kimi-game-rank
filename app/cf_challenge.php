<?php
declare(strict_types=1);
/**
 * Cloudflare 挑战层（Managed / Interactive Challenge）
 * ------------------------------------------------------------
 * 与 app/cf_shield.php 是两件事：
 *   安全盾管「谁��能连到源站」——只接受带私有头的请求；
 *   挑战层管「连到的人先过哪一关」——在 Cloudflare 边缘就出验证，脚本流量根本没机会碰到 PHP。
 *
 * 挑战规则本身建在 Cloudflare 那边（控制台或 API），本文件是唯一口径：
 * 配置写在 config/config.php 的 'cf_challenge' 段，
 * tools/cf_rules.php 按这里的取值生成可直接粘贴/执行的规则清单。
 *
 * 两条硬约束：
 *   1) 已验证的搜索引擎与监控机器人必须跳过挑战，否则爬虫被反复验证，站点在搜索引擎里消失。
 *   2) 流式与长连接接口（AI 对话、世界对话、站点互通、定时任务）绝不套挑战，
 *      一次挑战就会把长连接掐断。
 */
define('CF_CH_TAGS', 'KGR');

/** 归一化配置，缺项给安全默认值 */
function cf_challenge_settings(array $conf): array
{
    $out = array(
        'enabled'    => !empty($conf['enabled']),
        'bots_skip'  => !isset($conf['bots_skip']) || !empty($conf['bots_skip']),
        'auth'       => 'off',
        'probe'      => 'off',
        'write_api'  => 'off',
        'exclude'    => array(),
        'rate_limit' => array('per_minute' => 0, 'action' => 'off'),
    );
    foreach (array('auth', 'probe', 'write_api') as $k) {
        $v = isset($conf[$k]) ? strtolower(trim((string)$conf[$k])) : 'off';
        if ($v === 'managed' || $v === 'interactive') { $out[$k] = $v; }
    }
    if (isset($conf['exclude']) && is_array($conf['exclude'])) {
        foreach ($conf['exclude'] as $e) {
            $e = trim((string)$e);
            if ($e !== '') { $out['exclude'][] = $e; }
        }
    }
    if (isset($conf['rate_limit']) && is_array($conf['rate_limit'])) {
        $n = isset($conf['rate_limit']['per_minute']) ? (int)$conf['rate_limit']['per_minute'] : 0;
        $a = isset($conf['rate_limit']['action']) ? strtolower(trim((string)$conf['rate_limit']['action'])) : 'managed';
        if (!in_array($a, array('managed', 'interactive'), true)) { $a = 'managed'; }
        $out['rate_limit'] = array('per_minute' => max(0, $n), 'action' => $a);
    }
    return $out;
}

/** 挑战动作 → Cloudflare API 的 action 名 */
function cf_challenge_action(string $kind): string
{
    return $kind === 'interactive' ? 'challenge' : 'managed_challenge';
}

/**
 * 生成 Cloudflare 规则定义（与 Cloudflare API 的规则结构一致）。
 * 返回 array('custom' => [...], 'rate' => [...])
 */
function cf_challenge_rules(array $s, string $host): array
{
    $custom = array();
    $rate   = array();

    if (!$s['enabled']) { return array('custom' => $custom, 'rate' => $rate); }

    if ($s['bots_skip']) {
        $custom[] = array(
            'description' => CF_CH_TAGS . ': 放行已验证机器人（搜索引擎、监控）',
            'expression'  => '(cf.client.bot)',
            'action'      => 'skip',
            'action_parameters' => array(
                'products' => array('waf', 'rateLimit', 'uaBlock', 'bic', 'hot', 'securityLevel', 'zoneLockdown'),
            ),
            'enabled' => true,
        );
    }

    if ($s['auth'] !== 'off') {
        $custom[] = array(
            'description' => CF_CH_TAGS . ': 注册与登录 → ' . ($s['auth'] === 'interactive' ? 'Interactive' : 'Managed') . ' Challenge',
            'expression'  => '(http.host eq "' . $host . '") and (starts_with(http.request.uri.path, "/api/auth"))',
            'action'      => cf_challenge_action($s['auth']),
            'enabled'     => true,
        );
    }

    if ($s['probe'] !== 'off') {
        $custom[] = array(
            'description' => CF_CH_TAGS . ': 探针路径 → ' . ($s['probe'] === 'interactive' ? 'Interactive' : 'Managed') . ' Challenge',
            'expression'  => '(http.host eq "' . $host . '") and (starts_with(http.request.uri.path, "/probe/"))',
            'action'      => cf_challenge_action($s['probe']),
            'enabled'     => true,
        );
    }

    if ($s['write_api'] !== 'off') {
        $expr = '(http.host eq "' . $host . '")'
              . ' and (http.request.method in {"POST" "PUT" "PATCH" "DELETE"})'
              . ' and (starts_with(http.request.uri.path, "/api/"))';
        foreach ($s['exclude'] as $e) {
            $expr .= ' and not (starts_with(http.request.uri.path, "' . $e . '"))';
        }
        $custom[] = array(
            'description' => CF_CH_TAGS . ': 写接口 → ' . ($s['write_api'] === 'interactive' ? 'Interactive' : 'Managed') . ' Challenge',
            'expression'  => $expr,
            'action'      => cf_challenge_action($s['write_api']),
            'enabled'     => true,
        );
    }

    if ($s['rate_limit']['per_minute'] > 0) {
        $rate[] = array(
            'description' => CF_CH_TAGS . ': 单 IP 每分钟超过 ' . $s['rate_limit']['per_minute'] . ' 次 → Challenge',
            'expression'  => '(http.host eq "' . $host . '")',
            'action'      => cf_challenge_action($s['rate_limit']['action']),
            'ratelimit'   => array(
                'characteristics'      => array('ip.src'),
                'period'               => 60,
                'requests_per_period'  => $s['rate_limit']['per_minute'],
                'mitigation_timeout'   => 600,
            ),
            'enabled' => true,
        );
    }

    return array('custom' => $custom, 'rate' => $rate);
}

/** 人类可读摘要（面板与文档用） */
function cf_challenge_summary(array $s): array
{
    $kind = function ($k) {
        if ($k === 'managed')    { return 'Managed Challenge'; }
        if ($k === 'interactive'){ return 'Interactive Challenge'; }
        return '关闭';
    };
    return array(
        'enabled'    => $s['enabled'],
        'bots_skip'  => $s['bots_skip'],
        'auth'       => $kind($s['auth']),
        'probe'      => $kind($s['probe']),
        'write_api'  => $kind($s['write_api']),
        'exclude'    => $s['exclude'],
        'rate_limit' => $s['rate_limit']['per_minute'] > 0
            ? ('每分钟 ' . $s['rate_limit']['per_minute'] . ' 次 · ' . $kind($s['rate_limit']['action']))
            : '不限速',
    );
}

/** 可直接粘进 Cloudflare 控制台的表达式清单 */
function cf_challenge_text(array $rules): string
{
    $lines = array();
    $i = 1;
    foreach (array('custom' => '自定义规则', 'rate' => '限速规则') as $k => $label) {
        foreach ($rules[$k] as $r) {
            $lines[] = $i . '. [' . $label . '] ' . $r['description'];
            $lines[] = '   条件：' . $r['expression'];
            $lines[] = '   动作：' . $r['action'];
            $i++;
        }
    }
    return implode("\n", $lines);
}
