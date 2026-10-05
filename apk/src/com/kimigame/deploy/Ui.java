package com.kimigame.deploy;

import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.Typeface;
import android.graphics.drawable.Drawable;
import android.graphics.drawable.GradientDrawable;
import android.graphics.drawable.RippleDrawable;
import android.graphics.drawable.StateListDrawable;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

/**
 * MD3 界面工具箱。
 *
 * 设计约束（与站点的视觉规范一致）：
 *   · 全部矢量：图标是 res/drawable 下的 vector，运行时 setColorFilter 上色；
 *   · 不使用任何第三方 UI 库（Material Components 也不引入），
 *     圆角、涟漪、描边、进度条全部用框架 API 自绘；
 *   · 颜色一律走 @color/md_*，深浅色由 values-night 自动切换。
 */
final class Ui {

    private Ui() { }

    /* ============================ 令牌 ============================ */

    static int c(Context ctx, int res) {
        return ctx.getResources().getColor(res, ctx.getTheme());
    }

    static int primary(Context c) { return c(c, R.color.md_primary); }
    static int onPrimary(Context c) { return c(c, R.color.md_on_primary); }
    static int primaryContainer(Context c) { return c(c, R.color.md_primary_container); }
    static int onPrimaryContainer(Context c) { return c(c, R.color.md_on_primary_container); }
    static int surface(Context c) { return c(c, R.color.md_surface); }
    static int surfaceLow(Context c) { return c(c, R.color.md_surface_container_low); }
    static int surfaceBox(Context c) { return c(c, R.color.md_surface_container); }
    static int surfaceHigh(Context c) { return c(c, R.color.md_surface_container_high); }
    static int onSurface(Context c) { return c(c, R.color.md_on_surface); }
    static int onVariant(Context c) { return c(c, R.color.md_on_surface_variant); }
    static int outline(Context c) { return c(c, R.color.md_outline); }
    static int outlineVariant(Context c) { return c(c, R.color.md_outline_variant); }
    static int error(Context c) { return c(c, R.color.md_error); }
    static int ok(Context c) { return c(c, R.color.md_ok); }
    static int warn(Context c) { return c(c, R.color.md_warn); }

    static int dp(Context c, float v) {
        return Math.round(v * c.getResources().getDisplayMetrics().density);
    }

    /* ============================ 形状 ============================ */

    private static GradientDrawable shape(Context c, float radiusDp, int fill, int strokeColor, float strokeDp) {
        GradientDrawable g = new GradientDrawable();
        g.setShape(GradientDrawable.RECTANGLE);
        g.setColor(fill);
        float r = dp(c, radiusDp);
        g.setCornerRadius(r);
        if (strokeColor != 0) { g.setStroke(Math.max(1, dp(c, strokeDp)), strokeColor); }
        return g;
    }

    /** 纯填充圆角 */
    static GradientDrawable fill(Context c, float radiusDp, int color) {
        return shape(c, radiusDp, color, 0, 0);
    }

    /** 填充 + 描边圆角 */
    static GradientDrawable stroked(Context c, float radiusDp, int fill, int stroke, float strokeDp) {
        return shape(c, radiusDp, fill, stroke, strokeDp);
    }

    /** 带涟漪的可点击背景（MD3 的 state layer） */
    static Drawable clickable(Context c, float radiusDp, int fill, int rippleColor) {
        Drawable content = shape(c, radiusDp, fill, 0, 0);
        Drawable mask = shape(c, radiusDp, 0xFFFFFFFF, 0, 0);
        return new RippleDrawable(ColorStateList.valueOf(withAlpha(rippleColor, 0.18f)), content, mask);
    }

    /** 输入框背景：默认细描边，聚焦时主色加粗 */
    static StateListDrawable fieldBg(Context c) {
        StateListDrawable s = new StateListDrawable();
        s.addState(new int[]{android.R.attr.state_focused},
                shape(c, 12, surfaceLow(c), primary(c), 2));
        s.addState(new int[]{android.R.attr.state_enabled},
                shape(c, 12, surfaceLow(c), outlineVariant(c), 1));
        s.addState(new int[]{},
                shape(c, 12, surfaceLow(c), outlineVariant(c), 1));
        return s;
    }

