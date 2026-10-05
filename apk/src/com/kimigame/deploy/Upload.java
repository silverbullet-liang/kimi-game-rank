package com.kimigame.deploy;

import java.io.BufferedInputStream;
import java.io.BufferedReader;
import java.io.File;
import java.io.FileInputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.io.OutputStreamWriter;
import java.io.Writer;
import java.lang.reflect.InvocationTargetException;
import java.lang.reflect.Method;
import java.net.InetSocketAddress;
import java.net.ServerSocket;
import java.net.Socket;
import java.util.ArrayList;
import java.util.List;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * 自动部署的上传层：FTP 与 SSH(SFTP) 两条通道。
 *
 * FTP 自己写协议（免依赖）：
 *   · 被动模式 PASV —— 服务器开端口，我们连过去（绝大多数场景，宽带/NAT 后面也能用）；
 *   · 主动模式 PORT —— 本地开端口，服务器连回来（部分老服务器或受限网络只认这种）。
 *   两种都按字节报进度；目录逐级自动创建；二进制传输。
 *
 * SFTP 走可选内置的 JSch。本类刻意不 import JSch，改反射调用 Sftp ——
 * 这样「不内置 SSH 库」的构建也能编译通过，只是运行时提示改用 FTP。
 */
final class Upload {

    interface Progress {
        void on(String currentFile, long done, long total, int filesDone, int filesTotal);
    }

    private Upload() { }

    static void upload(Store.Site s, File root, Progress cb, Net.Cancel cancel) throws Exception {
        List<File> files = new ArrayList<File>();
        List<String> rels = new ArrayList<String>();
        collect(root, root, files, rels);

        long total = 0;
        for (int i = 0; i < files.size(); i++) { total += files.get(i).length(); }

        if ("ssh".equals(s.conn)) {
            sftp(s, files, rels, total, cb, cancel);
        } else {
            ftp(s, files, rels, total, cb, cancel);
        }
    }

    private static void collect(File root, File dir, List<File> files, List<String> rels) {
        File[] kids = dir.listFiles();
        if (kids == null) { return; }
        for (int i = 0; i < kids.length; i++) {
            File f = kids[i];
            if (f.isDirectory()) { collect(root, f, files, rels); }
            else {
                files.add(f);
                rels.add(relOf(root, f));
            }
        }
    }

    private static String relOf(File root, File f) {
        String r = root.getAbsolutePath();
        String p = f.getAbsolutePath();
        return p.startsWith(r + File.separator)
                ? p.substring(r.length() + 1).replace(File.separatorChar, '/') : f.getName();
    }

    /* ============================ FTP ============================ */

    static final class Ftp {
        private Socket ctrl;
        private BufferedReader in;
        private Writer out;
        private boolean passive = true;
        private String baseDir = "";
        private final StringBuilder log = new StringBuilder();

        void setPassive(boolean p) { passive = p; }

        void setBase(String dir) {
            baseDir = dir == null ? "" : dir.trim();
            while (baseDir.endsWith("/")) { baseDir = baseDir.substring(0, baseDir.length() - 1); }
        }

        void connect(String host, int port, String user, String pass, int timeoutMs) throws IOException {
            ctrl = new Socket();
            ctrl.connect(new InetSocketAddress(host, port), timeoutMs);
            ctrl.setSoTimeout(timeoutMs);
            in = new BufferedReader(new InputStreamReader(ctrl.getInputStream(), "US-ASCII"));
            out = new OutputStreamWriter(ctrl.getOutputStream(), "US-ASCII");
            int code = readResponse();
            if (code / 100 != 2) { throw new IOException("服务器未就绪：" + trim()); }
            login(user, pass);
        }

        private void login(String user, String pass) throws IOException {
            int code = cmd("USER " + user);
            if (code == 331) { code = cmd("PASS " + pass); }
            if (code / 100 != 2) {
                throw new IOException("登录失败：" + trim() + "（核对主机 / 账号 / 密码）");
            }
            code = cmd("TYPE I");
            if (code / 100 != 2) { throw new IOException("切换到二进制模式失败：" + trim()); }
        }

        private int cmd(String line) throws IOException {
            out.write(line + "\r\n");
            out.flush();
            return readResponse();
        }

        private int readResponse() throws IOException {
            log.setLength(0);
            String line = in.readLine();
            if (line == null) { throw new IOException("连接被服务器关闭"); }
            log.append(line).append('\n');
            int code = 0;
            try { code = Integer.parseInt(line.substring(0, 3)); } catch (Exception ignored) { }
            if (line.length() > 3 && line.charAt(3) == '-') {
                String end = line.substring(0, 3) + " ";
                String more;
                while ((more = in.readLine()) != null) {
                    log.append(more).append('\n');
                    if (more.startsWith(end)) { break; }
                }
            }
            return code;
        }

        private String trim() {
            String s = log.toString().trim().replace('\n', ' ');
            return s.length() > 140 ? s.substring(0, 140) + "…" : s;
        }

        void ensureDir(String path) throws IOException {
            if (path == null) { return; }
            String p = path.trim();
            if (p.length() == 0 || "/".equals(p)) { return; }
            String[] segs = p.split("/");
            String cur = "";
            for (int i = 0; i < segs.length; i++) {
                if (segs[i].length() == 0) { continue; }
                cur = cur + "/" + segs[i];
                cmd("MKD " + cur);            // 已存在返回 550，忽略
            }
        }

