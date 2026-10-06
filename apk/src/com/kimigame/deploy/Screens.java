package com.kimigame.deploy;

import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.textview.MaterialTextView;

/**
 * 前几屏：首屏提示 → 连接方式 → 部署列表。
 * 每屏都只是「照着 Store 画一遍」，自身不留状态。
 * 控件用 Material Components；卡片内容一律经 Ui.add 落到卡内层，避免叠图。
 */
final class Screens {

    private Screens() { }

    /* ============================ 公用零件 ============================ */

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
        l.setPadding(Ui.dp(a, 20), Ui.dp(a, 8), Ui.dp(a, 20), Ui.dp(a, 28));
        return l;
    }

    /** 大按钮（图标 + 标题 + 说明），style: 0 实心主色 / 1 主色容器 / 2 描边 */
    static MaterialCardView bigButton(MainActivity a, int iconRes, String titleText, String sub, int style,
                                      View.OnClickListener l) {
        int fg;
        MaterialCardView card = new MaterialCardView(a);
        card.setRadius(Ui.dp(a, 22));
        card.setCardElevation(0f);
        card.setLayoutParams(new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        if (style == 0) {
            fg = Ui.onPrimary(a);
            card.setCardBackgroundColor(Ui.primary(a));
        } else if (style == 1) {
            fg = Ui.onPrimaryContainer(a);
            card.setCardBackgroundColor(Ui.primaryContainer(a));
        } else {
            fg = Ui.primary(a);
            card.setCardBackgroundColor(0x00000000);
            card.setStrokeColor(Ui.outline(a));
            card.setStrokeWidth(Ui.dp(a, 1));
        }

        LinearLayout row = Ui.row(a);
        int pad = Ui.dp(a, 18);
        row.setPadding(pad, pad, pad, pad);
        row.addView(Ui.icon(a, iconRes, fg, 24));

        LinearLayout texts = Ui.col(a);
        MaterialTextView t = Ui.title(a, titleText);
        t.setTextColor(fg);
        texts.addView(t);
        if (sub != null && sub.length() > 0) {
            MaterialTextView s = Ui.sub(a, sub);
            if (style == 0) { s.setTextColor(fg); }
            texts.addView(s);
        }
        LinearLayout.LayoutParams tp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        tp.leftMargin = Ui.dp(a, 14);
        texts.setLayoutParams(tp);
        row.addView(texts);

        card.addView(row, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        card.setClickable(true);
        card.setOnClickListener(l);
        return card;
    }

    /* ============================ 首屏 ============================ */

    static final class Start implements MainActivity.Screen {
        public View render(final MainActivity a) {
            LinearLayout col = Ui.col(a);
            col.setBackgroundColor(Ui.surface(a));

            LinearLayout pad = Ui.col(a);
            pad.setPadding(Ui.dp(a, 24), Ui.dp(a, 44), Ui.dp(a, 24), Ui.dp(a, 24));
            pad.setGravity(Gravity.CENTER_HORIZONTAL);

            MaterialCardView iconWrap = new MaterialCardView(a);
            int s = Ui.dp(a, 96);
            LinearLayout.LayoutParams iw = new LinearLayout.LayoutParams(s, s);
            iw.gravity = Gravity.CENTER_HORIZONTAL;
            iconWrap.setLayoutParams(iw);
            iconWrap.setRadius(s / 2f);
            iconWrap.setCardElevation(0f);
            iconWrap.setCardBackgroundColor(Ui.primaryContainer(a));
            ImageView ic = Ui.icon(a, R.drawable.ic_cloud, Ui.onPrimaryContainer(a), 46);
            FrameLayout.LayoutParams il = new FrameLayout.LayoutParams(Ui.dp(a, 46), Ui.dp(a, 46));
            il.gravity = Gravity.CENTER;
            ic.setLayoutParams(il);
            iconWrap.addView(ic);
            pad.addView(iconWrap);

            MaterialTextView h = Ui.headline(a, "把榜单部署到你的主机");
            h.setGravity(Gravity.CENTER_HORIZONTAL);
            Ui.add(pad, h, 26);

            MaterialTextView d = Ui.body(a, "自动部署会直接把站点传到你填的主机（FTP 或 SSH），一条龙跑完；"
                    + "手动部署只生成压缩包并保存到下载目录，由你自己上传。");
            d.setGravity(Gravity.CENTER_HORIZONTAL);
            d.setTextColor(Ui.onVariant(a));
            Ui.add(pad, d, 12);
            pad.addView(Ui.gap(a, 18));

            Ui.add(pad, bigButton(a, R.drawable.ic_bolt, "自动部署",
                    "填好主机后一键完成上传", 0, new View.OnClickListener() {
                        public void onClick(View v) {
                            Store.mode = Store.MODE_AUTO;
                            a.push(new Screens.Conn());
                        }
                    }), 0);
            pad.addView(Ui.gap(a, 14));
            Ui.add(pad, bigButton(a, R.drawable.ic_gear, "手动部署",
                    "只生成压缩包，自己上传", 2, new View.OnClickListener() {
                        public void onClick(View v) {
                            Store.mode = Store.MODE_MANUAL;
                            Store.save(a);
                            a.push(new Screens.List());
                        }
                    }), 0);

            MaterialTextView foot = Ui.sub(a, "源码取自开源仓库（AGPL-3.0）；本工具只搬运文件，不改动站点逻辑。");
            foot.setGravity(Gravity.CENTER_HORIZONTAL);
            Ui.add(pad, foot, 26);

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
            Ui.add(bd, Ui.sub(a, "两种通道都能上传整站；免费主机多数只开 FTP。"), 8);
            bd.addView(Ui.gap(a, 16));

            Ui.add(bd, bigButton(a, R.drawable.ic_terminal, "SSH 隧道",
                    "主机、端口、账号、口令或私钥", 1, new View.OnClickListener() {
                        public void onClick(View v) {
                            Store.mode = Store.MODE_AUTO;
                            Store.conn = "ssh";
                            Store.save(a);
                            a.push(new Screens.List());
                        }
                    }), 0);
            bd.addView(Ui.gap(a, 14));
            Ui.add(bd, bigButton(a, R.drawable.ic_folder, "FTP",
                    "主机、端口、账号、口令；被动 / 主动可选", 1, new View.OnClickListener() {
                        public void onClick(View v) {
                            Store.mode = Store.MODE_AUTO;
                            Store.conn = "ftp";
                            Store.save(a);
                            a.push(new Screens.List());
                        }
                    }), 0);

            bd.addView(Ui.gap(a, 20));
            MaterialCardView note = Ui.card(a);
            Ui.add(note, Ui.label(a, "说明"), 0);
            Ui.add(note, Ui.sub(a, "· 免费主机若只开 FTP，选 FTP 即可；\n"
                    + "· SSH 需要主机开放 SFTP，部分免费套餐不开；\n"
                    + "· 两种方式都在下一步的「新增部署」里逐站填写。"), 10);
            bd.addView(note);

            return page(a, Ui.topBar(a, "连接方式", true), bd);
        }
    }

    /* ============================ 部署列表 ============================ */

    static final class List implements MainActivity.Screen {

        public View render(final MainActivity a) {
            LinearLayout bar = Ui.topBar(a, "部署", false);
            LinearLayout bd = body(a);

            String modeText = Store.MODE_AUTO.equals(Store.mode)
                    ? ("自动部署 · " + ("ssh".equals(Store.conn) ? "SSH 隧道" : "FTP"))
                    : "手动部署（生成压缩包后自行上传）";
            bd.addView(Ui.sub(a, "当前方式：" + modeText));

            if (Store.sites.isEmpty()) {
                MaterialCardView empty = Ui.card(a);
                Ui.add(empty, Ui.title(a, "还没有站点"), 0);
                Ui.add(empty, Ui.sub(a, "点右下角「+」添加第一个站点：选源码版本，"
                        + "填好数据库与管理员密钥，就能出包了。"), 10);
                Ui.add(bd, empty, 16);
            } else {
                for (int i = 0; i < Store.sites.size(); i++) {
                    Ui.add(bd, siteCard(a, Store.sites.get(i)), i == 0 ? 16 : 12);
                }
            }

            /* 底部动作区：1 个站 → 一个按钮；≥2 个 → 多一个「站点配对」 */
            LinearLayout actions = Ui.row(a);
            actions.setPadding(0, Ui.dp(a, 18), 0, Ui.dp(a, 84));
            LinearLayout.LayoutParams one = new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            LinearLayout.LayoutParams half = new LinearLayout.LayoutParams(
                    0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);

            MaterialButton main = Ui.button(a, mainLabel());
            main.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { a.push(new Run()); }
            });

            if (Store.sites.size() >= 2) {
                MaterialButton pair = Ui.tonal(a, "站点配对");
                pair.setLayoutParams(half);
                pair.setOnClickListener(new View.OnClickListener() {
                    public void onClick(View v) { pairSheet(a); }
                });
                LinearLayout.LayoutParams mlp = new LinearLayout.LayoutParams(
                        0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
                mlp.leftMargin = Ui.dp(a, 12);
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

        private MaterialCardView siteCard(final MainActivity a, final Store.Site s) {
            MaterialCardView card = Ui.card(a);
            LinearLayout head = Ui.row(a);
            head.addView(Ui.icon(a, R.drawable.ic_site,
                    s.missing().isEmpty() ? Ui.primary(a) : Ui.warn(a), 22));
            MaterialTextView name = Ui.title(a, Store.blank(s.name) ? "未命名站点" : s.name);
            LinearLayout.LayoutParams np = new LinearLayout.LayoutParams(0,
                    ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
            np.leftMargin = Ui.dp(a, 10);
            name.setLayoutParams(np);
            head.addView(name);
            if (s.paired) {
                MaterialTextView chip = Ui.sub(a, "已配对");
                chip.setTextColor(Ui.ok(a));
                head.addView(chip);
            }
            Ui.add(card, head, 0);

            Ui.add(card, Ui.kv(a, "域名", Store.blank(s.domain) ? "—" : s.domain), 12);
            Ui.add(card, Ui.kv(a, "源码版本", Store.blank(s.version) ? "—" : s.version), 8);
            Ui.add(card, Ui.kv(a, "数据库", Store.blank(s.dbName) ? "—" : s.dbName), 8);
            Ui.add(card, Ui.kv(a, "上传通道", channelText(s)), 8);
            if (!Store.blank(s.adminRaw)) {
                Ui.add(card, Ui.kv(a, "管理员密码", s.adminSha()), 8);
            }

            java.util.List<String> miss = s.missing();
            if (!miss.isEmpty()) {
                MaterialTextView m = Ui.sub(a, "还缺：" + Store.join(miss, "、"));
                m.setTextColor(Ui.warn(a));
                Ui.add(card, m, 12);
            } else if (!Store.blank(s.note)) {
                Ui.add(card, Ui.sub(a, s.note), 12);
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
            LinearLayout sheet = Ui.col(a);
            sheet.addView(Ui.title(a, "删除站点"));
            Ui.add(sheet, Ui.sub(a, "只从列表里移除 "
                    + (Store.blank(s.name) ? "这个站点" : s.name) + "，不影响已经上传到主机的站点文件。"), 10);
            LinearLayout row = Ui.row(a);
            row.setPadding(0, Ui.dp(a, 16), 0, 0);
            MaterialButton cancel = Ui.outlined(a, "取消");
            LinearLayout.LayoutParams half = new LinearLayout.LayoutParams(
                    0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
            cancel.setLayoutParams(half);
            cancel.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { a.closeSheet(); }
            });
            MaterialButton ok = Ui.danger(a, "删除");
            LinearLayout.LayoutParams half2 = new LinearLayout.LayoutParams(
                    0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
            half2.leftMargin = Ui.dp(a, 12);
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
            Ui.add(sheet, row, 0);
            a.showSheet(padSheet(a, sheet));
        }

        /** 站点配对（全互联）：结果逐条列出 */
        private void pairSheet(final MainActivity a) {
            Store.PairResult r = Store.pairAll();
            Store.save(a);
            LinearLayout sheet = Ui.col(a);
            sheet.addView(Ui.title(a, "站点配对 · 全互联"));
            Ui.add(sheet, Ui.sub(a, "已配对 " + r.okCount + " 个，跳过 " + r.skipCount
                    + " 个。配对会把各站的地址与公钥互相写进配置，保证任意两站都能双向同步；"
                    + "重复点不会重新生成密钥。"), 10);
            for (int i = 0; i < r.lines.size(); i++) {
                Ui.add(sheet, Ui.sub(a, "· " + r.lines.get(i)), 8);
            }
            MaterialButton ok = Ui.button(a, "知道了");
            ok.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { a.closeSheet(); }
            });
            Ui.add(sheet, ok, 18);
            a.showSheet(padSheet(a, sheet));
        }
    }

    /** BottomSheet 内容统一加内边距 */
    static LinearLayout padSheet(MainActivity a, LinearLayout content) {
        content.setPadding(Ui.dp(a, 24), Ui.dp(a, 22), Ui.dp(a, 24), Ui.dp(a, 22));
        return content;
    }
}
