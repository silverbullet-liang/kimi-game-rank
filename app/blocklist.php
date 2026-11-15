<?php
/**
 * 永久的拒绝名单
 * ------------------------------------------------------------
 * 与「访问准入」分工不同：
 *   · 准入（app/gate.php）：命中池 → 要求过人机验证，通过后照常使用
 *   · 本文件：命中名单 → 直接 302 到域名停用页，不给验证机会，也不加载任何业务代码
 *
 * 为什么伪装成「域名已停用」而不是返回 403：
 *   403 等于确认「这个站点还活着，只是把你挡了」，反而给对方继续试的理由；
 *   而主机商暂停账户时，访问就是被 302 到 suspended-domain.net。
 *   还原这个行为，对方更可能判定站点已关闭而放弃。
 *
 * 名单放在 storage/blocklist.txt，每行一条，支持三种写法：
 *   203.0.113.7         单个地址
 *   203.0.113.0/24      网段
 *   203.0.113.          前三段前缀
 *   # 开头为注释
 *
 * 本文件必须自包含：只用 $_SERVER 与 storage 目录，不 require 任何模块。
 */

define('BLK_FILE', 'blocklist.txt');

/* 命中后跳转的地址。与主机商暂停账户时的跳转目标一致，对方看到的就是「域名已停用」。
   要换目标就改这一行——本文件在 config 之前执行，所以不读配置项。 */
define('BLK_SUSPEND_URL', 'https://suspended-domain.net/');

function blk_dir(): string
{
    return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage';
}

/** 远端地址：只信 REMOTE_ADDR —— 转发头可以被伪造，本站在主机直连后面 */
function blk_ip(): string
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
}

/** 名单条目（每个请求只读一次） */
function blk_rules(): array
{
    if (isset($GLOBALS['__BLK'])) { return $GLOBALS['__BLK']; }
    $out = array();
    $f = blk_dir() . '/' . BLK_FILE;
    if (is_file($f) && is_readable($f)) {
        $raw = @file_get_contents($f);
        if (is_string($raw) && $raw !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $ln) {
                $ln = trim($ln);
                if ($ln === '' || $ln[0] === '#') { continue; }
                $out[] = $ln;
            }
        }
    }
    $GLOBALS['__BLK'] = $out;
    return $out;
}

/** 单条规则是否命中 */
function blk_match(string $ip, string $rule): bool
{
    if ($ip === '') { return false; }

    /* 网段 */
    if (strpos($rule, '/') !== false) {
        $p = explode('/', $rule, 2);
        $net = @inet_pton($p[0]);
        $me  = @inet_pton($ip);
        if ($net === false || $me === false || strlen($net) !== strlen($me)) { return false; }
        $bits = (int)$p[1];
        $max  = strlen($net) * 8;
        if ($bits < 0) { $bits = 0; }
        if ($bits > $max) { $bits = $max; }
        $full = intdiv($bits, 8);
        $rest = $bits % 8;
        if (strncmp($net, $me, $full) !== 0) { return false; }
        if ($rest === 0) { return true; }
        $mask = 0xFF << (8 - $rest) & 0xFF;
        return (ord($net[$full]) & $mask) === (ord($me[$full]) & $mask);
    }

    /* 前缀写法，如 203.0.113. */
    if (substr($rule, -1) === '.') {
        return strpos($ip, $rule) === 0;
    }

    /* 单个地址 */
    return $rule === $ip;
}

/** 入口调用：命中即拒，不再往下走 */
function blk_guard()
{
    /* 命令行不走拦截；自检脚本用 BLK_TEST 打开以便断言 */
    if (PHP_SAPI === 'cli' && !defined('BLK_TEST')) { return; }
    $rules = blk_rules();
    if (!$rules) { return; }

    $ip = blk_ip();
    if ($ip === '') { return; }

    foreach ($rules as $r) {
        if (!blk_match($ip, $r)) { continue; }

        /* 记一行，便于日后核对是否误伤（追加写，失败不影响拒绝） */
        $dir = blk_dir() . '/trap';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        @file_put_contents(
            $dir . '/' . date('Ymd') . '.log',
            json_encode(array('ts' => date('c'), 'tag' => 'deny:' . $r, 'ip' => $ip,
                'uri' => substr((string)(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''), 0, 160),
                'ua' => substr((string)(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 100)),
                JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );

        if (!headers_sent()) {
            /* 必须 302，不能用 301 —— 301 会被浏览器与客户端永久缓存，
               一旦名单写错，被误伤的访客即使事后修好也仍会一直跳到暂停页，
               而且无法远程清除。302 是临时重定向，随时可撤销。 */
            @header_remove('X-Powered-By');   // 别让对方看出这是应用层主动跳的
            header('Location: ' . BLK_SUSPEND_URL, true, 302);
            header('Cache-Control: no-store');
        }
        exit;
    }
}
