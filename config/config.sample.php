<?php
/**
 * 主配置文件（示例）
 * ------------------------------------------------------------
 * 复制为 config.php 并填写你自己的连接参数。
 * ⚠️ 本文件只需填「数据库」与「站点」两段；其余密钥由 install.php 自动生成写入。
 */
declare(strict_types=1);

return array(

    /* ---------- 数据库：主库 ---------- */
    'db' => array(
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'kimi_rank',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ),

    /* ---------- 数据库：管理员凭证库（独立库，物理分离） ---------- */
    'db_admin' => array(
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'kimi_admin_sec',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ),

    /* ---------- 站点 ---------- */
    'site' => array(
        'name'        => 'kimi游戏榜',
        'url'         => 'https://your-domain.com',   // 不带结尾斜杠
        'timezone'    => 'Asia/Shanghai',              // 仅展示层；库内全 UTC
        'force_https' => true,
    ),

    /* ---------- 站点互通（多站互为镜像） ---------- */
    'peers' => array(
        // 本站私钥：64 位十六进制（32 字节）。留空则互通功能整体不可用。
        // 生成命令：openssl rand -hex 32
        'private_key'   => '',
        // 需要一并互通的 settings 键（默认不同步任何设置；密钥类键永不互通）
        'sync_settings' => array(),

        // 对端站点：几个站点就列几条。已知的那条填上，其余留空即可（留空的不生效）。
        // 全部留空也行，改在控制面板「站点互通」里增删。每个对端只要：地址 + 公钥。
        'sites' => array(
            array('name' => '', 'base_url' => '', 'pubkey' => ''),   // 站点一（已知的填这里）
            array('name' => '', 'base_url' => '', 'pubkey' => ''),   // 站点二（留空）
            array('name' => '', 'base_url' => '', 'pubkey' => ''),   // 站点三（留空）
        ),
    ),

    /* ---------- 密钥（install.php 生成后自动回填，请勿手改） ---------- */
    'secrets' => array(
        'rc4_key'      => '',   // RC4 密钥（admin密钥sha256 + md5(1970101) + sha256(jgybhjhjh:jji) + 512位随机串）
        'aes_key'      => '',   // AES 密钥（100 位随机）
        'cron_key'     => '',   // 定时同步 key
    ),

    /* ---------- 管理员账户（预置） ---------- */
    'admin' => array(
        'username' => 'admin',
        // 原始密钥文本（sha256 之前）；install.php 会写入管理员库
        'secret_raw' => 'vsisgywhssis sjebehevegejdcdje euavxaxagshsceche846455185',
    ),

    /* ---------- AI 用量限额 ---------- */
    'ai_limits' => array(
        'daily_tokens'   => 100000,
        'monthly_tokens' => 3000000,
        'per_minute'     => 6,      // 登录用户每分钟调用上限
    ),

    /* ---------- 操作频次 ---------- */
    'rate_limits' => array(
        'per_minute' => 50,      // 每位用户（管理员除外）每分钟最多操作次数
    ),

    /* ---------- 安全 ---------- */
    'security' => array(
        'token_ttl'        => 604800,   // 登录 token 有效期（秒，7 天滑动）
        'guest_token_ttl'  => 86400,    // 游客 token 有效期（24h）
        'trusted_proxies'  => array(),  // 受信反向代理网段；留空=只信 REMOTE_ADDR（推荐）
        'login_max_fails'  => 5,        // 同一 IP 在锁定时长内的失败次数上限
        'login_account_max_fails' => 8, // 同一账号在锁定时长内的失败次数上限（跨 IP 累计）
        'login_lock_time'  => 600,      // 锁定时长（秒）
        'fail_delay_us'    => array(60000, 180000),  // 失败随机延迟区间（微秒）
    ),

    /* ---------- 链接守卫（发送前的本地初筛） ---------- */
    'link_guard' => array(
        'block_text' => 1,   // 文本消息命中可疑域名时是否拦截；0=只对图片生效
    ),

    'adblock' => array(
        /* 规则源：按顺序尝试，第一个成功的即采用。留空则用内置默认源。 */
        'sources' => array(
            'https://adguardteam.github.io/AdGuardSDNSFilter/Filters/filter.txt',
            'https://ghproxy.net/https://raw.githubusercontent.com/AdguardTeam/AdGuardSDNSFilter/master/Filters/filter.txt',
        ),
    ),

    /* ---------- 液态玻璃 ---------- */
    'glass' => array(
        'default_mode'  => 'css',      // css | webgl
        'default_accent' => 'blue-purple', // blue-purple | ios-colorful | custom
    ),

    /* ---------- 内容审核 ---------- */
    'moderation' => array(
        'enabled' => 1,                // 发送前审核总开关
        // 违规程度分级（10 档制）的处置阈值：误判偏多就上调，漏放偏多就下调。
        // 实测标尺：正常寒暄≈1–2，对作品的差评≈3–4，轻度粗鲁≈5，人身攻击≈6–7。
        'jev_reject_level' => 5.5,     // 达到该档位即拦截
        'jev_flag_level'   => 4.5,     // 达到该档位放行，但在内容旁标注「可能有恶意」
        'jev_sarcasm_level' => 0.95,   // 阴阳怪气 / 讽刺概率达到该值，同样标注（仍放行）
        'jev_inject_prob'   => 0.85,   // 提示词注入的判定概率；命中只标注、不拦截
        'jev_inject_benign' => 0.50,   // 「纯技术讨论」概率达到该值即视为讨论，不标注
    ),
);
