<?php
/**
 * 站点看门与冻结保护
 * ------------------------------------------------------------
 * 每次请求在文件里拍一张轻量快照：本分钟请求数。请求量越过阈值时，
 * 站点**就地冻结**：入口与引导文件被替换成静态页，所有接口一并失效，
 * 不再加载任何业务代码。
 *
 * 冻结不可自动解除 —— 只有站长在主机商的文件管理器或 FTP 里手动还原文件。
 * 这样即便有人拿到了站内管理权限，也无法把门打开；也不会有「维护 X 分钟后恢复」
 * 这类字样告诉对方「我认得你在攻击我」。
 *
 * 本文件必须自包含：只用常量、$_SERVER、storage 目录，
 * 不 require 其它模块、不查数据库 —— 它跑在一切之前。
 */

define('BRK_STATE', 'breaker.json');
define('BRK_HB', 'breaker_hb.json');
define('BRK_FROZEN', 'frozen');

function brk_dir(): string
{
    $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
    return $root . '/storage';
}

function brk_root(): string
{
    return defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
}

function brk_read(string $file): array
{
    $p = brk_dir() . '/' . $file;
    if (!is_file($p)) { return array(); }
    $raw = @file_get_contents($p);
    if (!is_string($raw) || $raw === '') { return array(); }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : array();
}

