package com.kimigame.deploy;

import android.content.ContentValues;
import android.content.Context;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.provider.MediaStore;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;

/**
 * 部署流程编排。
 *
 * 步骤（两种模式共通）：
 *   1 正在下载压缩包（显示速度 / 剩余）
 *   2 正在解压
 *   3 正在删除冗余文件
 *   4 正在处理（站点名）—— 生成配置、写互通密钥、改分页页数
 *   5 正在打包
 *   6 保存到 Downloads
 * 自动部署在最后多一步：正在上传（FTP / SSH）。
 *
 * 下载按版本缓存：两个站点用同一版本时只下一次。
 */
final class Deploy {

    interface Step {
        /** @param label 当前在干什么；detail 补充信息（速度/剩余、文件名…）；overall 0~1 总进度 */
        void on(String label, String detail, float overall);
    }

    static final class Result {
        boolean ok = true;
        final List<String> lines = new ArrayList<String>();
        final List<File> produced = new ArrayList<File>();
        String outDirText = "";
    }

    private Deploy() { }

    /** 输出目录：Android 10+ 走公共下载目录（免权限），9 及以下走外部存储 */
    static String outDirPath() {
        return Environment.getExternalStorageDirectory().getAbsolutePath()
                + "/Download/kimi-game-rank";
    }

    static Result run(Context ctx, Step cb, Net.Cancel cancel) throws Exception {
        Result res = new Result();
        List<Store.Site> targets = new ArrayList<Store.Site>();
        for (int i = 0; i < Store.sites.size(); i++) {
            Store.Site s = Store.sites.get(i);
            List<String> miss = s.missing();
            if (!miss.isEmpty()) {
                res.lines.add("跳过 " + (Store.blank(s.name) ? "未命名站点" : s.name)
                        + "：还缺 " + Store.join(miss, "、"));
                continue;
            }
            targets.add(s);
        }
        if (targets.isEmpty()) {
            res.ok = false;
            res.lines.add("没有可部署的站点：请先在「添加」里把配置填完整");
            return res;
        }

        List<Store.Site> all = Store.pairable();          // 互通条目取自「填好域名」的全部站点
        File work = new File(ctx.getCacheDir(), "kimgr");
        if (!work.isDirectory() && !work.mkdirs()) { throw new IOException("无法创建工作目录"); }
        File outDir = new File(ctx.getCacheDir(), "out");
        if (!outDir.isDirectory() && !outDir.mkdirs()) { throw new IOException("无法创建输出目录"); }

        HashMap<String, File> srcCache = new HashMap<String, File>();
        int n = targets.size();
        boolean auto = Store.MODE_AUTO.equals(Store.mode);
        float uploadSlice = auto ? 0.15f : 0f;
        float perSite = (1f - uploadSlice) / n;

        for (int i = 0; i < n; i++) {
            Store.Site s = targets.get(i);
            if (cancel != null && cancel.isCancelled()) { throw new IOException("已取消"); }
            final float base = i * perSite;
            final String tag = Store.blank(s.version) ? "" : s.version.trim();

            /* ---- 1 下载 ---- */
            File src = srcCache.get(tag);
            if (src == null) {
                File cache = new File(work, "src-" + (tag.length() > 0 ? Store.slug(tag) : "latest") + ".zip");
                if (!cache.isFile() || cache.length() < 1024) {
                    String url = tag.length() > 0 ? Repo.API + "zipball/" + tag : Repo.API + "zipball/HEAD";
                    final String label = "正在下载压缩包" + (tag.length() > 0 ? "（" + tag + "）" : "");
                    Net.download(url, cache, new Net.Progress() {
                        public void on(final long done, final long total, final double speed, final long remain) {
                            if (cb == null) { return; }
                            String d = Net.bytes(done) + (total > 0 ? " / " + Net.bytes(total) : "")
                                    + " · " + Net.speed(speed) + " · " + Net.eta(remain);
                            cb.on(label, d, base + perSite * 0.45f * (total > 0 ? (float) done / total : 0.5f));
                        }
                    }, cancel);
                }
                srcCache.put(tag, cache);
                src = cache;
            }
            if (cb != null) { cb.on("正在下载压缩包", "已就绪 " + Net.bytes(src.length()), base + perSite * 0.45f); }
            res.lines.add(s.name + "：源码 " + (tag.length() > 0 ? tag : "默认分支")
                    + "（" + Net.bytes(src.length()) + "）");

            /* ---- 2 解压 ---- */
            onStep(cb, "正在解压", s.name, base + perSite * 0.5f);
            File root = new File(work, "build-" + Store.slug(s.name));
            deleteTree(root);
            if (!root.mkdirs()) { throw new IOException("无法创建解压目录"); }
            int files = Pack.unzip(src, root);
            res.lines.add(s.name + "：解压 " + files + " 个文件");

            /* ---- 3 删冗余 ---- */
            onStep(cb, "正在删除冗余文件", s.name, base + perSite * 0.62f);
            int removed = Pack.prune(root);
            res.lines.add(s.name + "：剔除冗余 " + removed + " 项");

            /* ---- 4 生成配置 ---- */
            onStep(cb, "正在处理 " + s.name, "生成配置与互通密钥", base + perSite * 0.7f);
            Pack.writeConfig(root, s, all);
            Pack.writeApiKeys(root, s);
            if (!Store.blank(s.pageSize)) {
                try {
                    int ps = Integer.parseInt(s.pageSize.trim());
                    if (ps >= 1 && ps <= 50) {
                        boolean okPs = Pack.applyPageSize(root, ps);
                        res.lines.add(s.name + "：分页 " + (okPs ? "已设为 " + ps : "未能写入（版本不含该位置）"));
                    }
                } catch (NumberFormatException ignored) { }
            }

            /* ---- 5 打包 ---- */
            onStep(cb, "正在打包", s.packageName() + ".zip", base + perSite * 0.78f);
            File zipOut = new File(outDir, s.packageName() + ".zip");
            if (zipOut.isFile()) { zipOut.delete(); }
            Pack.ZipResult zr = Pack.zip(root, zipOut);
            res.produced.add(zipOut);
            res.lines.add(s.name + "：" + zipOut.getName() + " · " + zr.files + " 个文件 / "
                    + Net.bytes(zr.bytes));

            /* ---- 6 存到 Downloads ---- */
            onStep(cb, "正在保存到 Downloads", zipOut.getName(), base + perSite * 0.85f);
            try {
                String where = export(ctx, zipOut);
                res.outDirText = where;
                res.lines.add(s.name + "：已保存到 " + where);
            } catch (IOException e) {
                res.lines.add(s.name + "：保存到下载目录失败 —— " + e.getMessage());
            }

            /* ---- 7 上传（自动模式） ---- */
            if (auto) {
                final float ub = 1f - uploadSlice;
                onStep(cb, "正在上传", s.name + " · " + ("ssh".equals(s.conn) ? "SSH" : "FTP"), ub + uploadSlice * (float) i / n);
                if ("ssh".equals(s.conn) && !Upload.sftpAvailable()) {
                    throw new IOException("本次构建未内置 SSH 库，请改用 FTP，或改用「下载后手动」");
                }
                Upload.upload(s, root, new Upload.Progress() {
                    public void on(String cur, long done, long total, int fd, int ft) {
                        if (cb == null) { return; }
                        String d = Net.bytes(done) + " / " + Net.bytes(total)
                                + (cur.length() > 0 ? " · " + cur : "");
                        cb.on("正在上传", d, ub + uploadSlice * (float) (i + (total > 0 ? (double) done / total : 0)) / n);
                    }
                }, cancel);
                res.lines.add(s.name + "：已上传到 " + s.host
                        + (Store.blank(s.remoteDir) ? "（站点根目录）" : s.remoteDir));
            }

            if (cb != null) { cb.on("完成", s.name, base + perSite); }
        }

        if (cb != null) { cb.on("全部完成", "", 1f); }
        return res;
    }