    static int withAlpha(int color, float a) {
        int al = Math.max(0, Math.min(255, Math.round(255 * a)));
        return (color & 0x00FFFFFF) | (al << 24);
    }

    /* ============================ 文本 ============================ */

    private static TextView tv(Context c, String text, float sp, int color, boolean medium) {
        TextView t = new TextView(c);
        t.setText(text);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        t.setTextColor(color);
        t.setTypeface(Typeface.create(medium ? "sans-serif-medium" : "sans-serif", Typeface.NORMAL));
        t.setLineSpacing(dp(c, 3), 1f);
        return t;
    }

    /** 大标题 */
    static TextView headline(Context c, String s) {
        TextView t = tv(c, s, 24, onSurface(c), true);
        t.setLetterSpacing(-0.01f);
        return t;
    }

    /** 卡片/区块标题 */
    static TextView title(Context c, String s) {
        return tv(c, s, 16, onSurface(c), true);
    }

    /** 正文 */
    static TextView body(Context c, String s) {
        return tv(c, s, 14.5f, onSurface(c), false);
    }

    /** 次要说明 */
    static TextView sub(Context c, String s) {
        return tv(c, s, 12.5f, onVariant(c), false);
    }

    /** 字段标签（表单用，MD3 的 label 小一号） */
    static TextView label(Context c, String s) {
        TextView t = tv(c, s, 12.5f, onVariant(c), true);
        t.setLetterSpacing(0.01f);
        return t;
    }

    /** 等宽（展示 token / sha256 / 路径） */
    static TextView mono(Context c, String s, int color) {
        TextView t = tv(c, s, 12.5f, color, false);
        t.setTypeface(Typeface.MONOSPACE);
        return t;
    }

    /* ============================ 容器 ============================ */

