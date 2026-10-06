<?php
/**
 * 全站对象编号（oid）自检 —— 桩件驱动，不连数据库。
 *   php tools/verify_oid.php
 * 覆盖：类型码映射、14 位编码（日期+类型码+4 位随机）、日期可指定。
 */
declare(strict_types=1);

/* ---- 桩件：编号生成只用到这些，返回「无重复」使查重立即通过 ---- */
function col_ok($t, $c) { return true; }
function db_val($sql, $a = array()) { return null; }
function app_log($m) { }

require __DIR__ . '/../app/oid.php';

$pass = 0; $fail = 0;
function check($n, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n\n"; } }

/* 1) 类型码 */
$codes = array('comments'=>'01','discipline_reports'=>'02','messages'=>'03',
               'ai_messages'=>'04','works'=>'05','feedback'=>'06','announcements'=>'07');
foreach ($codes as $t => $code) { check('类型码 ' . $t . ' = ' . $code, oid_type_code($t) === $code); }
check('未知表类型码兜底 = 00', oid_type_code('something_else') === '00');

/* 2) 编码格式 */
$ymd = gmdate('Ymd');
$o = oid_new('comments');
check('长度 = 14（' . $o . '）', strlen($o) === 14);
check('纯 14 位数字', preg_match('/^\d{14}$/', $o) === 1);
check('前缀 = 当日 + 类型码 01', strpos($o, $ymd . '01') === 0);

foreach ($codes as $t => $code) {
    $x = oid_new($t);
    check($t . ' 编号前缀含当日+' . $code, strpos($x, $ymd . $code) === 0 && strlen($x) === 14);
}

/* 3) 可指定日期（回填历史数据用） */
$g = oid_generate('works', '20250101');
check('指定日期生成：20250101 + 05（' . $g . '）', strpos($g, '2025010105') === 0 && strlen($g) === 14);

/* 4) oid_ready 依赖列探测（桩件恒真） */
check('oid_ready 桩件返回真', oid_ready('comments') === true);

echo "\noid 自检：通过 " . $pass . "，失败 " . $fail . "\n";
