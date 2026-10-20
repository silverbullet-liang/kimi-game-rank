<?php
/**
 * 校验智谱 key 轮询：跳过冷却、全冷却取最早解冻、重试次数与 key 数解耦。
 * 只读 config/api_keys.php 里的 key（不打印、不外发），会临时改写 storage/cache/zhipu_keys.json 并还原。
 */
declare(strict_types=1);

define('APP_ROOT', '/sandbox/workspace/kimi-game-rank');

function cfg($k, $d = null) { return $d; }
function app_log($a, $b = null) {}

require APP_ROOT . '/app/zhipu.php';

$stateFile = APP_ROOT . '/storage/cache/zhipu_keys.json';
$backup = file_exists($stateFile) ? file_get_contents($stateFile) : null;

$cfg = zhipu_config();
$keys = array_values($cfg['keys']);
$n = count($keys);
$pass = 0; $fail = 0;
function chk(string $name, bool $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n"; }
}
$h = function ($k) { return substr(hash('sha256', $k), 0, 12); };

echo "key 数量：$n\n";

/* 1) 一个 key 冷却中 → 必须被跳过 */
$st = array('cursor' => 0, 'cooldown' => array($h($keys[0]) => time() + 3600));
file_put_contents($stateFile, json_encode($st));
$got = array();
for ($i = 0; $i < 3; $i++) { $got[] = zhipu_pick_key(); }
chk('冷却中的 key 不会被选中', !in_array($keys[0], $got, true) || $n === 1);
if ($n > 1) { chk('三次都落在可用 key 上', count(array_filter($got, function ($k) use ($keys) { return $k !== $keys[0]; })) === 3); }

/* 2) 全部冷却 → 取最早解冻 */
if ($n > 1) {
    $cool = array();
    foreach ($keys as $idx => $k) { $cool[$h($k)] = time() + 3600; }
    $cool[$h($keys[1])] = time() + 10;   /* 只有 1 号最早解冻 */
    file_put_contents($stateFile, json_encode(array('cursor' => 3, 'cooldown' => $cool)));
    $pick = zhipu_pick_key();
    chk('全部冷却时选最早解冻的那个', $pick === $keys[1]);
}

/* 3) 冷却分级 */
chk('401 → 长冷却(3600)', zhipu_cool_seconds(401) === 3600);
chk('429 → 60s', zhipu_cool_seconds(429) === 60);
chk('500 → 20s', zhipu_cool_seconds(500) === 20);
chk('网络错误 → 15s', zhipu_cool_seconds(0, true) === 15);

/* 4) 重试次数与 key 数解耦（源码闸门） */
$src = file_get_contents(APP_ROOT . '/app/zhipu.php');
chk('旧写法 max(1, count($keys)) 已移除', strpos($src, '$tries = max(1, count($keys))') === false);
chk('保留至少 3 次重试', strpos($src, 'max(3, count($keys) * 2)') !== false);

if ($backup === null) { @unlink($stateFile); }
else { file_put_contents($stateFile, $backup); }

echo "\n结果：通过 $pass 项，失败 $fail 项\n";
exit($fail > 0 ? 1 : 0);
