package com.kimigame.deploy;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Typeface;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.button.MaterialButtonToggleGroup;
import com.google.android.material.card.MaterialCardView;
import com.google.android.material.floatingactionbutton.FloatingActionButton;
import com.google.android.material.progressindicator.LinearProgressIndicator;
import com.google.android.material.textfield.TextInputEditText;
import com.google.android.material.textfield.TextInputLayout;
import com.google.android.material.textview.MaterialTextView;

/**
 * 原生 MD3 工具箱。
 *
 * 只做三件事：取色、换算尺寸、造 Material 控件。
 * 关键约定：MaterialCardView 是 FrameLayout 系，不能直接堆多个子视图（会叠成一团），
 * 所以 card() 会在卡内自动套一个垂直 LinearLayout，所有内容经 add() 落到这个内层容器。
 */
final class Ui {

    private Ui() { }

    /** 二选一 / 择一回调 */
    interface Pick { void on(int index); }

    /* ============================ 取色 ============================ */

    static int c(Context x, int res) {
        return x.getResources().getColor(res, x.getTheme());
    }

    static int primary(Context x) { return c(x, R.color.kd_primary); }
    static int onPrimary(Context x) { return c(x, R.color.kd_on_primary); }
    static int primaryContainer(Context x) { return c(x, R.color.kd_primary_container); }
    static int onPrimaryContainer(Context x) { return c(x, R.color.kd_on_primary_container); }
    static int surface(Context x) { return c(x, R.color.kd_surface); }
    static int surfaceLow(Context x) { return c(x, R.color.kd_surface_low); }
    static int surfaceBox(Context x) { return c(x, R.color.kd_surface_container); }
    static int surfaceHigh(Context x) { return c(x, R.color.kd_surface_high); }
    static int onSurface(Context x) { return c(x, R.color.kd_on_surface); }
    static int onVariant(Context x) { return c(x, R.color.kd_on_surface_variant); }
    static int outline(Context x) { return c(x, R.color.kd_outline); }
    static int outlineVariant(Context x) { return c(x, R.color.kd_outline_variant); }
    static int error(Context x) { return c(x, R.color.kd_error); }
    static int ok(Context x) { return c(x, R.color.kd_ok); }
    static int warn(Context x) { return c(x, R.color.kd_warn); }

    /* ============================ 尺寸 ============================ */

    static int dp(Context x, float v) {
        return Math.round(v * x.getResources().getDisplayMetrics().density);
    }

    /* ============================ 文本 ============================ */

    static MaterialTextView text(Context x, CharSequence s, float sp, int color, boolean medium) {
        MaterialTextView t = new MaterialTextView(x);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        t.setText(s);
        t.setTextColor(color);
        t.setTypeface(Typeface.create(medium ? "sans-serif-medium" : "sans-serif", Typeface.NORMAL));
        t.setLineSpacing(dp(x, 5), 1f);
        return t;
    }

    static MaterialTextView headline(Context x, String s) {
        MaterialTextView t = text(x, s, 26, onSurface(x), true);
        t.setLetterSpacing(0f);
        return t;
    }

    static MaterialTextView title(Context x, String s) { return text(x, s, 18, onSurface(x), true); }
    static MaterialTextView body(Context x, String s) { return text(x, s, 15, onSurface(x), false); }
    static MaterialTextView sub(Context x, String s) { return text(x, s, 14, onVariant(x), false); }

    static MaterialTextView label(Context x, String s) {
        MaterialTextView t = text(x, s, 13, onVariant(x), true);
        t.setLetterSpacing(0.03f);
        return t;
    }

    static MaterialTextView mono(Context x, String s, int color) {
        MaterialTextView t = text(x, s, 13, color, false);
        t.setTypeface(Typeface.MONOSPACE);
        return t;
    }

