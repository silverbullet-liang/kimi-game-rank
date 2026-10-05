package com.kimigame.deploy;

import android.content.Context;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.nio.charset.Charset;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.util.ArrayList;
import java.util.List;

/**
 * 站点清单与全局状态。
 *
 * 一处状态，界面只读它、改它，然后重绘 —— 不做双份状态，避免「界面和实际不一致」。
 * 落盘：filesDir/sites.json（应用私有目录，卸载即清）。
 */
final class Store {

    static final Charset UTF8 = Charset.forName("UTF-8");

    /* ---------- 全局：最初的选择决定列表底部的按钮 ---------- */
    static final String MODE_AUTO = "auto";     // 自动部署（再选通道：SSH / FTP）
    static final String MODE_MANUAL = "manual"; // 下载后手动

    static String mode = MODE_AUTO;
    static String conn = "ftp";                 // auto 模式下的通道：ftp | ssh

    /* ---------- 单个站点 ---------- */
    static final class Site {
        String id = "";
        String name = "";              // 站点名（压缩包命名 / 列表显示）
        String domain = "";            // 主域名（不带结尾斜杠）
        String version = "";           // 源码版本（tag）
        String note = "";              // 上次结果/状态

        // 数据库（必填）
        String dbHost = "";
        String dbPort = "3306";
        String dbName = "";            // 用户数据库名
        String dbAdminName = "";       // 管理员数据库名
        String dbLogin = "";           // 数据库账号
        String dbPass = "";

        // 管理员密钥：原文进 config；sha256 是登录密码
        String adminRaw = "";

        // 选填
        String aiKey = "";
        String pageSize = "";

        // 连接信息（自动部署用；手动模式可留空）
        String conn = "ftp";           // ftp | ssh
        String host = "";
        String port = "";              // 空则用默认（FTP 21 / SSH 22）
        String login = "";
        String pass = "";
        String remoteDir = "";         // 目标目录，默认站点根
        String sshPriv = "";           // SSH 私钥（选填，填了优先于口令）
        String ftpMode = "passive";    // FTP 数据连接方式：passive（被动 PASV）| active（主动 PORT）

        // 互通密钥（生成后固定，不随编辑变化）
        String privKey = "";
        String pubKey = "";
        boolean paired = false;        // 是否已写入对端条目

        String adminSha() { return sha256Hex(adminRaw); }

        String portOrDefault() {
            if (port != null && port.trim().length() > 0) { return port.trim(); }
            return "ssh".equals(conn) ? "22" : "21";
        }

        /** 还缺什么（用于列表提示与配对前置检查） */
        List<String> missing() {
            List<String> miss = new ArrayList<String>();
            if (blank(name)) { miss.add("站点名"); }
            if (blank(domain)) { miss.add("主域名"); }
            if (blank(dbHost)) { miss.add("数据库主机"); }
            if (blank(dbName)) { miss.add("用户数据库名"); }
            if (blank(dbAdminName)) { miss.add("管理员数据库名"); }
            if (blank(dbLogin)) { miss.add("数据库账号"); }
            if (blank(dbPass)) { miss.add("数据库密码"); }
            if (blank(adminRaw)) { miss.add("管理员密钥"); }
            if (MODE_AUTO.equals(mode)) {
                if (blank(host)) { miss.add("连接主机"); }
                if (blank(login)) { miss.add("连接账号"); }
                if (blank(pass) && blank(sshPriv)) { miss.add("连接口令"); }
            }
            return miss;
        }

        String packageName() { return "kimi-game-rank-" + name.trim(); }

        /** 草稿副本：表单里改的是它，点「保存」才回写进列表 */
        Site copy() {
            Site t = new Site();
            t.id = id; t.name = name; t.domain = domain; t.version = version; t.note = note;
            t.dbHost = dbHost; t.dbPort = dbPort; t.dbName = dbName; t.dbAdminName = dbAdminName;
            t.dbLogin = dbLogin; t.dbPass = dbPass; t.adminRaw = adminRaw;
            t.aiKey = aiKey; t.pageSize = pageSize;
            t.conn = conn; t.host = host; t.port = port; t.login = login; t.pass = pass;
            t.remoteDir = remoteDir; t.sshPriv = sshPriv; t.ftpMode = ftpMode;
            t.privKey = privKey; t.pubKey = pubKey; t.paired = paired;
            return t;
        }
    }

