package com.kimigame.deploy;

import android.content.Context;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

/**
 * 全屏进度页。
 *
 * 进来自动开跑（两种模式共通：下载 → 解压 → 删冗余 → 处理站点 → 打包 → 存下载；
 * 自动部署末尾多一步上传）。所有更新都 post 回 UI 线程，后台线程只负责算。
 * 退出这一屏会取消任务（见 MainActivity.Leavable），不会留下还在下载的线程。
 */
final class Run implements MainActivity.Screen, MainActivity.Leavable {

    private final Net.Cancel cancel = new Net.Cancel();
    private volatile boolean started = false;
    private volatile boolean done = false;

    private Ui.Bar bar;
    private TextView label;
    private TextView detail;
    private LinearLayout logBox;
    private LinearLayout resultBox;
    private TextView action;
    private String lastStep = "";

    public View render(final MainActivity a) {
        LinearLayout bd = Screens.body(a);

        /* ---- 进度 ---- */
        LinearLayout top = Ui.card(a);
        label = Ui.title(a, "正在准备…");
        top.addView(label);
        detail = Ui.sub(a, "");
        Ui.add(top, detail, 8);
        bar = Ui.bar(a);
        Ui.add(top, bar, 14);
        bd.addView(top);

        /* ---- 过程记录（只记步骤，不刷每次进度） ---- */
        LinearLayout logCard = Ui.card(a);
        logCard.addView(Ui.title(a, "过程记录"));
        logBox = Ui.col(a);
        Ui.add(logCard, logBox, 8);
        Ui.add(bd, logCard, 14);

        resultBox = Ui.col(a);
        bd.addView(resultBox);

        action = Ui.button(a, "取消", 2);
        action.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) {
                if (!done) { askCancel(a); } else { a.pop(); }
            }
        });
        Ui.add(bd, action, 18);

        if (!started) {
            started = true;
            start(a);
        }
        return Screens.page(a, Screens.topBar(a, "正在部署", true), bd);
    }

    /* ============================ 跑流程 ============================ */

    private void start(final MainActivity a) {
        new Thread(new Runnable() {
            public void run() {
                Deploy.Result r = null;
                String err = null;
                try {
                    r = Deploy.run(a, new Deploy.Step() {
                        public void on(final String l, final String d, final float overall) {
                            a.ui().post(new Runnable() {
                                public void run() { paint(l, d, overall); }
                            });
                        }
                    }, cancel);
                } catch (Exception e) {
                    String m = e.getMessage();
                    err = (m == null || m.length() == 0) ? e.getClass().getSimpleName() : m;
                }
                final Deploy.Result fr = r;
                final String fe = err;
                a.ui().post(new Runnable() {
                    public void run() { finish(a, fr, fe); }
                });
            }
        }).start();
    }

    private void paint(String l, String d, float overall) {
        if (label != null) { label.setText(l); }
        if (detail != null) { detail.setText(d == null ? "" : d); }
        if (bar != null) { bar.setValue(overall); }
        if (logBox != null && l != null && !l.equals(lastStep)) {
            lastStep = l;
            Context c = logBox.getContext();
            Ui.add(logBox, Ui.sub(c, "· " + l + (Store.blank(d) ? "" : "：" + d)), 6);
        }
    }

    private void finish(MainActivity a, Deploy.Result r, String err) {
        done = true;
        boolean bad = (err != null) || (r != null && !r.ok);

        if (bar != null) { bar.setValue(1f); }
        if (label != null) { label.setText(err != null ? "出错了" : (bad ? "未完成" : "全部完成")); }
        if (detail != null) {
            detail.setText(err != null ? err
                    : (bad ? "没有可部署的站点" : "压缩包已保存到下载目录"));
        }

        Context c = a;
        if (r != null && r.lines.size() > 0) {
            LinearLayout res = Ui.card(a);
            res.addView(Ui.title(a, "结果"));
            for (int i = 0; i < r.lines.size(); i++) {
                Ui.add(res, Ui.sub(c, "· " + r.lines.get(i)), 6);
            }
            String where = Store.blank(r.outDirText) ? Deploy.outDirPath() : r.outDirText;
            Ui.add(res, Ui.label(c, "下载位置"), 12);
            Ui.add(res, Ui.mono(c, where, Ui.primary(c)), 6);
            Ui.add(resultBox, res, 14);
        }

        if (r != null && r.ok && !bad) {
            LinearLayout tip = Ui.card(a);
            tip.setBackground(Ui.fill(a, 20, Ui.surfaceLow(a)));
            tip.addView(Ui.title(a, Store.MODE_AUTO.equals(Store.mode) ? "已自动上传" : "下一步"));
            Ui.add(tip, Ui.sub(c, Store.MODE_AUTO.equals(Store.mode)
                    ? "整站文件已传到你的主机。用浏览器打开站点域名，按向导完成初始化即可。"
                    : "把压缩包解压后上传到主机根目录，再访问域名完成初始化。"), 8);
            Ui.add(resultBox, tip, 14);
        }

        if (action != null) {
            action.setText(bad ? "返回" : "完成");
            action.setBackground(Ui.clickable(a, 22, Ui.primary(a), 0xFFFFFFFF));
            action.setTextColor(Ui.onPrimary(a));
        }
    }

    private void askCancel(final MainActivity a) {
        LinearLayout sheet = Ui.card(a);
        sheet.addView(Ui.title(a, "停止部署？"));
        Ui.add(sheet, Ui.sub(a, "正在进行的下载会被中断，已经完成的站点不受影响。"), 8);
        LinearLayout row = Ui.row(a);
        row.setPadding(0, Ui.dp(a, 16), 0, 0);
        TextView cancelBtn = Ui.button(a, "继续", 2);
        cancelBtn.setLayoutParams(new LinearLayout.LayoutParams(0,
                ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        cancelBtn.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) { a.closeSheet(); }
        });
        TextView ok = Ui.button(a, "停止", 3);
        LinearLayout.LayoutParams olp = new LinearLayout.LayoutParams(0,
                ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        olp.leftMargin = Ui.dp(a, 10);
        ok.setLayoutParams(olp);
        ok.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) {
                cancel.cancel();
                a.closeSheet();
                if (label != null) { label.setText("正在停止…"); }
                if (action != null) { action.setText("正在停止…"); }
            }
        });
        row.addView(cancelBtn);
        row.addView(ok);
        sheet.addView(row);
        a.showSheet(sheet);
    }

    /** 离开这一屏：任务还在跑就取消 */
    public void onLeave() {
        if (!done) { cancel.cancel(); }
    }
}
