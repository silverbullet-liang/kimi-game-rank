<?php
/**
 * 链接守卫回归：规律域名判据 + 域名提取 + 规则分片查询
 * 运行：php tools/verify_link_guard.php
 *
 * 会真的去读 app/data/adblock/ 下的分片文件（若存在），
 * 因此这一份同时验证「生成脚本与 PHP 端的桶号算法是否一致」——
 * 两边算法一旦错位，查询会永远落空且不报错，只有这个测试能发现。
 */
declare(strict_types=1);
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__)); }
require __DIR__ . '/../app/link_guard.php';

$GLOBALS['fail_n'] = 0;
$GLOBALS['pass_n'] = 0;
function ck(string $name, $got, $want): void
{
    $ok = ($got === $want);
    $ok ? $GLOBALS['pass_n']++ : $GLOBALS['fail_n']++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* ---------- 1. 规律域名：命中的 ---------- */
foreach (array('111111.com', '121212.com', '125125.com', '125521.cc', '123456.com',
               '654321.cc', '66666.com', '123123123.com', '00000.cc',
               '1234567.com') as $h) {
    ck('命中规律域名 ' . $h, link_guard_is_pattern_host($h), true);
}

/* ---------- 2. 规律域名：不该命中的（真实老站与正常域名） ---------- */
foreach (array('163.com', '360.com', '51.com', '12306.com', 'qq.com', 'abc.com',
               '111111.cn', '111111.net', 'a12345.com') as $h) {
    ck('不命中 ' . $h, link_guard_is_pattern_host($h), false);
}

/* ---------- 3. 域名提取 ---------- */
$t = '看这个 www.abc123.com 还有 HTTP://Sub.Example.COM/path 以及 1.2.3.4 和 a@mail.com';
$got = link_guard_extract_hosts($t);
ck('提取到 2 个域名', count($got), 2);
ck('含 www.abc123.com（整段域名）', in_array('www.abc123.com', $got, true), true);
ck('含 sub.example.com（已转小写）', in_array('sub.example.com', $got, true), true);
ck('不含 IP 片段 2.3.4', in_array('2.3.4', $got, true), false);
ck('不含邮箱域名 mail.com', in_array('mail.com', $got, true), false);

/* ---------- 4. 规则解析 ---------- */
$rules = "! 注释行\n||ads.example.com^\n@@||safe.example.com^\n||a.b.c^d\n/^regex\\.rule/\n||wild.*card^\n||dup.example.com^\n||dup.example.com^\n";
$p = link_guard_parse_rules($rules);
ck('解析出 2 条（去重且跳过例外/正则/通配）', count($p), 2);
ck('含 ads.example.com', in_array('ads.example.com', $p, true), true);
ck('含 dup.example.com', in_array('dup.example.com', $p, true), true);

/* ---------- 5. 桶号算法一致性（与 tools/build_adblock.py 的 crc32 % 128） ---------- */
$expect = array('ads.example.com' => 39, 'a.com' => 25, 'zzz.top' => 7);
$mismatch = array();
foreach ($expect as $h => $n) {
    $path = link_guard_shard_path($h);
    if (basename($path) !== sprintf('b%03d.php', $n)) { $mismatch[] = $h . ' → ' . basename($path); }
}
ck('桶号与生成脚本一致（PHP crc32 与 Python zlib.crc32 同源）',
   $mismatch ? implode(',', $mismatch) : 'ok', 'ok');

/* ---------- 6. 分片查询（需已生成规则文件） ---------- */
$stats = link_guard_stats();
if ($stats['count'] > 0) {
    /* 从分片里随机取一条真实规则，验证「查得到」 */
    $sample = '';
    foreach (array(0, 63, 127) as $i) {
        $f = APP_ROOT . '/app/data/adblock/b' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '.php';
        if (is_file($f)) {
            $arr = require $f;
            if ($arr) { $sample = (string)array_key_first($arr); break; }
        }
    }
    ck('取到一条真实规则用于验证', $sample !== '', true);
    ck('真实规则命中', link_guard_rule_hit($sample), $sample);
    ck('其子域也命中（父域逐级查）', link_guard_rule_hit('x.y.' . $sample), $sample);
    ck('不存在的域名不命中', link_guard_rule_hit('this-host-should-never-exist-xyz.com'), '');
    printf("  （规则库 %d 条，构建于 %s）\n", $stats['count'], $stats['updated']);
} else {
    printf("  --  规则库未生成，跳过查询用例（先跑 tools/build_adblock.py）\n");
}

/* ---------- 7. 综合判定 ---------- */
ck('综合判定命中规律域名', count(link_guard_check('来 111111.com 看看')) > 0, true);
ck('综合判定对干净文本返回空', link_guard_check('今天天气不错，我们在 163.com 看了新闻'), array());

printf("\n通过 %d，失败 %d\n", $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
