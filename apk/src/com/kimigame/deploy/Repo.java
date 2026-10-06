package com.kimigame.deploy;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.BufferedInputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.zip.ZipEntry;
import java.util.zip.ZipOutputStream;

/**
 * 源码仓库。
 *
 * 版本列表：从 raw.githubusercontent 上的 CHANGELOG.md 索引表动态读（用户要求走 raw），
 *          读不到时退回 GitHub tags 接口；两个都不通就明确报错，不猜。
 * 源码包：  优先官方 zipball；zipball 不通（部分地区 codeload 不可达）时，
 *          退回「读文件树 → 每个文件从 raw 拉」，在本地重新打包成 zip。
 *          两条路产出的都是标准 zip，交给 Pack 解压，调用方不必区分。
 */
final class Repo {

    static final String OWNER = "silverbullet-liang";
    static final String NAME = "kimi-game-rank";
    static final String REPO_URL = "https://github.com/" + OWNER + "/" + NAME;
    static final String RAW_MAIN = "https://raw.githubusercontent.com/" + OWNER + "/" + NAME + "/main/";
    static final String API = "https://api.github.com/repos/" + OWNER + "/" + NAME + "/";

    private Repo() { }

    static final class Version {
        final String tag;
        final String date;
        final String note;
        Version(String tag, String date, String note) {
            this.tag = tag; this.date = date; this.note = note;
        }
        @Override public String toString() {
            return tag + (date.length() > 0 ? "  · " + date : "");
        }
    }

    /** 版本列表（新到旧） */
    static List<Version> versions() throws IOException {
        List<Version> raw = fromChangelog();
        Map<String, String> tags = null;
        try { tags = apiTags(); } catch (IOException e) { tags = null; }

        if (raw.isEmpty() && tags == null) {
            throw new IOException("拿不到版本列表：请检查网络（raw.githubusercontent 与 api.github.com 都不通）");
        }
        if (tags == null || tags.isEmpty()) {
            if (raw.isEmpty()) { throw new IOException("拿不到版本列表"); }
            return raw;
        }

        // 有 tags 时以 tags 为准（避免列出「日志里有、但没打过 tag」的版本导致下载 404）
        Map<String, Version> byTag = new LinkedHashMap<String, Version>();
        for (int i = 0; i < raw.size(); i++) {
            Version v = raw.get(i);
            if (tags.containsKey(v.tag)) { byTag.put(v.tag, new Version(v.tag, v.date, v.note)); }
        }
        if (byTag.isEmpty()) {
            for (Map.Entry<String, String> e : tags.entrySet()) {
                byTag.put(e.getKey(), new Version(e.getKey(), e.getValue(), ""));
            }
        }
        List<Version> out = new ArrayList<Version>(byTag.values());
        // tags 接口可能含有日志里还没有的版本，补在末尾
        for (Map.Entry<String, String> e : tags.entrySet()) {
            if (!byTag.containsKey(e.getKey())) { out.add(new Version(e.getKey(), e.getValue(), "")); }
        }
        return out;
    }

    /** 从 raw 的 CHANGELOG.md 里解析版本索引表 */
    private static List<Version> fromChangelog() {
        List<Version> out = new ArrayList<Version>();
        try {
            String md = Net.getString(RAW_MAIN + "CHANGELOG.md", 15000);
            Pattern p = Pattern.compile("^\\|\\s*(v[0-9][0-9.]*)\\s*\\|\\s*([0-9]{4}-[0-9]{2}-[0-9]{2})?\\s*\\|(.*)\\|\\s*$");
            String[] lines = md.split("\n");
            for (int i = 0; i < lines.length; i++) {
                Matcher m = p.matcher(lines[i].trim());
                if (!m.matches()) { continue; }
                String tag = m.group(1);
                String date = m.group(2) == null ? "" : m.group(2);
                String note = m.group(3).replace("|", " ").trim();
                if (note.length() > 60) { note = note.substring(0, 60) + "…"; }
                boolean dup = false;
                for (int j = 0; j < out.size(); j++) { if (out.get(j).tag.equals(tag)) { dup = true; break; } }
                if (!dup) { out.add(new Version(tag, date, note)); }
            }
        } catch (Exception e) {
            // 交给上层决定是否退到 tags 接口
        }
        return out;
    }

