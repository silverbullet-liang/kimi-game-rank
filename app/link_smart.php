<?php
/**
 * 智能链接识别
 * ------------------------------------------------------------
 * 有些作品的「页面地址」本身只是个跳转页：一句标题、一个按钮（「开始游戏」），
 * 真正的作品在别处。开启后，收录与更新时会尝试从这类页面里找出真实地址并替换，
 * 后续的评分、标题、特征全部基于**真实页面**。
 *
 * 判定思路（结构，而不是字符串匹配）：
 *   0. 强信号旁路：页面自身不承载作品，只有一个可点元素，且它指向「平台分享型托管域名」
 *      ——作品介绍卡 + 跳转按钮，文案长、带装饰动画也认；
 *   1. 否则三条硬条件：页面只有一段标题或简介（可见文字极短）；
 *   2. 只有一个按钮 / 链接（只有一个去处）；
 *   3. 页面脚本很短，且不含动画 / 渲染引擎特征——短到只可能是那个按钮的跳转代码；
 *   4. 真实地址从该按钮的脚本（或 href）里提取。
 *
 * 候选取舍：
 *   · 先剔除视频页、AI 对话分享页、各种 API 地址（见 smart_link_excluded）；
 *   · 多个候选时按域名优先级取最高的那个：kimi.link / kimi.site 最优先，
 *     其次是各家 AI 平台的托管域名，最后才是普通域名。
 *
 * 默认开启（可在控制面板关闭）。
 */
declare(strict_types=1);

define('SMART_LINK_MAX_TEXT', 120);      // 可见文字上限：超过就不像「只有标题或简介」
define('SMART_LINK_MAX_JS', 4000);       // 内联脚本体量上限：超过就不像「只有按钮的代码」
define('SMART_LINK_ASSET_RE', '#\.(?:js|mjs|css|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|json|xml|txt|mp3|mp4|webm|map)(?:\?|$)#i');

