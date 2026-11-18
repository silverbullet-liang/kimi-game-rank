<?php
/**
 * Cloudflare 安全盾 · 离线自检
 * 只测判定逻辑（cf_shield_decide），不产生任何 HTTP 输出、不联网、不碰数据库。
 * 跑法：php tools/verify_cf_shield.php
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/cf_shield.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [ok]   {$name}\n"; }
    else { $fail++; echo "  [FAIL] {$name} {$extra}\n"; }
}

function conf(array $over = array()): array
{
    return cf_shield_settings(array_merge(array(
        'enabled' => true,
        'hosts'   => array('kimi-game-rank.nx.kg'),
        'secret'  => 'S3cretOriginValue_0123456789',
        'header'  => 'HTTP_X_ORIGIN_AUTH',
        'mode'    => 'enforce',
        'deny'    => 'suspend',
        'allow'   => array('cron.php', 'api/peer.php'),
    ), $over));
}

echo "== 配置归一化 ==\n";
$c = conf();
ok('enabled 生效', $c['enabled'] === true);
ok('hosts 小写化', in_array('kimi-game-rank.nx.kg', $c['hosts'], true));
ok('header 大写化', $c['header'] === 'HTTP_X_ORIGIN_AUTH');
ok('mode 默认 enforce', $c['mode'] === 'enforce');
ok('非法 deny 回落到 suspend', conf(array('deny' => 'xyz'))['deny'] === 'suspend');
ok('合法 deny=suspend 保留', conf(array('deny' => 'suspend'))['deny'] === 'suspend');
ok('默认即 suspend', cf_shield_settings(array('enabled' => true))['deny'] === 'suspend');
ok('mode=observe 保留', conf(array('mode' => 'observe'))['mode'] === 'observe');

echo "\n== 放行场景 ==\n";
$H = 'kimi-game-rank.nx.kg';
ok('总开关关闭 → 放行', cf_shield_decide(conf(array('enabled' => false)), $H, 'index.php', '')['why'] === 'disabled');
ok('密钥为空 → 放行（并留痕）', cf_shield_decide(conf(array('secret' => '')), $H, 'index.php', '')['why'] === 'no-secret');
ok('非覆盖域名（镜像站）→ 放行', cf_shield_decide(conf(), 'kimi-game-rank.byethost33.com', 'index.php', '')['why'] === 'host-skip');
ok('allow 精确路径 → 放行', cf_shield_decide(conf(), $H, 'cron.php', '')['why'] === 'allow');
ok('allow 精确路径（已去查询串）→ 放行', cf_shield_decide(conf(), $H, 'api/peer.php', '')['why'] === 'allow');
ok('allow 目录前缀 → 放行', cf_shield_decide(conf(array('allow' => array('api/peer/'))), $H, 'api/peer/sub.php', '')['why'] === 'allow');
ok('正确密钥 → 通过', cf_shield_decide(conf(), $H, 'index.php', 'S3cretOriginValue_0123456789')['why'] === 'verified');
ok('hosts 为空数组 → 全部域名生效并通过', cf_shield_decide(conf(array('hosts' => array())), 'any.example', 'index.php', 'S3cretOriginValue_0123456789')['action'] === 'pass');

echo "\n== 拦截场景 ==\n";
$d = cf_shield_decide(conf(), $H, 'index.php', '');
ok('缺头 → 拦截', $d['action'] === 'deny' && $d['why'] === 'missing');
$d = cf_shield_decide(conf(), $H, 'index.php', 'wrong-value');
ok('错值 → 拦截', $d['action'] === 'deny' && $d['why'] === 'mismatch');
$d = cf_shield_decide(conf(), $H, 'api/auth.php', '');
ok('接口同样拦截', $d['action'] === 'deny');
$d = cf_shield_decide(conf(), $H, 'index.php', strtoupper('S3cretOriginValue_0123456789'));
ok('大小写不同 → 拦截（hash_equals 严格）', $d['action'] === 'deny');
$d = cf_shield_decide(conf(), $H, 'index.php', 'S3cretOriginValue_0123456789 ');
ok('尾部空格 → 拦截', $d['action'] === 'deny');

echo "\n== 边界 ==\n";
ok('密钥为前缀的短值 → 拦截', cf_shield_decide(conf(), $H, 'index.php', 'S3cretOriginValue')['action'] === 'deny');
ok('超长垃圾值 → 拦截', cf_shield_decide(conf(), $H, 'index.php', str_repeat('a', 4096))['action'] === 'deny');
ok('备份文件不被 allow 放过', cf_shield_decide(conf(array('allow' => array('cron.php'))), $H, 'cron.php.bak', '')['action'] === 'deny');
ok('目录遍历不会绕过 allow', cf_shield_decide(conf(), $H, 'xx/cron.php', '')['action'] === 'deny');

echo "\n== 拒绝方式 ==\n";
ok('默认 suspend（伪装站点已停用）', conf()['deny'] === 'suspend');
ok('errors404 可显式指定', conf(array('deny' => 'errors404'))['deny'] === 'errors404');
ok('http404 可显式指定', conf(array('deny' => 'http404'))['deny'] === 'http404');

echo "\n结果：通过 {$pass}，失败 {$fail}\n";
exit($fail === 0 ? 0 : 1);