    /** tags 接口：tag -> 日期 */
    private static Map<String, String> apiTags() throws IOException {
        String js = Net.getString(API + "tags?per_page=100", 15000);
        Map<String, String> out = new LinkedHashMap<String, String>();
        try {
            JSONArray arr = new JSONArray(js);
            for (int i = 0; i < arr.length(); i++) {
                JSONObject o = arr.getJSONObject(i);
                String name = o.optString("name", "");
                if (name.length() == 0) { continue; }
                String date = o.optJSONObject("commit") != null ? o.optJSONObject("commit").optString("sha", "") : "";
                out.put(name, date.length() > 7 ? date.substring(0, 7) : "");
            }
        } catch (JSONException e) {
            throw new IOException("tags 解析失败：" + e.getMessage());
        }
        return out;
    }

    /* ============================ 取源码包 ============================ */

    static void fetchSource(String tag, File dest, Net.Progress cb, Net.Cancel cancel) throws IOException {
        IOException first = null;
        try {
            Net.download(API + "zipball/" + tag, dest, cb, cancel);
            if (!isZip(dest)) { throw new IOException("拿到的不是压缩包"); }
            return;
        } catch (IOException e) {
            first = e;
        }
        if (cancel != null && cancel.isCancelled()) { throw first; }
        if (cb != null) { cb.on(0, -1, 0, -1); }
        dest.delete();
        try {
            fetchByTree(tag, dest, cb, cancel);
            if (!isZip(dest)) { throw new IOException("组装出来的不是压缩包"); }
        } catch (IOException e) {
            throw new IOException("两种方式都失败：zipball " + first.getMessage() + "；按文件拉 " + e.getMessage());
        }
    }

    /** 退回方案：读文件树，逐个从 raw 拉，边拉边写进 zip */
    private static void fetchByTree(String tag, File dest, Net.Progress cb, Net.Cancel cancel) throws IOException {
        String js = Net.getString(API + "git/trees/" + tag + "?recursive=1", 20000);
        List<String> files = new ArrayList<String>();
        try {
            JSONArray tree = new JSONObject(js).optJSONArray("tree");
            if (tree == null) { throw new IOException("文件树为空"); }
            for (int i = 0; i < tree.length(); i++) {
                JSONObject o = tree.getJSONObject(i);
                if ("blob".equals(o.optString("type")) && o.optLong("size", 0) < 8L * 1024 * 1024) {
                    files.add(o.optString("path"));
                }
            }
        } catch (JSONException e) {
            throw new IOException("文件树解析失败：" + e.getMessage());
        }
        if (files.isEmpty()) { throw new IOException("文件树里没有可下载文件"); }

        ZipOutputStream zos = new ZipOutputStream(new FileOutputStream(dest));
        zos.setLevel(6);
        long done = 0;
        String prefix = NAME + "-" + tag + "/";
        for (int i = 0; i < files.size(); i++) {
            if (cancel != null && cancel.isCancelled()) {
                zos.close(); dest.delete(); throw new IOException("已取消");
            }
            String path = files.get(i);
            zos.putNextEntry(new ZipEntry(prefix + path));
            HttpURLConnection conn = (HttpURLConnection) new URL(RAW_MAIN.replace("/main/", "/" + tag + "/") + path).openConnection();
            conn.setConnectTimeout(20000);
            conn.setReadTimeout(30000);
            conn.setInstanceFollowRedirects(true);
            conn.setRequestProperty("User-Agent", "KimiGameRankDeploy/1.0");
            conn.setRequestProperty("Accept-Encoding", "identity");
            int code = conn.getResponseCode();
            if (code / 100 != 2) { throw new IOException("取 " + path + " 失败（HTTP " + code + "）"); }
            InputStream in = new BufferedInputStream(conn.getInputStream(), 65536);
            byte[] buf = new byte[65536];
            int n;
            while ((n = in.read(buf)) > 0) {
                zos.write(buf, 0, n);
                done += n;
                if (cb != null) { cb.on(done, -1, 0, -1); }
            }
            in.close();
            conn.disconnect();
            zos.closeEntry();
        }
        zos.finish();
        zos.close();
    }

    private static boolean isZip(File f) {
        if (!f.isFile() || f.length() < 4) { return false; }
        try {
            InputStream in = new java.io.FileInputStream(f);
            byte[] m = new byte[4];
            int n = in.read(m);
            in.close();
            return n == 4 && m[0] == 'P' && m[1] == 'K';
        } catch (Exception e) {
            return false;
        }
    }
}