    /** 键值行：左灰键、右深色值；小屏自动换行不重叠 */
    static LinearLayout kv(Context x, String k, String v) {
        LinearLayout r = row(x);
        r.addView(text(x, k, 14, onVariant(x), false));
        MaterialTextView val = text(x, v, 14, onSurface(x), true);
        val.setGravity(Gravity.END);
        val.setLayoutParams(new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        r.addView(val);
        return r;
    }

    /* ============================ 容器 ============================ */

    static LinearLayout row(Context x) {
        LinearLayout l = new LinearLayout(x);
        l.setOrientation(LinearLayout.HORIZONTAL);
        l.setGravity(Gravity.CENTER_VERTICAL);
        return l;
    }

    static LinearLayout col(Context x) {
        LinearLayout l = new LinearLayout(x);
        l.setOrientation(LinearLayout.VERTICAL);
        return l;
    }

    static View gap(Context x, float d) {
        View v = new View(x);
        v.setLayoutParams(new LinearLayout.LayoutParams(1, dp(x, d)));
        return v;
    }

    /** 往容器加一个满宽子视图。若容器是卡片，会自动落到其内层垂直容器，避免叠图。 */
    static void add(ViewGroup p, View c, float topDp) {
        ViewGroup host = contentOf(p);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.topMargin = dp(p.getContext(), topDp);
        host.addView(c, lp);
    }

    /** 取容器的内容宿主：卡片取内层 LinearLayout，其余就是它自己 */
    static ViewGroup contentOf(ViewGroup p) {
        Object t = p.getTag(R.id.card_content);
        return (t instanceof ViewGroup) ? (ViewGroup) t : p;
    }

    static MaterialCardView card(Context x) {
        return card(x, R.color.kd_surface_low, 18);
    }

    /** 卡片：Material 外观 + 内层垂直 LinearLayout（内容宿主） */
    static MaterialCardView card(Context x, int bgColorRes, float padDp) {
        MaterialCardView c = new MaterialCardView(x);
        c.setCardBackgroundColor(c(x, bgColorRes));
        c.setRadius(dp(x, 22));
        c.setCardElevation(0f);
        c.setStrokeWidth(0);
        LinearLayout inner = new LinearLayout(x);
        inner.setOrientation(LinearLayout.VERTICAL);
        int p = dp(x, padDp);
        inner.setPadding(p, p, p, p);
        c.addView(inner, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        c.setTag(R.id.card_content, inner);
        c.setLayoutParams(new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        return c;
    }

    /* ============================ 控件 ============================ */

    static MaterialButton button(Context x, String t) {
        MaterialButton b = new MaterialButton(x);
        b.setText(t);
        b.setAllCaps(false);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        b.setBackgroundTintList(ColorStateList.valueOf(primary(x)));
        b.setTextColor(onPrimary(x));
        b.setMinHeight(dp(x, 48));
        return b;
    }

    static MaterialButton tonal(Context x, String t) {
        MaterialButton b = new MaterialButton(x);
        b.setText(t);
        b.setAllCaps(false);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        b.setBackgroundTintList(ColorStateList.valueOf(primaryContainer(x)));
        b.setTextColor(onPrimaryContainer(x));
        b.setMinHeight(dp(x, 48));
        return b;
    }

    static MaterialButton outlined(Context x, String t) {
        MaterialButton b = new MaterialButton(x);
        b.setText(t);
        b.setAllCaps(false);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        b.setBackgroundTintList(ColorStateList.valueOf(0x00000000));
        b.setTextColor(primary(x));
        b.setStrokeColor(ColorStateList.valueOf(outline(x)));
        b.setStrokeWidth(dp(x, 1));
        b.setMinHeight(dp(x, 48));
        return b;
    }

    static MaterialButton danger(Context x, String t) {
        MaterialButton b = new MaterialButton(x);
        b.setText(t);
        b.setAllCaps(false);
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        b.setBackgroundTintList(ColorStateList.valueOf(error(x)));
        b.setTextColor(c(x, R.color.kd_on_error));
        b.setMinHeight(dp(x, 48));
        return b;
    }

    /** 文本输入（Material 的 TextInputLayout，自带浮动标签与错误态动画） */
    static TextInputLayout input(Context x, String hint, int inputType, boolean singleLine) {
        TextInputLayout t = new TextInputLayout(x);
        t.setHint(hint);
        t.setLayoutParams(new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        TextInputEditText e = new TextInputEditText(x);
        e.setInputType(inputType);
        e.setSingleLine(singleLine);
        e.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        t.addView(e);
        return t;
    }

    static TextInputEditText edit(TextInputLayout t) {
        return (TextInputEditText) t.getEditText();
    }

    /** 分段控件（Material 的 ButtonToggleGroup，原生切换动画） */
    static MaterialButtonToggleGroup segment(Context x, String[] labels, int sel, final Pick p) {
        MaterialButtonToggleGroup g = new MaterialButtonToggleGroup(x);
        g.setSingleSelection(true);
        g.setSelectionRequired(true);
        final int[] ids = new int[labels.length];
        for (int i = 0; i < labels.length; i++) {
            MaterialButton b = outlined(x, labels[i]);
            b.setId(View.generateViewId());
            ids[i] = b.getId();
            g.addView(b);
        }
        if (sel >= 0 && sel < ids.length) { g.check(ids[sel]); }
        g.addOnButtonCheckedListener(new MaterialButtonToggleGroup.OnButtonCheckedListener() {
            public void onButtonChecked(MaterialButtonToggleGroup group, int checkedId, boolean isChecked) {
                if (!isChecked) { return; }
                for (int i = 0; i < ids.length; i++) {
                    if (ids[i] == checkedId) { p.on(i); return; }
                }
            }
        });
        return g;
    }

    /** 悬浮添加按钮 */
    static FloatingActionButton fab(Context x, int iconRes, View.OnClickListener l) {
        FloatingActionButton f = new FloatingActionButton(x);
        f.setImageResource(iconRes);
        f.setContentDescription("添加");
        FrameLayout.LayoutParams lp = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.gravity = Gravity.END | Gravity.BOTTOM;
        lp.setMargins(0, 0, dp(x, 20), dp(x, 20));
        f.setLayoutParams(lp);
        f.setOnClickListener(l);
        return f;
    }

    /** 矢量图标 */
    static ImageView icon(Context x, int res, int color, float sizeDp) {
        ImageView iv = new ImageView(x);
        iv.setImageResource(res);
        iv.setColorFilter(color);
        int s = dp(x, sizeDp);
        iv.setLayoutParams(new LinearLayout.LayoutParams(s, s));
        iv.setScaleType(ImageView.ScaleType.CENTER_INSIDE);
        iv.setClickable(false);
        return iv;
    }

    /** 把图标变成可点（原生 selectableItemBackgroundBorderless 涟漪） */
    static void touchable(View v) {
        TypedValue tv = new TypedValue();
        v.getContext().getTheme().resolveAttribute(
                android.R.attr.selectableItemBackgroundBorderless, tv, true);
        v.setBackgroundResource(tv.resourceId);
        v.setClickable(true);
        int p = dp(v.getContext(), 8);
        v.setPadding(p, p, p, p);
    }

    static LinearProgressIndicator bar(Context x) {
        LinearProgressIndicator p = new LinearProgressIndicator(x);
        p.setTrackCornerRadius(dp(x, 4));
        p.setTrackThickness(dp(x, 6));
        p.setIndicatorColor(primary(x));
        p.setTrackColor(surfaceHigh(x));
        p.setMax(1000);
        p.setProgress(0);
        return p;
    }

    /* ============================ 顶栏 ============================ */

    /** 顶栏：标题 + 可选返回（返回键走原生涟漪） */
    static LinearLayout topBar(final MainActivity a, String title, boolean back) {
        LinearLayout bar = row(a);
        bar.setPadding(dp(a, 6), dp(a, 10), dp(a, 16), dp(a, 10));
        if (back) {
            ImageView b = icon(a, R.drawable.ic_back, onSurface(a), 24);
            touchable(b);
            b.setOnClickListener(new View.OnClickListener() {
                public void onClick(View v) { a.pop(); }
            });
            bar.addView(b);
        }
        MaterialTextView t = title(a, title);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, 20);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        lp.leftMargin = dp(a, 8);
        t.setLayoutParams(lp);
        bar.addView(t);
        return bar;
    }
}
