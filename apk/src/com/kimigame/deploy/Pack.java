package com.kimigame.deploy;

import java.io.BufferedInputStream;
import java.io.BufferedOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.ArrayList;
import java.util.Collections;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.zip.ZipEntry;
import java.util.zip.ZipFile;
import java.util.zip.ZipOutputStream;

/**
 * 部署打包器：把源码版本包加工成「可直接上传到主机」的交付包。
 *
 * 顺序很关键：先解压 → 读样例配置 → 生成正式配置 → 再删冗余（样例就在冗余里）。
 *
 * 冗余清单与仓库打包脚本（tools/zip.py）保持一致，
 * 保证「用这个 App 出的包」与「站长自己打的包」内容口径相同。
 */
final class Pack {

    interface Log { void step(String text); }

    /** 目录级排除（相对项目根，末尾带 /） */
    private static final String[] SKIP_DIRS = {
            "storage/cache/", "storage/logs/", "tools/", "docs/", "standalone/",
            ".github/", ".git/", "assets/js/src/",
    };

    /** 文件级排除 */
    private static final Set<String> SKIP_FILES = new HashSet<String>(java.util.Arrays.asList(
            ".gitignore", "LICENSE", "install.php", "diag.php",
            "README.md", "README.zh-CN.md", "README.zh-TW.md", "README.ja.md",
            "README.ko.md", "README.it.md", "README.fr.md",
            "CHANGELOG.md", "CONTRIBUTING.md", "CODE_OF_CONDUCT.md", "SECURITY.md", "FONTS.md",
            "check.php", "selfcheck.php", "mimecheck.php", "changelog.zip", "jump.zip",
            "config/config.sample.php", "config/api_keys.sample.php",
            "app/data/moderation_words.sample.txt", "app/data/moderation_allow.sample.txt"
    ));

    private Pack() { }

    /* ============================ 解压 ============================ */

    static int unzip(File zip, File into) throws IOException {
        ZipFile zf = new ZipFile(zip);
        try {
            String prefix = commonPrefix(zf);
            int n = 0;
            java.util.Enumeration<? extends ZipEntry> en = zf.entries();
            while (en.hasMoreElements()) {
                ZipEntry e = en.nextElement();
                String name = e.getName();
                if (prefix.length() > 0) {
                    if (!name.startsWith(prefix)) { continue; }
                    name = name.substring(prefix.length());
                }
                if (name.length() == 0) { continue; }
                File out = new File(into, name);
                if (!isInside(into, out)) { continue; }            // 防路径穿越
                if (e.isDirectory()) { out.mkdirs(); continue; }
                File parent = out.getParentFile();
                if (parent != null && !parent.isDirectory()) { parent.mkdirs(); }
                InputStream in = new BufferedInputStream(zf.getInputStream(e), 65536);
                OutputStream os = new BufferedOutputStream(new FileOutputStream(out), 65536);
                Store.readAll(in, os);
                os.close();
                in.close();
                out.setLastModified(e.getTime() > 0 ? e.getTime() : System.currentTimeMillis());
                n++;
            }
            return n;
        } finally {
            zf.close();
        }
    }

    /** 压缩包统一的顶层目录（GitHub 归档都带一层 仓库名-版本/），没有就返回空串 */
    private static String commonPrefix(ZipFile zf) {
        String prefix = null;
        java.util.Enumeration<? extends ZipEntry> en = zf.entries();
        while (en.hasMoreElements()) {
            String name = en.nextElement().getName();
            int slash = name.indexOf('/');
            if (slash < 0) { return ""; }                          // 有裸文件 → 视为无前缀
            String head = name.substring(0, slash + 1);
            if (prefix == null) { prefix = head; }
            else if (!prefix.equals(head)) { return ""; }
        }
        return prefix == null ? "" : prefix;
    }

    private static boolean isInside(File root, File f) {
        try {
            String r = root.getCanonicalPath();
            String p = f.getCanonicalPath();
            return p.equals(r) || p.startsWith(r + File.separator);
        } catch (IOException e) {
            return false;
        }
    }

    /* ============================ 删冗余 ============================ */

    /** 是否应排除（rel 用 / 分隔，相对项目根） */
    static boolean skip(String rel) {
        for (int i = 0; i < SKIP_DIRS.length; i++) {
            if (rel.startsWith(SKIP_DIRS[i])) { return true; }
        }
        if (SKIP_FILES.contains(rel)) { return true; }
        if (rel.toLowerCase().endsWith(".zip")) { return true; }
        if (rel.startsWith("storage/")) {
            String base = rel.substring(rel.lastIndexOf('/') + 1);
            return !(".gitkeep".equals(base) || ".htaccess".equals(base));   // 运行期产物只留骨架
        }
        return false;
    }

    static int prune(File root) {
        return prune(root, root);
    }

