package com.kimigame.deploy;

/**
 * 高级配置清单（单一真源）。
 *
 * 这里只描述「有哪些项、什么类型、敏感与否」，不写任何具体默认值——
 * 默认值一律由源码包里的 config.sample.php / api_keys.sample.php 提供。
 * 留空 = 不覆盖样例里的值，因此表单里永远看不到、产物里也永远不会多出
 * 一个凭空写死的密钥或占位串。
 *
 * 敏感项（密钥类）在界面上以密码框显示；留空时由打包器生成随机值，
 * 绝不使用 CHANGE_ME / YOUR_ 这类占位串。
 */
final class Adv {

    static final int K_TEXT = 0;    // 普通文本
    static final int K_NUM = 1;     // 整数
    static final int K_BOOL = 2;    // 1 / 0
    static final int K_LIST = 3;    // 多值（按逗号或换行拆分 → PHP 数组）
    static final int K_SECRET = 4;  // 密钥：掩码显示；留空自动生成（若该键支持）

    static final class Item {
        final String sec;       // 段（支持 a.b 两级，如 cf_challenge.rate_limit）
        final String key;       // 键
        final String title;     // 界面标题
        final String hint;      // 界面提示（只写「留空=默认」之类，不写真实默认值）
        final int kind;

        Item(String sec, String key, String title, String hint, int kind) {
            this.sec = sec; this.key = key; this.title = title;
            this.hint = hint; this.kind = kind;
        }

        /** 顶层键不加段前缀（".debug_key" 这种写法取不到值，曾害得排障口令写不进去） */
        String path() { return sec.length() == 0 ? key : sec + "." + key; }
    }

    /* ---------------- config.php ---------------- */
    static final Item[] CFG = {
        new Item("site", "timezone", "时区", "留空用 Asia/Shanghai", K_TEXT),
        new Item("site", "force_https", "强制 HTTPS", "留空开启；站点只用 http 时填 0", K_BOOL),
        new Item("admin", "username", "管理员用户名", "留空用 admin", K_TEXT),

        new Item("ai_limits", "daily_tokens", "全站每日 token 上限", "留空用源码默认", K_NUM),
        new Item("ai_limits", "monthly_tokens", "全站每月 token 上限", "留空用源码默认", K_NUM),
        new Item("ai_limits", "per_minute", "单次对话限速", "留空用源码默认", K_NUM),
        new Item("ai_limits", "lobby_tool_rounds", "世界对话工具轮数", "留空用源码默认", K_NUM),
        new Item("ai_limits", "lobby_tool_max", "世界对话工具上限", "留空用源码默认", K_NUM),
        new Item("ai_limits", "think_model", "思考模型", "留空用源码默认", K_TEXT),
        new Item("ai_limits", "fallback_model", "兜底模型", "留空用源码默认", K_TEXT),

        new Item("rate_limits", "per_minute", "全站每分钟请求上限", "留空用源码默认", K_NUM),

        new Item("security", "token_ttl", "登录有效期（秒）", "留空用源码默认", K_NUM),
        new Item("security", "guest_token_ttl", "游客凭证有效期（秒）", "留空用源码默认", K_NUM),
        new Item("security", "api_per_minute", "单 IP 每分钟 API 上限", "留空用源码默认", K_NUM),
        new Item("security", "login_max_fails", "同一 IP 登录失败上限", "留空用源码默认", K_NUM),
        new Item("security", "login_account_max_fails", "同一账号失败上限", "留空用源码默认", K_NUM),
        new Item("security", "login_lock_time", "锁定时长（秒）", "留空用源码默认", K_NUM),
        new Item("security", "trusted_proxies", "受信代理网段", "反向代理后才填；直接对外留空", K_LIST),

        new Item("breaker", "on", "熔断开关", "1 开 / 0 关", K_BOOL),
        new Item("breaker", "rate", "熔断速率阈值", "每分钟请求数，留空用源码默认", K_NUM),
        new Item("breaker", "rate_hard", "硬阈值", "留空用源码默认", K_NUM),

        new Item("link_guard", "block_text", "文本可疑链接拦截", "1 拦 / 0 只拦图片", K_BOOL),

        new Item("adblock", "sources", "规则源地址", "一行一个；留空用源码内置源", K_LIST),

        new Item("glass", "default_mode", "玻璃默认模式", "css / webgl", K_TEXT),
        new Item("glass", "default_accent", "玻璃默认配色", "留空用源码默认", K_TEXT),

        new Item("moderation", "enabled", "发送前审核", "1 开 / 0 关", K_BOOL),
        new Item("moderation", "jev_reject_level", "拦截档位", "1–10，留空用源码默认", K_NUM),
        new Item("moderation", "jev_flag_level", "标注档位", "1–10，留空用源码默认", K_NUM),
        new Item("moderation", "jev_sarcasm_level", "讽刺判定阈值", "0–1，留空用源码默认", K_NUM),
        new Item("moderation", "jev_inject_prob", "注入判定阈值", "0–1，留空用源码默认", K_NUM),
        new Item("moderation", "jev_inject_benign", "注入放行阈值", "0–1，留空用源码默认", K_NUM),

        new Item("cf_shield", "enabled", "私有盾开关", "1 开 / 0 关", K_BOOL),
        new Item("cf_shield", "hosts", "允许的主机名", "一行一个；留空用源码默认", K_LIST),
        new Item("cf_shield", "secret", "私有盾密钥", "留空 = 盾不生效；接入 CF 后手填，与 CF 注入头的值一致", K_SECRET),
        new Item("cf_shield", "header", "校验请求头名", "留空用源码默认", K_TEXT),
        new Item("cf_shield", "mode", "校验模式", "留空用源码默认", K_TEXT),
        new Item("cf_shield", "deny", "拒绝方式", "直接拒绝/引导验证，留空用源码默认", K_TEXT),
        new Item("cf_shield", "allow", "豁免路径", "一行一个", K_LIST),

        new Item("cf_challenge", "enabled", "人机验证开关", "1 开 / 0 关", K_BOOL),
        new Item("cf_challenge", "bots_skip", "已验证爬虫放行", "1 放行 / 0 不放行", K_BOOL),
        new Item("cf_challenge", "auth", "验证接口路径", "留空用源码默认", K_TEXT),
        new Item("cf_challenge", "probe", "探测接口路径", "留空用源码默认", K_TEXT),
        new Item("cf_challenge", "write_api", "写入接口路径", "留空用源码默认", K_TEXT),
        new Item("cf_challenge", "exclude", "豁免路径", "一行一个", K_LIST),
        new Item("cf_challenge.rate_limit", "requests", "限速窗口请求数", "留空用源码默认", K_NUM),
        new Item("cf_challenge.rate_limit", "period", "限速窗口（秒）", "留空用源码默认", K_NUM),
        new Item("cf_challenge.rate_limit", "mitigation", "处置时长（秒）", "留空用源码默认", K_NUM),
        new Item("cf_challenge.rate_limit", "action", "处置方式", "留空用源码默认", K_TEXT),
    };