function brk_write(string $file, array $data)
{
    $p = brk_dir() . '/' . $file;
    @file_put_contents($p, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function brk_cfg(string $k, $d)
{
    return isset($GLOBALS['APP_CONFIG']['breaker'][$k]) ? $GLOBALS['APP_CONFIG']['breaker'][$k] : $d;
}

/** 是否已经冻结（冻结后按说不该再走到判定，这里只作兜底） */
function brk_frozen(): bool
{
    return is_file(brk_dir() . '/' . BRK_FROZEN . '/index.master.php');
}

/** 最近约 60 秒的请求数（当前分钟 + 上一分钟） */
function brk_rate(): int
{
    $hb  = brk_read(BRK_HB);
    $min = (int)floor(time() / 60);
    if (!isset($hb['m'])) { return 0; }
    if ((int)$hb['m'] === $min || (int)$hb['m'] === $min - 1) { return (int)$hb['n']; }
    return 0;
}

function brk_tick()
{
    $min = (int)floor(time() / 60);
    $hb  = brk_read(BRK_HB);
    if (isset($hb['m']) && (int)$hb['m'] === $min) {
        $hb['n'] = (int)$hb['n'] + 1;
    } else {
        $hb = array('m' => $min, 'n' => 1);
    }
    brk_write(BRK_HB, $hb);
}

/**
 * 未过期的关键异常信号（撞库 / 管理接口探测）。
 * 由 app/sentinel.php 写进 storage/cache/pen_*.txt；这里只读文件，不 require 任何模块 ——
 * 熔断跑在一切之前，必须自包含。
 */
function brk_signals(): int
{
    $dir = brk_dir() . '/cache';
    if (!is_dir($dir)) { return 0; }
    $now = time();
    $n = 0;
    $names = @scandir($dir);
    if (!is_array($names)) { return 0; }
    foreach ($names as $f) {
        if (!is_string($f)) { continue; }
        if (strpos($f, 'pen_auth_all_') !== 0 && strpos($f, 'pen_admin_probe_') !== 0
            && strpos($f, 'pen_auth_dev_') !== 0 && strpos($f, 'pen_reg_all_') !== 0) { continue; }
        $exp = (int)@file_get_contents($dir . '/' . $f);
        if ($exp > $now) { $n++; }
    }
    return $n;
}

/**
 * 判定一次：返回 array(rate, limit, signals, trip)。
 * 只看请求数会被慢速绕开，也容易把正常高峰误判成攻击 —— 所以越线之后还要看信号：
 *   · 速率达到两倍阈值：直接熔断；
 *   · 速率越线且有任一提权信号（撞库 / 探测管理接口）：熔断；
 *   · 信号已有三条以上、速率到六成：熔断。
 */
function brk_decide(): array
{
    brk_tick();
    $rate  = brk_rate();
    $limit = (int)brk_cfg('rate', 1200);
    if ($limit < 120) { $limit = 120; }
    $hard = (int)brk_cfg('rate_hard', 0);
    if ($hard <= 0) { $hard = $limit * 2; }

    $sig = brk_signals();
    $trip = ($rate >= $hard)
        || ($rate >= $limit && $sig >= 1)
        || ($sig >= 3 && $rate >= (int)round($limit * 0.6));

    return array('rate' => $rate, 'limit' => $limit, 'signals' => $sig, 'trip' => $trip);
}

/**
 * 冻结：把入口与引导文件换成静态页。
 * 顺序上先备份、再写静态页、最后替换入口 —— 任一环节失败都不会让站点变成白屏，
 * 因为最坏情况只是「换了一半」：入口还在，业务还在，只是多了一个 index.html。
 */
function brk_trip(int $rate)
{
    $dir  = brk_dir();
    $fz   = $dir . '/' . BRK_FROZEN;
    $root = brk_root();

    if (is_file($fz . '/index.master.php')) { return; }   /* 已经冻结过，不做第二次 */
    if (!is_dir($fz)) { @mkdir($fz, 0755, true); }

    $html = brk_page();

    /* 1. 备份原件（恢复靠它） */
    $okIdx = @copy($root . '/index.php', $fz . '/index.master.php');
    $okApp = @copy($root . '/app/bootstrap.php', $fz . '/bootstrap.master.php');
    if (!$okIdx || !$okApp) {
        /* 备份失败就绝不替换 —— 保住可恢复性优先 */
        brk_write(BRK_STATE, array('state' => 'degraded', 'at' => time(), 'rate' => $rate));
        return;
    }

    /* 2. 静态页 */
    @file_put_contents($root . '/index.html', $html, LOCK_EX);

    $static = "<?php\n"
        . "/* 静态入口 */\n"
        . "if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }\n"
        . "readfile(__DIR__ . '/index.html');\n";

    /* 3. 入口换成静态输出 */
    @file_put_contents($root . '/index.php', $static, LOCK_EX);

    /* 4. 引导文件换成静态输出：所有接口同样只回一张页面，不再加载业务代码 */
    $boot = "<?php\n"
        . "/* 静态入口 */\n"
        . "if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }\n"
        . "readfile(dirname(__DIR__) . '/index.html');\n";
    @file_put_contents($root . '/app/bootstrap.php', $boot, LOCK_EX);

    brk_write(BRK_STATE, array('state' => 'frozen', 'at' => time(), 'rate' => $rate));

    @file_put_contents($fz . '/RECOVER.txt',
        "手动恢复步骤（主机商文件管理器 / FTP）\n"
        . "1. 把本目录的 index.master.php 覆盖回站点根目录的 index.php\n"
        . "2. 把本目录的 bootstrap.master.php 覆盖回 app/bootstrap.php\n"
        . "3. 删除站点根目录的 index.html\n"
        . "4. 删除本目录（storage/frozen）\n"
        . "恢复前请先确认攻击已经停止，否则会再次冻结。\n");
}

/** 冻结后对外呈现的页面：一个普通的静态首页，不写「维护」「拦截」之类的字。 */
function brk_page(): string
{
    $name = 'Kimi 游戏榜';
    if (isset($GLOBALS['APP_CONFIG']['site']['name'])) {
        $n = trim((string)$GLOBALS['APP_CONFIG']['site']['name']);
        if ($n !== '') { $name = $n; }
    }
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<title>' . $name . '</title><style>'
        . '*{box-sizing:border-box}html,body{height:100%;margin:0}'
        . 'body{display:flex;align-items:center;justify-content:center;padding:22px;'
        . 'font:15px/1.8 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;'
        . 'color:#1c1c1e;background:#f6f5f9}'
        . 'body::before{content:"";position:fixed;inset:-20%;z-index:-1;'
        . 'background:radial-gradient(38% 32% at 22% 18%,rgba(124,77,255,.24),transparent 70%),'
        . 'radial-gradient(36% 30% at 80% 30%,rgba(88,140,255,.18),transparent 70%)}'
        . '.c{width:100%;max-width:420px;padding:28px 22px;border-radius:22px;text-align:center;'
        . 'background:rgba(255,255,255,.74);border:1px solid rgba(255,255,255,.85);'
        . 'box-shadow:0 18px 50px -18px rgba(60,30,120,.28)}'
        . 'h1{margin:0 0 10px;font-size:19px;letter-spacing:.3px}'
        . 'p{margin:0;color:#5b5b66;font-size:13.5px}'
        . '.m{margin-top:16px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap}'
        . '.m span{font-size:12px;color:#6b5b96;padding:5px 11px;border-radius:999px;'
        . 'background:rgba(124,77,255,.10);border:1px solid rgba(124,77,255,.16)}'
        . '@media(prefers-color-scheme:dark){body{color:#eceaf3;background:#141218}'
        . '.c{background:rgba(32,29,40,.74);border-color:rgba(255,255,255,.1)}p{color:#a8a3b8}'
        . '.m span{color:#cfc2ff;background:rgba(124,77,255,.16);border-color:rgba(124,77,255,.28)}}'
        . '</style></head><body><div class="c">'
        . '<h1>' . $name . '</h1>'
        . '<p>内容正在整理，页面稍后恢复。</p>'
        . '<div class="m"><span>榜单</span><span>作品</span><span>主题</span><span>社区</span></div>'
        . '</div></body></html>';
}

/**
 * 入口调用：需要拦就当场冻结并退出；放行则返回。
 * 冻结后不只是这一条请求 —— 入口与引导文件都已被替换，
 * 后续任何请求（页面、接口、定时任务）都只会拿到这张静态页。
 */
function brk_guard()
{
    if (PHP_SAPI === 'cli') { return; }
    if ((int)brk_cfg('on', 1) !== 1) { return; }
    if (is_file(brk_dir() . '/breaker.off')) { return; }
    if (brk_frozen()) { return; }

    $r = brk_decide();
    if (empty($r['trip'])) { return; }

    brk_trip((int)$r['rate']);

    if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
    echo brk_page();
    exit;
}

/**
 * 工具接口的访问登记（供 api/_trap.php 调用）
 * ------------------------------------------------------------
 * 记一条流水，并把来源指纹并入访问准入池，让后续请求走人机验证。
 * 只记录，不做任何反制动作。
 */
function brk_trap_log(string $tag)
{
    $ip   = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $key  = '';
    if (isset($GLOBALS['APP_CONFIG']['secrets']['cron_key'])) {
        $key = (string)$GLOBALS['APP_CONFIG']['secrets']['cron_key'];
    }
    $hash = $ip === '' ? '' : hash('sha256', $key . '|' . $ip);

    $dir = brk_dir() . '/trap';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

    $row = array(
        'ts'     => date('c'),
        'tag'    => $tag,
        'ip'     => $hash,
        'ua'     => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 120) : '',
        'uri'    => isset($_SERVER['REQUEST_URI']) ? substr((string)$_SERVER['REQUEST_URI'], 0, 200) : '',
        'method' => isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '',
        'ref'    => isset($_SERVER['HTTP_REFERER']) ? substr((string)$_SERVER['HTTP_REFERER'], 0, 120) : '',
    );
    @file_put_contents($dir . '/' . date('Ymd') . '.log', json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    if ($hash !== '') {
        $pool = brk_dir() . '/gate_pool.txt';
        $have = is_file($pool) ? (string)@file_get_contents($pool) : '';
        if (strpos($have, $hash) === false) {
            @file_put_contents($pool, "\n" . $hash . '  # trap ' . date('Y-m-d') . ' ' . $tag . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
