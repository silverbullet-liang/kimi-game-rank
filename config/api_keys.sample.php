<?php
/**
 * 密钥池（示例）
 * ------------------------------------------------------------
 * 复制为 config/api_keys.php 后填入你自己的 Key。
 * ⚠️ config/api_keys.php 已在 .gitignore 中——切勿提交真实 Key。
 *
 * 请求时 round-robin 轮询取用；失败自动切下一个，连续失败进入短冷却。
 */
declare(strict_types=1);

return array(
    'endpoint'   => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
    'model'      => 'glm-4-flash',       // 免费模型，支持 web_search 联网工具
    'enable_web_search' => true,         // 联网搜索：tools 里以 web_search.search_query 传入当前提问
    'cooldown'   => 60,                  // 单 key 失败冷却秒数

    /* 智谱 Key 池：可放多个，轮询使用。格式形如 {id}.{secret} */
    'keys' => array(
        'YOUR_ZHIPU_API_KEY_1',
        'YOUR_ZHIPU_API_KEY_2',
    ),

    /* OpenRouter：免费文本模型 */
    'openrouter' => array(
        'base'            => 'https://openrouter.ai/api/v1',
        'key'             => 'YOUR_OPENROUTER_API_KEY',
        'site_quota'      => 50,      // 全站每日次数；以 API 返回的 limit 优先，失败时用此值兜底
        'per_user_daily'  => 2,       // 每人每日次数
        'title'           => 'kimi游戏榜',
        'reasoning'       => 'low',   // 统一思考等级
        'timeout'         => 90,
    ),
);
