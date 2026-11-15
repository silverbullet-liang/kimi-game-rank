<?php
/**
 * 自检：流量看门与冻结保护（app/breaker.php）
 * 纯文件驱动，不连库、不联网： php tools/verify_breaker.php
 */

$tmp = sys_get_temp_dir() . '/brktest_' . getmypid();
@mkdir($tmp . '/storage', 0755, true);
@mkdir($tmp . '/app', 0755, true);
file_put_contents($tmp . '/index.php', "<?php\n/* 原入口 */\nrequire __DIR__ . '/app/bootstrap.php';\n");
file_put_contents($tmp . '/app/bootstrap.php', "<?php\n/* 原引导 */\n");

define('APP_ROOT', $tmp);

function cfg($p, $d = null) { return $d; }

require dirname(__DIR__) . '/app/breaker.php';

$pass = 0; $fail = 0;
function chk($cond, $name)
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name\n"; }
}
function wipe()
{
    foreach (array('breaker.json', 'breaker_hb.json', 'breaker.off') as $f) {
        $p = brk_dir() . '/' . $f;
        if (is_file($p)) { unlink($p); }
    }
    $fz = brk_dir() . '/' . BRK_FROZEN;
    if (is_dir($fz)) {
        foreach ((array)glob($fz . '/*') as $f) { @unlink($f); }
        @rmdir($fz);
    }
    $d = brk_dir() . '/trap';
    if (is_dir($d)) {
        foreach ((array)glob($d . '/*') as $f) { @unlink($f); }
        @rmdir($d);
    }
    @unlink($GLOBALS['TMP'] . '/index.html');
    /* 还原入口与引导文件，让每个用例都在同一初始态下跑 */
    file_put_contents($GLOBALS['TMP'] . '/index.php', "<?php\n/* 原入口 */\nrequire __DIR__ . '/app/bootstrap.php';\n");
    file_put_contents($GLOBALS['TMP'] . '/app/bootstrap.php', "<?php\n/* 原引导 */\n");
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['REQUEST_URI'] = '/api/db_dump.php';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 test';
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
$GLOBALS['TMP'] = $tmp;

echo "== 阈值判定 ==\n";
wipe();
$r = brk_decide();
chk($r['trip'] === false, '空状态下不冻结');
chk($r['limit'] === 1200, '默认阈值 1200 次/分钟');

wipe();
brk_write(BRK_HB, array('m' => (int)floor(time() / 60), 'n' => 5000));
$r = brk_decide();
chk($r['trip'] === true, '越过阈值即冻结');
chk($r['rate'] >= 5000, '判定时带上实测速率');

wipe();
brk_write(BRK_HB, array('m' => (int)floor(time() / 60) - 5, 'n' => 9000));
$r = brk_decide();
chk($r['trip'] === false, '五分钟前的旧计数不参与判定');

echo "\n== 计数 ==\n";
wipe();
brk_tick(); brk_tick();
chk(brk_rate() === 2, '两次请求计数为 2');

echo "\n== 冻结动作 ==\n";
wipe();
chk(brk_frozen() === false, '冻结前状态为未冻结');
brk_trip(9999);
chk(brk_frozen() === true, '冻结后留下标记');
chk(is_file(brk_dir() . '/frozen/index.master.php'), '备份了原入口');
chk(is_file(brk_dir() . '/frozen/bootstrap.master.php'), '备份了原引导文件');
chk(strpos((string)@file_get_contents(brk_dir() . '/frozen/index.master.php'), '原入口') !== false, '备份内容正确');
chk(is_file($tmp . '/index.html'), '生成静态页');
$idx = (string)@file_get_contents($tmp . '/index.php');
chk(strpos($idx, 'readfile') !== false && strpos($idx, 'bootstrap') === false, '入口已换成静态输出（不再加载引导）');
$boot = (string)@file_get_contents($tmp . '/app/bootstrap.php');
chk(strpos($boot, 'readfile') !== false, '引导文件已换成静态输出（接口一并失效）');
chk(is_file(brk_dir() . '/frozen/RECOVER.txt'), '留下手动恢复说明');
$st = brk_read(BRK_STATE);
chk(isset($st['state']) && $st['state'] === 'frozen', '状态记为 frozen');

echo "\n== 幂等与不可自动解除 ==\n";
$before = (string)@file_get_contents($tmp . '/index.php');
brk_trip(9999);
chk((string)@file_get_contents($tmp . '/index.php') === $before, '重复冻结不重复改写');
$html = (string)@file_get_contents($tmp . '/index.html');
chk(strpos($html, '维护') === false && strpos($html, '拦截') === false, '静态页不提「维护」「拦截」');
chk(strpos($html, '内容正在整理') !== false, '静态页像正常站点文案');
chk(strpos($html, 'http://') === false && strpos($html, 'https://') === false, '零外部资源');
$src = (string)@file_get_contents(dirname(__DIR__) . '/app/breaker.php');
chk(strpos($src, 'until') === false, '源码里没有「到期自动恢复」的字段');
chk(strpos($src, 'opskey') === false, '源码里没有站内自救口令');

echo "\n== 备份失败则拒绝替换 ==\n";
wipe();
@unlink($tmp . '/app/bootstrap.php');   /* 引导文件缺失，备份必然失败 */
brk_trip(9999);
$idx2 = (string)@file_get_contents($tmp . '/index.php');
chk(strpos($idx2, '原入口') !== false, '备份失败时入口保持原样');
$st2 = brk_read(BRK_STATE);
chk(isset($st2['state']) && $st2['state'] === 'degraded', '状态记为 degraded（降级未冻结）');

/* 复原，供后续用例 */
file_put_contents($tmp . '/app/bootstrap.php', "<?php\n/* 原引导 */\n");

echo "\n== 工具接口登记 ==\n";
wipe();
brk_trap_log('db_dump.php');
$log = (array)glob(brk_dir() . '/trap/*.log');
chk(count($log) === 1, '写入当日流水文件');
$row = json_decode(trim((string)@file_get_contents($log[0])), true);
chk(is_array($row) && $row['tag'] === 'db_dump.php', '流水含来源标记');
chk(is_array($row) && strlen($row['ip']) === 64, '只存指纹不存明文 IP');
chk(strpos((string)@file_get_contents(brk_dir() . '/gate_pool.txt'), $row['ip']) !== false, '指纹并入准入池');
brk_trap_log('db_dump.php');
chk(substr_count((string)@file_get_contents(brk_dir() . '/gate_pool.txt'), '# trap') === 1, '同一指纹不重复灌池');

echo "\n----------------------------------------\n";
echo "通过 $pass 项，失败 $fail 项\n";

wipe();
@rmdir(brk_dir());
@rmdir($tmp . '/app');
@rmdir($tmp);
exit($fail > 0 ? 1 : 0);
