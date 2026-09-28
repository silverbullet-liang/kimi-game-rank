<?php
/**
 * 智能链接识别（BETA）
 * ------------------------------------------------------------
 * 有些作品的「页面地址」本身只是个跳转页（一句"正在前往…"，真正的作品在别处）。
 * 开启后，收录与更新时会尝试从这类页面里找出真实地址并替换。
 *
 * 保守原则（宁可不动，也不要改错）：
 *   1. 先判断这页"像不像跳转页"——要有明确的跳转信号，且正文极短；
 *   2. 候选链接里剔除常见大站与本站自身，只认资产类后缀以外的 http(s) 地址；
 *   3. 候选**有且只有一个**时才替换；0 个或多个一律保持原样。
 * 默认关闭（面板可开启，标 BETA）。
 */
declare(strict_types=1);

define('SMART_LINK_MIN_TEXT', 300);        // 去标签后的正文字数上限：超过就不当跳转页
define('SMART_LINK_ASSET_RE', '#\.(?:js|mjs|css|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|json|xml|txt|mp3|mp4|webm|map)(?:\?|$)#i');

/** 开关（默认关闭） */
function smart_link_enabled(): bool
{
    return setting_get('smart_link', '0') === '1';
}

/** 常见站点：这些域名下的链接不会被当成"真实作品地址" */
function smart_link_common_hosts(): array
{
    return array(
        // 社区 / 内容平台
        'bilibili.com', 'b23.tv', 'weibo.com', 'zhihu.com', 'xiaohongshu.com', 'douyin.com', 'kuaishou.com',
        'douban.com', 'tieba.baidu.com', 'juejin.cn', 'csdn.net', 'cnblogs.com', 'segmentfault.com',
        // 账号 / 社交 / 即时通讯
        'qq.com', 'weixin.qq.com', 'dingtalk.com', 'feishu.cn', 'telegram.org', 'discord.com',
        // 商城 / 视频
        'taobao.com', 'tmall.com', 'jd.com', 'youku.com', 'iqiyi.com', 'v.qq.com',
        // 海外常见
        'youtube.com', 'youtu.be', 'twitter.com', 'x.com',
        'facebook.com', 'instagram.com', 'tiktok.com', 'reddit.com', 'medium.com', 'notion.so',
        // 搜索 / 百科 / 通用
        'google.com', 'bing.com', 'wikipedia.org',
        // 代码托管仓库页（Pages 域另见"作品宿主"白名单）
        'github.com', 'gitee.com', 'gitlab.com',
        // 素材站 / CDN：这些域下的地址不是作品页
        'jsdelivr.net', 'unpkg.com', 'cdnjs.com', 'staticfile.org', 'bootcdn.net',
        'gstatic.com', 'googleapis.com', 'aliyuncs.com', 'myqcloud.com', 'hdslb.com', 'byteimg.com',
    );
}

/**
 * 作品宿主：这些域名下的页面本身就是作品的"家"（作者自己的部署或 AI 平台给作者生成的
 * 子站 / 分享页），因此：
 *   · 来源页落在这些域名 → 一律不当跳转页，绝不替换；
 *   · 出现在跳转页里的这类链接 → 允许作为"真实地址"候选。
 * 目的是不误伤 Kimi 与各 Agent 产品的子站、以及 GitHub Pages 这类托管。
 */
function smart_link_trusted_hosts(): array
{
    return array(
        // 静态托管 / 开发者平台
        'github.io', 'githubusercontent.com', 'gitlab.io', 'gitee.io',
        'vercel.app', 'now.sh', 'netlify.app', 'pages.dev', 'workers.dev',
        'edgeone.ai', 'edgeone.app', 'surge.sh', 'web.app', 'firebaseapp.com',
        'onrender.com', 'up.railway.app', 'fly.dev', 'herokuapp.com', 'deno.dev',
        'glitch.me', 'repl.co', 'replit.app', 'replit.dev',
        'hf.space', 'huggingface.co', 'hf.co',       // HF Spaces：入口页在 huggingface.co，应用在 *.hf.space
        'modelscope.cn',                             // 魔搭创空间
        'streamlit.app', 'gradio.live', 'devfile.cn', 'lovable.app', 'bolt.new', 'v0.dev',
        // AI 平台 / Agent 产品
        'kimi.com', 'kimi.ai', 'moonshot.cn', 'moonshot.ai',
        'z.ai', 'chatglm.cn', 'zhipuai.cn', 'bigmodel.cn', 'ima.qq.com',
        'doubao.com', 'coze.cn', 'coze.com', 'n.cn',
        'tongyi.ai', 'tongyi.aliyun.com', 'aliyun.com',
        'yuanbao.tencent.com', 'yuanqi.tencent.com',
        'agents.baidu.com', 'yiyan.baidu.com', 'baidu.com',
        'chat.deepseek.com', 'deepseek.com', 'metaso.cn', 'minimax.ai',
        'jimeng.jianying.com', 'klingai.kuaishou.com', 'youdao.com',
        'claude.ai', 'chatgpt.com', 'openai.com',
    );
}

/** 某个地址的宿主是否属于"作品宿主" */
function smart_link_host_trusted(string $url): bool
{
    $host = strtolower((string)parse_url(trim($url), PHP_URL_HOST));
    if ($host === '') { return false; }
    foreach (smart_link_trusted_hosts() as $d) {
        if ($host === $d || substr($host, -strlen('.' . $d)) === '.' . $d) { return true; }
    }
    return false;
}