    /* ---------- 列表 ---------- */
    static final ArrayList<Site> sites = new ArrayList<Site>();

    static boolean blank(String s) { return s == null || s.trim().length() == 0; }

    static Site find(String id) {
        for (int i = 0; i < sites.size(); i++) { if (sites.get(i).id.equals(id)) { return sites.get(i); } }
        return null;
    }

    /** 能参与配对的站点：域名与站点名都齐 */
    static List<Site> pairable() {
        List<Site> out = new ArrayList<Site>();
        for (int i = 0; i < sites.size(); i++) {
            Site s = sites.get(i);
            if (!blank(s.name) && !blank(s.domain)) { out.add(s); }
        }
        return out;
    }

    /* ---------- 落盘 ---------- */
    private static File file(Context c) { return new File(c.getFilesDir(), "sites.json"); }

    static synchronized void load(Context c) {
        File f = file(c);
        if (!f.isFile()) { return; }
        try {
            InputStream in = new FileInputStream(f);
            byte[] buf = new byte[(int) f.length()];
            int n = in.read(buf);
            in.close();
            if (n <= 0) { return; }
            JSONObject root = new JSONObject(new String(buf, 0, n, UTF8));
            mode = root.optString("mode", MODE_AUTO);
            conn = root.optString("conn", "ftp");
            JSONArray arr = root.optJSONArray("sites");
            sites.clear();
            if (arr != null) {
                for (int i = 0; i < arr.length(); i++) { sites.add(fromJson(arr.getJSONObject(i))); }
            }
        } catch (Exception e) {
            // 配置损坏时宁可空着，也不要让应用起不来
        }
    }

    static synchronized void save(Context c) {
        try {
            JSONObject root = new JSONObject();
            root.put("mode", mode);
            root.put("conn", conn);
            JSONArray arr = new JSONArray();
            for (int i = 0; i < sites.size(); i++) { arr.put(toJson(sites.get(i))); }
            root.put("sites", arr);
            FileOutputStream out = new FileOutputStream(file(c));
            out.write(root.toString(2).getBytes(UTF8));
            out.close();
        } catch (Exception e) {
            // 静默：保存失败不该打断流程
        }
    }

    private static JSONObject toJson(Site s) throws JSONException {
        JSONObject o = new JSONObject();
        o.put("id", s.id);
        o.put("name", s.name);
        o.put("domain", s.domain);
        o.put("version", s.version);
        o.put("note", s.note);
        o.put("dbHost", s.dbHost);
        o.put("dbPort", s.dbPort);
        o.put("dbName", s.dbName);
        o.put("dbAdminName", s.dbAdminName);
        o.put("dbLogin", s.dbLogin);
        o.put("dbPass", s.dbPass);
        o.put("adminRaw", s.adminRaw);
        o.put("aiKey", s.aiKey);
        o.put("pageSize", s.pageSize);
        o.put("conn", s.conn);
        o.put("host", s.host);
        o.put("port", s.port);
        o.put("login", s.login);
        o.put("pass", s.pass);
        o.put("remoteDir", s.remoteDir);
        o.put("sshPriv", s.sshPriv);
        o.put("ftpMode", s.ftpMode);
        o.put("privKey", s.privKey);
        o.put("pubKey", s.pubKey);
        o.put("paired", s.paired);
        return o;
    }

    private static Site fromJson(JSONObject o) {
        Site s = new Site();
        s.id = o.optString("id", "");
        s.name = o.optString("name", "");
        s.domain = o.optString("domain", "");
        s.version = o.optString("version", "");
        s.note = o.optString("note", "");
        s.dbHost = o.optString("dbHost", "");
        s.dbPort = o.optString("dbPort", "3306");
        s.dbName = o.optString("dbName", "");
        s.dbAdminName = o.optString("dbAdminName", "");
        s.dbLogin = o.optString("dbLogin", "");
        s.dbPass = o.optString("dbPass", "");
        s.adminRaw = o.optString("adminRaw", "");
        s.aiKey = o.optString("aiKey", "");
        s.pageSize = o.optString("pageSize", "");
        s.conn = o.optString("conn", "ftp");
        s.host = o.optString("host", "");
        s.port = o.optString("port", "");
        s.login = o.optString("login", "");
        s.pass = o.optString("pass", "");
        s.remoteDir = o.optString("remoteDir", "");
        s.sshPriv = o.optString("sshPriv", "");
        s.ftpMode = o.optString("ftpMode", "passive");
        s.privKey = o.optString("privKey", "");
        s.pubKey = o.optString("pubKey", "");
        s.paired = o.optBoolean("paired", false);
        return s;
    }

