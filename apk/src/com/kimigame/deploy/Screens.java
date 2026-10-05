package com.kimigame.deploy;

import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.util.List;

/**
 * 前几屏：首屏提示 → 连接方式 → 部署列表。
 * 每屏都只是「照着 Store 画一遍」，自身不留状态：返回、旋转、重绘都不会丢东西。
 */
final class Screens {

    private Screens() { }

    /* ============================ 公用零件 ============================ */

    /** 顶部栏：可选返回键 + 标题 */
    static LinearLayout topBar(final MainActivity a, String title, boolean back) {
        LinearLayout bar = Ui.row(a);
        bar.setPadding(Ui.dp(a, 8), Ui.dp(a, 10), Ui.dp(a, 16), Ui.dp(a, 10));
        bar.setBackgroundColor(Ui.surface(a));
        if (back) {
            ImageView b = Ui.icon(a, R.drawable.ic_back, Ui.onSurface(a), 24);
            b.setPadding(Ui.dp(a, 10), Ui.dp(a, 10), Ui.dp(a, 10), Ui.dp(a, 10));
            b.setBackground(Ui.clickable(a, 22, 0, Ui.onSurface(a)));
            b.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { a.pop(); }
            });
            bar.addView(b);
        }
        TextView t = Ui.title(a, title);
        t.setTextSize(android.util.TypedValue.COMPLEX_UNIT_SP, 18);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0,
                ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        lp.leftMargin = Ui.dp(a, back ? 6 : 8);
        t.setLayoutParams(lp);
        bar.addView(t);
        return bar;
    }

    /** 页面骨架：顶栏 + 可滚动内容 */
    static LinearLayout page(MainActivity a, LinearLayout bar, LinearLayout body) {
        LinearLayout col = Ui.col(a);
        col.setBackgroundColor(Ui.surface(a));
        col.addView(bar, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        ScrollView sv = new ScrollView(a);
        sv.setVerticalScrollBarEnabled(false);
        sv.setClipToPadding(false);
        sv.addView(body, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        col.addView(sv, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        return col;
    }

    static LinearLayout body(MainActivity a) {
        LinearLayout l = Ui.col(a);
        l.setPadding(Ui.dp(a, 20), Ui.dp(a, 8), Ui.dp(a, 20), Ui.dp(a, 24));
        return l;
    }

    /** 大按钮（图标 + 标题 + 说明），style: 0 实心主色 / 1 主色容器 / 2 描边 */
    static LinearLayout bigButton(MainActivity a, int iconRes, String title, String sub, int style,
                                  View.OnClickListener l) {
        int fg = (style == 0) ? Ui.onPrimary(a) : Ui.primary(a);
        LinearLayout row = Ui.row(a);
        row.addView(Ui.icon(a, iconRes, fg, 22));

        LinearLayout texts = Ui.col(a);
        TextView t = Ui.title(a, title);
        if (style == 0) { t.setTextColor(Ui.onPrimary(a)); }
        texts.addView(t);
        if (sub != null && sub.length() > 0) {
            TextView s = Ui.sub(a, sub);
            if (style == 0) { s.setTextColor(Ui.onPrimary(a)); }
            texts.addView(s);
        }
        LinearLayout.LayoutParams tp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        tp.leftMargin = Ui.dp(a, 14);
        texts.setLayoutParams(tp);
        row.addView(texts);

        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setPadding(Ui.dp(a, 18), Ui.dp(a, 16), Ui.dp(a, 18), Ui.dp(a, 16));
        if (style == 0) {
            row.setBackground(Ui.clickable(a, 18, Ui.primary(a), 0xFFFFFFFF));
        } else if (style == 1) {
            row.setBackground(Ui.clickable(a, 18, Ui.primaryContainer(a), Ui.primary(a)));
        } else {
            row.setBackground(Ui.stroked(a, 18, 0x00000000, Ui.outline(a), 1));
        }
        row.setClickable(true);
        row.setOnClickListener(l);
        row.setLayoutParams(new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        return row;
    }

    /* ============================ 首屏 ============================ */

    static final class Start implements MainActivity.Screen {
        public View render(final MainActivity a) {
            LinearLayout col = Ui.col(a);
            col.setBackgroundColor(Ui.surface(a));

            LinearLayout pad = Ui.col(a);
            pad.setPadding(Ui.dp(a, 24), Ui.dp(a, 40), Ui.dp(a, 24), Ui.dp(a, 24));
            pad.setGravity(Gravity.CENTER_HORIZONTAL);

            FrameLayout iconWrap = new FrameLayout(a);
            int s = Ui.dp(a, 96);
            LinearLayout.LayoutParams iw = new LinearLayout.LayoutParams(s, s);
            iw.gravity = Gravity.CENTER_HORIZONTAL;
            iconWrap.setLayoutParams(iw);
            iconWrap.setBackground(Ui.fill(a, 48, Ui.primaryContainer(a)));
            ImageView ic = Ui.icon(a, R.drawable.ic_cloud, Ui.onPrimaryContainer(a), 44);
            FrameLayout.LayoutParams il = new FrameLayout.LayoutParams(Ui.dp(a, 44), Ui.dp(a, 44));
            il.gravity = Gravity.CENTER;
            ic.setLayoutParams(il);
            iconWrap.addView(ic);
            pad.addView(iconWrap);

            TextView h = Ui.headline(a, "把榜单部署到你的主机");
            h.setGravity(Gravity.CENTER_HORIZONTAL);
            Ui.add(pad, h, 24);

            TextView d = Ui.body(a, "自动部署会直接把站点传到你填的主机（FTP 或 SSH），一条龙跑完；"
                    + "手动部署只生成压缩包并保存到下载目录，由你自己上传。");
            d.setGravity(Gravity.CENTER_HORIZONTAL);
            d.setTextColor(Ui.onVariant(a));
            Ui.add(pad, d, 10);
            pad.addView(Ui.gap(a, 14));

            Ui.add(pad, bigButton(a, R.drawable.ic_bolt, "自动部署",
                    "填好主机后一键完成上传", 0, new View.OnClickListener() {
                        public void onClick(View v) {
                            Store.mode = Store.MODE_AUTO;
                            a.push(new Screens.Conn());
                        }
                    }), 0);
            pad.addView(Ui.gap(a, 12));
            Ui.add(pad, bigButton(a, R.drawable.ic_gear, "手动部署",
                    "只生成压缩包，自己上传", 2, new View.OnClickListener() {
                        public void onClick(View v) {
                            Store.mode = Store.MODE_MANUAL;
                            Store.save(a);
                            a.push(new Screens.List());
                        }
                    }), 0);

            TextView foot = Ui.sub(a, "源码取自开源仓库（AGPL-3.0）；本工具只搬运文件，不改动站点逻辑。");
            foot.setGravity(Gravity.CENTER_HORIZONTAL);
            Ui.add(pad, foot, 22);

            ScrollView sv = new ScrollView(a);
            sv.setVerticalScrollBarEnabled(false);
            sv.addView(pad, new FrameLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
            col.addView(sv, new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
            return col;
        }
    }

    /* ============================ 连接方式（自动部署） ============================ */

    static final class Conn implements MainActivity.Screen {
        public View render(final MainActivity a) {
            LinearLayout bd = body(a);
            bd.addView(Ui.headline(a, "选择连接方式"));
            Ui.add(bd, Ui.sub(a, "两种通道都能上传整站；免费主机多数只开 FTP。"), 6);
            bd.addView(Ui.gap(a, 14));

            Ui.add(bd, bigButton(a, R.drawable.ic_terminal, "SSH 隧道",
                    "主机、端口、账号、口令或私钥", 1, new View.OnClickListener() {
                        public void onClick(View v) {
                            Store.mode = Store.MODE_AUTO;
                            Store.conn = "ssh";
                            Store.save(a);
                            a.push(new Screens.List());
                        }
                    }), 0);
            bd.addView(Ui.gap(a, 12));
            Ui.add(bd, bigButton(a, R.drawable.ic_folder, "FTP",
                    "主机、端口、账号、口令；被动 / 主动可选", 1, new View.OnClickListener() {
                        public void onClick(View v) {
                            Store.mode = Store.MODE_AUTO;
                            Store.conn = "ftp";
                            Store.save(a);
                            a.push(new Screens.List());
                        }
                    }), 0);

            bd.addView(Ui.gap(a, 18));
            LinearLayout note = Ui.card(a);
            note.setBackground(Ui.fill(a, 16, Ui.surfaceLow(a)));
            note.addView(Ui.label(a, "说明"));
            TextView t = Ui.sub(a, "· 免费主机若只开 FTP，选 FTP 即可；\n"
                    + "· SSH 需要主机开放 SFTP，部分免费套餐不开；\n"
                    + "· 两种方式都在下一步的「新增部署」里逐站填写。");
            Ui.add(note, t, 8);
            bd.addView(note);

            return page(a, topBar(a, "连接方式", true), bd);
        }
    }

    /* ============================ 部署列表 ============================ */

    static final class List implements MainActivity.Screen {

        public View render(final MainActivity a) {
            LinearLayout bar = topBar(a, "部署", false);
            LinearLayout bd = body(a);

            String modeText = Store.MODE_AUTO.equals(Store.mode)
                    ? ("自动部署 · " + ("ssh".equals(Store.conn) ? "SSH 隧道" : "FTP"))
                    : "手动部署（生成压缩包后自行上传）";
            bd.addView(Ui.sub(a, "当前方式：" + modeText));

            if (Store.sites.isEmpty()) {
                LinearLayout empty = Ui.card(a);
                empty.setBackground(Ui.fill(a, 16, Ui.surfaceLow(a)));
                empty.addView(Ui.title(a, "还没有站点"));
                Ui.add(empty, Ui.sub(a, "点右下角「+」添加第一个站点：选源码版本，"
                        + "填好数据库与管理员密钥，就能出包了。"), 8);
                Ui.add(bd, empty, 16);
            } else {
                for (int i = 0; i < Store.sites.size(); i++) {
                    Ui.add(bd, siteCard(a, Store.sites.get(i)), i == 0 ? 16 : 12);
                }
            }

            /* 底部动作区：1 个站 → 一个按钮；≥2 个 → 多一个「站点配对」 */
            LinearLayout actions = Ui.row(a);
            actions.setPadding(0, Ui.dp(a, 18), 0, Ui.dp(a, 80));
            LinearLayout.LayoutParams one = new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            LinearLayout.LayoutParams half = new LinearLayout.LayoutParams(
                    0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);

            TextView main = Ui.button(a, mainLabel(), 0);
            main.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { a.push(new Run()); }
            });

            if (Store.sites.size() >= 2) {
                TextView pair = Ui.button(a, "站点配对", 2);
                pair.setLayoutParams(half);
                pair.setOnClickListener(new View.OnClickListener() {
                    public void onClick(View v) { pairSheet(a); }
                });
                LinearLayout.LayoutParams mlp = new LinearLayout.LayoutParams(
                        0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
                mlp.leftMargin = Ui.dp(a, 10);
                main.setLayoutParams(mlp);
                actions.addView(pair);
                actions.addView(main);
            } else {
                main.setLayoutParams(one);
                actions.addView(main);
            }
            bd.addView(actions);

            FrameLayout host = new FrameLayout(a);
            host.addView(page(a, bar, bd), new FrameLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            host.addView(Ui.fab(a, R.drawable.ic_add, new View.OnClickListener() {
                public void onClick(View v) { a.push(new Form(null)); }
            }));
            return host;
        }

        private String mainLabel() {
            return Store.MODE_AUTO.equals(Store.mode) ? "开始自动部署" : "生成压缩包";
        }

        private LinearLayout siteCard(final MainActivity a, final Store.Site s) {
            LinearLayout card = Ui.card(a);
            LinearLayout head = Ui.row(a);
            head.addView(Ui.icon(a, R.drawable.ic_site,
                    s.missing().isEmpty() ? Ui.primary(a) : Ui.warn(a), 20));
            TextView name = Ui.title(a, Store.blank(s.name) ? "未命名站点" : s.name);
            LinearLayout.LayoutParams np = new LinearLayout.LayoutParams(0,
                    ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
            np.leftMargin = Ui.dp(a, 10);
            name.setLayoutParams(np);
            head.addView(name);
            if (s.paired) {
                TextView chip = Ui.sub(a, "已配对");
                chip.setTextColor(Ui.ok(a));
                head.addView(chip);
            }
            card.addView(head);

            Ui.add(card, Ui.kv(a, "域名", Store.blank(s.domain) ? "—" : s.domain), 10);
            Ui.add(card, Ui.kv(a, "源码版本", Store.blank(s.version) ? "—" : s.version), 6);
            Ui.add(card, Ui.kv(a, "数据库", Store.blank(s.dbName) ? "—" : s.dbName), 6);
            Ui.add(card, Ui.kv(a, "上传通道", channelText(s)), 6);
            if (!Store.blank(s.adminRaw)) {
                Ui.add(card, Ui.kv(a, "管理员密码", s.adminSha()), 6);
            }

            List<String> miss = s.missing();
            if (!miss.isEmpty()) {
                TextView m = Ui.sub(a, "还缺：" + Store.join(miss, "、"));
                m.setTextColor(Ui.warn(a));
                Ui.add(card, m, 10);
            } else if (!Store.blank(s.note)) {
                Ui.add(card, Ui.sub(a, s.note), 10);
            }

            card.setClickable(true);
            card.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { a.push(new Form(s)); }
            });
            card.setOnLongClickListener(new View.OnLongClickListener() {
                public boolean onLongClick(View v) { confirmDelete(a, s); return true; }
            });
            return card;
        }

        private String channelText(Store.Site s) {
            if (!Store.MODE_AUTO.equals(Store.mode) && Store.blank(s.host)) { return "—"; }
            if ("ssh".equals(s.conn)) { return "SSH"; }
            return "FTP · " + ("active".equals(s.ftpMode) ? "主动" : "被动");
        }

        private void confirmDelete(final MainActivity a, final Store.Site s) {
            LinearLayout sheet = Ui.card(a);
            sheet.addView(Ui.title(a, "删除站点"));
            Ui.add(sheet, Ui.sub(a, "只从列表里移除 "
                    + (Store.blank(s.name) ? "这个站点" : s.name) + "，不影响已经上传到主机的站点文件。"), 8);
            LinearLayout row = Ui.row(a);
            row.setPadding(0, Ui.dp(a, 16), 0, 0);
            TextView cancel = Ui.button(a, "取消", 2);
            LinearLayout.LayoutParams half = new LinearLayout.LayoutParams(
                    0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
            cancel.setLayoutParams(half);
            cancel.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { a.closeSheet(); }
            });
            TextView ok = Ui.button(a, "删除", 3);
            LinearLayout.LayoutParams half2 = new LinearLayout.LayoutParams(
                    0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
            half2.leftMargin = Ui.dp(a, 10);
            ok.setLayoutParams(half2);
            ok.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) {
                    Store.sites.remove(s);
                    Store.save(a);
                    a.closeSheet();
                    a.reset(new Screens.List());
                }
            });
            row.addView(cancel);
            row.addView(ok);
            sheet.addView(row);
            a.showSheet(sheet);
        }

        /** 站点配对（全互联）：结果逐条列出 */
        private void pairSheet(final MainActivity a) {
            Store.PairResult r = Store.pairAll();
            Store.save(a);
            LinearLayout sheet = Ui.card(a);
            sheet.addView(Ui.title(a, "站点配对 · 全互联"));
            Ui.add(sheet, Ui.sub(a, "已配对 " + r.okCount + " 个，跳过 " + r.skipCount
                    + " 个。配对会把各站的地址与公钥互相写进配置，保证任意两站都能双向同步；"
                    + "重复点不会重新生成密钥。"), 8);
            for (int i = 0; i < r.lines.size(); i++) {
                Ui.add(sheet, Ui.sub(a, "· " + r.lines.get(i)), 6);
            }
            TextView ok = Ui.button(a, "知道了", 0);
            ok.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { a.closeSheet(); }
            });
            Ui.add(sheet, ok, 16);
            a.showSheet(sheet);
        }
    }
}
