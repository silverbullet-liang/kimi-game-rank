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
    '2026-09-30' => 'festival',    // 国庆窗口首日
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
             && (isset($f['day']) || isset($f['lunar']) || isset($f['from'], $f['to']));
    if ($okDef) { $pass++; echo "  ok   目录项 $k 定义完整\n"; }
    else { $fail++; echo "  FAIL 目录项 $k 定义不完整\n"; }
}

/* 当前生效值应能在目录中找到（空串也算合法） */
$now = festival_now();
$ok = ($now === '' || isset(festival_catalog()[$now]));
if ($ok) { $pass++; echo "  ok   当前生效：" . ($now === '' ? '(无)' : $now) . "\n"; }
else { $fail++; echo "  FAIL 当前生效值不在目录中：$now\n"; }

echo "\n通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail === 0 ? 0 : 1);
