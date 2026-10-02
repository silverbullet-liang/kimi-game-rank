<?php
/**
 * 自检：限时节日皮肤的日期窗口判定。
 *   用法：php tools/verify_festival.php
 *   依赖：php-cli（离线运行，不联网）
 */
declare(strict_types=1);

function cfg($k, $d = null) { return $d; }      // festival.php 不依赖 cfg，留桩以防万一
require __DIR__ . '/../app/festival.php';

$cases = array(
    930  => 'festival',    // 国庆窗口首日
    1001 => 'festival',
    1008 => 'festival',    // 国庆窗口末日
    1009 => '',            // 窗口外
    1010 => 'xinhai',      // 辛亥革命纪念日
    1011 => '',
    1024 => '',
    1025 => 'kmyc',        // 抗美援朝纪念日
    1031 => 'halloween',   // 万圣夜
    1101 => '',
    1231 => '',
);

$pass = 0; $fail = 0;
foreach ($cases as $md => $want) {
    $got = festival_key_for_md((int)$md);
    if ($got === $want) { $pass++; printf("  ok   %04d → %-10s\n", $md, $got === '' ? '(无)' : $got); }
    else { $fail++; printf("  FAIL %04d → %s（期望 %s）\n", $md, $got === '' ? '(无)' : $got, $want === '' ? '(无)' : $want); }
}

/* 目录完整性：每个节日都要有名称、描述、dark 标记与窗口 */
foreach (festival_catalog() as $k => $f) {
    $okDef = isset($f['name'], $f['desc'], $f['dark']) && (isset($f['day']) || isset($f['from'], $f['to']));
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