/** 开关（默认开启） */
function smart_link_enabled(): bool
{
    return setting_get('smart_link', '1') === '1';
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
        'taobao.com', 'tmall.com', 'jd.com', 'youku.com', 'iqiyi.com', 'v.qq.com', 'mgtv.com',
        'ixigua.com', 'haokan.baidu.com', 'pearvideo.com', 'vimeo.com', 'dailymotion.com',
        // 海外常见
        'youtube.com', 'youtu.be', 'twitter.com', 'x.com', 'tiktok.com',
        'facebook.com', 'instagram.com', 'reddit.com', 'medium.com', 'notion.so',
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
 * 作品宿主：这些域名下的页面本身就是作品的"家"（作者自己的部署，或 AI 平台为作者生成的
 * 子站 / 分享页），因此：
 *   · 来源页落在这些域名 → 一律不当跳转页，绝不替换；
 *   · 出现在跳转页里的这类链接 → 是"真实地址"的强候选（优先级高于普通域名）。
 */
function smart_link_trusted_hosts(): array
{
    return array(
        // ---- AI 平台发布的网站 / 应用 ----
        'kimi.link',                                 // Kimi 网站一键发布（形如 abc.ok.kimi.link）
        'kimi.site', 'miaoda.online', 'miaoda.cn',   // Kimi 另一域名 / 妙搭
        'appmiaoda.com',                             // 百度秒哒托管
        'upma.site',                                 // 小众静态部署
        'coze.site',                                 // 扣子编程
        'claude.site',                               // Claude Artifacts 发布
        'lovable.app', 'lovable.dev', 'bolt.host',   // 主流 AI 建站
        'figma.site', 'base44.app', 'notion.site',   // 设计 / 建站平台
        'ai.studio', 'aistudio.google.com',          // Google AI Studio 部署的应用
        // ---- 静态托管 / 开发者平台 ----
        'github.io', 'githubusercontent.com', 'gitlab.io', 'gitee.io',
        'vercel.app', 'now.sh', 'netlify.app', 'pages.dev', 'workers.dev',
        'edgeone.ai', 'edgeone.app', 'surge.sh', 'web.app', 'firebaseapp.com',
        'onrender.com', 'up.railway.app', 'fly.dev', 'herokuapp.com', 'deno.dev',
        'glitch.me', 'repl.co', 'replit.app', 'replit.dev',
        'hf.space', 'huggingface.co', 'hf.co',       // HF Spaces：入口页在 huggingface.co，应用在 *.hf.space
        'modelscope.cn',                             // 魔搭创空间
        'streamlit.app', 'gradio.live', 'devfile.cn', 'lovable.app', 'bolt.new', 'v0.dev',
        // ---- AI 平台 / Agent 产品 ----
        /* 只认对话分享这等作者产物所在的子域，不用泛域 moonshot.cn：
           泛域会把 kimi-file.moonshot.cn 这种「存放作品 HTML 的文件 CDN」一并当成
           作品宿主，而那里的 HTML 本身完全可能是张跳转卡，正需要识别。 */
        'kimi.com', 'kimi.ai', 'kimi.moonshot.cn', 'moonshot.ai',
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

/** 宿主是否属于给定域列表之一（含子域） */
function smart_link_host_in(string $host, array $domains): bool
{
    $host = strtolower($host);
    if ($host === '') { return false; }
    foreach ($domains as $d) {
        if ($host === $d || substr($host, -strlen('.' . $d)) === '.' . $d) { return true; }
    }
    return false;
}

/* ============================================================
 * 一、结构判定：这页是不是「一句标题 + 一个按钮」的跳转页
 * ============================================================ */

/** 去掉脚本、样式与标签后的可见文字 */
function smart_link_visible_text(string $html): string
{
    $s = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
    $s = preg_replace('#<[^>]+>#', ' ', (string)$s);
    $s = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('#\s+#u', ' ', (string)$s));
}

/** 可见文字长度 */
function smart_link_text_len(string $html): int
{
    return mb_strlen(smart_link_visible_text($html), 'UTF-8');
}

/**
 * 从一个标签的属性与内容里解析出「可点元素」；不可点的返回 null。
 * div / span 只有带 onclick / data-url 才算——纯布局容器不算按钮，
 * 否则一张卡片会被数成十几个按钮。
 */
function smart_link_node(string $attr, string $inner, string $tag)
{
    $href = '';
    if (preg_match('#\bhref\s*=\s*["\']([^"\']*)["\']#i', $attr, $h)) { $href = trim($h[1]); }

    $onclick = '';
    if (preg_match('#\bonclick\s*=\s*["\']([^"\']*)["\']#i', $attr, $o)) { $onclick = trim($o[1]); }

    $dataUrl = '';
    if (preg_match('#\bdata-(?:url|href)\s*=\s*["\']([^"\']*)["\']#i', $attr, $d)) { $dataUrl = trim($d[1]); }

    $text = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string)preg_replace('#\s+#u', ' ', $text));

    /* 可点：带 href / onclick / data-url，或是带文案的 button（去向常由脚本绑在按钮上） */
    $clickable = ($href !== '' || $onclick !== '' || $dataUrl !== '')
                 || ($tag === 'button' && $text !== '');
    if (!$clickable) { return null; }

    return array('text' => $text, 'href' => $href, 'onclick' => $onclick, 'data' => $dataUrl, 'tag' => $tag);
}

/**
 * 收集页面里「可点」的元素：<a>、<button>，以及自带 onclick / data-url 的 div、span。
 * 返回 array(array('text' => 文案, 'href' => 地址, 'onclick' => 代码, 'data' => 地址, 'tag' => 标签), …)。
 *
 * 这里刻意让 a / button 各自独立配对：若把它们与 div / span 塞进同一条交替正则，
 * 外层 <div> 的非贪婪闭合会先匹配到页面里第一个 </div>，把区间内的 <a> 一并吞掉
 * （同一段文本只被匹配一次），按钮因此漏数。div / span 只扫开标签，不做配对。
 */
function smart_link_buttons(string $html): array
{
    $html = (string)preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);

    $found = array();
    foreach (array('a', 'button') as $tag) {
        if (preg_match_all('#<' . $tag . '\b([^>]*)>(.*?)</' . $tag . '>#is', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $one) {
                $node = smart_link_node((string)$one[1][0], (string)$one[2][0], $tag);
                if ($node !== null) { $found[$one[0][1]] = $node; }
            }
        }
    }
    if (preg_match_all('#<(div|span)\b([^>]*)>#is', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $one) {
            $node = smart_link_node((string)$one[2][0], '', (string)$one[1][0]);
            if ($node !== null) { $found[$one[0][1]] = $node; }
        }
    }
    ksort($found);                       // 按在页面里出现的顺序返回
    return array_values($found);
}
/**
 * 内联脚本统计：返回 array(len => 字符数, animated => 是否含动画/渲染特征).
 * 跳转页的脚本短到只可能是那一行跳转；含动画或 3D 渲染的一律不当跳转页。
 */
