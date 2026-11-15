<?php
/**
 * 自检：永久拒绝名单（app/blocklist.php）
 * 纯文件驱动，不连库、不联网： php tools/verify_blocklist.php
 */

$tmp = sys_get_temp_dir() . '/blktest_' . getmypid();
@mkdir($tmp . '/storage', 0755, true);
define('APP_ROOT', $tmp);

require dirname(__DIR__) . '/app/blocklist.php';

$pass = 0; $fail = 0;
function chk($cond, $name)
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name\n"; }
}
function rules(array $lines)
{
    @file_put_contents(brk_path(), implode("\n", $lines));
    unset($GLOBALS['__BLK']);
}
function brk_path(): string
{
    return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/' . BLK_FILE;
}
function hit($ip, array $lines): bool
{
    rules($lines);
    foreach (blk_rules() as $r) {
        if (blk_match($ip, $r)) { return true; }
    }
    return false;
}

echo "== 单地址 ==";
chk(hit('39.106.187.173', array('39.106.187.173')) === true, '精确命中');
chk(hit('39.106.187.174', array('39.106.187.173')) === false, '邻居不误伤');

echo "\n== 网段 ==";
chk(hit('39.106.187.9', array('39.106.187.0/24')) === true, '/24 命中');
chk(hit('39.106.188.9', array('39.106.187.0/24')) === false, '/24 外不命中');
chk(hit('10.1.2.3', array('10.0.0.0/8')) === true, '/8 命中');
chk(hit('11.1.2.3', array('10.0.0.0/8')) === false, '/8 外不命中');
chk(hit('192.168.1.5', array('192.168.1.1/32')) === false, '/32 只命中那一个');

echo "\n== 前缀写法 ==";
chk(hit('203.0.113.7', array('203.0.113.')) === true, '前三段前缀命中');
chk(hit('203.0.114.7', array('203.0.113.')) === false, '前缀不误伤');

echo "\n== 注释与空行 ==";
chk(hit('39.106.187.173', array('# 注释', '', '39.106.187.173', '  # 缩进注释')) === true,
    '注释与空行被忽略、命中照常');
chk(hit('1.2.3.4', array()) === false, '空名单不拒绝任何人');

echo "\n== 内置名单：四个云主机 ==";
$real = dirname(__DIR__) . '/storage/blocklist.txt';
chk(is_file($real), '仓库里存在 blocklist.txt');
if (is_file($real)) {
    $raw = (string)file_get_contents($real);
    foreach (array('39.106.187.173', '139.199.246.138', '49.235.105.51', '54.238.213.168') as $ip) {
        chk(strpos($raw, $ip) !== false, '名单包含 ' . $ip);
    }
    chk(strpos($raw, '120.239.146') === false, '不含家用宽带段（不误伤同段住户）');
    $lines = array();
    foreach (explode("\n", $raw) as $ln) {
        $ln = trim($ln);
        if ($ln !== '' && $ln[0] !== '#') { $lines[] = $ln; }
    }
    $hasNet = false;
    foreach ($lines as $ln) { if (strpos($ln, '/') !== false) { $hasNet = true; } }
    chk($hasNet === false, '生效条目全是单机，没有网段（不误伤同段邻居）');
    chk(count($lines) === 4, '生效条目正好 4 条');
}

echo "\n== 拒绝响应 ==";
$src = (string)file_get_contents(dirname(__DIR__) . '/app/blocklist.php');
chk(strpos($src, 'BLK_SUSPEND_URL') !== false, '存在可替换的暂停页地址常量');
chk(strpos($src, "'https://suspended-domain.net/'") !== false, '暂停页地址与主机商一致');
chk(strpos($src, ', true, 302') !== false, '命中返回 302 重定向');
chk(strpos($src, ', true, 301') === false, '绝不用 301（永久缓存不可撤销）');
chk(strpos($src, 'http_response_code(403)') === false, '不再返回 403（不确认站点在线）');
chk(strpos($src, "'Cache-Control: no-store'") !== false, '302 带 no-store，避免被中间层缓存');
chk(strpos($src, 'exit;') !== false, '命中即退出、不加载业务代码');
chk(strpos($src, 'REMOTE_ADDR') !== false && strpos($src, 'X-Forwarded-For') === false,
    '只信 REMOTE_ADDR，不读可伪造的转发头');
chk(strpos($src, "'/trap'") !== false, '命中留一条流水，便于复查误伤');

echo "\n----------------------------------------\n";
echo "通过 $pass 项，失败 $fail 项\n";
@unlink(brk_path());
@rmdir($tmp . '/storage');
@rmdir($tmp);
exit($fail > 0 ? 1 : 0);