    /* ---------------- api_keys.php ---------------- */
    static final Item[] KEYS = {
        new Item("", "endpoint", "AI 接口地址", "留空用源码默认", K_TEXT),
        new Item("", "model", "默认模型", "留空用源码默认", K_TEXT),
        new Item("", "cooldown", "Key 冷却（秒）", "留空用源码默认", K_NUM),
        new Item("openrouter", "key", "OpenRouter Key", "留空则该来源不可用", K_SECRET),
        new Item("openrouter", "site_quota", "全站每日次数", "留空用源码默认", K_NUM),
        new Item("openrouter", "per_user_daily", "每人每日次数", "留空用源码默认", K_NUM),
        new Item("openrouter", "reasoning", "思考等级", "low / high / max", K_TEXT),
        new Item("openrouter", "timeout", "超时（秒）", "留空用源码默认", K_NUM),
        new Item("", "debug_key", "排障口令", "留空 = 排障页（check/diag 等）完全不可访问", K_SECRET),
        new Item("turnstile", "sitekey", "Turnstile sitekey", "公开值；不用就不填", K_TEXT),
        new Item("turnstile", "secret", "Turnstile secret", "只在本机；不用就不填", K_SECRET),
    };

    /** 按段分组，保持声明顺序 */
    static java.util.List<String> secList(Item[] items) {
        java.util.List<String> out = new java.util.ArrayList<String>();
        for (int i = 0; i < items.length; i++) {
            if (!out.contains(items[i].sec)) { out.add(items[i].sec); }
        }
        return out;
    }

    static String secTitle(String sec) {
        if (sec.length() == 0) { return "AI 接口"; }
        if ("ai_limits".equals(sec)) { return "AI 限额与模型"; }
        if ("rate_limits".equals(sec)) { return "全站频次"; }
        if ("security".equals(sec)) { return "安全与登录"; }
        if ("breaker".equals(sec)) { return "熔断"; }
        if ("link_guard".equals(sec)) { return "可疑链接"; }
        if ("adblock".equals(sec)) { return "规则源"; }
        if ("glass".equals(sec)) { return "外观（液态玻璃）"; }
        if ("moderation".equals(sec)) { return "内容审核"; }
        if ("cf_shield".equals(sec)) { return "私有盾"; }
        if ("cf_challenge".equals(sec)) { return "人机验证"; }
        if ("cf_challenge.rate_limit".equals(sec)) { return "人机验证 · 限速"; }
        if ("openrouter".equals(sec)) { return "OpenRouter"; }
        if ("turnstile".equals(sec)) { return "Turnstile"; }
        return sec;
    }

    /** 敏感项不入日志、不出现在导出文本里 */
    static boolean isSecret(String path, Item[] items) {
        for (int i = 0; i < items.length; i++) {
            if (items[i].path().equals(path)) { return items[i].kind == K_SECRET; }
        }
        return false;
    }

    private Adv() { }
}
