package com.kimigame.deploy;

import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;

import androidx.appcompat.app.AppCompatActivity;
import androidx.coordinatorlayout.widget.CoordinatorLayout;

import com.google.android.material.bottomsheet.BottomSheetDialog;
import com.google.android.material.snackbar.Snackbar;

import java.util.ArrayList;

/**
 * 单 Activity + 自管理屏栈（基于 AppCompatActivity，跑 Material3 主题）。
 *
 * 屏状态全部在 Store 里，屏只管「照着 Store 画一遍」。
 * 覆盖层改用 Material 的 BottomSheetDialog（自带滑入动画）；
 * 底部提示改用 Snackbar。
 */
public class MainActivity extends AppCompatActivity {

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

    private CoordinatorLayout root;
    private FrameLayout content;
    private final ArrayList<Screen> stack = new ArrayList<Screen>();
    private Handler ui;
    private BottomSheetDialog sheet;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Store.load(this);
        ui = new Handler(Looper.getMainLooper());

        root = new CoordinatorLayout(this);
        root.setBackgroundColor(Ui.surface(this));

        content = new FrameLayout(this);
        root.addView(content, new FrameLayout.LayoutParams(
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

    /* ============================ 覆盖层（BottomSheet） ============================ */

    /** 弹出一张底部卡片（选择列表 / 确认 / 结果），下滑或点外部关闭 */
    public void showSheet(View card) {
        closeSheet();
        sheet = new BottomSheetDialog(this);
        sheet.setContentView(card);
        sheet.show();
    }

    public void closeSheet() {
        if (sheet != null) {
            sheet.dismiss();
            sheet = null;
        }
    }

    public boolean sheetOpen() { return sheet != null && sheet.isShowing(); }

    /* ============================ 底部提示 ============================ */

    public void toast(String msg) {
        Snackbar sb = Snackbar.make(root, msg, Snackbar.LENGTH_LONG);
        sb.setAction("知道了", new View.OnClickListener() {
            public void onClick(View v) { /* 收起 */ }
        });
        sb.show();
    }
}
