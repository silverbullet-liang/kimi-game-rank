package com.kimigame.deploy;

import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * 配置文本处理（纯 Java，无 Android 依赖 —— 因此可以在电脑上单独编译跑测试）。
 *
 * 只在 PHP 源码文本里做「段内、按键」的定点替换：不解析整个文件，
 * 也不重排任何内容，样例里的注释、顺序、未填项全部原样保留。
 */
final class Cfg {

    /** 定位段（支持 a.b 两级），返回段内绝对区间 [起, 止)；找不到返回 null */
    static int[] rangeOf(String src, String path) {
        if (path.length() == 0) { return new int[]{0, src.length()}; }
        String[] parts = path.split("\\.");
        int base = 0, end = src.length();
        for (int i = 0; i < parts.length; i++) {
            Matcher m = Pattern.compile("'" + Pattern.quote(parts[i]) + "'\\s*=>\\s*array\\s*\\(").matcher(src);
            m.region(base, end);
            if (!m.find()) { return null; }
            base = m.end();
            end = closeParen(src, base);
            if (end < 0) { return null; }
        }
        return new int[]{base, end};
    }

    /** 从 from（左括号之后）起找与之配对的右括号位置；跳过字符串 */
    static int closeParen(String s, int from) {
        int depth = 1;
        for (int i = from; i < s.length(); i++) {
            char c = s.charAt(i);
            if (c == '\'' || c == '"') { i = skipStr(s, i); continue; }
            if (c == '(') { depth++; }
            else if (c == ')') { depth--; if (depth == 0) { return i; } }
        }
        return -1;
    }

    static int skipStr(String s, int i) {
        char q = s.charAt(i);
        for (i++; i < s.length(); i++) {
            char c = s.charAt(i);
            if (c == '\\') { i++; continue; }
            if (c == q) { return i; }
        }
        return s.length() - 1;
    }

    /** 段内替换一个标量值（保留行尾注释与逗号） */
    static String setScalar(String src, int[] range, String key, String lit) {
        if (range == null) { return src; }
        /* 值取到「行内第一个逗号或右括号」为止：既支持独占一行的写法，
           也支持 'rate_limit' => array('requests' => 200, ...) 这种内联数组。 */
        Pattern p = Pattern.compile("('" + Pattern.quote(key) + "'\\s*=>\\s*)([^,)\\n]*)([,)])");
        Matcher m = p.matcher(src);
        m.region(range[0], range[1]);
        if (!m.find()) { return src; }
        return src.substring(0, m.start(2)) + lit + src.substring(m.end(2));
    }

    /** 段内把一个键整体换成数组字面量（原值单行或多行都行） */
    static String setArray(String src, int[] range, String key, String lit) {
        if (range == null) { return src; }
        Matcher m = Pattern.compile("('" + Pattern.quote(key) + "'\\s*=>\\s*)").matcher(src);
        m.region(range[0], range[1]);
        if (!m.find()) { return src; }
        int vs = m.end();
        if (src.startsWith("array", vs)) {
            int op = src.indexOf('(', vs);
            int cl = closeParen(src, op + 1);
            if (cl > 0) {
                int ce = cl + 1;
                while (ce < src.length() && src.charAt(ce) != ',' && src.charAt(ce) != '\n') { ce++; }
                return src.substring(0, vs) + lit + src.substring(ce);
            }
        }
        int nl = src.indexOf('\n', vs);
        if (nl < 0) { nl = src.length(); }
        int comma = src.lastIndexOf(',', nl);
        if (comma < vs) { comma = nl; }
        return src.substring(0, vs) + lit + src.substring(comma);
    }

    /** 取某个键当前的字面量（用于判断原本是 true/false 还是 1/0） */
    static String currentLit(String src, int[] range, String key) {
        if (range == null) { return ""; }
        Matcher m = Pattern.compile("'" + Pattern.quote(key) + "'\\s*=>\\s*([^,\n]*)").matcher(src);
        m.region(range[0], range[1]);
        return m.find() ? m.group(1).trim() : "";
    }

    static boolean truthy(String v) {
        String t = v == null ? "" : v.trim().toLowerCase();
        return "1".equals(t) || "true".equals(t) || "on".equals(t) || "yes".equals(t);
    }

