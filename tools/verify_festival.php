<?php
/**
 * 自检：限时节日皮肤的日期窗口判定。
 *   用法：php tools/verify_festival.php
 *   依赖：php-cli（离线运行，不联网）
 */
declare(strict_types=1);

function cfg($k, $d = null) { return $d; }      // festival.php 不依赖 cfg，留桩以防万一
require __DIR__ . '/../app/lunar.php';
require __DIR__ . '/../app/festival.php';

/* 公历固定窗口（2026 年） */
$cases = array(
    '2026-09-30' => 'martyr',      // 烈士纪念日与国庆窗口重叠，单日优先
    '2026-10-01' => 'festival',
    '2026-10-08' => 'festival',    // 国庆窗口末日
    '2026-10-09' => '',            // 窗口外
    '2026-10-10' => 'xinhai',      // 辛亥革命纪念日
    '2026-10-11' => '',
    '2026-10-24' => '',
    '2026-10-25' => 'kmyc',        // 抗美援朝纪念日
    '2026-10-31' => 'halloween',   // 万圣夜
    '2026-11-01' => '',
    '2026-12-31' => '',
    /* 重阳节：农历九月初九，公历日期逐年变化 */
    '2024-10-11' => 'chongyang',
    '2025-10-29' => 'chongyang',
    '2026-10-18' => 'chongyang',
    '2026-10-19' => '',            // 重阳次日
    '2027-10-08' => 'chongyang',   // 该年重阳落在国庆窗口内 → 单日节日优先
    '2028-10-26' => 'chongyang',
    /* 1024 程序员节 / 5·12 纪念日 */
    '2026-10-24' => 'code1024',
    '2026-10-23' => '',
    '2026-05-12' => 'may12',
    '2026-05-11' => '',
    /* 记者节 */
    '2026-11-08' => 'journalist',
    '2026-11-07' => '',
    /* 感恩节：11 月第 4 个星期四，逐年不同 */
    '2024-11-28' => 'thanksgiving',
    '2025-11-27' => 'thanksgiving',
    '2026-11-26' => 'thanksgiving',
    '2027-11-25' => 'thanksgiving',
    '2026-11-27' => '',
    /* 寒衣节：农历十月初一 */
    '2025-11-20' => 'hanyi',
    '2026-11-09' => 'hanyi',
    '2026-11-10' => '',
    /* 春节：正月初一起 9 天 */
    '2026-02-17' => 'spring',
    '2026-02-25' => 'spring',
    '2026-02-26' => '',
    '2027-02-06' => 'spring',
    '2027-02-14' => 'valentine',   // 情人节单日优先于春节窗口
    '2027-02-15' => '',
    /* ---- 新增 37 套：公历固定日 ---- */
    '2026-01-01' => 'newyear',
    '2026-01-02' => '',
    '2026-01-10' => 'police',
    '2026-01-11' => '',
    '2026-02-14' => 'valentine',
    '2026-02-15' => '',
    '2026-04-01' => 'aprilfool',
    '2026-04-02' => '',
    '2026-04-05' => 'qingming',
    '2026-04-06' => '',
    '2026-04-22' => 'earthday',
    '2026-04-23' => 'bookday',
    '2026-05-01' => 'labor',
    '2026-05-04' => 'youth',
    '2026-05-08' => 'smile',
    '2026-05-18' => 'museum',
    '2026-06-01' => 'children',
    '2026-06-05' => 'environment',
    '2026-06-23' => 'olympic',
    '2026-06-26' => 'antidrug',
    '2026-07-01' => 'party',
    '2026-07-07' => 'july7',
    '2026-08-01' => 'army',
    '2026-08-15' => 'surrender',
    '2026-09-03' => 'victory',
    '2026-09-10' => 'teacher',
    '2026-09-18' => 'nineone',
    /* ---- 新增 37 套：农历日（腊月与正月会落在公历 1~2 月，跨农历年） ---- */
    '2026-01-26' => 'laba',
    '2026-01-27' => '',
    '2026-02-03' => 'weiya',
    '2026-02-10' => 'xiaonian_n',
    '2026-02-11' => 'xiaonian_s',
    '2026-02-16' => 'chuxi',
    '2026-03-03' => 'yuanxiao',
    '2026-03-04' => '',
    '2026-04-19' => 'shangsi',
    '2026-06-19' => 'duanwu',
    '2026-06-20' => '',
    '2026-08-19' => 'qixi',        // 与医师节同日：目录顺序在前者优先
    '2026-08-27' => 'zhongyuan',
    '2026-09-25' => 'midautumn',
    '2026-09-26' => '',
    /* ---- 某月第 N 个星期 X：母亲节 / 父亲节 ---- */
    '2026-05-10' => 'mothersday',
    '2026-05-17' => '',
    '2026-06-21' => 'fathersday',
    '2026-06-28' => '',
    /* ---- 医师节：8/19 与七夕同日的年份由目录顺序决定，故取七夕不在 8/19 的年份 ---- */
    '2025-08-19' => 'doctor',
);

$pass = 0; $fail = 0;
foreach ($cases as $day => $want) {
    list($yy, $mm, $dd) = array_map('intval', explode('-', $day));
    $got = festival_key_for_date($yy, $mm, $dd);
    if ($got === $want) { $pass++; printf("  ok   %s → %-11s\n", $day, $got === '' ? '(无)' : $got); }
    else { $fail++; printf("  FAIL %s → %s（期望 %s）\n", $day, $got === '' ? '(无)' : $got, $want === '' ? '(无)' : $want); }
}