function smart_link_js_stats(string $html): array
{
    $len = 0;
    $animated = false;
    if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $m)) {
        foreach ($m[1] as $js) {
            $len += strlen((string)$js);
            if (preg_match('#(requestAnimationFrame|setInterval|new\s+THREE|WebGLRenderingContext|three\.module|gsap\.|anime\.|lottie)#i', (string)$js)) {
                $animated = true;
            }
        }
    }
    /* 样式里的关键帧动画同样说明它是内容页而非跳转页 */
    if (preg_match('#@keyframes#i', $html)) { $animated = true; }
    return array('len' => $len, 'animated' => $animated);
}

/**
 * 强信号旁路：页面自身不承载作品（没有画布 / 内嵌框架 / 音视频），只有一个可点元素，
 * 且它指向「平台分享型托管域名」（优先级 ≥ 20：kimi.link、miaoda.online、coze.site、
 * claude.site、ai.studio 等）。这是典型的作品介绍卡 + 跳转按钮——文案长、带装饰动画，
 * 走不了下面三条硬条件，但去路足够明确。
 *
 * 刻意只认平台分享型域名：作者自建类宿主（github.io / vercel.app 等）不在此列，
 * 因为真作品页里放一个自建站外链是很常见的事，纳入进来容易误判。
 */
function smart_link_is_share_hop(string $html): bool
{
    if (preg_match('#<(canvas|iframe|video|audio)\b#i', $html)) { return false; }
    $btns = smart_link_buttons($html);
    if (count($btns) !== 1) { return false; }
    foreach (array('href', 'data') as $k) {
        $v = (string)$btns[0][$k];
        if (preg_match('#^https?://#i', $v) && smart_link_priority($v) >= 20) { return true; }
    }
    return false;
}

/**
 * 像不像「跳转页」。
 *
 * 先说旁路：介绍卡 + 唯一的平台分享型按钮，直接成立。
 * 否则三条硬条件同时满足才成立：
 *   ① 可见文字 ≤ SMART_LINK_MAX_TEXT（只有一句标题或简介）
 *   ② 可点元素恰好 1 个（只有一个按钮）
 *   ③ 内联脚本 ≤ SMART_LINK_MAX_JS 且不含动画 / 渲染特征（短到只有按钮的代码）
 * 另外：页面含画布 / 内嵌框架 / 音视频 → 直接当真实作品页，绝不动。
 */
function smart_link_looks_like_jump(string $html): bool
{
    if ($html === '') { return false; }

    /* 旁路优先：介绍卡 + 唯一的平台分享型按钮 */
    if (smart_link_is_share_hop($html)) { return true; }

    if (preg_match('#<(canvas|iframe|video|audio)\b#i', $html)) { return false; }

    if (smart_link_text_len($html) > SMART_LINK_MAX_TEXT) { return false; }

    $btns = smart_link_buttons($html);
    if (count($btns) > 1) { return false; }
    if (count($btns) === 0) {
        /* 一个按钮都没有也认，但前提是页面里存在明确的跳转机制
           （纯 meta refresh 页就是这样，不该因为"没按钮"被放过）。 */
        $hasMech = (bool)preg_match('#<meta[^>]+http-equiv\s*=\s*["\']?\s*refresh#i', $html)
                || (bool)preg_match('#(?:location\s*\.\s*(?:href|replace|assign)|window\s*\.\s*open\s*\(|location\s*\.\s*replace\s*\()#i', $html);
        if (!$hasMech) { return false; }
    }

    $js = smart_link_js_stats($html);
    if ($js['len'] > SMART_LINK_MAX_JS || $js['animated']) { return false; }

    return true;
}

/* ============================================================
 * 二、候选地址的提取与剔除
 * ============================================================ */

/** 从任意文本（脚本、属性）里抠出 http(s) 地址；同时容忍被转义的写法 */
function smart_link_urls_in(string $text): array
{
    $out = array();
    $t = str_replace(array('\\/', '\\u002F', '\\u002f', '&amp;'), array('/', '/', '/', '&'), $text);
    if (preg_match_all('#https?://[^\s"\'<>)\\}\\]]+#i', $t, $m)) {
        foreach ($m[0] as $u) {
            $u = trim($u, " \t\n\r\0\x0B.,;");
            if ($u !== '') { $out[] = $u; }
        }
    }
    return $out;
}