        void store(String remoteRel, File local, long[] done, long total, Progress cb, Net.Cancel cancel)
                throws IOException {
            String remote = baseDir + "/" + remoteRel;
            int slash = remote.lastIndexOf('/');
            if (slash > 0) { ensureDir(remote.substring(0, slash)); }

            Socket data = null;
            ServerSocket ss = null;
            int code;

            if (passive) {
                code = cmd("PASV");
                if (code / 100 != 2) {
                    throw new IOException("被动模式失败：" + trim() + "（可改用主动模式）");
                }
                Matcher m = Pattern.compile("\\((\\d+),(\\d+),(\\d+),(\\d+),(\\d+),(\\d+)\\)").matcher(log.toString());
                if (!m.find()) { throw new IOException("解析被动模式地址失败：" + trim()); }
                String dataHost = m.group(1) + "." + m.group(2) + "." + m.group(3) + "." + m.group(4);
                int dataPort = Integer.parseInt(m.group(5)) * 256 + Integer.parseInt(m.group(6));
                if ("0.0.0.0".equals(dataHost)) { dataHost = ctrl.getInetAddress().getHostAddress(); }
                data = new Socket();
                data.connect(new InetSocketAddress(dataHost, dataPort), 15000);
                code = cmd("STOR " + remote);
                if (code != 150 && code != 125) {
                    data.close();
                    throw new IOException("创建远程文件失败：" + trim());
                }
            } else {
                // 主动模式：本地开一个端口，把地址告诉服务器，等它连回来
                ss = new ServerSocket(0, 1, ctrl.getLocalAddress());
                ss.setSoTimeout(20000);
                String ip = ctrl.getLocalAddress().getHostAddress();
                if (ip.indexOf(':') >= 0) {                     // IPv6 不支持 PORT
                    ss.close();
                    throw new IOException("主动模式需要 IPv4 地址，当前连接是 IPv6（请改用被动模式）");
                }
                int p = ss.getLocalPort();
                String[] o = ip.split("\\.");
                String arg = o[0] + "," + o[1] + "," + o[2] + "," + o[3] + "," + (p / 256) + "," + (p % 256);
                code = cmd("PORT " + arg);
                if (code / 100 != 2) {
                    ss.close();
                    throw new IOException("主动模式失败：" + trim() + "（服务器可能不接受 PORT，请改用被动）");
                }
                code = cmd("STOR " + remote);
                if (code != 150 && code != 125) {
                    ss.close();
                    throw new IOException("创建远程文件失败：" + trim());
                }
                try {
                    data = ss.accept();
                } catch (IOException e) {
                    ss.close();
                    throw new IOException("服务器没有连回来（主动模式常被 NAT / 防火墙挡住，建议改用被动模式）");
                } finally {
                    try { ss.close(); } catch (Exception ignored) { }
                }
            }

            InputStream is = new BufferedInputStream(new FileInputStream(local), 65536);
            OutputStream os = data.getOutputStream();
            byte[] buf = new byte[65536];
            int n;
            int sinceReport = 0;
            while ((n = is.read(buf)) > 0) {
                if (cancel != null && cancel.isCancelled()) {
                    is.close(); data.close();
                    throw new IOException("已取消");
                }
                os.write(buf, 0, n);
                done[0] += n;
                sinceReport += n;
                if (cb != null && sinceReport >= 262144) {
                    sinceReport = 0;
                    cb.on(remoteRel, done[0], total, -1, -1);
                }
            }
            os.flush();
            is.close();
            data.close();
            code = readResponse();
            if (code / 100 != 2) { throw new IOException("传输未完成：" + trim()); }
            if (cb != null) { cb.on(remoteRel, done[0], total, -1, -1); }
        }

        void close() {
            try { cmd("QUIT"); } catch (Exception ignored) { }
            try { ctrl.close(); } catch (Exception ignored) { }
        }
    }

    private static void ftp(Store.Site s, List<File> files, List<String> rels, long total,
                            Progress cb, Net.Cancel cancel) throws IOException {
        Ftp f = new Ftp();
        try {
            f.setPassive(!"active".equals(s.ftpMode));
            f.connect(s.host.trim(), parsePort(s, 21), s.login.trim(), s.pass, 20000);
            f.setBase(s.remoteDir);
            long[] done = new long[]{0};
            for (int i = 0; i < files.size(); i++) {
                if (cancel != null && cancel.isCancelled()) { throw new IOException("已取消"); }
                if (cb != null) { cb.on(rels.get(i), done[0], total, i, files.size()); }
                f.store(rels.get(i), files.get(i), done, total, cb, cancel);
            }
            if (cb != null) { cb.on("", total, total, files.size(), files.size()); }
        } finally {
            f.close();
        }
    }

    static int parsePort(Store.Site s, int def) {
        try { return Integer.parseInt(s.portOrDefault()); } catch (Exception e) { return def; }
    }

    /* ============================ SFTP（反射到可选类） ============================ */

    private static void sftp(Store.Site s, List<File> files, List<String> rels, long total,
                             Progress cb, Net.Cancel cancel) throws Exception {
        if (!sftpAvailable()) {
            throw new IOException("本次构建未内置 SSH 库，请改用 FTP，或改用「下载后手动」");
        }
        Class<?> c = Class.forName("com.kimigame.deploy.Sftp");
        Method m = c.getMethod("upload", Store.Site.class, List.class, List.class, long.class,
                Progress.class, Net.Cancel.class);
        try {
            m.invoke(null, s, files, rels, Long.valueOf(total), cb, cancel);
        } catch (InvocationTargetException e) {
            Throwable t = e.getCause();
            if (t instanceof Exception) { throw (Exception) t; }
            if (t instanceof Error) { throw (Error) t; }
            throw new IOException(String.valueOf(t));
        }
    }

    /** 供界面判断：SSH 库是否已内置 */
    static boolean sftpAvailable() {
        try { Class.forName("com.jcraft.jsch.JSch"); return true; }
        catch (Throwable e) { return false; }
    }
}
