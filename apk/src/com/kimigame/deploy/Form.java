package com.kimigame.deploy;

import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.text.Editable;
import android.text.InputType;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.util.List;

/**
 * 新增 / 编辑站点（全屏）。
 *
 * 编辑的是一份副本（draft），点「保存」才回写列表 —— 中途返回不会污染已存配置。
 * 所有输入在改动时就写进副本，所以切换 FTP/SSH、重绘界面都不会丢内容。
 */
final class Form implements MainActivity.Screen {

    private static final int T_TEXT = InputType.TYPE_CLASS_TEXT;
    private static final int T_PASS = InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD;
    private static final int T_NUM = InputType.TYPE_CLASS_NUMBER;

    private final Store.Site d;
    private final boolean isNew;

    Form(Store.Site site) {
        isNew = (site == null);
        if (isNew) {
            d = new Store.Site();
            d.conn = Store.conn;          // 新建时沿用首屏选的通道
        } else {
            d = site.copy();
        }
    }

    private interface Setter { void set(String v); }
    private interface Pick { void on(int index); }

    /* ============================ 界面 ============================ */

    public View render(final MainActivity a) {
        LinearLayout bd = Screens.body(a);

        /* ---- 源码版本（点击弹列表，内容从仓库动态读） ---- */
        LinearLayout verRow = Ui.row(a);
        verRow.addView(Ui.icon(a, R.drawable.ic_download, Ui.onPrimaryContainer(a), 22));
        LinearLayout vcol = Ui.col(a);
        vcol.setLayoutParams(new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        vcol.addView(Ui.label(a, "源码版本"));
        vcol.addView(Ui.title(a, Store.blank(d.version) ? "默认分支（最新）" : d.version));
        verRow.addView(vcol);
        verRow.addView(Ui.icon(a, R.drawable.ic_expand, Ui.onVariant(a), 20));
        verRow.setPadding(Ui.dp(a, 16), Ui.dp(a, 14), Ui.dp(a, 16), Ui.dp(a, 14));
        verRow.setBackground(Ui.clickable(a, 18, Ui.primaryContainer(a), Ui.primary(a)));
        verRow.setClickable(true);
        verRow.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) { pickVersion(a); }
        });
        bd.addView(verRow);

        /* ---- 站点 ---- */
        LinearLayout site = Ui.card(a);
        field(a, site, "站点名", d.name, "例：我的镜像站", T_TEXT, new Setter() {
            public void set(String v) { d.name = v; }
        });
        field(a, site, "主域名", d.domain, "例：https://example.com", T_TEXT, new Setter() {
            public void set(String v) { d.domain = v; }
        });
        Ui.add(site, Ui.sub(a, "压缩包会命名为 kimi-game-rank-" + (Store.blank(d.name) ? "站点名" : d.name.trim())
                + ".zip，保存到 /storage/emulated/0/Download/kimi-game-rank/"), 12);
        Ui.add(bd, site, 14);

        /* ---- 数据库 ---- */
        LinearLayout db = Ui.card(a);
        db.addView(Ui.title(a, "数据库"));
        field(a, db, "数据库主机", d.dbHost, "例：sql123.epizy.com", T_TEXT, new Setter() {
            public void set(String v) { d.dbHost = v; }
        });
        field(a, db, "端口", d.dbPort, "默认 3306", T_NUM, new Setter() {
            public void set(String v) { d.dbPort = v; }
        });
        field(a, db, "用户数据库名", d.dbName, "例：b33_43085350_user_data", T_TEXT, new Setter() {
            public void set(String v) { d.dbName = v; }
        });
        field(a, db, "管理员数据库名", d.dbAdminName, "例：b33_43085350_admin_data", T_TEXT, new Setter() {
            public void set(String v) { d.dbAdminName = v; }
        });
        field(a, db, "数据库账号", d.dbLogin, "主机面板里的数据库用户", T_TEXT, new Setter() {
            public void set(String v) { d.dbLogin = v; }
        });
        field(a, db, "数据库密码", d.dbPass, "", T_PASS, new Setter() {
            public void set(String v) { d.dbPass = v; }
        });
        Ui.add(db, Ui.sub(a, "两个库名与账号都照主机面板原样填；表结构由站点首次访问时自动建。"), 12);
        Ui.add(bd, db, 14);

        /* ---- 管理员密钥：原文进配置，sha256 是登录密码 ---- */
        LinearLayout key = Ui.card(a);
        key.addView(Ui.title(a, "管理员密钥"));
        final TextView[] shaRef = new TextView[1];
        field(a, key, "密钥原文", d.adminRaw, "登录后台时输入这个", T_TEXT, new Setter() {
            public void set(String v) {
                d.adminRaw = v;
                if (shaRef[0] != null) {
                    shaRef[0].setText(Store.blank(v) ? "—" : Store.sha256Hex(v));
                }
            }
        });
        LinearLayout shaRow = Ui.row(a);
        final TextView sha = Ui.mono(a, Store.blank(d.adminRaw) ? "—" : d.adminSha(), Ui.onSurface(a));
        sha.setLayoutParams(new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        shaRow.addView(sha);
        ImageView cp = Ui.icon(a, R.drawable.ic_copy, Ui.primary(a), 20);
        cp.setPadding(Ui.dp(a, 10), Ui.dp(a, 10), Ui.dp(a, 10), Ui.dp(a, 10));
        cp.setBackground(Ui.clickable(a, 20, 0, Ui.primary(a)));
        cp.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) {
                String val = sha.getText().toString();
                if ("—".equals(val)) { a.toast("先填密钥原文"); return; }
                ClipboardManager cm = (ClipboardManager) a.getSystemService(Context.CLIPBOARD_SERVICE);
                cm.setPrimaryClip(ClipData.newPlainText("sha256", val));
                a.toast("sha256 已复制");
            }
        });
        shaRow.addView(cp);
        Ui.add(key, shaRow, 12);
        shaRef[0] = sha;
        Ui.add(key, Ui.sub(a, "密钥原文写进站点配置；sha256 是后台登录密码，请一并妥善保存。"), 10);
        Ui.add(bd, key, 14);

        /* ---- 选填 ---- */
        LinearLayout opt = Ui.card(a);
        opt.addView(Ui.title(a, "选填"));
        opt.addView(Ui.label(a, "AI Key"), mt(a, 12));
        EditText ai = Ui.area(a, "一行一个；多个即组成 Key 池", 3);
        ai.setText(d.aiKey);
        watch(ai, new Setter() { public void set(String v) { d.aiKey = v; } });
        opt.addView(ai);
        field(a, opt, "分页加载页数", d.pageSize, "1–50，留空用默认 12", T_NUM, new Setter() {
            public void set(String v) { d.pageSize = v; }
        });
        Ui.add(bd, opt, 14);

        /* ---- 连接信息（自动部署才需要） ---- */
        if (Store.MODE_AUTO.equals(Store.mode)) {
            LinearLayout cn = Ui.card(a);
            LinearLayout head = Ui.row(a);
            head.addView(Ui.title(a, "连接方式"));
            TextView tag = Ui.sub(a, "自动部署用");
            tag.setLayoutParams(new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
            tag.setGravity(Gravity.END);
            head.addView(tag);
            cn.addView(head);
            Ui.add(cn, segment(a, new String[]{"FTP", "SSH 隧道"}, "ssh".equals(d.conn) ? 1 : 0, new Pick() {
                public void on(int i) {
                    d.conn = (i == 1) ? "ssh" : "ftp";
                    a.refresh();
                }
            }), 12);

            field(a, cn, "主机", d.host, "例：ftpupload.net 或服务器 IP", T_TEXT, new Setter() {
                public void set(String v) { d.host = v; }
            });
            field(a, cn, "端口", d.port, "留空用默认（FTP 21 / SSH 22）", T_NUM, new Setter() {
                public void set(String v) { d.port = v; }
            });
            field(a, cn, "账号", d.login, "主机面板里的上传账号", T_TEXT, new Setter() {
                public void set(String v) { d.login = v; }
            });
            field(a, cn, "口令", d.pass, "与账号配套的密码", T_PASS, new Setter() {
                public void set(String v) { d.pass = v; }
            });

            if ("ssh".equals(d.conn)) {
                cn.addView(Ui.label(a, "私钥（选填，优先于口令）"), mt(a, 12));
                EditText pk = Ui.area(a, "粘贴 OpenSSH / PEM 私钥全文；留空则用上面的口令", 3);
                pk.setText(d.sshPriv);
                watch(pk, new Setter() { public void set(String v) { d.sshPriv = v; } });
                cn.addView(pk);
            } else {
                Ui.add(cn, Ui.label(a, "FTP 数据连接"), 14);
                Ui.add(cn, segment(a, new String[]{"被动 PASV", "主动 PORT"},
                        "active".equals(d.ftpMode) ? 1 : 0, new Pick() {
                            public void on(int i) {
                                d.ftpMode = (i == 1) ? "active" : "passive";
                                a.refresh();
                            }
                        }), 8);
                Ui.add(cn, Ui.sub(a, "不确定就留「被动」——绝大多数主机与本公司网络都适配；"
                        + "主机明确要求主动模式时才切「主动」。"), 8);
            }

            field(a, cn, "远程目录", d.remoteDir, "留空 = 站点根目录", T_TEXT, new Setter() {
                public void set(String v) { d.remoteDir = v; }
            });
            Ui.add(bd, cn, 14);
        } else {
            LinearLayout note = Ui.card(a);
            note.setBackground(Ui.fill(a, 20, Ui.surfaceLow(a)));
            note.addView(Ui.title(a, "当前是「手动部署」"));
            Ui.add(note, Ui.sub(a, "只生成压缩包并保存到下载目录，不需要填连接信息。"
                    + "需要自动上传时，回到首屏改选「自动部署」。"), 8);
            Ui.add(bd, note, 14);
        }

        /* ---- 动作 ---- */
        TextView save = Ui.button(a, isNew ? "保存并加入列表" : "保存修改", 0);
        save.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) { save(a); }
        });
        Ui.add(bd, save, 18);

        if (!isNew) {
            TextView del = Ui.button(a, "删除这个站点", 3);
            del.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { confirmDelete(a); }
            });
            Ui.add(bd, del, 10);
        }

        return Screens.page(a, Screens.topBar(a, isNew ? "新增部署" : "编辑部署", true), bd);
    }

    /* ============================ 版本列表 ============================ */

    private void pickVersion(final MainActivity a) {
        final LinearLayout sheet = Ui.card(a);
        sheet.addView(Ui.title(a, "选择源码版本"));
        final LinearLayout box = Ui.col(a);
        box.addView(Ui.sub(a, "正在读取版本列表…"));
        ScrollView sv = new ScrollView(a);
        sv.setVerticalScrollBarEnabled(false);
        sv.addView(box, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        LinearLayout.LayoutParams sp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, Ui.dp(a, 380));
        sp.topMargin = Ui.dp(a, 12);
        sv.setLayoutParams(sp);
        sheet.addView(sv);
        a.showSheet(sheet);

        new Thread(new Runnable() {
            public void run() {
                List<Repo.Version> list = null;
                String err = null;
                try {
                    list = Repo.versions();
                } catch (Exception e) {
                    err = e.getMessage();
                }
                final List<Repo.Version> fl = list;
                final String fe = err;
                a.ui().post(new Runnable() {
                    public void run() {
                        box.removeAllViews();
                        if (fl == null) {
                            box.addView(Ui.sub(a, "读取失败：" + (fe == null ? "未知错误" : fe)
                                    + "\n（可稍后重试，或先留「默认分支」直接出包）"));
                            return;
                        }
                        box.addView(verItem(a, "", "默认分支（最新）", "跟随仓库主分支"));
                        for (int i = 0; i < fl.size(); i++) {
                            Repo.Version v = fl.get(i);
                            String sub = v.date == null ? "" : v.date;
                            if (v.note != null && v.note.length() > 0) {
                                sub = (sub.length() > 0 ? sub + "  ·  " : "") + v.note;
                            }
                            box.addView(verItem(a, v.tag, v.tag, sub));
                        }
                    }
                });
            }
        }).start();
    }

    private View verItem(final MainActivity a, final String tag, String title, String sub) {
        LinearLayout item = Ui.col(a);
        item.setPadding(Ui.dp(a, 14), Ui.dp(a, 12), Ui.dp(a, 14), Ui.dp(a, 12));
        item.setBackground(Ui.clickable(a, 14, 0, Ui.primary(a)));
        item.addView(Ui.title(a, title));
        if (sub != null && sub.length() > 0) { item.addView(Ui.sub(a, sub)); }
        item.setClickable(true);
        item.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) {
                d.version = tag;
                a.closeSheet();
                a.refresh();
            }
        });
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.topMargin = Ui.dp(a, 6);
        item.setLayoutParams(lp);
        return item;
    }

    /* ============================ 保存 / 删除 ============================ */

    private void save(MainActivity a) {
        normalize();
        if (Store.blank(d.name)) { a.toast("先给站点起个名字"); return; }
        if (!Store.blank(d.pageSize)) {
            try {
                int ps = Integer.parseInt(d.pageSize.trim());
                if (ps < 1 || ps > 50) { a.toast("分页页数请填 1–50"); return; }
            } catch (NumberFormatException e) {
                a.toast("分页页数只能是数字"); return;
            }
        }
        int idx = indexOf(d.id);
        if (idx >= 0) {
            Store.sites.set(idx, d);
        } else {
            if (Store.blank(d.id)) { d.id = Store.randomHex(6); }
            Store.sites.add(d);
        }
        Store.save(a);
        a.pop();
        a.toast("已保存");
    }

    private void confirmDelete(final MainActivity a) {
        LinearLayout sheet = Ui.card(a);
        sheet.addView(Ui.title(a, "删除站点"));
        Ui.add(sheet, Ui.sub(a, "只会从列表里移除「" + (Store.blank(d.name) ? "未命名站点" : d.name)
                + "」，主机上已上传的文件不受影响。"), 8);
        LinearLayout row = Ui.row(a);
        row.setPadding(0, Ui.dp(a, 16), 0, 0);
        TextView cancel = Ui.button(a, "取消", 2);
        cancel.setLayoutParams(new LinearLayout.LayoutParams(0,
                ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        cancel.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) { a.closeSheet(); }
        });
        TextView ok = Ui.button(a, "删除", 3);
        LinearLayout.LayoutParams olp = new LinearLayout.LayoutParams(0,
                ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        olp.leftMargin = Ui.dp(a, 10);
        ok.setLayoutParams(olp);
        ok.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) {
                int idx = indexOf(d.id);
                if (idx >= 0) { Store.sites.remove(idx); }
                Store.save(a);
                a.closeSheet();
                a.pop();
                a.toast("已删除");
            }
        });
        row.addView(cancel);
        row.addView(ok);
        sheet.addView(row);
        a.showSheet(sheet);
    }

    /* ============================ 小工具 ============================ */

    private void normalize() {
        d.name = d.name.trim();
        d.domain = d.domain.trim();
        d.dbHost = d.dbHost.trim();
        d.dbPort = Store.blank(d.dbPort) ? "3306" : d.dbPort.trim();
        d.dbName = d.dbName.trim();
        d.dbAdminName = d.dbAdminName.trim();
        d.dbLogin = d.dbLogin.trim();
        d.dbPass = d.dbPass.trim();
        d.host = d.host.trim();
        d.port = d.port.trim();
        d.login = d.login.trim();
        d.remoteDir = d.remoteDir.trim();
        d.aiKey = d.aiKey.trim();
        d.pageSize = d.pageSize.trim();
        if (!Store.blank(d.domain)) {
            while (d.domain.endsWith("/")) { d.domain = d.domain.substring(0, d.domain.length() - 1); }
        }
    }

    private static int indexOf(String id) {
        if (Store.blank(id)) { return -1; }
        for (int i = 0; i < Store.sites.size(); i++) {
            if (id.equals(Store.sites.get(i).id)) { return i; }
        }
        return -1;
    }

    /** 标签 + 输入框：值改动即写进副本（切界面/重绘都不丢） */
    private void field(MainActivity a, LinearLayout box, String label, String value, String hint,
                       int type, final Setter set) {
        box.addView(Ui.label(a, label), mt(a, box.getChildCount() == 0 ? 0 : 12));
        EditText e = Ui.field(a, hint, type);
        e.setText(value == null ? "" : value);
        watch(e, set);
        box.addView(e);
    }

    private static void watch(EditText e, final Setter set) {
        e.addTextChangedListener(new TextWatcher() {
            public void beforeTextChanged(CharSequence s, int st, int c, int af) { }
            public void onTextChanged(CharSequence s, int st, int b, int c) { }
            public void afterTextChanged(Editable s) { set.set(s.toString()); }
        });
    }

    private static LinearLayout.LayoutParams mt(MainActivity a, float topDp) {
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.topMargin = Ui.dp(a, topDp);
        return lp;
    }

    /** 二选一分段控件（MD3 segmented button 的自绘版） */
    private LinearLayout segment(MainActivity a, String[] labels, int sel, final Pick p) {
        LinearLayout row = Ui.row(a);
        for (int i = 0; i < labels.length; i++) {
            final int idx = i;
            boolean on = (i == sel);
            TextView t = Ui.body(a, labels[i]);
            t.setGravity(Gravity.CENTER);
            t.setTextColor(on ? Ui.onPrimary(a) : Ui.onVariant(a));
            t.setPadding(0, Ui.dp(a, 11), 0, Ui.dp(a, 11));
            if (on) {
                t.setBackground(Ui.clickable(a, 20, Ui.primary(a), 0xFFFFFFFF));
            } else {
                t.setBackground(new android.graphics.drawable.RippleDrawable(
                        android.content.res.ColorStateList.valueOf(Ui.withAlpha(Ui.primary(a), 0.14f)),
                        Ui.stroked(a, 20, 0x00000000, Ui.outline(a), 1),
                        Ui.stroked(a, 20, 0xFFFFFFFF, 0, 0)));
            }
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0,
                    ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
            if (i > 0) { lp.leftMargin = Ui.dp(a, 8); }
            t.setLayoutParams(lp);
            t.setClickable(true);
            t.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { p.on(idx); }
            });
            row.addView(t);
        }
        return row;
    }
}
