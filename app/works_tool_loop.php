<?php
/**
 * 工具循环（非流式）
 * ------------------------------------------------------------
 * 供「世界对话 @官方AI」使用：模型不走流式，就一轮一轮问 ——
 *   模型输出 → 解析工具调用 → 执行 → 把结果作为 user 消息回灌 → 再问，
 * 直到模型不再请求工具或轮次用尽。私聊 AI 走的是流式循环（api/ai.php），
 * 两条链路各自贴合自己的输出形态，故刻意不合并，避免动到已稳定的流式链路。
 *
 * $opts:
 *   max_rounds  工具轮次上限（默认 1）
 *   max_tools   每轮最多执行的工具数（默认 2）
 *   allow       允许的工具名白名单（null = 全部；世界对话传公共工具集）
 * 返回 array(text, tools[], usage[])
 */
declare(strict_types=1);

function works_tool_run(array $msgs, string $model = '', array $opts = array()): array
{
    $maxRounds = isset($opts['max_rounds']) ? max(0, (int)$opts['max_rounds']) : 1;
    $maxTools  = isset($opts['max_tools']) ? max(1, (int)$opts['max_tools']) : 2;
    $allow     = (isset($opts['allow']) && is_array($opts['allow'])) ? $opts['allow'] : null;

    $tools = array();
    $usage = array();

    for ($round = 0; $round <= $maxRounds; $round++) {
        $sniff = ($round < $maxRounds);          // 最后一轮不再嗅探，直接产出最终答案
        $r = zhipu_chat($msgs, $model);          // 失败会抛异常，交调用方处理
        if (!empty($r['usage'])) { $usage = (array)$r['usage']; }

        $parse = works_tool_parse((string)$r['text']);
        $calls = $parse['calls'];
        if (!$sniff || !$calls) {
            return array('text' => $parse['clean'], 'tools' => $tools, 'usage' => $usage);
        }

        $msgs[] = array('role' => 'assistant', 'content' => (string)$r['text']);
        $toolText = '';
        $n = 0;
        foreach ($calls as $c) {
            if ($n >= $maxTools) { break; }
            $act = isset($c['action']) ? (string)$c['action'] : '';
            if ($act === '' || ($allow !== null && !in_array($act, $allow, true))) { continue; }
            try {
                $res = works_tool_execute($c);
            } catch (Throwable $e) {
                $res = array('ok' => false, 'action' => $act, 'error' => '工具执行异常');
            }
            if (empty($res['action'])) { $res['action'] = $act; }
            $tools[] = array('action' => $act, 'ok' => !empty($res['ok']), 'summary' => works_tool_summary($res));
            $toolText .= works_tool_result_text($res) . "\n";
            $n++;
        }
        if ($n === 0) {
            /* 全部调用被白名单挡下（如公共频道里的 user）：通告不可用，交给下一轮直接作答 */
            $msgs[] = array('role' => 'assistant', 'content' => (string)$r['text']);
            $msgs[] = array('role' => 'user', 'content' => '该工具在当前场景不可用，请直接用中文回答，不要再调用工具。');
            continue;
        }
        $msgs[] = array('role' => 'user', 'content' => trim($toolText));
    }

    return array('text' => '', 'tools' => $tools, 'usage' => $usage);
}
