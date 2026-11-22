package com.kimigame.deploy;

import java.io.File;
import java.io.FileOutputStream;
import java.nio.charset.Charset;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * 配置写入的自检（纯 Java，可在电脑上直接跑）。
 *
 * 判据一：什么都不填时，输出必须与样例逐字节相同 —— 说明工具不会偷偷写入任何默认值、
 *        占位串或密钥。
 * 判据二：每一项单独填值时，只有目标键变化，其它键（尤其同名的 db.name / site.name）
 *        一个都不许动。
 * 判据三：产物交给 PHP 解析，键集合必须与样例完全一致（缺项 = 部署后站点缺配置）。
 *
 * 运行：javac -d /tmp/cfg src/com/kimigame/deploy/Cfg.java src/com/kimigame/deploy/Adv.java test/CfgTest.java
 *      java -cp /tmp/cfg com.kimigame.deploy.CfgTest <项目根> <输出目录>
 */
public final class CfgTest {

    static final Charset UTF8 = Charset.forName("UTF-8");
    static int pass = 0, fail = 0;

    public static void main(String[] args) throws Exception {
        File root = new File(args.length > 0 ? args[0] : ".");
        File out = new File(args.length > 1 ? args[1] : "/tmp/cfgout");
        out.mkdirs();

        String cfg = read(new File(root, "config/config.sample.php"));
        String keys = read(new File(root, "config/api_keys.sample.php"));

        /* 1. 空配置 = 原样输出 */
        ck(cfg.equals(Cfg.applyAdv(cfg, Adv.CFG, new LinkedHashMap<String, String>())),
                "空配置时 config 逐字节不变");
        ck(keys.equals(Cfg.applyAdv(keys, Adv.KEYS, new LinkedHashMap<String, String>())),
                "空配置时 api_keys 逐字节不变");

        /* 2. 站点名只能落在 site 段：db.name 必须保持原样 */
        LinkedHashMap<String, String> a = new LinkedHashMap<String, String>();
        a.put("site.timezone", "Asia/Tokyo");
        a.put("site.force_https", "0");
        a.put("admin.username", "boss");
        a.put("security.api_per_minute", "200");
        a.put("security.trusted_proxies", "10.0.0.0/8\n172.16.0.0/12");
        a.put("moderation.jev_reject_level", "6.5");
        a.put("breaker.on", "0");
        a.put("cf_shield.enabled", "false");
        a.put("cf_shield.allow", "cron.php, api/peer.php");
        a.put("cf_challenge.rate_limit.requests", "99");
        a.put("adblock.sources", "https://a.example/f.txt\nhttps://b.example/f.txt");
        a.put("link_guard.block_text", "0");

        int[] site = Cfg.rangeOf(cfg, "site");
        String withName = Cfg.setScalar(cfg, site, "name", Cfg.phpQuote("测试站"));
        String outCfg = Cfg.applyAdv(withName, Adv.CFG, a);

        int[] db = Cfg.rangeOf(outCfg, "db");
        int[] s2 = Cfg.rangeOf(outCfg, "site");
        ck("'kimi_rank'".equals(Cfg.currentLit(outCfg, db, "name")), "db.name 未被站点名覆盖");
        ck("'测试站'".equals(Cfg.currentLit(outCfg, s2, "name")), "site.name 已写入");
        ck("'Asia/Tokyo'".equals(Cfg.currentLit(outCfg, s2, "timezone")), "时区写入");
        ck("false".equals(Cfg.currentLit(outCfg, s2, "force_https")), "布尔按原词形写 false");
        ck("'boss'".equals(Cfg.currentLit(outCfg, Cfg.rangeOf(outCfg, "admin"), "username")), "管理员用户名写入");
        ck("200".equals(Cfg.currentLit(outCfg, Cfg.rangeOf(outCfg, "security"), "api_per_minute")), "数字写入");
        ck("6.5".equals(Cfg.currentLit(outCfg, Cfg.rangeOf(outCfg, "moderation"), "jev_reject_level")), "小数写入");
        ck("0".equals(Cfg.currentLit(outCfg, Cfg.rangeOf(outCfg, "breaker"), "on")), "整数布尔写 0");
        ck("99".equals(Cfg.currentLit(outCfg, Cfg.rangeOf(outCfg, "cf_challenge.rate_limit"), "requests")),
                "两级段写入");
        ck(outCfg.contains("array('https://a.example/f.txt', 'https://b.example/f.txt')"), "列表写入为 PHP 数组");
        ck(outCfg.contains("array('cron.php', 'api/peer.php')"), "豁免路径写入");
        ck(outCfg.contains("array('10.0.0.0/8', '172.16.0.0/12')"), "代理网段写入");

        /* 3. 没填的键必须保持样例原值 */
        ck(outCfg.contains("'daily_tokens'"), "未填的 ai_limits 仍存在");
        ck(!outCfg.contains("CHANGE_ME"), "产物里没有 CHANGE_ME 占位串");
        ck(!outCfg.contains("YOUR_"), "产物里没有 YOUR_ 占位串");

        /* 4. 非法数字不落盘 */
        LinkedHashMap<String, String> bad = new LinkedHashMap<String, String>();
        bad.put("security.api_per_minute", "many");
        ck(cfg.equals(Cfg.applyAdv(cfg, Adv.CFG, bad)), "非数字输入被忽略，不写坏配置");

        /* 5. api_keys：只写填了的值 */
        LinkedHashMap<String, String> k = new LinkedHashMap<String, String>();
        k.put("openrouter.site_quota", "30");
        k.put("turnstile.sitekey", "0xABCDEF");
        k.put("debug_key", "s3cret");
        /* 与 Pack.writeApiKeys 同序：先把 OpenRouter key 置空，再套用用户填的值 */
        String cleared = Cfg.setScalar(keys, Cfg.rangeOf(keys, "openrouter"), "key", Cfg.phpQuote(""));
        String outKeys = Cfg.applyAdv(cleared, Adv.KEYS, k);
        ck(outKeys.contains("'site_quota'      => 30"), "api_keys 数字写入");
        ck(outKeys.contains("'0xABCDEF'"), "sitekey 写入");
        ck(outKeys.contains("'s3cret'"), "排障口令写入（仅在站长主动填时）");
        ck("''".equals(Cfg.currentLit(outKeys, Cfg.rangeOf(outKeys, "openrouter"), "key")), "未填的 OpenRouter key 保持空");

        /* 6. 收尾：占位域名换成真实域名、CHANGE_ME 口令清空 */
        String sealed = Cfg.sealConfig(withName, "https://My-Site.example/path");
        ck(sealed.contains("array('My-Site.example')"), "盾 hosts 用真实域名替换占位");
        ck(!sealed.contains("your-domain.example"), "产物里不再有占位域名");
        String kFilled = Cfg.sealApiKeys(outKeys, k);
        ck("'s3cret'".equals(Cfg.currentLit(kFilled, Cfg.rangeOf(kFilled, ""), "debug_key")),
                "填了排障口令时保留填写值");
        String kEmpty = Cfg.sealApiKeys(cleared, new LinkedHashMap<String, String>());
        ck("''".equals(Cfg.currentLit(kEmpty, Cfg.rangeOf(kEmpty, ""), "debug_key")),
                "未填时清空 CHANGE_ME 占位口令");
        ck(Cfg.hostOf("https://a.b.cn:8443/x").equals("a.b.cn"), "hostOf 取主机名");

        write(new File(out, "config.php"), outCfg);
        write(new File(out, "api_keys.php"), outKeys);

        System.out.println(fail == 0 ? "\n全部 " + pass + " 项通过" : "\n失败 " + fail + " / " + (pass + fail));
        if (fail != 0) { System.exit(1); }
    }

    static void ck(boolean ok, String label) {
        if (ok) { pass++; System.out.println("  ok   " + label); }
        else { fail++; System.out.println("  FAIL " + label); }
    }

    static String read(File f) throws Exception {
        java.io.FileInputStream in = new java.io.FileInputStream(f);
        java.io.ByteArrayOutputStream b = new java.io.ByteArrayOutputStream();
        byte[] buf = new byte[65536];
        int n;
        while ((n = in.read(buf)) > 0) { b.write(buf, 0, n); }
        in.close();
        return new String(b.toByteArray(), UTF8);
    }

    static void write(File f, String s) throws Exception {
        FileOutputStream o = new FileOutputStream(f);
        o.write(s.getBytes(UTF8));
        o.close();
    }

    private CfgTest() { }
}
