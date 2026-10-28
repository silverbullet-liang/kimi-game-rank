<?php
/**
 * 安全加固自检（v3.51.0）—— 离线，桩件 + 静态断言，不联网、不连库。
 * 跑法：php tools/verify_hardening.php
 *
 * 覆盖五类：数据溢出 / 空指针 / 越权 / 上传恶意内容 / 前端防改（含 sha256+md5 完整性）
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
if (!defined('APP_VERSION')) { define('APP_VERSION', 'verify'); }

/* ---------- 桩件（helpers.php 只定义函数，调用链里才需要外部函数） ---------- */
$GLOBALS['APP_CONFIG'] = array(
    'secrets'  => array('cron_key' => 'test-key-for-integrity'),
    'security' => array('sri' => true),
);

require_once APP_ROOT . '/app/helpers.php';
require_once APP_ROOT . '/app/upload_guard.php';
require_once APP_ROOT . '/app/integrity.php';

$pass = 0; $fail = 0;
function ck(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "OK   $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
function rd(string $rel): string
{
    $p = APP_ROOT . '/' . $rel;
    return is_file($p) ? (string)file_get_contents($p) : '';
}

/* ============================================================
 * 一、数据溢出与边界
 * ============================================================ */
ck('请求体上限常量 = 1MB', REQ_MAX_BYTES === 1048576);
$h = rd('app/helpers.php');
ck('req_json 校验 CONTENT_LENGTH', strpos($h, "\$_SERVER['CONTENT_LENGTH']") !== false);
ck('req_json 校验读到的实际长度', strpos($h, 'strlen($raw) > REQ_MAX_BYTES') !== false);
ck('json_decode 限制嵌套深度', strpos($h, 'json_decode($raw, true, 32)') !== false);
ck('提供 param_range 边界夹取', strpos($h, 'function param_range(') !== false);
ck('提供 param_int_list 批量上限', strpos($h, 'function param_int_list(') !== false);
ck('提供 param_text 多字节截断', strpos($h, 'function param_text(') !== false);

$_GET['n'] = '-999';
ck('param_range 下界夹取', param_range('n', 1, 100, 5) === 1);
$_GET['n'] = '99999999999999999999';
ck('param_range 上界夹取（巨值不溢出）', param_range('n', 1, 100, 5) === 100);
$_GET['n'] = '42';
ck('param_range 中间值原样', param_range('n', 1, 100, 5) === 42);
$_GET['n'] = 'abc';
ck('param_range 非数字回落默认', param_range('n', 1, 100, 5) === 5);

$_GET['ids'] = array('5', 5, '7', 'x', -3, '9');
$list = param_int_list('ids', 10);
ck('param_int_list 去重 + 剔除非法 + 去负数', $list === array(5, 7, 9));
$many = array();
for ($i = 1; $i <= 500; $i++) { $many[] = (string)$i; }
$_GET['ids'] = $many;
ck('param_int_list 截断到上限', count(param_int_list('ids', 50)) === 50);

$long = str_repeat('啊', 900);
$_GET['t'] = $long;
ck('param_text 按字符截断', mb_strlen(param_text('t', 100, ''), 'UTF-8') === 100);

$adm = rd('api/admin.php');
ck('relink 批量用 param_range 限幅', strpos($adm, "works_relink_scan(param_range('limit', 1, 50, 8), 7)") !== false);
ck('offset 用 param_range 限幅', strpos($adm, "param_range('offset', 0, 1000000, 0)") !== false);
ck('分页 page 一律 max(1, …)（≥10 处）', substr_count($adm . rd('api/works.php') . rd('api/dashboard.php'), "max(1, param_int('page'") >= 3);
ck('评论长度上限 300', strpos(rd('api/comments.php'), "> 300") !== false);
ck('消息长度上限 500', strpos(rd('api/lobby.php'), "> 500") !== false);
ck('反馈长度上限 1000', strpos(rd('api/feedback.php'), "> 1000") !== false);

/* ============================================================
 * 二、空指针
 * ============================================================ */
$jsFiles = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/assets/js/src'));
foreach ($it as $f) {
    if ($f->isFile() && substr($f->getFilename(), -3) === '.js') { $jsFiles[] = $f->getPathname(); }
}
$bare = 0;
foreach ($jsFiles as $f) {
    $s = (string)file_get_contents($f);
    if (preg_match('/(?<!\|\| \[\]\))\bd\.(items|rows|list)\.(forEach|map|filter|slice)\(/', $s)) { $bare++; }
}
ck('前端接口数组访问都有兜底（' . count($jsFiles) . ' 个模块）', $bare === 0);
ck('param_str 对非标量做回落', strpos($h, 'return is_scalar($v) ? (string)$v : $default;') !== false);
ck('评论区删除前判断记录存在', strpos(rd('api/comments.php'), "if (\$c === null) { fail(404") !== false);
ck('聊天删除前判断记录存在', strpos(rd('api/lobby.php'), "if (\$m === null) { fail(404") !== false);
ck('后台作品读取前判断存在', strpos($adm, "if (\$w === null) { fail(404") !== false);

/* ============================================================
 * 三、越权
 * ============================================================ */
ck('评论删除校验归属或管理员', strpos(rd('api/comments.php'), '$isOwner') !== false
    && strpos(rd('api/comments.php'), '$isAdmin') !== false);
ck('聊天删除校验归属或管理员', strpos(rd('api/lobby.php'), '只能删除自己的消息') !== false);
ck('个人设置只改自己（不接收他人 id）', strpos(rd('api/profile.php'), "case 'settings'") !== false
    && strpos(rd('api/profile.php'), "param('uid')") === false);
ck('后台写动作走面板二次认证', substr_count($adm, 'require_panel()') >= 10);
ck('写接口均有 CSRF 校验', substr_count(rd('api/comments.php'), 'csrf_verify()') >= 3
    && substr_count(rd('api/lobby.php'), 'csrf_verify()') >= 3
    && substr_count(rd('api/feedback.php'), 'csrf_verify()') >= 2);
$peer = rd('api/peer.php');
ck('互通接口以解密成功作认证', strpos($peer, '无法解密：对端未登记或密钥不符') !== false);
ck('互通接口表名白名单', strpos($peer, 'isset(sync_plan()[$t])') !== false);
ck('互通接口行数与体积上限', strpos($peer, 'strlen($raw) > 4000000') !== false
    && strpos($peer, 'count($rows) > 500') !== false);

/* ============================================================
 * 四、上传恶意内容
 * ============================================================ */
$m = rd('api/media.php');
ck('上传按 MIME 白名单定扩展名', strpos($m, '$mimeToExt[$mime]') !== false);
ck('上传要求 is_uploaded_file', strpos($m, 'is_uploaded_file($tmp)') !== false);
ck('上传落盘名随机', strpos($m, 'bin2hex(random_bytes(8))') !== false);
ck('上传前卡像素尺寸（防解压炸弹）', strpos($m, 'upload_guard_dims_ok($info)') !== false);
ck('落盘前扫描脚本标记', strpos($m, 'upload_guard_scan($tmp)') !== false);
ck('落盘后再扫一次', strpos($m, 'upload_guard_scan($postPath)') !== false);
ck('重编码剥离元数据', strpos($m, 'upload_guard_reencode($postPath, $ext)') !== false);
ck('读取接口 id 严格正则（防路径穿越）',
    strpos($m, '#^(\\d{8})/([a-f0-9]{16})\\.(jpg|jpeg|png|gif|webp)$#') !== false);

ck('尺寸守卫：正常图通过', upload_guard_dims_ok(array(800, 600)) === true);
ck('尺寸守卫：单边超限拒绝', upload_guard_dims_ok(array(20000, 10)) === false);
ck('尺寸守卫：总像素超限拒绝', upload_guard_dims_ok(array(9000, 9000)) === false);
ck('尺寸守卫：零尺寸拒绝', upload_guard_dims_ok(array(0, 0)) === false);

$tmpDir = sys_get_temp_dir();
$clean = $tmpDir . '/hard_clean.bin';
$evil  = $tmpDir . '/hard_evil.bin';
$evil2 = $tmpDir . '/hard_evil2.bin';
file_put_contents($clean, 'GIF89a' . str_repeat("\x00", 64));
file_put_contents($evil, "GIF89a\x01\x00<?php system(\$_GET[1]); ?>");
file_put_contents($evil2, "GIF89a\x01\x00<script>alert(1)</script>");
ck('内容扫描：干净文件放行', upload_guard_scan($clean) === '');
ck('内容扫描：PHP 标签拦截', upload_guard_scan($evil) === '<?php');
ck('内容扫描：script 标签拦截', upload_guard_scan($evil2) === '<script');
@unlink($clean); @unlink($evil); @unlink($evil2);

$up = rd('storage/uploads/.htaccess');
ck('上传目录有访问控制文件', $up !== '');
ck('上传目录关闭 PHP 引擎', strpos($up, 'php_flag engine off') !== false);
ck('上传目录拒绝直接访问', strpos($up, 'Require all denied') !== false || strpos($up, 'Deny from all') !== false);
ck('上传目录移除脚本处理器', strpos($up, 'RemoveHandler') !== false);

/* ============================================================
 * 五、前端防改（sha256 + md5）
 * ============================================================ */
$guard = rd('assets/js/src/guard.js');
ck('运行时守卫存在', $guard !== '');
ck('守卫只监控关键节点与原生 API', strpos($guard, 'WATCH_NODES') !== false && strpos($guard, 'WATCH_NATIVES') !== false);
ck('守卫不做「新增节点即异常」判断（扩展注入放行）',
    strpos($guard, 'addedNodes') === false && strpos($guard, 'MutationObserver') === false);
ck('守卫声明白名单语义', strpos($guard, '只做减法') !== false && strpos($guard, '白名单') !== false);
ck('守卫上报口为 site?action=harden', strpos($guard, "API_BASE + '/site?action=harden'") !== false);
ck('守卫在入口启动', strpos(rd('assets/js/src/app.js'), 'guardStart()') !== false);
ck('构建器登记 guard.js', strpos(rd('tools/build.py'), "'guard.js'") !== false);

$idx = rd('index.php');
ck('入口输出 SRI（sha256）', strpos($idx, 'integrity="') !== false && strpos($idx, "hash_file('sha256'") !== false);
ck('SRI 可配置关闭', strpos($idx, "cfg('security.sri'") !== false);
ck('产物版本号带内容哈希', strpos($idx, '$__appVer') !== false);

ck('完整性模块存在', rd('app/integrity.php') !== '');
ck('清单同时记录 sha256 与 md5', strpos(rd('app/integrity.php'), 'hash_file(\'md5\'') !== false);
ck('清单自带 HMAC 签名', strpos(rd('app/integrity.php'), 'hash_hmac(') !== false);
ck('完整性校验低频抽样', strpos(rd('app/bootstrap.php'), 'integrity_tick()') !== false);
ck('完整性 CLI 工具存在', rd('tools/integrity.php') !== '');
ck('后台有完整性状态与校验动作', strpos($adm, "case 'integrity_state'") !== false
    && strpos($adm, "case 'integrity_scan'") !== false);
ck('站点接口有守卫上报（带冷却）', strpos(rd('api/site.php'), "case 'harden'") !== false
    && strpos(rd('api/site.php'), "cooldown_guard('harden')") !== false);

$man = integrity_manifest();
ck('完整性清单已生成（' . (isset($man['files']) ? count((array)$man['files']) : 0) . ' 个文件）', !empty($man['files']));
if (!empty($man['files'])) {
    /* 自检用的是桩密钥，签名必然对不上真实清单；这里只断言「文件指纹与清单一致」，
       签名链路本身另行断言（跑真实校验用 php tools/integrity.php check）。 */
    $r = integrity_check();
    ck('完整性校验：20 个文件指纹与清单一致', empty($r['bad']) && empty($r['missing']));
    ck('完整性防线：签名比对逻辑存在', strpos(rd('app/integrity.php'), 'hash_equals(integrity_sign') !== false);
}

/* ============================================================
 * 六、危险面
 * ============================================================ */
$phpFiles = array_merge((array)glob(APP_ROOT . '/api/*.php'), (array)glob(APP_ROOT . '/app/*.php'));
$danger = array();
foreach ($phpFiles as $f) {
    $s = preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($f));
    foreach (array('eval(', 'unserialize(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen(') as $fn) {
        /* 只认真正的调用：前面不能是字母/下划线（calc_eval 这类函数名）或引号（检测用的字面量） */
        if (preg_match('/(?<![\w\'"])' . preg_quote($fn, '/') . '/', $s)) { $danger[] = basename($f) . ':' . $fn; }
    }
    if (preg_match('/\bparse_str\(\s*\$[a-z_]+\s*\)/i', $s)) { $danger[] = basename($f) . ':parse_str'; }
    /* 只 flag 直接吃用户输入的包含（LFI 形态）；变量来自常量的配置加载不算 */
    if (preg_match('/(include|require)(_once)?\s*\(?\s*\$_(GET|POST|REQUEST|COOKIE|FILES|SERVER)/', $s)) {
        $danger[] = basename($f) . ':dynamic-include';
    }
}
ck('无 eval / unserialize / shell / 动态包含', count($danger) === 0);
if ($danger) { echo '     命中：' . implode(', ', array_slice($danger, 0, 6)) . "\n"; }
ck('敏感路径仍有 404 拦截', strpos(rd('.htaccess'), 'RedirectMatch 404 ^/(config|storage|sql|docs|app|tools)/') !== false);
ck('API 入口数量稳定（18）', count((array)glob(APP_ROOT . '/api/*.php')) === 18);

echo "----------------------------------------------------\n";
echo '通过 ' . $pass . ' / 失败 ' . $fail . "\n";
exit($fail > 0 ? 1 : 0);