/** 视频页路径特征：这些是"作品本体的家"以外的东西，一律不当作真实地址 */
function smart_link_video_re(): string
{
    return '#(?:'
        // B 站：视频 / 番剧 / 短链
        . '(?://|\.)bilibili\.com/(?:video|bangumi|read)/'
        . '|(?://|\.)b23\.tv/'
        // 抖音 / 快手 / 小红书
        . '|(?://|\.)douyin\.com/(?:video|note)/|(?://|\.)iesdouyin\.com/share/video/|(?://|\.)v\.douyin\.com/'
        . '|(?://|\.)kuaishou\.com/(?:short-video|f)/|(?://|\.)v\.kuaishou\.com/|(?://|\.)gifshow\.com/fw/photo/'
        . '|(?://|\.)xiaohongshu\.com/(?:explore|discovery/item)/|(?://|\.)xhslink\.com/'
        // 长视频 / 海外
        . '|(?://|\.)youtube\.com/(?:watch|shorts|embed)/|(?://|\.)youtu\.be/'
        . '|(?://|\.)youku\.com/v_show/|(?://|\.)iqiyi\.com/|(?://|\.)iq\.com/play/'
        . '|(?://|\.)v\.qq\.com/x/|(?://|\.)mgtv\.com/b/|(?://|\.)ixigua\.com/'
        . '|(?://|\.)haokan\.baidu\.com/v|(?://|\.)pearvideo\.com/video_'
        . '|(?://|\.)vimeo\.com/|(?://|\.)tiktok\.com/@|(?://|\.)vm\.tiktok\.com/'
        . '|(?://|\.)weibo\.com/|(?://|\.)video\.weibo\.com/'
        . '|(?://|\.)weixin\.qq\.com/sph/'
        . ')#i';
}

/** AI 对话分享页特征：那是"一段对话"，不是作品 */
function smart_link_chat_share_re(): string
{
    return '#(?:'
        . '(?://|\.)chatgpt\.com/(?:share|s)/'                    // 分享会话 / 排程任务
        . '|(?://|\.)claude\.ai/(?:share|public/artifacts)/'      // 分享会话
        . '|(?://|\.)gemini\.google\.com/share/|(?://|\.)g\.co/gemini/share/'
        . '|(?://|\.)chat\.deepseek\.com/share/'
        . '|(?://|\.)doubao\.com/thread/'
        . '|(?://|\.)kimi\.com/share/|(?://|\.)kimi\.moonshot\.cn/share/'
        . '|(?://|\.)grok\.com/share/|(?://|\.)chat\.mistral\.ai/share/'
        . '|(?://|\.)perplexity\.ai/.*-share|(?://|\.)yuanbao\.tencent\.com/share/'
        . ')#i';
}

/** API 端点特征：按规则识别，不穷举域名 */
function smart_link_is_api(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $path = strtolower((string)parse_url($url, PHP_URL_PATH));
    if ($host === '') { return false; }

    /* 主机名以 api. / platform. / open. 开头 */
    if (preg_match('#^(?:api|platform|open|gateway|endpoint)\.#i', $host)) { return true; }
    if (strpos($host, 'api.') === 0) { return true; }

    /* 路径特征 */
    foreach (array('/api/', '/graphql', '/openapi', '/swagger', '/chat/completions',
                   '/v1/chat', '/embeddings', '/v1/messages', '/api-docs') as $needle) {
        if (strpos($path, $needle) !== false) { return true; }
    }
    return false;
}

/** 候选地址是否应当被剔除 */
function smart_link_excluded(string $url): bool
{
    if (preg_match(SMART_LINK_ASSET_RE, $url)) { return true; }          // 资源文件
    if (smart_link_is_api($url)) { return true; }                        // API 地址
    if (preg_match(smart_link_video_re(), $url)) { return true; }        // 视频页
    if (preg_match(smart_link_chat_share_re(), $url)) { return true; }   // AI 对话分享页
    return false;
}

/** 候选链接是否可用（域名、后缀、与来源页同站等一律排除） */
function smart_link_acceptable(string $url, string $baseUrl): bool
{
    if (!preg_match('#^https?://#i', $url)) { return false; }
    if (smart_link_excluded($url)) { return false; }

    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) { return false; }

    /* 与来源页同站、或就是本站的地址，都不算"真实作品地址" */
    foreach (array($baseUrl, (string)cfg('site.url', '')) as $self) {
        $h = strtolower((string)parse_url((string)$self, PHP_URL_HOST));
        if ($h !== '' && ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h)) { return false; }
    }
    /* 常见大站排除 */
    if (smart_link_host_in($host, smart_link_common_hosts())) { return false; }
    return true;
}

/**
 * 候选优先级：数值越大越优先。
 * 用户实际遇到的多是 Kimi 系跳转页，所以 kimi.link / kimi.site 置顶；
 * 其次是妙搭、upma 这类平台托管，再到通用静态托管。
 */
