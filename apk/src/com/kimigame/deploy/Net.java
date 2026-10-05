package com.kimigame.deploy;

import java.io.BufferedInputStream;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;

/**
 * 网络层：只做两件事 —— 取文本（版本列表）与流式下载（源码包）。
 *
 * 下载要给出「速度 / 剩余时间」，所以按块读、按时间窗统计，
 * 并且全程可取消（用户退出进度页时不能留下还在跑的线程）。
 */
final class Net {

    interface Progress {
        /** @param done 已完成字节；total 未知时为 -1；speed 字节/秒；remain 剩余秒数（未知为 -1） */
        void on(long done, long total, double speed, long remain);
    }

    static final class Cancel {
        private volatile boolean c = false;
        void cancel() { c = true; }
        boolean isCancelled() { return c; }
    }

    private static final String UA = "KimiGameRankDeploy/1.0 (+Android)";
    private static final int BUF = 65536;

    private Net() { }

    private static HttpURLConnection open(String url, int timeoutMs) throws IOException {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setConnectTimeout(timeoutMs);
        conn.setReadTimeout(timeoutMs);
        conn.setInstanceFollowRedirects(true);
        conn.setRequestProperty("User-Agent", UA);
        conn.setRequestProperty("Accept-Encoding", "identity");
        return conn;
    }

    /** 取文本（版本列表等小文件） */
    static String getString(String url, int timeoutMs) throws IOException {
        HttpURLConnection conn = open(url, timeoutMs);
        try {
            int code = conn.getResponseCode();
            if (code / 100 != 2) { throw new IOException("HTTP " + code); }
            InputStream in = new BufferedInputStream(conn.getInputStream(), BUF);
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            byte[] buf = new byte[BUF];
            int n;
            while ((n = in.read(buf)) > 0) {
                out.write(buf, 0, n);
                if (out.size() > 4 * 1024 * 1024) { break; }   // 防意外大文件
            }
            in.close();
            return new String(out.toByteArray(), Store.UTF8);
        } finally {
            conn.disconnect();
        }
    }

    /** 流式下载到文件；边写边报速度与剩余 */
    static void download(String url, File dest, Progress cb, Cancel cancel) throws IOException {
        HttpURLConnection conn = open(url, 30000);
        try {
            int code = conn.getResponseCode();
            if (code / 100 != 2) { throw new IOException("HTTP " + code); }
            long total = conn.getContentLengthLong();
            InputStream in = new BufferedInputStream(conn.getInputStream(), BUF);
            File parent = dest.getParentFile();
            if (parent != null && !parent.isDirectory()) { parent.mkdirs(); }
            OutputStream out = new FileOutputStream(dest);

            byte[] buf = new byte[BUF];
            long done = 0, winBytes = 0, winStart = System.currentTimeMillis(), last = 0;
            double speed = 0;
            int n;
            while ((n = in.read(buf)) > 0) {
                if (cancel != null && cancel.isCancelled()) {
                    out.close(); in.close(); dest.delete();
                    throw new IOException("已取消");
                }
                out.write(buf, 0, n);
                done += n;
                winBytes += n;
                long now = System.currentTimeMillis();
                if (now - winStart >= 500) {
                    speed = winBytes * 1000.0 / Math.max(1, now - winStart);
                    winBytes = 0; winStart = now;
                }
                if (cb != null && now - last >= 200) {
                    last = now;
                    cb.on(done, total, speed, remain(done, total, speed));
                }
                // 落地：万一中途被杀，至少文件不是空的
                if (done % (4 * 1024 * 1024) < n) { out.flush(); }
            }
            out.flush();
            out.close();
            in.close();
            if (cb != null) { cb.on(done, total, speed, 0); }
        } finally {
            conn.disconnect();
        }
    }

    private static long remain(long done, long total, double speed) {
        if (total <= 0 || speed <= 1) { return -1; }
        return Math.max(0, Math.round((total - done) / speed));
    }

    /** 人类可读的字节数 */
    static String bytes(long b) {
        if (b < 0) { return "—"; }
        if (b < 1024) { return b + " B"; }
        double kb = b / 1024.0;
        if (kb < 1024) { return String.format(java.util.Locale.US, "%.0f KB", kb); }
        double mb = kb / 1024.0;
        if (mb < 1024) { return String.format(java.util.Locale.US, "%.1f MB", mb); }
        return String.format(java.util.Locale.US, "%.2f GB", mb / 1024.0);
    }

    /** 人类可读的速度 */
    static String speed(double bytesPerSec) {
        if (bytesPerSec <= 0) { return "—"; }
        return bytes(bytesPerSec) + "/s";
    }

    /** 人类可读的剩余时间 */
    static String eta(long sec) {
        if (sec < 0) { return "剩余 计算中"; }
        if (sec < 60) { return "剩余 " + sec + " 秒"; }
        return "剩余 " + (sec / 60) + " 分 " + (sec % 60) + " 秒";
    }
}