    static LinearLayout row(Context c) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.HORIZONTAL);
        l.setGravity(Gravity.CENTER_VERTICAL);
        return l;
    }

    static LinearLayout col(Context c) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.VERTICAL);
        return l;
    }

    /** MD3 卡片：填充 + 20dp 圆角 + 内边距 */
    static LinearLayout card(Context c) {
        LinearLayout l = col(c);
        l.setBackground(fill(c, 20, surfaceBox(c)));
        int p = dp(c, 16);
        l.setPadding(p, p, p, p);
        return l;
    }

    static View gap(Context c, float h) {
        View v = new View(c);
        v.setLayoutParams(new LinearLayout.LayoutParams(1, dp(c, h)));
        return v;
    }

    static View divider(Context c) {
        View v = new View(c);
        v.setBackgroundColor(outlineVariant(c));
        v.setLayoutParams(new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, Math.max(1, dp(c, 0.7f))));
        return v;
    }

    /* ============================ 图标 ============================ */

    static ImageView icon(Context c, int res, int color, float sizeDp) {
        ImageView iv = new ImageView(c);
        iv.setImageResource(res);
        iv.setColorFilter(color);
        iv.setScaleType(ImageView.ScaleType.FIT_CENTER);
        int s = dp(c, sizeDp);
        iv.setLayoutParams(new LinearLayout.LayoutParams(s, s));
        return iv;
    }

    /* ============================ 组件 ============================ */

    /** MD3 按钮（filled / tonal / outlined 三态用同一实现） */
    static TextView button(Context c, String text, int style) {
        // style: 0=filled 1=tonal 2=outlined 3=danger
        int bg, fg;
        if (style == 0) { bg = primary(c); fg = onPrimary(c); }
        else if (style == 1) { bg = primaryContainer(c); fg = onPrimaryContainer(c); }
        else if (style == 3) { bg = withAlpha(error(c), 0.12f); fg = error(c); }
        else { bg = 0; fg = primary(c); }

        TextView t = tv(c, text, 14.5f, fg, true);
        t.setGravity(Gravity.CENTER);
        t.setMinHeight(dp(c, 44));
        int px = dp(c, 20), py = dp(c, 11);
        t.setPadding(px, py, px, py);
        if (style == 2) {
            t.setBackground(clickable(c, 22, 0, primary(c)));
            Drawable outlineOnly = stroked(c, 22, 0x00000000, outline(c), 1);
            t.setBackground(new RippleDrawable(ColorStateList.valueOf(withAlpha(primary(c), 0.14f)),
                    outlineOnly, stroked(c, 22, 0xFFFFFFFF, 0, 0)));
        } else {
            t.setBackground(clickable(c, 22, bg, fg == onPrimary(c) ? 0xFFFFFFFF : primary(c)));
        }
        t.setClickable(true);
        t.setFocusable(true);
        return t;
    }

    /** 输入框（MD3 outlined，前缀图标可选） */
    static EditText field(Context c, String hint, int inputType) {
        EditText e = new EditText(c);
        e.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14.5f);
        e.setTextColor(onSurface(c));
        e.setHintTextColor(outline(c));
        e.setHint(hint);
        e.setInputType(inputType);
        e.setBackground(fieldBg(c));
        e.setPadding(dp(c, 14), dp(c, 11), dp(c, 14), dp(c, 11));
        e.setSingleLine(true);
        e.setLayoutParams(new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        return e;
    }

    static EditText number(Context c, String hint) {
        return field(c, hint, InputType.TYPE_CLASS_NUMBER);
    }

    /** 多行输入（AI Key 池可以一行一个） */
    static EditText area(Context c, String hint, int lines) {
        EditText e = field(c, hint, InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE);
        e.setSingleLine(false);
        e.setLines(lines);
        e.setGravity(Gravity.TOP | Gravity.START);
        return e;
    }

    /** FAB（右下角悬浮按钮） */
    static FrameLayout fab(Context c, int iconRes, View.OnClickListener l) {
        FrameLayout f = new FrameLayout(c);
        int s = dp(c, 56);
        FrameLayout.LayoutParams lp = new FrameLayout.LayoutParams(s, s);
        lp.gravity = Gravity.END | Gravity.BOTTOM;
        lp.setMargins(0, 0, dp(c, 20), dp(c, 20));
        f.setLayoutParams(lp);
        f.setBackground(clickable(c, 16, primaryContainer(c), primary(c)));
        f.setElevation(dp(c, 6));
        f.setClickable(true);
        ImageView iv = icon(c, iconRes, onPrimaryContainer(c), 24);
        FrameLayout.LayoutParams ip = new FrameLayout.LayoutParams(dp(c, 24), dp(c, 24));
        ip.gravity = Gravity.CENTER;
        iv.setLayoutParams(ip);
        f.addView(iv);
        f.setOnClickListener(l);
        return f;
    }

    /** MD3 线性进度条（确定性） */
    static final class Bar extends View {
        private final Paint p = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final int trackColor, fillColor;
        private float value = 0f;

        Bar(Context c, int trackColor, int fillColor) {
            super(c);
            this.trackColor = trackColor;
            this.fillColor = fillColor;
            setLayoutParams(new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, dp(c, 6)));
        }

        void setValue(float v) {
            float nv = Math.max(0f, Math.min(1f, v));
            if (Math.abs(nv - value) < 0.001f) { return; }
            value = nv;
            invalidate();
        }

        float value() { return value; }

        @Override
        protected void onDraw(Canvas cv) {
            float h = getHeight(), w = getWidth(), r = h / 2f;
            p.setColor(trackColor);
            cv.drawRoundRect(0, 0, w, h, r, r, p);
            if (value > 0f) {
                p.setColor(fillColor);
                float fw = Math.max(h, w * value);
                cv.drawRoundRect(0, 0, fw, h, r, r, p);
            }
        }
    }

    static Bar bar(Context c) {
        return new Bar(c, surfaceHigh(c), primary(c));
    }

    /** 把 View 加进带边距的纵向容器 */
    static void add(LinearLayout parent, View child, float topDp) {
        if (topDp > 0) {
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            lp.topMargin = dp(parent.getContext(), topDp);
            child.setLayoutParams(lp);
        }
        parent.addView(child);
    }

    /** 左侧标签 + 右侧值的两列行（凭证/结果展示用） */
    static LinearLayout kv(Context c, String k, String v) {
        LinearLayout r = row(c);
        TextView kk = tv(c, k, 12.5f, onVariant(c), false);
        kk.setLayoutParams(new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        TextView vv = tv(c, v, 12.5f, onSurface(c), true);
        vv.setGravity(Gravity.END);
        r.addView(kk);
        r.addView(vv);
        return r;
    }
}
