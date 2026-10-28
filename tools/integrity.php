<?php
/**
 * 关键文件完整性：签名 / 校验（CLI）
 *
 *   php tools/integrity.php sign    重新生成 app/data/integrity.json（发版前必跑）
 *   php tools/integrity.php check   校验当前文件与清单是否一致（不一致退出码 1）
 *   php tools/integrity.php list    只看清单摘要
 *
 * 清单用 config 的 secrets.cron_key 做 HMAC：只改文件不改清单、或改清单但不知密钥，
 * 都能被 check 抓出来。
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

$GLOBALS['__cfg'] = require APP_ROOT . '/config/config.php';

function cfg($k, $d = null)
{
    $v = $GLOBALS['__cfg'];
    foreach (explode('.', (string)$k) as $seg) {
        if (!is_array($v) || !array_key_exists($seg, $v)) { return $d; }
        $v = $v[$seg];
    }
    return $v;
}
function app_log($m) { fwrite(STDERR, $m . "\n"); }
function setting_set($k, $v) { }
/* 版本号取 bootstrap 里的真实值，避免清单记成 cli */
if (!defined('APP_VERSION')) {
    $__boot = (string)@file_get_contents(APP_ROOT . '/app/bootstrap.php');
    $__ver  = preg_match("/define\('APP_VERSION'\s*,\s*'([0-9.]+)'\)/", $__boot, $__m) ? $__m[1] : 'unknown';
    define('APP_VERSION', $__ver);
}

require APP_ROOT . '/app/integrity.php';

$cmd = isset($argv[1]) ? (string)$argv[1] : 'check';

if ($cmd === 'sign') {
    $doc = integrity_sign_now();
    echo '已签名 ' . count($doc['files']) . ' 个文件（sha256 + md5），清单 → app/data/integrity.json' . "\n";
    echo '版本 ' . $doc['version'] . ' · ' . $doc['at'] . ' UTC' . "\n";
    exit(0);
}

if ($cmd === 'list') {
    $man = integrity_manifest();
    if (empty($man['files'])) { echo "清单不存在，先跑 sign\n"; exit(1); }
    echo '签名版本 ' . (isset($man['version']) ? $man['version'] : '?') . ' · ' . (isset($man['at']) ? $man['at'] : '?') . ' UTC' . "\n";
    foreach ($man['files'] as $rel => $h) {
        printf("  %-28s %s  %d 字节\n", $rel, substr((string)$h['sha256'], 0, 16), (int)$h['size']);
    }
    exit(0);
}

$r = integrity_check();
if ($r['ok']) {
    echo 'OK：' . $r['count'] . ' 个文件与清单一致（sha256 + md5），清单签名有效' . "\n";
    exit(0);
}
echo "FAIL：完整性校验未通过\n";
if (!$r['sig_ok']) { echo "  · 清单签名无效（清单被改过，或密钥与签名时不同）\n"; }
foreach ($r['bad'] as $f) { echo "  · 已被改动：" . $f . "\n"; }
foreach ($r['missing'] as $f) { echo "  · 文件缺失：" . $f . "\n"; }
if (empty($r['bad']) && empty($r['missing']) && $r['sig_ok'] === false) {
    echo "  （文件本身与清单一致，但清单签名不匹配）\n";
}
echo "修复：确认改动合理后跑 php tools/integrity.php sign 重新签名\n";
exit(1);