    /** 多值 → PHP 数组字面量（按逗号/分号/换行拆分，去空、去重） */
    static String listLit(String v, String indent) {
        java.util.List<String> items = new java.util.ArrayList<String>();
        String[] parts = (v == null ? "" : v).split("[\\r\\n,，;；]+");
        for (int i = 0; i < parts.length; i++) {
            String x = parts[i].trim();
            if (x.length() > 0 && !items.contains(x)) { items.add(x); }
        }
        if (items.isEmpty()) { return "array()"; }
        StringBuilder sb = new StringBuilder("array(");
        for (int i = 0; i < items.size(); i++) {
            if (i > 0) { sb.append(", "); }
            sb.append(phpQuote(items.get(i)));
        }
        return sb.append(")").toString();
    }

    /**
     * 把用户填的高级项写进配置。空值一律不写——样例里原本是什么就保留什么，
     * 于是产物里既不会出现 CHANGE_ME / YOUR_ 之类占位串，也不会凭空写死某个密钥。
     * 标记 gen 的敏感项留空时生成随机值，并回写到站点（供站长查看与复制）。
     */
    static String applyAdv(String php, Adv.Item[] items, java.util.Map<String, String> adv) {
        for (int i = 0; i < items.length; i++) {
            Adv.Item it = items[i];
            String raw = adv == null ? "" : adv.get(it.path());
            String v = raw == null ? "" : raw.trim();
            if (v.length() == 0) { continue; }   /* 空 = 保持样例默认，不写任何值 */
            int[] r = rangeOf(php, it.sec);
            if (r == null) { continue; }
            if (it.kind == Adv.K_LIST) {
                php = setArray(php, r, it.key, listLit(v, "            "));
                continue;
            }
            String lit;
            if (it.kind == Adv.K_BOOL) {
                String orig = currentLit(php, r, it.key);
                boolean asWord = !orig.matches("-?\\d+");
                lit = asWord ? (truthy(v) ? "true" : "false") : (truthy(v) ? "1" : "0");
            } else if (it.kind == Adv.K_NUM) {
                if (!v.matches("-?\\d+(\\.\\d+)?")) { continue; }   /* 填错就不写，别把站点带崩 */
                lit = v;
            } else {
                lit = phpQuote(v);
            }
            php = setScalar(php, r, it.key, lit);
        }
        return php;
    }


    /** 从域名里取主机名（去协议、去路径与端口） */
    static String hostOf(String url) {
        String u = url == null ? "" : url.trim();
        int i = u.indexOf("://");
        if (i >= 0) { u = u.substring(i + 3); }
        int j = u.indexOf('/');
        if (j >= 0) { u = u.substring(0, j); }
        int c = u.indexOf(':');
        if (c >= 0) { u = u.substring(0, c); }
        return u.trim();
    }

    /**
     * 收尾：把样例里那些「给人看的占位」换成真实值或空值。
     *
     * 1. 私有盾的 hosts 原本是 your-domain.example —— 留着它，站长即使填了密钥，
     *    盾也会因为主机名对不上而静默失效；这里改用真实域名，取不到就写成空数组（盾不生效）。
     * 2. url 若仍是 your-domain.com（站点名没填域名时），一并留空，避免产物里带假地址。
     */
    static String sealConfig(String php, String domain) {
        String host = hostOf(domain);
        int[] shield = rangeOf(php, "cf_shield");
        if (shield != null) {
            if (host.length() > 0) {
                php = setArray(php, shield, "hosts", "array(" + phpQuote(host) + ")");
            } else if (php.contains("your-domain.example")) {
                php = setArray(php, shield, "hosts", "array()");
            }
        }
        if (php.contains("https://your-domain.com")) {
            int[] site = rangeOf(php, "site");
            php = setScalar(php, site, "url", phpQuote(host.length() > 0 ? "https://" + host : ""));
        }
        return php;
    }

    /**
     * api_keys 收尾：样例里的 CHANGE_ME_DEBUG_KEY 是一个「谁都猜得到」的口令，
     * 留着等于把 check.php / diag.php 这些排障页对着全网开放。没填就置空 ——
     * 空口令在站点侧等于「完全不可访问」，这是最安全的默认。
     */
    static String sealApiKeys(String php, java.util.Map<String, String> adv) {
        String v = adv == null ? "" : adv.get("debug_key");
        if (v == null || v.trim().length() == 0) {
            php = setScalar(php, rangeOf(php, ""), "debug_key", phpQuote(""));
        }
        return php;
    }

    static String phpQuote(String s) {
        return "'" + (s == null ? "" : s).replace("\\", "\\\\").replace("'", "\\'") + "'";
    }

    private Cfg() { }
}