    /* ============================ 站点配对（全互联） ============================
     * N 个站两两互通：每个站各持一把私钥，peers 里放其余 N-1 个站的
     * 名字 + 地址 + 公钥。任意两站之间都能同步，加第 N+1 个站重跑一次即可并入。
     * 幂等：已生成过密钥的站不重新生成（否则已部署的站密钥一变就全部失联）。
     */
    static final class PairResult {
        final List<String> lines = new ArrayList<String>();
        int okCount = 0;
        int skipCount = 0;
    }

    static PairResult pairAll() {
        PairResult r = new PairResult();
        List<Site> list = pairable();
        for (int i = 0; i < sites.size(); i++) {
            Site s = sites.get(i);
            if (blank(s.name) || blank(s.domain)) {
                r.skipCount++;
                r.lines.add("跳过 " + (blank(s.name) ? "未命名站点" : s.name)
                        + "：还缺 " + join(s.missing(), "、"));
                continue;
            }
            if (blank(s.privKey) || blank(s.pubKey)) {
                byte[] sk = randomBytes(32);
                s.privKey = hex(sk);
                s.pubKey = hex(X25519.base(sk));
            }
            if (list.size() < 2) {
                r.skipCount++;
                r.lines.add("跳过 " + s.name + "：至少要两个填好域名的站点才能配对");
                continue;
            }
            StringBuilder peerNames = new StringBuilder();
            for (int j = 0; j < list.size(); j++) {
                Site other = list.get(j);
                if (other == s) { continue; }
                if (peerNames.length() > 0) { peerNames.append("、"); }
                peerNames.append(other.name);
            }
            s.paired = true;
            r.okCount++;
            r.lines.add(s.name + " ↔ " + (peerNames.length() > 0 ? peerNames.toString() : "—"));
        }
        return r;
    }

    static String join(List<String> xs, String sep) {
        StringBuilder b = new StringBuilder();
        for (int i = 0; i < xs.size(); i++) {
            if (i > 0) { b.append(sep); }
            b.append(xs.get(i));
        }
        return b.toString();
    }

    /* ============================ 工具 ============================ */

    static String sha256Hex(String s) {
        try {
            MessageDigest md = MessageDigest.getInstance("SHA-256");
            return hex(md.digest(s.getBytes(UTF8)));
        } catch (Exception e) {
            return "";
        }
    }

    static String hex(byte[] b) {
        StringBuilder sb = new StringBuilder(b.length * 2);
        for (int i = 0; i < b.length; i++) {
            int v = b[i] & 0xFF;
            if (v < 16) { sb.append('0'); }
            sb.append(Integer.toHexString(v));
        }
        return sb.toString();
    }

    static byte[] randomBytes(int n) {
        byte[] b = new byte[n];
        try {
            new SecureRandom().nextBytes(b);
        } catch (Throwable t) {
            for (int i = 0; i < n; i++) { b[i] = (byte) (Math.random() * 256); }
        }
        return b;
    }

    static String randomHex(int bytes) { return hex(randomBytes(bytes)); }

    /** 压缩包名里的站点名只保留安全字符，避免路径穿越与非法文件名 */
    static String slug(String raw) {
        String s = raw == null ? "" : raw.trim();
        StringBuilder b = new StringBuilder();
        for (int i = 0; i < s.length(); i++) {
            char ch = s.charAt(i);
            boolean safe = (ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z')
                    || (ch >= '0' && ch <= '9') || ch == '-' || ch == '_' || ch > 127;
            b.append(safe ? ch : '-');
        }
        String out = b.toString().replaceAll("-{2,}", "-");
        out = out.replaceAll("^-+|-+$", "");
        return out.length() == 0 ? "site" : out;
    }

    static void readAll(InputStream in, OutputStream out) throws IOException {
        byte[] buf = new byte[65536];
        int n;
        while ((n = in.read(buf)) > 0) { out.write(buf, 0, n); }
    }
}