    private static int prune(File root, File dir) {
        int removed = 0;
        File[] kids = dir.listFiles();
        if (kids == null) { return 0; }
        for (int i = 0; i < kids.length; i++) {
            File f = kids[i];
            String rel = relOf(root, f).replace(File.separatorChar, '/') + (f.isDirectory() ? "/" : "");
            if (skip(rel)) {
                removed += deleteTree(f);
                continue;
            }
            if (f.isDirectory()) { removed += prune(root, f); }
        }
        return removed;
    }

    private static int deleteTree(File f) {
        int n = 0;
        if (f.isDirectory()) {
            File[] kids = f.listFiles();
            if (kids != null) { for (int i = 0; i < kids.length; i++) { n += deleteTree(kids[i]); } }
        }
        if (f.delete()) { n++; }
        return n;
    }

    private static String relOf(File root, File f) {
        String r = root.getAbsolutePath();
        String p = f.getAbsolutePath();
        return p.startsWith(r + File.separator) ? p.substring(r.length() + 1) : p;
    }

    /* ============================ 生成配置 ============================ */

    static String phpQuote(String s) {
        return "'" + (s == null ? "" : s).replace("\\", "\\\\").replace("'", "\\'") + "'";
    }

    private static String readText(File f) throws IOException {
        InputStream in = new java.io.FileInputStream(f);
        java.io.ByteArrayOutputStream out = new java.io.ByteArrayOutputStream();
        byte[] b = new byte[65536];
        int n;
        while ((n = in.read(b)) > 0) { out.write(b, 0, n); }
        in.close();
        return new String(out.toByteArray(), Store.UTF8);
    }

    private static void writeText(File f, String s) throws IOException {
        File p = f.getParentFile();
        if (p != null && !p.isDirectory()) { p.mkdirs(); }
        OutputStream os = new FileOutputStream(f);
        os.write(s.getBytes(Store.UTF8));
        os.close();
    }

    /** 把某一段 array(...) 整体替换掉 */
    private static String replaceBlock(String src, String key, String replacement) {
        Pattern p = Pattern.compile("(\\n\\s*'" + Pattern.quote(key) + "'\\s*=>\\s*array\\()(.*?)(\\n\\s*\\),)", Pattern.DOTALL);
        Matcher m = p.matcher(src);
        if (!m.find()) { return src; }
        return src.substring(0, m.start(2)) + replacement + src.substring(m.end(2));
    }

    private static String dbBlock(Store.Site s, String name) {
        return "\n        'host'    => " + phpQuote(s.dbHost)
                + ",\n        'port'    => " + (Store.blank(s.dbPort) ? "3306" : s.dbPort.trim())
                + ",\n        'name'    => " + phpQuote(name)
                + ",\n        'user'    => " + phpQuote(s.dbLogin)
                + ",\n        'pass'    => " + phpQuote(s.dbPass)
                + ",\n        'charset' => 'utf8mb4',\n    ";
    }

    /** 互通：本站私钥 + 其余各站的地址与公钥（全互联） */
    private static String peersBlock(Store.Site self, List<Store.Site> all) {
        StringBuilder sb = new StringBuilder();
        sb.append("\n        'private_key'   => ").append(phpQuote(self.privKey));
        sb.append(",\n        'sync_settings' => array(),\n        'sites' => array(");
        List<Store.Site> others = new ArrayList<Store.Site>();
        for (int i = 0; i < all.size(); i++) { if (all.get(i) != self) { others.add(all.get(i)); } }
        Collections.sort(others, new java.util.Comparator<Store.Site>() {
            public int compare(Store.Site a, Store.Site b) { return a.name.compareTo(b.name); }
        });
        for (int i = 0; i < others.size(); i++) {
            Store.Site o = others.get(i);
            sb.append("\n            array('name' => ").append(phpQuote(o.name))
              .append(", 'base_url' => ").append(phpQuote(normUrl(o.domain)))
              .append(", 'pubkey' => ").append(phpQuote(o.pubKey)).append("),");
        }
        sb.append("\n        ),\n    ");
        return sb.toString();
    }

    static String normUrl(String u) {
        String s = u == null ? "" : u.trim();
        while (s.endsWith("/")) { s = s.substring(0, s.length() - 1); }
        return s;
    }

