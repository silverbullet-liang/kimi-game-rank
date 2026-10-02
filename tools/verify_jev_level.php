<?php
/**
 * 自检：Jev 违规程度分级（10 档）的档位换算与处置阈值。
 *
 *   默认离线运行（stub cfg，不联网）——验证纯函数 jev_level_from_scores 与阈值分支；
 *   加 --live 会真实调用 classifier.dev 跑一组样本，只打印档位，不打印样本原文。
 *
 *   用法：php tools/verify_jev_level.php [--live]
 *   依赖：php-cli（--live 还需要 php-curl 与网络）
 */
declare(strict_types=1);

$CFG = array(
    'moderation.jev_reject_level' => 5.5,
    'moderation.jev_flag_level'   => 4.5,
);
function cfg($k, $d = null) { global $CFG; return array_key_exists($k, $CFG) ? $CFG[$k] : $d; }
function app_log($m) {}

require __DIR__ . '/../app/jev.php';

$pass = 0; $fail = 0;
function check($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $name\n"; }
    else       { $fail++; echo "  FAIL $name\n"; }
}

$L = jev_level_labels();

/* 1) 档位标签 */
check('档位标签恰好 10 条', count($L) === 10);
foreach (array(1, 5, 10) as $lv) {
    $s = array_fill_keys($L, 0.0); $s[$L[$lv - 1]] = 1.0;
    check("独占第 $lv 档 → " . $lv . '.0', abs(jev_level_from_scores($s) - $lv) < 1e-9);
}

/* 2) 两档各半 → 取中点 */
$s = array_fill_keys($L, 0.0); $s[$L[3]] = 0.5; $s[$L[4]] = 0.5;
check('第 4、5 档各半 → 4.5', abs(jev_level_from_scores($s) - 4.5) < 1e-9);

/* 3) 概率未归一（总和 2.0）不影响结果 */
$s = array_fill_keys($L, 0.0); $s[$L[2]] = 2.0;
check('总和为 2 仍归一到第 3 档', abs(jev_level_from_scores($s) - 3.0) < 1e-9);

/* 4) 空分布 → 0（表示无法计算，调用方回落到大模型） */
check('空分布 → 0', jev_level_from_scores(array_fill_keys($L, 0.0)) === 0.0);

/* 5) 负概率被忽略 */
$s = array_fill_keys($L, 0.0); $s[$L[9]] = -1.0; $s[$L[0]] = 1.0;
check('负概率被忽略 → 第 1 档', abs(jev_level_from_scores($s) - 1.0) < 1e-9);

/* 6) 实测分布：档位换算 + 阈值分支（拦 / 标注 / 放行） */
$cases = array(
    array('正常寒暄', array(1 => 0.65, 2 => 0.35),                       1.35, false, ''),
    array('作品差评', array(3 => 0.88, 4 => 0.10, 2 => 0.02),            3.08, false, ''),
    array('轻度粗鲁', array(5 => 0.96, 6 => 0.02, 3 => 0.02),            4.98, false, 'middle'),
    array('轻攻击',   array(5 => 0.62, 6 => 0.28, 4 => 0.06),            5.23, false, 'middle'),
    array('人身攻击', array(7 => 0.44, 6 => 0.34, 5 => 0.20),            6.24, true,  ''),
    array('恶劣攻击', array(7 => 0.39, 8 => 0.32, 6 => 0.25),            7.07, true,  ''),
);
foreach ($cases as $c) {
    list($name, $dist, $want, $wantReject, $wantFlag) = $c;
    $s = array_fill_keys($L, 0.0);
    foreach ($dist as $lv => $p) { $s[$L[$lv - 1]] = $p; }
    $got = jev_level_from_scores($s);
    $reject = $got >= jev_reject_level();
    $flag = (!$reject && $got >= jev_flag_level()) ? 'middle' : '';
    check(sprintf('%s：档位 %.2f（期望 %.2f）', $name, $got, $want), abs($got - $want) < 0.02);
    check(sprintf('%s：拦截=%s 标注=%s', $name, $reject ? 'Y' : 'N', $flag ?: '-'),
          $reject === $wantReject && $flag === $wantFlag);
}

echo "\n离线用例：通过 " . $pass . "，失败 " . $fail . "\n";

if (in_array('--live', $argv, true)) {
    echo "\n--live：真实调用 classifier.dev（只打印档位，不打印样本原文）\n";
    $samples = array(
        'L1' => '今天天气不错，心情很好。',
        'L2' => '这个作品有点粗糙，细节还能再打磨。',
        'L3' => '这作品就是垃圾。',
        'L4' => '别再发这种东西了，烦不烦。',
        'L5' => '你是不是脑子有问题？',
    );
    $liveFail = 0;
    foreach ($samples as $sid => $t) {
        $r = jev_classify($t);
        if ($r['ok'] === null) { echo "  $sid  不可用（" . $r['reason'] . "）\n"; $liveFail++; continue; }
        printf("  %s  档位=%.2f(%d)  拦截=%s  标注=%s\n", $sid,
            $r['level'], $r['level_int'], $r['ok'] ? 'N' : 'Y', $r['flag'] ?: '-');
    }
    echo $liveFail ? "\n有 $liveFail 个样本调用失败（网络或服务不可用）\n" : "\n实时调用全部成功\n";
}

exit($fail === 0 ? 0 : 1);
