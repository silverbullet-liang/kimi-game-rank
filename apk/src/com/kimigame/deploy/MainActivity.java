package com.kimigame.deploy;

import android.app.Activity;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.TextView;

import java.util.ArrayList;

/**
 * 单 Activity + 自管理屏栈。
 *
 * 为什么不用 Fragment / Navigation：这两个都要拉 androidx 依赖，
 * 本工程刻意零依赖（只内置一个 SSH 库），屏栈自己管更可控。
 * 状态全部在 Store 里，屏只管「照着 Store 画一遍」，所以返回时重建也不会丢东西。
 */
public class MainActivity extends Activity {

    /** 一屏 = 一个能画出自己界面的对象（无状态，重建即可）；Activity 由外部传入，不静态持有 */
    public interface Screen {
        View render(MainActivity a);
    }

    /** 可选：这一屏被移出栈时回调（进度页靠它取消后台任务） */
    public interface Leavable {
        void onLeave();
    }

    private static void leave(Screen s) {
        if (s instanceof Leavable) { ((Leavable) s).onLeave(); }
    }

    private FrameLayout root;
    private FrameLayout content;
    private FrameLayout sheetLayer;
    private View sheetView;
    private final ArrayList<Screen> stack = new ArrayList<Screen>();
    private Handler ui;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Store.load(this);
        ui = new Handler(Looper.getMainLooper());

        root = new FrameLayout(this);
        root.setBackgroundColor(Ui.surface(this));

        content = new FrameLayout(this);
        root.addView(content, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        sheetLayer = new FrameLayout(this);
        sheetLayer.setVisibility(View.GONE);
        root.addView(sheetLayer, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        setContentView(root);
        push(new Screens.Start());
    }

    public Handler ui() { return ui; }

    public void push(Screen s) {
        stack.add(s);
        rebuild();
    }

    public void pop() {
        if (stack.size() > 1) {
            leave(stack.remove(stack.size() - 1));
            rebuild();
        }
    }

    /** 清空屏栈换成新的一屏（部署完成后回列表用） */
    public void reset(Screen s) {
        for (int i = 0; i < stack.size(); i++) { leave(stack.get(i)); }
        stack.clear();
        stack.add(s);
        rebuild();
    }

    public int depth() { return stack.size(); }

    /** 重绘当前屏（表单里切换枚举、进度刷新用；不动屏栈） */
    public void refresh() { rebuild(); }

    private void rebuild() {
        closeSheet();
        content.removeAllViews();
        content.addView(stack.get(stack.size() - 1).render(this));
    }

    @Override
    public void onBackPressed() {
        if (sheetOpen()) { closeSheet(); return; }
        if (stack.size() > 1) { pop(); return; }
        super.onBackPressed();
    }

    /* ============================ 覆盖层 ============================ */

    /** 弹出一张卡片（选择列表 / 确认 / 结果），点遮罩关闭 */
    public void showSheet(View card) {
        closeSheet();
        FrameLayout scrim = new FrameLayout(this);
        scrim.setBackgroundColor(Ui.c(this, R.color.md_scrim));
        scrim.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) { closeSheet(); }
        });
        FrameLayout holder = new FrameLayout(this);
        FrameLayout.LayoutParams lp = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.gravity = Gravity.CENTER;
        lp.setMargins(Ui.dp(this, 20), Ui.dp(this, 24), Ui.dp(this, 20), Ui.dp(this, 24));
        holder.setLayoutParams(lp);
        holder.addView(card, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        holder.setOnClickListener(new View.OnClickListener() {
            public void onClick(View v) { /* 吞掉点击，避免穿透到遮罩 */ }
        });
        scrim.addView(holder);
        sheetLayer.addView(scrim, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        sheetLayer.setVisibility(View.VISIBLE);
        sheetView = scrim;
    }

    public void closeSheet() {
        if (sheetView != null) {
            sheetLayer.removeAllViews();
            sheetLayer.setVisibility(View.GONE);
            sheetView = null;
        }
    }

    public boolean sheetOpen() { return sheetView != null; }

    /* ============================ 底部提示 ============================ */

    public void toast(String msg) {
        final TextView t = Ui.body(this, msg);
        t.setTextColor(Ui.c(this, R.color.md_on_primary_container));
        t.setBackground(Ui.fill(this, 12, Ui.primaryContainer(this)));
        t.setPadding(Ui.dp(this, 16), Ui.dp(this, 12), Ui.dp(this, 16), Ui.dp(this, 12));
        FrameLayout.LayoutParams lp = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.gravity = Gravity.BOTTOM | Gravity.CENTER_HORIZONTAL;
        lp.bottomMargin = Ui.dp(this, 96);
        t.setLayoutParams(lp);
        t.setElevation(Ui.dp(this, 6));
        root.addView(t);
        ui.postDelayed(new Runnable() {
            public void run() {
                try { root.removeView(t); } catch (Exception ignored) { }
            }
        }, 2600);
    }

    /* ============================ 短工具 ============================ */

    public static ViewGroup.LayoutParams match() {
        return new ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT);
    }
}