    /**
     * 生成 config.php：以样例为模板，只替换「数据库 / 站点 / 管理员 / 互通」四处，
     * 其余（限额、频次、安全、审核、玻璃…）原样保留 —— 将来站点加了新配置项也自动带上。
     * secrets 留空：站点首次访问会自动生成并落盘（storage/secrets.json）。
     */
    static void writeConfig(File root, Store.Site s, List<Store.Site> all) throws IOException {
        File sample = new File(root, "config/config.sample.php");
        if (!sample.isFile()) { throw new IOException("源码包里缺少 config/config.sample.php"); }
        String php = readText(sample);

        php = replaceBlock(php, "db", dbBlock(s, s.dbName));
        php = replaceBlock(php, "db_admin", dbBlock(s, s.dbAdminName));

        String peers = peersBlock(s, all);
        if (!php.contains("'peers' => array(")) {
            php = php.replaceFirst("(\\n\\s*'secrets'\\s*=>\\s*array\\()", Matcher.quoteReplacement(
                    "\n    'peers' => array(" + peers + "),\n") + "$1");
        } else {
            php = replaceBlock(php, "peers", peers);
        }

        php = php.replaceFirst("('name'\\s*=>\\s*)'[^']*'", "$1" + Matcher.quoteReplacement(phpQuote(
                Store.blank(s.name) ? "Kimi游戏榜" : s.name.trim())));   // 仅第一处 site.name
        php = php.replaceFirst("('url'\\s*=>\\s*)'[^']*'", "$1" + Matcher.quoteReplacement(phpQuote(normUrl(s.domain))));
        php = php.replaceFirst("('secret_raw'\\s*=>\\s*)'[^']*'", "$1" + Matcher.quoteReplacement(phpQuote(s.adminRaw)));

        writeText(new File(root, "config/config.php"), php);
        new File(root, "config/config.sample.php").delete();
    }

    /** 生成 api_keys.php：填了 AI Key 就进智谱池，没填就留空池（站点照常跑，AI 不可用） */
    static void writeApiKeys(File root, Store.Site s) throws IOException {
        File sample = new File(root, "config/api_keys.sample.php");
        if (!sample.isFile()) { return; }
        String php = readText(sample);

        List<String> keys = splitKeys(s.aiKey);
        StringBuilder arr = new StringBuilder();
        for (int i = 0; i < keys.size(); i++) {
            arr.append("\n        ").append(phpQuote(keys.get(i))).append(",");
        }
        if (keys.isEmpty()) { arr.append("\n        // 未填写：AI 功能不可用，站点其余功能不受影响\n    "); }
        else { arr.append("\n    "); }
        php = replaceBlock(php, "keys", arr.toString());
        php = php.replaceFirst("('key'\\s*=>\\s*)'[^']*'",
                "$1" + Matcher.quoteReplacement(phpQuote("")));      // OpenRouter 未填，留空

        writeText(new File(root, "config/api_keys.php"), php);
        new File(root, "config/api_keys.sample.php").delete();
    }

    static List<String> splitKeys(String raw) {
        List<String> out = new ArrayList<String>();
        if (Store.blank(raw)) { return out; }
        String[] parts = raw.split("[\\r\\n,，;；\\s]+");
        for (int i = 0; i < parts.length; i++) {
            String k = parts[i].trim();
            if (k.length() > 0 && !k.startsWith("YOUR_")) { out.add(k); }
        }
        return out;
    }

    /* ============================ 分页页数 ============================ */

    /** 把前端分页大小改成用户填的值（服务端上限 50）。返回是否改成功。 */
    static boolean applyPageSize(File root, int size) {
        File js = new File(root, "assets/js/app.js");
        if (!js.isFile()) { return false; }
        try {
            String src = readText(js);
            Matcher m = Pattern.compile("PAGE_SIZE\\s*=\\s*\\d+").matcher(src);
            if (!m.find()) { return false; }
            String out = m.replaceAll("PAGE_SIZE = " + size);
            writeText(js, out);
            return true;
        } catch (IOException e) {
            return false;
        }
    }

    /* ============================ 打包 ============================ */

    static final class ZipResult {
        int files = 0;
        long bytes = 0;
    }

    static ZipResult zip(File root, File out) throws IOException {
        File parent = out.getParentFile();
        if (parent != null && !parent.isDirectory()) { parent.mkdirs(); }
        ZipResult r = new ZipResult();
        List<String> paths = new ArrayList<String>();
        collect(root, root, paths);
        Collections.sort(paths);                       // 顺序固定 → 同一批输入产出可复现
        ZipOutputStream zos = new ZipOutputStream(new FileOutputStream(out));
        zos.setLevel(6);
        try {
            for (int i = 0; i < paths.size(); i++) {
                String rel = paths.get(i);
                File f = new File(root, rel);
                ZipEntry e = new ZipEntry(rel);
                e.setTime(f.lastModified());
                zos.putNextEntry(e);
                InputStream in = new BufferedInputStream(new java.io.FileInputStream(f), 65536);
                byte[] buf = new byte[65536];
                int n;
                while ((n = in.read(buf)) > 0) { zos.write(buf, 0, n); }
                in.close();
                zos.closeEntry();
                r.files++;
            }
        } finally {
            zos.close();
        }
        r.bytes = out.length();
        return r;
    }

    private static void collect(File root, File dir, List<String> out) {
        File[] kids = dir.listFiles();
        if (kids == null) { return; }
        for (int i = 0; i < kids.length; i++) {
            File f = kids[i];
            if (f.isDirectory()) { collect(root, f, out); }
            else { out.add(relOf(root, f).replace(File.separatorChar, '/')); }
        }
    }
}
