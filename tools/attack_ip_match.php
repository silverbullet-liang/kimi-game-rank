<?php
/**
 * 攻击 IP 比对（只读，不写任何表）
 * ------------------------------------------------------------
 * 用途：把某次攻击日志里的来源 IP，与站内留存的 IP 痕迹逐个比对，
 *       回答三个问题：
 *         1) 这些 IP 里，有没有以前就被封过的（历史攻击池）
 *         2) 有没有尝试登录过本站、试的是哪个账号
 *         3) 有没有访问记录，能对上站内哪个 user_id（这是把 IP 关联到人的唯一途径）
 *
 * 用法（在站点根目录执行）：
 *   php tools/attack_ip_match.php --log=/path/access.log
 *   php tools/attack_ip_match.php --log=/path/access.log --top=3000 --out=/tmp/match.md
 *   php tools/attack_ip_match.php --ips=/path/ips.txt          # 每行一个 IP，可带次数
 *   php tools/attack_ip_match.php --log=a.log --pool=old.txt   # 额外与一份历史 IP 列表比对
 *
 * 依据：站内 ip_hash = sha256(ip)（app/helpers.php），ip_masked = 前 3 字符 + 星号 + 后 2 字符。
 * 因此只要日志里拿到明文 IP，就能精确反查这三张表，不存在"只能靠猜"的问题。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("仅限命令行执行\n"); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

$opt = array('log' => '', 'ips' => '', 'pool' => '', 'top' => 2000, 'out' => '');
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) {
        $k = $m[1];
        if (!array_key_exists($k, $opt)) { continue; }
        $opt[$k] = ($k === 'top') ? (int)$m[2] : $m[2];
    }
}
if ($opt['log'] === '' && $opt['ips'] === '') {
    exit("用法：php tools/attack_ip_match.php --log=<access.log> [--top=2000] [--pool=<old_ips.txt>] [--out=<out.md>]\n");
}

/* ---------- 1. 收集本次 IP 与次数 ---------- */
$hits = array();
$lines = 0;

function add_ip(array &$hits, string $ip, int $n = 1)
{
    if ($ip === '' || $ip === '0.0.0.0') { return; }
    if (!preg_match('/^[0-9a-fA-F:.]+$/', $ip)) { return; }
    if (!isset($hits[$ip])) { $hits[$ip] = 0; }
    $hits[$ip] += $n;
}

if ($opt['log'] !== '') {
    if (!is_file($opt['log'])) { exit('找不到日志：' . $opt['log'] . "\n"); }
    $fh = fopen($opt['log'], 'r');
    if (!$fh) { exit('无法打开日志' . "\n"); }
    while (($line = fgets($fh)) !== false) {
        $lines++;
        /* Apache / nginx 的行首都以 IP 开头 */
        if (preg_match('/^\s*([0-9a-fA-F:.]+)\s/', $line, $m)) { add_ip($hits, $m[1]); }
    }
    fclose($fh);
}