    private static void onStep(Step cb, String label, String detail, float overall) {
        if (cb != null) { cb.on(label, detail, overall); }
    }

    /** 把缓存里的 zip 落到公共下载目录 /storage/emulated/0/Download/kimi-game-rank/ */
    static String export(Context ctx, File zip) throws IOException {
        String rel = "Download/kimi-game-rank";
        if (Build.VERSION.SDK_INT >= 29) {
            ContentValues v = new ContentValues();
            v.put(MediaStore.MediaColumns.DISPLAY_NAME, zip.getName());
            v.put(MediaStore.MediaColumns.MIME_TYPE, "application/zip");
            v.put(MediaStore.MediaColumns.RELATIVE_PATH, rel);
            v.put(MediaStore.MediaColumns.IS_PENDING, 1);
            Uri uri = ctx.getContentResolver().insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, v);
            if (uri == null) { throw new IOException("系统未提供下载目录写入权限"); }
            OutputStream os = ctx.getContentResolver().openOutputStream(uri);
            if (os == null) { throw new IOException("无法写入下载目录"); }
            InputStream in = new java.io.FileInputStream(zip);
            Store.readAll(in, os);
            in.close();
            os.flush();
            os.close();
            ContentValues done = new ContentValues();
            done.put(MediaStore.MediaColumns.IS_PENDING, 0);
            ctx.getContentResolver().update(uri, done, null, null);
            return "/storage/emulated/0/" + rel + "/" + zip.getName();
        }
        File dir = new File(Environment.getExternalStorageDirectory(), rel);
        if (!dir.isDirectory() && !dir.mkdirs()) { throw new IOException("无法创建 " + dir.getAbsolutePath()); }
        File dst = new File(dir, zip.getName());
        InputStream in = new java.io.FileInputStream(zip);
        FileOutputStream out = new FileOutputStream(dst);
        Store.readAll(in, out);
        in.close();
        out.close();
        return dst.getAbsolutePath();
    }

    static void deleteTree(File f) {
        if (f == null) { return; }
        if (f.isDirectory()) {
            File[] kids = f.listFiles();
            if (kids != null) { for (int i = 0; i < kids.length; i++) { deleteTree(kids[i]); } }
        }
        f.delete();
    }
}
