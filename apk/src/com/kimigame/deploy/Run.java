package com.kimigame.deploy;

import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.progressindicator.LinearProgressIndicator;
import com.google.android.material.textview.MaterialTextView;

/**
 * 全屏进度页。
 *
 * 进来自动开跑（下载 → 解压 → 删冗余 → 处理站点 → 打包 → 存下载；自动部署末尾多一步上传）。
 * 所有更新都 post 回 UI 线程；退出这一屏会取消任务。进度条用 Material 的 LinearProgressIndicator。
 */
final class Run implements MainActivity.Screen, MainActivity.Leavable {

    private final Net.Cancel cancel = new Net.Cancel();
    private volatile boolean started = false;
    private volatile boolean done = false;

    private LinearProgressIndicator bar;
    private MaterialTextView label;
    private MaterialTextView detail;
    private LinearLayout logBox;
    private LinearLayout resultBox;
    private MaterialButton action;
    private String lastStep = "";

    public View render(final MainActivity a) {
        LinearLayout bd = Screens.body(a);

        /* ---- 进度 ---- */
        MaterialCardView top = Ui.card(a);
        label = Ui.title(a, "正在准备…");
        detail = Ui.sub(a, "");
        bar = Ui.bar(a);
        Ui.add(top, label, 0);
        Ui.add(top, detail, 8);
        Ui.add(top, bar, 16);
        bd.addView(top);

        /* ---- 过程记录 ---- */
        MaterialCardView logCard = Ui.card(a);
        logBox = Ui.col(a);
        Ui.add(logCard, Ui.title(a, "过程记录"), 0);
        Ui.add(logCard, logBox, 10);
        Ui.add(bd, logCard, 14);

        resultBox = Ui.col(a);
        bd.addView(resultBox);

        action = Ui.outlined(a, "取消");
        action.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) {
                if (!done) { askCancel(a); } else { a.pop(); }
            }
        });
        Ui.add(bd, action, 20);

        if (!started) {
            started = true;
            start(a);
        }
        return Screens.page(a, Ui.topBar(a, "正在部署", true), bd);
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
        if (bar != null) { bar.setProgressCompat(Math.round(overall * 1000f), true); }
        if (logBox != null && l != null && !l.equals(lastStep)) {
            lastStep = l;
            Ui.add(logBox, Ui.sub(logBox.getContext(), "· " + l
                    + (Store.blank(d) ? "" : "：" + d)), 8);
        }
    }

    private void finish(MainActivity a, Deploy.Result r, String err) {
        done = true;
        boolean bad = (err != null) || (r != null && !r.ok);

        if (bar != null) { bar.setProgressCompat(1000, true); }
        if (label != null) { label.setText(err != null ? "出错了" : (bad ? "未完成" : "全部完成")); }
        if (detail != null) {
            detail.setText(err != null ? err
                    : (bad ? "没有可部署的站点" : "压缩包已保存到下载目录"));
        }

        if (r != null && r.lines.size() > 0) {
            MaterialCardView res = Ui.card(a);
            Ui.add(res, Ui.title(a, "结果"), 0);
            for (int i = 0; i < r.lines.size(); i++) {
                Ui.add(res, Ui.sub(a, "· " + r.lines.get(i)), 8);
            }
            String where = Store.blank(r.outDirText) ? Deploy.outDirPath() : r.outDirText;
            Ui.add(res, Ui.label(a, "下载位置"), 14);
            Ui.add(res, Ui.mono(a, where, Ui.primary(a)), 6);
            Ui.add(resultBox, res, 14);
        }

        if (r != null && r.ok && !bad) {
            MaterialCardView tip = Ui.card(a);
            Ui.add(tip, Ui.title(a, Store.MODE_AUTO.equals(Store.mode) ? "已自动上传" : "下一步"), 0);
            Ui.add(tip, Ui.sub(a, Store.MODE_AUTO.equals(Store.mode)
                    ? "整站文件已传到你的主机。用浏览器打开站点域名，按向导完成初始化即可。"
                    : "把压缩包解压后上传到主机根目录，再访问域名完成初始化。"), 10);
            Ui.add(resultBox, tip, 14);
        }

        if (action != null) {
            action.setText(bad ? "返回" : "完成");
            action.setBackgroundTintList(android.content.res.ColorStateList.valueOf(Ui.primary(a)));
            action.setTextColor(Ui.onPrimary(a));
            action.setStrokeWidth(0);
        }
    }

    private void askCancel(final MainActivity a) {
        LinearLayout sheet = Ui.col(a);
        sheet.addView(Ui.title(a, "停止部署？"));
        Ui.add(sheet, Ui.sub(a, "正在进行的下载会被中断，已经完成的站点不受影响。"), 10);
        LinearLayout row = Ui.row(a);
        row.setPadding(0, Ui.dp(a, 16), 0, 0);
        MaterialButton cancelBtn = Ui.outlined(a, "继续");
        cancelBtn.setLayoutParams(new LinearLayout.LayoutParams(0,
                ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        cancelBtn.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) { a.closeSheet(); }
        });
        MaterialButton ok = Ui.danger(a, "停止");
        LinearLayout.LayoutParams olp = new LinearLayout.LayoutParams(0,
                ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        olp.leftMargin = Ui.dp(a, 12);
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
        Ui.add(sheet, row, 0);
        a.showSheet(Screens.padSheet(a, sheet));
    }

    /** 离开这一屏：任务还在跑就取消 */
    public void onLeave() {
        if (!done) { cancel.cancel(); }
    }
}