if ($opt['ips'] !== '') {
    if (!is_file($opt['ips'])) { exit('找不到 IP 列表：' . $opt['ips'] . "\n"); }
    foreach (file($opt['ips'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (!$parts || $parts[0] === '') { continue; }
        $n = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : 1;
        add_ip($hits, $parts[0], $n);
    }
}

if (!$hits) { exit("没有解析出任何 IP。检查日志格式或文件是否为空。\n"); }
arsort($hits);
$totalIp = count($hits);
$totalReq = array_sum($hits);
$top = array_slice($hits, 0, max(1, (int)$opt['top']), true);

/* ---------- 2. 历史池：站内封禁 + 登录失败 + 外部列表 ---------- */
$dbErr = array();
$banHashes = array();
try {
    foreach ((array)db_all('SELECT ip_hash FROM banned_ips', array()) as $r) {
        $banHashes[(string)$r['ip_hash']] = true;
    }
} catch (Throwable $e) { $dbErr[] = 'banned_ips 查询失败'; app_log('attack_ip_match banned_ips: ' . $e->getMessage()); }

$loginHashes = array();   // ip_hash => array(user_norm => count)
try {
    foreach ((array)db_all("SELECT ip_hash, user_norm, COUNT(*) AS c FROM login_attempts
                            WHERE success = 0 GROUP BY ip_hash, user_norm", array()) as $r) {
        $h = (string)$r['ip_hash'];
        if (!isset($loginHashes[$h])) { $loginHashes[$h] = array(); }
        $loginHashes[$h][(string)$r['user_norm']] = (int)$r['c'];
    }
} catch (Throwable $e) { $dbErr[] = 'login_attempts 查询失败'; app_log('attack_ip_match login_attempts: ' . $e->getMessage()); }

$poolIps = array();
if ($opt['pool'] !== '' && is_file($opt['pool'])) {
    foreach (file($opt['pool'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if ($parts && $parts[0] !== '') { $poolIps[$parts[0]] = true; }
    }
}

/* ---------- 3. 逐个比对 ---------- */
$rows = array();
$hitBan = 0;
$hitLogin = 0;
$hitVisit = 0;
$hitPool = 0;
$userScore = array();   // user_id => 关联 IP 数

foreach ($top as $ip => $cnt) {
    $h = ip_hash($ip);
    $mk = mask_ip($ip);
    $flag = array();
    $loginInfo = '';
    $visitInfo = '';

    if (isset($banHashes[$h])) { $flag[] = '曾封禁'; $hitBan++; }
    if (isset($poolIps[$ip])) { $flag[] = '历史池'; $hitPool++; }

    if (isset($loginHashes[$h])) {
        $flag[] = '登录失败';
        $hitLogin++;
        $acc = array();
        foreach ($loginHashes[$h] as $norm => $c) { $acc[] = ($norm !== '' ? $norm : '（空）') . '×' . $c; }
        $loginInfo = implode('、', array_slice($acc, 0, 3));
    }

    /* 访问记录：按脱敏串反查。脱敏会撞车，因此只作为"关联线索"而非定论。 */
    try {
        $vs = db_all('SELECT user_id, ip_masked, city, province, MAX(created_at) AS last_at, COUNT(*) AS c
                      FROM user_visits WHERE ip_masked = ? GROUP BY user_id, ip_masked, city, province
                      ORDER BY c DESC LIMIT 3', array($mk));
        if ($vs) {
            $bits = array();
            foreach ($vs as $v) {
                $bits[] = 'user#' . (int)$v['user_id'] . ' ' . (string)$v['city'] . ' ×' . (int)$v['c'] . '（' . (string)$v['last_at'] . '）';
                $uid = (int)$v['user_id'];
                if (!isset($userScore[$uid])) { $userScore[$uid] = 0; }
                $userScore[$uid]++;
            }
            $visitInfo = implode('；', $bits);
            $hitVisit++;
        }
    } catch (Throwable $e) { /* user_visits 不可用时静默跳过 */ }

    $rows[] = array('ip' => $ip, 'cnt' => $cnt, 'mask' => $mk,
                    'flag' => $flag ? implode(' / ', $flag) : '—',
                    'login' => $loginInfo, 'visit' => $visitInfo);
}

/* ---------- 4. 输出 ---------- */
$out = array();
$out[] = '# 攻击 IP 比对';
$out[] = '';
$out[] = '- 日志行数：' . $lines;
$out[] = '- 解析出 IP：' . $totalIp . ' 个，请求 ' . $totalReq . ' 次';
$out[] = '- 参与比对：Top ' . count($top) . ' 个 IP';
$out[] = '';
$out[] = '## 命中汇总';
$out[] = '';
$out[] = '| 项 | 命中 IP 数 | 说明 |';
$out[] = '|---|---|---|';
$out[] = '| 曾在 banned_ips（被封禁） | ' . $hitBan . ' | 与历史封禁重合，同一批人的强信号 |';
$out[] = '| 曾在 login_attempts（登录失败） | ' . $hitLogin . ' | 尝试过登录，能看出试的是哪个账号 |';
$out[] = '| 曾在 user_visits（可关联账号） | ' . $hitVisit . ' | 按脱敏串反查，可能是同一出口下的其他用户 |';
$out[] = '| 在外部历史 IP 池 | ' . $hitPool . ' | 来源：' . ($opt['pool'] !== '' ? $opt['pool'] : '未提供') . ' |';
$out[] = '';
if ($dbErr) {
    $out[] = '> 注意：' . implode('、', array_unique($dbErr)) . '。这几项显示 0 命中不代表真的没有，需在站点正常运行、数据库可连时重跑。';
    $out[] = '';
}
if ($userScore) {
    arsort($userScore);
    $out[] = '## 关联到的账号（按关联 IP 数排序）';
    $out[] = '';
    $out[] = '| user_id | 关联 IP 数 |';
    $out[] = '|---|---|';
    foreach (array_slice($userScore, 0, 10, true) as $uid => $n) { $out[] = '| ' . $uid . ' | ' . $n . ' |'; }
    $out[] = '';
    $out[] = '> 脱敏串只保留前 3 后 2 字符，同一串可能对应多个不同 IP。这里只作线索，不能单独作为指认依据。';
    $out[] = '';
}
$out[] = '## 明细（Top ' . count($rows) . '）';
$out[] = '';
$out[] = '| IP | 请求 | 脱敏 | 命中 | 登录尝试 | 站内访问记录 |';
$out[] = '|---|---|---|---|---|---|';
foreach ($rows as $r) {
    $out[] = '| ' . $r['ip'] . ' | ' . $r['cnt'] . ' | ' . $r['mask'] . ' | ' . $r['flag'] . ' | '
        . ($r['login'] !== '' ? $r['login'] : '—') . ' | ' . ($r['visit'] !== '' ? $r['visit'] : '—') . ' |';
}
$out[] = '';
$out[] = '## 怎么读这份结果';
$out[] = '';
$out[] = '- **曾封禁命中**：说明这次和以前是同一批来源，可以直接把整段 IP 加回 deny 列表。';
$out[] = '- **登录失败命中**：说明这些 IP 试过登录，`user_norm` 就是它试的账号——如果试的正是某一个人的账号，那动机很清楚。';
$out[] = '- **访问记录命中**：这是唯一能把 IP 和账号对上的路径。注意脱敏碰撞，必须配合时间、城市一起看。';
$out[] = '- 三项全不命中，说明攻击源是全新的 IP 池（住宅代理/云主机轮换），只能靠前置 CDN 吸收，名单式防御无效。';

$text = implode("\n", $out) . "\n";
echo $text;
if ($opt['out'] !== '') {
    @file_put_contents($opt['out'], $text);
    echo "\n已写入 " . $opt['out'] . "\n";
}