function smart_link_priority(string $url): int
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') { return 0; }
    if (smart_link_host_in($host, array('kimi.link', 'kimi.site'))) { return 40; }
    if (smart_link_host_in($host, array('miaoda.online', 'miaoda.cn', 'appmiaoda.com', 'upma.site'))) { return 30; }
    if (smart_link_host_in($host, array('coze.site', 'claude.site', 'ai.studio', 'figma.site',
                                       'base44.app', 'notion.site', 'lovable.app', 'bolt.host'))) { return 20; }
    if (smart_link_host_trusted($url)) { return 10; }
    return 1;
}

/** 归一化地址：去掉协议、www.、查询串与末尾斜杠，用于判断"是不是同一个地址" */
function smart_link_norm(string $u): string
{
    $u = (string)preg_replace('~^https?://~i', '', trim($u));
    $u = (string)preg_replace('~^www\.~i', '', $u);
    $u = (string)preg_replace('~[?#].*$~', '', $u);
    return strtolower(rtrim($u, '/'));
}

/** 相对地址补全成绝对地址 */
function smart_link_abs(string $url, string $baseUrl): string
{
    $url = trim($url);
    if ($url === '' || preg_match('#^https?://#i', $url)) { return $url; }
    if (strpos($url, '//') === 0) { return 'https:' . $url; }
    $p = parse_url($baseUrl);
    if (!is_array($p) || empty($p['host'])) { return ''; }
    $scheme = isset($p['scheme']) ? $p['scheme'] : 'https';
    if (strpos($url, '/') === 0) { return $scheme . '://' . $p['host'] . $url; }
    $dir = isset($p['path']) ? (string)preg_replace('#/[^/]*$#', '/', (string)$p['path']) : '/';
    return $scheme . '://' . $p['host'] . $dir . $url;
}

/* ============================================================
 * 三、主入口
 * ============================================================ */

/**
 * 尝试从跳转页里解析真实地址。
 * 返回 '' 表示"不确定/不需要替换"，调用方应保持原样。
 */
function smart_link_resolve(string $html, string $baseUrl): string
{
    if ($html === '' || $baseUrl === '') { return ''; }
    if (smart_link_host_trusted($baseUrl)) { return ''; }   // 来源本身就是作品宿主
    if (!smart_link_looks_like_jump($html)) { return ''; }

    /* 候选来源：① 那个按钮自己的 href / data-url / onclick；② 全页内联脚本。
       因为脚本被判为"极短"，整段脚本里的地址可以放心当作这个按钮的去向。 */
    $raw = array();
    $btns = smart_link_buttons($html);
    foreach ($btns as $b) {
        /* href / data-url：可能是绝对地址，也可能是同站相对路径（跳转页自己就是个入口页） */
        foreach (array('href', 'data') as $k) {
            $v = isset($b[$k]) ? trim((string)$b[$k]) : '';
            if ($v === '' || $v[0] === '#') { continue; }
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $v) && !preg_match('#^https?://#i', $v)) { continue; }
            $raw[] = preg_match('#^https?://#i', $v) ? $v : smart_link_abs($v, $baseUrl);
        }
        if (!empty($b['onclick'])) {
            foreach (smart_link_urls_in((string)$b['onclick']) as $u) { $raw[] = $u; }
        }
    }
    if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $sm)) {
        foreach ($sm[1] as $js) {
            foreach (smart_link_urls_in((string)$js) as $u) { $raw[] = $u; }
        }
    }
    /* 顺带认一下 meta refresh —— 有些跳转页只用它 */
    if (preg_match_all('#<meta[^>]+http-equiv\s*=\s*["\']?\s*refresh[^>]*content\s*=\s*["\']([^"\']+)["\']#i', $html, $mm)) {
        foreach ($mm[1] as $c) {
            if (preg_match('#url\s*=\s*[\'"]?([^\'"\s>;]+)#i', $c, $u2)) { $raw[] = $u2[1]; }
        }
    }

    /* 过滤 + 去重（保留首次出现的原样地址） */
    $found = array();
    foreach ($raw as $u) {
        $u = smart_link_abs((string)$u, $baseUrl);
        if ($u === '' || !smart_link_acceptable($u, $baseUrl)) { continue; }
        $key = smart_link_norm($u);
        if ($key !== '' && $key !== smart_link_norm($baseUrl) && !isset($found[$key])) {
            $found[$key] = $u;
        }
    }
    if (empty($found)) { return ''; }

    /* 多个候选时：取域名优先级最高的那个；同级多个则取出现顺序里的第一个 */
    $best = '';
    $bestRank = -1;
    foreach ($found as $u) {
        $r = smart_link_priority($u);
        if ($r > $bestRank) { $bestRank = $r; $best = $u; }
    }
    return $best;
}
