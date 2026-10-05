package com.kimigame.deploy;

import com.jcraft.jsch.ChannelSftp;
import com.jcraft.jsch.JSch;
import com.jcraft.jsch.Session;

import java.io.BufferedInputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.IOException;
import java.io.InputStream;
import java.util.List;

/**
 * SFTP 通道，唯一的 JSch 使用点。
 *
 * 单独成类是为了「可选内置」：不打包 jsch.jar 时，构建脚本不编译本文件，
 * Upload 通过反射找不到它就提示改用 FTP —— 其余代码一行都不用改。
 *
 * upload(...) 的签名由 Upload 的反射调用约定，改签名要两边一起改。
 */
final class Sftp {

    private Sftp() { }

    public static void upload(Store.Site s, List<File> files, List<String> rels, long total,
                              Upload.Progress cb, Net.Cancel cancel) throws Exception {
        JSch jsch = new JSch();
        if (!Store.blank(s.sshPriv)) {
            jsch.addIdentity("deploy-key", s.sshPriv.getBytes(Store.UTF8), null, null);
        }
        Session session = jsch.getSession(s.login.trim(), s.host.trim(), Upload.parsePort(s, 22));
        if (Store.blank(s.sshPriv)) { session.setPassword(s.pass); }
        session.setConfig("StrictHostKeyChecking", "no");   // 免费主机的指纹不固定，不做强校验
        session.setTimeout(20000);
        session.connect();

        ChannelSftp ch = null;
        try {
            ch = (ChannelSftp) session.openChannel("sftp");
            ch.connect(15000);

            String base = s.remoteDir == null ? "" : s.remoteDir.trim();
            while (base.endsWith("/")) { base = base.substring(0, base.length() - 1); }
            if (base.length() > 0) {
                try { ch.cd(base); } catch (Exception e) { throw new IOException("进入远程目录失败：" + base); }
            }

            long done = 0;
            String lastDir = null;
            for (int i = 0; i < files.size(); i++) {
                if (cancel != null && cancel.isCancelled()) { throw new IOException("已取消"); }
                String rel = rels.get(i);
                String dir = rel.contains("/") ? rel.substring(0, rel.lastIndexOf('/')) : "";
                if (lastDir == null || !dir.equals(lastDir)) {
                    cdRoot(ch, base);
                    mkdirs(ch, dir);
                    lastDir = dir;
                }
                if (cb != null) { cb.on(rel, done, total, i, files.size()); }
                InputStream is = new BufferedInputStream(new FileInputStream(files.get(i)), 65536);
                ch.put(is, rel.substring(rel.lastIndexOf('/') + 1));
                is.close();
                done += files.get(i).length();
                if (cb != null) { cb.on(rel, done, total, i + 1, files.size()); }
            }
            if (cb != null) { cb.on("", total, total, files.size(), files.size()); }
        } finally {
            if (ch != null) { ch.disconnect(); }
            session.disconnect();
        }
    }

    private static void cdRoot(ChannelSftp ch, String base) throws Exception {
        ch.cd("/");
        if (base.length() > 0) { ch.cd(base); }
    }

    private static void mkdirs(ChannelSftp ch, String dir) throws Exception {
        if (dir == null || dir.length() == 0) { return; }
        String[] segs = dir.split("/");
        for (int i = 0; i < segs.length; i++) {
            if (segs[i].length() == 0) { continue; }
            try {
                ch.cd(segs[i]);
            } catch (Exception e) {
                try { ch.mkdir(segs[i]); } catch (Exception ignored) { }
                ch.cd(segs[i]);
            }
        }
    }
}