/** 去掉标签、脚本、样式后的正文长度（判断"是不是只有一句话"） */
function smart_link_text_len(string $html): int
{
    $s = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
    $s = preg_replace('#<[^>]+>#', ' ', (string)$s);
    $s = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return mb_strlen(trim(preg_replace('#\s+#u', ' ', $s)), 'UTF-8');
}

/**
 * 像不像"跳转页"。要求同时满足：
 *   有明确跳转信号（meta refresh 或 JS 跳转语句）＋ 标题带跳转字样或正文极短。
 */
function smart_link_looks_like_jump(string $html): bool
{
    if ($html === '') { return false; }

    /* 页面里有画布 / 内嵌框架 / 音视频等作品特征 → 当它是真实作品页，绝不动 */
    if (preg_match('#<(canvas|iframe|video|audio)\b#i', $html)) { return false; }

    $hasRefresh = (bool)preg_match('#<meta[^>]+http-equiv\s*=\s*["\']?\s*refresh#i', $html);
    $hasJsNav   = (bool)preg_match('#(?:location\s*\.\s*(?:href|replace|assign)\b|window\s*\.\s*open\s*\(|location\s*\.\s*replace\s*\()#i', $html);
    if (!$hasRefresh && !$hasJsNav) { return false; }

    $titleJump = false;
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
        $titleJump = (bool)preg_match('#(跳转|前往|正在|即将|redirect|Redirect|leaving|Continue|继续访问)#u', $m[1]);
    }
    $shortText = smart_link_text_len($html) <= SMART_LINK_MIN_TEXT;

    return ($hasRefresh && $shortText) || ($titleJump && $shortText) || ($hasRefresh && $titleJump);
}

/** 从 HTML 里收集候选链接（绝对 http(s) 地址） */
function smart_link_candidates(string $html): array
{
    $raw = array();
    $pat = array(
        '#<meta[^>]+http-equiv\s*=\s*["\']?\s*refresh[^>]*content\s*=\s*["\']([^"\']+)["\']#i',
        '#(?:location\s*\.\s*(?:href|replace|assign)\s*=\s*|location\s*\.\s*replace\s*\(\s*|window\s*\.\s*open\s*\(\s*)["\']([^"\']+)["\']#i',
        '#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\']#i',
        '#(?:data-url|data-href)\s*=\s*["\']([^"\']+)["\']#i',
    );
    foreach ($pat as $i => $p) {
        if (!preg_match_all($p, $html, $m)) { continue; }
        foreach ($m[1] as $hit) {
            if ($i === 0) {                              // meta refresh 要再抠出 url=
                if (!preg_match('#url\s*=\s*[\'"]?([^\'"\s>;]+)#i', $hit, $mm)) { continue; }
                $hit = $mm[1];
            }
            $raw[] = trim(html_entity_decode((string)$hit, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }
    return $raw;
}

/** 归一化地址：去掉协议、www.、查询串与末尾斜杠，用于判断"是不是同一个地址" */
function smart_link_norm(string $u): string
{
    $u = (string)preg_replace('~^https?://~i', '', trim($u));
    $u = (string)preg_replace('~^www\.~i', '', $u);
    $u = (string)preg_replace('~[?#].*$~', '', $u);
    return strtolower(rtrim($u, '/'));
}

/** 候选链接是否可用（域名、后缀、与来源页同站等一律排除） */
function smart_link_acceptable(string $url, string $baseUrl): bool
{
    if (!preg_match('#^https?://#i', $url)) { return false; }
    if (preg_match(SMART_LINK_ASSET_RE, $url)) { return false; }      // 资源文件不是作品页

    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) { return false; }

    /* 与来源页同站、或就是本站的地址，都不算"真实作品地址" */
    foreach (array($baseUrl, (string)cfg('site.url', '')) as $self) {
        $h = strtolower((string)parse_url((string)$self, PHP_URL_HOST));
        if ($h !== '' && ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h)) { return false; }
    }
    /* 常见大站排除 */
    foreach (smart_link_common_hosts() as $d) {
        if ($host === $d || substr($host, -strlen('.' . $d)) === '.' . $d) { return false; }
    }
    return true;
}

/**
 * 尝试从跳转页里解析真实地址。
 * 返回 '' 表示"不确定/不需要替换"，调用方应保持原样。
 */
function smart_link_resolve(string $html, string $baseUrl): string
{
    if ($html === '' || $baseUrl === '') { return ''; }
    if (smart_link_host_trusted($baseUrl) || smart_link_host_trusted($html)) { return ''; }   // 来源本身就是作品宿主
    if (!smart_link_looks_like_jump($html)) { return ''; }

    $found = array();
    foreach (smart_link_candidates($html) as $u) {
        if (!smart_link_acceptable($u, $baseUrl)) { continue; }
        $key = smart_link_norm($u);
        if ($key !== '' && !isset($found[$key])) { $found[$key] = $u; }
    }
    if (count($found) !== 1) { return ''; }          // 唯一才敢替换

    $real = (string)reset($found);
    return smart_link_norm($real) === smart_link_norm($baseUrl) ? '' : $real;
}