/* 目录完整性：每个节日都要有名称、描述、dark 标记与窗口 */
foreach (festival_catalog() as $k => $f) {
    $okDef = isset($f['name'], $f['desc'], $f['dark'])
             && (isset($f['day']) || isset($f['lunar']) || isset($f['nth']) || isset($f['lunar_end']) || isset($f['from'], $f['to']));
    if ($okDef) { $pass++; echo "  ok   目录项 $k 定义完整\n"; }
    else { $fail++; echo "  FAIL 目录项 $k 定义不完整\n"; }
}

/* 当前生效值应能在目录中找到（空串也算合法） */
$now = festival_now();
$ok = ($now === '' || isset(festival_catalog()[$now]));
if ($ok) { $pass++; echo "  ok   当前生效：" . ($now === '' ? '(无)' : $now) . "\n"; }
else { $fail++; echo "  FAIL 当前生效值不在目录中：$now\n"; }

/* ============================================================
 * 农历判定的年份回归：只比「月日」会把去年同一天误判成今天
 * ============================================================ */
$r1029 = festival_key_for_date(2026, 10, 29);
if ($r1029 === '') { $pass++; echo "  ok   2026-10-29 无节日（2025 年重阳的月日不得串到今年）\n"; }
else { $fail++; echo "  FAIL 2026-10-29 应为空，实际 $r1029\n"; }
if (festival_key_for_date(2026, 10, 18) === 'chongyang') { $pass++; echo "  ok   2026-10-18 才是重阳\n"; }
else { $fail++; echo "  FAIL 2026-10-18 应为 chongyang\n"; }
if (festival_key_for_date(2026, 10, 30) === '') { $pass++; echo "  ok   2026-10-30 无节日\n"; }
else { $fail++; echo "  FAIL 2026-10-30 应为空\n"; }

/* 一天两个节日：2026-08-19 同时是医师节与七夕，目录顺序取前者 */
$keys819 = festival_keys_for_date(2026, 8, 19);
$has2 = count($keys819) >= 2 && in_array('qixi', $keys819, true) && in_array('doctor', $keys819, true);
if ($has2) { $pass++; echo "  ok   2026-08-19 命中两个节日：" . implode('+', $keys819) . "\n"; }
else { $fail++; echo "  FAIL 2026-08-19 应同时命中 qixi 与 doctor，实际 " . implode('+', $keys819) . "\n"; }
if (festival_key_for_date(2026, 8, 19) === 'qixi') { $pass++; echo "  ok   同日多命中时皮肤取目录顺序第一个（qixi）\n"; }
else { $fail++; echo "  FAIL 同日取前者应为 qixi\n"; }

/* 下发给前端的数据结构 */
$cl = festival_client();
if (isset($cl['today'], $cl['date'], $cl['now'], $cl['list']) && is_array($cl['today'])) {
    $pass++; echo "  ok   festival_client 下发 today / date / now / list\n";
} else { $fail++; echo "  FAIL festival_client 结构不完整\n"; }
$todayOk = true;
foreach ($cl['today'] as $t1) {
    if (!isset($t1['key'], $t1['name'], $t1['desc'])) { $todayOk = false; }
}
if ($todayOk) { $pass++; echo "  ok   today 每项含 key/name/desc\n"; }
else { $fail++; echo "  FAIL today 项缺少字段\n"; }

/* ============================================================
 * 「关于今天」文档：48 套 × 中英日 = 144 份，每份 5 节
 * ============================================================ */
$docRoot = dirname(__DIR__) . '/assets/docs/festivals';
$langs = array('zh', 'en', 'ja');
$missing = array(); $badSec = array(); $dirty = array();
$count = 0;
foreach (array_keys(festival_catalog()) as $k) {
    foreach ($langs as $lg) {
        $f = $docRoot . '/' . $lg . '/' . $k . '.md';
        if (!is_file($f)) { $missing[] = $lg . '/' . $k; continue; }
        $count++;
        $t = (string)file_get_contents($f);
        if (substr_count($t, "\n## ") !== 5) { $badSec[] = $lg . '/' . $k; }
        if (preg_match('/<script|\.css|class="/i', $t)) { $dirty[] = $lg . '/' . $k; }
    }
}
if (!$missing) { $pass++; echo "  ok   节日文档齐全：$count 份（48 套 × 3 语言）\n"; }
else { $fail++; echo "  FAIL 缺少节日文档 " . count($missing) . " 份：" . implode(',', array_slice($missing, 0, 5)) . "\n"; }
if (!$badSec) { $pass++; echo "  ok   每份文档均为 5 节（简介/由来/习俗/故事/主题）\n"; }
else { $fail++; echo "  FAIL 节数异常：" . implode(',', array_slice($badSec, 0, 5)) . "\n"; }
if (!$dirty) { $pass++; echo "  ok   文档内无脚本/样式/类名等实现细节\n"; }
else { $fail++; echo "  FAIL 文档含实现细节：" . implode(',', array_slice($dirty, 0, 5)) . "\n"; }

echo "\n通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail === 0 ? 0 : 1);
