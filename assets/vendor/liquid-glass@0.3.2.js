// src/cdn/core/shaders/sdf.ts
var SDF_GLSL = `
// Corner style: 0 = circular (standard arc), 1 = continuous (squircle/superellipse).
// Declared here (in SDF_GLSL) because sdShape references it, and SDF_GLSL is
// included by multiple shaders (element, shadow, highlight, plain-rect).
uniform float uCornerStyle;

// --- Continuous-curvature SDF texture (capsule shape) ---
// When uUseContinuousSdf > 0.5, sdShape() dispatches to sdContinuousCurvature
// which samples a precomputed SDF texture (generated from the G2-continuous
// Bezier path in continuous-curve.ts). Only the dialog card sets this to 1;
// other shaders that include SDF_GLSL leave it at the default 0 — sdShape
// falls through to the analytic sdRoundedRect / sdContinuousRoundedRect path.
uniform sampler2D uContinuousSdf;
uniform float uUseContinuousSdf;        // 0 or 1
uniform float uNoContinuousSdfInRefraction;  // 0 or 1 — when 1, refraction/highlight SDF forces analytic sdRoundedRect (ignores uUseContinuousSdf). Mask/clip still uses uUseContinuousSdf.
uniform vec2  uContinuousSdfTexSize;    // SDF texture size in px (256, 256)
uniform vec2  uContinuousSdfElementSize; // element's original w,h in px

// radiusAt — picks the corner radius from cornerRadii based on which
// quadrant 'coord' is in. For uniform radii (the catalog case) this
// always returns the same value.
float radiusAt(vec2 coord, vec4 radii) {
    if (coord.x >= 0.0) {
        if (coord.y <= 0.0) return radii.y;
        else return radii.z;
    } else {
        if (coord.y <= 0.0) return radii.x;
        else return radii.w;
    }
}

// sdRoundedRect — signed distance to a rounded-rect boundary.
// Negative inside, positive outside, zero on the edge.
// Uses standard circular arcs for the corners.
float sdRoundedRect(vec2 coord, vec2 halfSize, float radius) {
    vec2 cornerCoord = abs(coord) - (halfSize - vec2(radius));
    float outside = length(max(cornerCoord, 0.0)) - radius;
    float inside = min(max(cornerCoord.x, cornerCoord.y), 0.0);
    return outside + inside;
}

// sdContinuousRoundedRect — continuous-curvature rounded rect.
// The original uses G2-continuous Bezier corners (ContinuousCurvatureRoundedRectangleCornerBuilder).
// The visual difference between Continuous and Circular is very subtle (only
// curvature continuity at the tangent points). For the SDF-based renderer,
// the circular arc SDF (sdRoundedRect) is a close enough approximation — the
// Bezier corners deviate from the arc by <0.5% of the radius, which is
// sub-pixel at typical element sizes.
//
// When uCornerStyle=1 (continuous), we use sdRoundedRect directly. The
// difference from the original is imperceptible. A future upgrade could
// implement exact Bezier SDF for pixel-perfect matching.
float sdContinuousRoundedRect(vec2 coord, vec2 halfSize, float radius) {
    return sdRoundedRect(coord, halfSize, radius);
}

// sampleClipMask — sample R channel (coverage) from the mask texture.
// Returns browser-native AA coverage [0,1] for clip + edgeAlpha.
float sampleClipMask(vec2 coord, vec2 halfSize, float radius) {
    float maxDim = max(max(uContinuousSdfElementSize.x, uContinuousSdfElementSize.y), 1e-4);
    float aspectW = uContinuousSdfElementSize.x / maxDim;
    float margin = 4.0;
    float drawW = (uContinuousSdfTexSize.x - 2.0 * margin) * aspectW;
    float scale = drawW / max(uContinuousSdfElementSize.x, 1e-4);
    vec2 tex = uContinuousSdfTexSize * 0.5 + coord * scale;
    vec2 uv = tex / uContinuousSdfTexSize;
    return texture2D(uContinuousSdf, uv).r;  // R = coverage [0,1]
}

// sampleClipSdf — sample G channel (SDF) from the mask texture.
// Returns signed distance: negative inside, positive outside, 0 at edge.
// Same shape as sampleClipMask (both from the same Bezier path), so clip
// and stroke shapes are always identical.
float sampleClipSdf(vec2 coord, vec2 halfSize, float radius) {
    float maxDim = max(max(uContinuousSdfElementSize.x, uContinuousSdfElementSize.y), 1e-4);
    float aspectW = uContinuousSdfElementSize.x / maxDim;
    float margin = 4.0;
    float drawW = (uContinuousSdfTexSize.x - 2.0 * margin) * aspectW;
    float scale = drawW / max(uContinuousSdfElementSize.x, 1e-4);
    vec2 tex = uContinuousSdfTexSize * 0.5 + coord * scale;
    vec2 uv = tex / uContinuousSdfTexSize;
    float g = texture2D(uContinuousSdf, uv).g;  // G = SDF [0,1]
    return (g * 2.0 - 1.0) * radius;  // decode to element-space distance
}

// sdClipShape — SDF for clip/discard when uUseContinuousSdf is OFF.
float sdClipShape(vec2 coord, vec2 halfSize, float radius) {
    return sdRoundedRect(coord, halfSize, radius);
}

// sdShape — SDF for refraction/highlight internal calculations.
// When uUseContinuousSdf=1 AND uNoContinuousSdfInRefraction=0, uses
// sampleClipSdf (same G2 shape as clip mask). Otherwise uses the analytic
// sdRoundedRect. This lets the "disable smooth SDF in glass" toggle strip
// the G2 SDF out of the refraction/lens computation while keeping the G2
// clip mask intact (capsuleShape still controls edge shape).
float sdShape(vec2 coord, vec2 halfSize, float radius) {
    if (uUseContinuousSdf > 0.5 && uNoContinuousSdfInRefraction < 0.5) {
        return sampleClipSdf(coord, halfSize, radius);
    }
    return sdRoundedRect(coord, halfSize, radius);
}

// gradSdRoundedRect — gradient of the SDF (points outward from edge).
// Used both for refraction direction and highlight specular.
vec2 gradSdRoundedRect(vec2 coord, vec2 halfSize, float radius) {
    vec2 cornerCoord = abs(coord) - (halfSize - vec2(radius));
    if (cornerCoord.x >= 0.0 || cornerCoord.y >= 0.0) {
        vec2 v = max(cornerCoord, vec2(0.0));
        // Guard against normalize(0,0) -> NaN
        float len = length(v);
        if (len < 1e-6) return vec2(0.0);
        return sign(coord) * (v / len);
    } else {
        float gradX = step(cornerCoord.y, cornerCoord.x);
        return sign(coord) * vec2(gradX, 1.0 - gradX);
    }
}

// rotateBy — rotate a 2D vector by angle (radians). Used to un-rotate the
// sample coord into the element's local space (so the SDF shape appears
// rotated by +uElementRotation), and to rotate refraction offsets back to
// screen space.
vec2 rotateBy(vec2 v, float angle) {
    float c = cos(angle);
    float s = sin(angle);
    return vec2(v.x * c - v.y * s, v.x * s + v.y * c);
}

// erfApprox — error function approximation (Abramowitz & Stegun 7.1.26).
// Max error < 2.5e-5. Used by inner shadow to model BlurMaskFilter's
// Gaussian convolution of a ring shape. erf(x) ∈ [-1, 1].
float erfApprox(float x) {
    float a = abs(x);
    float t = 1.0 / (1.0 + 0.47047 * a);
    float y = 1.0 - (((0.3480242 * t - 0.0958798) * t + 0.7478556) * t * exp(-a * a));
    return sign(x) * y;
}
`;
var COVER_GLSL = `
// Returns wallpaper UV for a canvas pixel coordinate (top-left origin).
vec2 coverUv(vec2 canvasPx) {
    float canvasAspect = uCanvasSize.x / uCanvasSize.y;
    float wpAspect = uWallpaperSize.x / uWallpaperSize.y;
    vec2 uv = canvasPx / uCanvasSize;
    if (wpAspect > canvasAspect) {
        // Wallpaper is wider than canvas — crop horizontally.
        float s = canvasAspect / wpAspect;
        uv.x = (uv.x - 0.5) * s + 0.5;
    } else {
        // Wallpaper is taller than canvas — crop vertically.
        float s = wpAspect / canvasAspect;
        uv.y = (uv.y - 0.5) * s + 0.5;
    }
    return uv;
}

// Per-axis scale: 1 canvas pixel in wallpaper UV units.
// Used to convert a blur radius (in canvas px) into UV-space offsets
// for poisson-disc sampling.
vec2 canvasPxToUvScale() {
    float canvasAspect = uCanvasSize.x / uCanvasSize.y;
    float wpAspect = uWallpaperSize.x / uWallpaperSize.y;
    if (wpAspect > canvasAspect) {
        return vec2(canvasAspect / wpAspect, 1.0) / uCanvasSize;
    } else {
        return vec2(1.0, wpAspect / canvasAspect) / uCanvasSize;
    }
}
`;
// src/cdn/core/shaders/element-uniforms.ts
var ELEMENT_UNIFORMS_GLSL = `
uniform sampler2D uBackdrop;
uniform sampler2D uWallpaperSampler;  // wallpaper texture (unscaled backdrop for toggle knobs)
uniform sampler2D uTabsBackdropSampler;  // tabsBackdrop FBO (tinted scene for indicator CombinedBackdrop)
uniform vec2  uCanvasSize;        // canvas size in px
uniform vec2  uWallpaperSize;     // UNUSED — kept for uniform-set compatibility
uniform vec2  uElementOffset;     // element top-left in canvas px (SCALED rect — where the quad is drawn)
uniform vec2  uElementSize;       // element size in px (SCALED — includes graphicsLayer scaleX/scaleY)
uniform vec4  uBackdropBbox;      // (offsetX, offsetY, sizeX, sizeY) in UV [0,1] — region of fullscreen scene the backdrop texture covers. Identity (0,0,1,1) when fullscreen.
uniform vec4  uCornerRadii;       // (topLeft, topRight, bottomRight, bottomLeft) in px (ORIGINAL, unscaled)
uniform float uRefractionHeight;  // px (ORIGINAL space — NOT scaled by layerScale, faithful to AGSL)
uniform float uRefractionAmount;  // px (ORIGINAL space — NOT scaled, faithful to AGSL)
// --- Layer transform (faithful to graphicsLayer { scaleX, scaleY }) ---
// The original applies the refraction shader at the ORIGINAL element size, THEN
// scales the entire rendered layer by (scaleX, scaleY) via graphicsLayer. To
// replicate this in a single-pass shader, we compute the SDF/refraction in
// ORIGINAL space (by dividing the screen-space centered coord by uLayerScale),
// then map the refraction offset back to screen space for backdrop sampling.
// This keeps the SDF shape correct (not stretched) while covering the scaled rect.
uniform vec2  uOriginalSize;        // element size in px (ORIGINAL, unscaled by graphicsLayer)
uniform float uOriginalCornerRadius; // corner radius in px (ORIGINAL, unscaled)
uniform vec2  uLayerScale;          // (scaleX, scaleY) from graphicsLayer — maps original→screen
uniform float uElementRotation;    // rotation in radians (graphicsLayer rotationZ) — 0 = none
uniform float uDepthEffect;       // 0 or 1
uniform float uChromaticAberration; // 0 or 1
uniform float uBlurRadius;        // px
uniform float uSaturation;        // vibrancy = 1.5
uniform float uBrightness;        // brightness offset (0 for vibrancy)
uniform float uContrast;          // 1.0 for vibrancy
uniform vec4  uTintColor;         // rgba; alpha 0 = no tint
uniform vec4  uSurfaceColor;      // rgba; alpha 0 = no surface
uniform vec4  uHighlightColor;    // rgb + 1.0 (alpha handled by uHighlightAlpha)
uniform float uHighlightAngle;    // radians
uniform float uHighlightFalloff;
uniform float uHighlightAlpha;
uniform float uHighlightMode;     // 0=default, 1=ambient, 2=plain
uniform float uHighlightStrokeWidth; // px (full stroke width, matching paint.strokeWidth)
uniform float uHighlightBlur;     // px (BlurMaskFilter radius)
// Content scale (non-uniform, faithful to LiquidToggle.kt / LiquidSlider.kt):
//   scale(scaleX, scaleY) { drawBackdrop() }
// Toggle: X lerp(2/3, 0.75, p), Y lerp(0, 0.75, p)
// Slider: X lerp(2/3, 1, p),    Y lerp(0, 1, p)
// At rest Y=0 → backdrop sampled from a single horizontal line (degenerate),
// but the white overlay (alpha=1) hides it. When pressed, scales to full.
uniform float uContentScaleX;
uniform float uContentScaleY;
// --- Toggle knob CombinedBackdrop effect (faithful to LiquidToggle.kt) ---
// The knob's backdrop is a CombinedBackdrop of:
//   1. Outer backdrop (LayerBackdrop wallpaper OR CanvasBackdrop solid color)
//   2. Scaled trackBackdrop (track color rect, scaled by lerp(2/3,0.75) x lerp(0,0.75))
// uUseToggleBackdrop = 1.0 → sample outer backdrop + composite scaled track color
// uUseToggleBackdrop = 0.0 → sample scene (uBackdrop) as before
//
// uUseSolidBackdrop = 1.0 → outer backdrop is solid color (uSolidBackdropColor)
// uUseSolidBackdrop = 0.0 → outer backdrop is wallpaper texture (uWallpaperSampler)
// Faithful to ToggleContent.kt:
//   - t1 (on wallpaper): backdrop = LayerBackdrop → sample wallpaper texture
//   - t2 (on card):      backdrop = rememberCanvasBackdrop { drawRect(color) } → solid color
uniform float uUseToggleBackdrop;
uniform float uUseSolidBackdrop;
uniform vec4  uSolidBackdropColor;  // rgba 0..1; used when uUseSolidBackdrop = 1.0
uniform vec4  uTrackColor;        // rgba 0..1; alpha 0 = no track color
uniform vec4  uTrackRect;         // (centerX, centerY, halfW, halfH) in canvas px (dpr-scaled)
uniform float uTrackCornerRadius; // canvas px (dpr-scaled)
// --- Bottom tab 指示器 CombinedBackdrop (faithful to LiquidBottomTabs.kt) ---
// The 指示器's backdrop = CombinedBackdrop(wallpaper, 内层背景板) where
// 内层背景板 (tabsBackdrop) is a hidden Row with ColorFilter.tint(accentColor). Only the
// opaque 标签内容 (icons/labels) becomes blue after tint — the glass part
// is transparent. We pass up to 8 tab content rects; pixels inside any rect
// (clipped to the 容器 capsule) are tinted accentColor.
uniform float uIndicatorBackdrop;    // 0 or 1
uniform vec4  uContainerRect;        // (centerX, centerY, halfW, halfH) in canvas px (dpr-scaled)
uniform float uContainerCornerRadius; // canvas px (dpr-scaled)
uniform vec4  uIndicatorAccent;      // (r, g, b, a) — accentColor + unused
uniform float uInsetPx;              // indicator backdrop inset in device px (4dp * dpr)
uniform float uIndicatorPressProgress; // 0..1 press progress (for 2nd-layer scale)
uniform float uIndicatorPanelOffset; // panel offset in device px (2nd-layer x translation)
uniform float uDpr;                 // device pixel ratio (for dp→px conversion)
uniform vec2  uContainerCenter;      // container center (scale origin) in canvas px (dpr-scaled)
uniform float uContainerScale;       // container layerBlock scale (1 + 16dp/width * pressProgress)
// Tab content fgTextures (icon+label alpha masks) for blue tint. Up to 8 tabs.
// Only opaque icon/label pixels become blue — the container glass stays natural.
uniform sampler2D uTabContentTex0;
uniform sampler2D uTabContentTex1;
uniform sampler2D uTabContentTex2;
uniform sampler2D uTabContentTex3;
uniform sampler2D uTabContentTex4;
uniform sampler2D uTabContentTex5;
uniform sampler2D uTabContentTex6;
uniform sampler2D uTabContentTex7;
uniform vec4  uTabContentRects[8];   // (centerX, centerY, halfW, halfH) per tab, canvas px (dpr-scaled)
uniform float uTabContentCount;      // number of valid tab rects (0..8)
uniform sampler2D uTabsGlassLayer;   // scene snapshot BEFORE tab-content (wallpaper+glass only, no text)
// --- SDF texture glass (faithful to SdfShader.kt) ---
uniform sampler2D uSdfTexSampler;   // clock_sdf texture (R=SDF, GB=normal, A=shape alpha)
uniform float uUseSdfTexture;       // 0 or 1
uniform vec2  uSdfTexSize;          // texture natural dimensions (px)
uniform float uSdfLightAngle;       // bevel light angle (degrees)
uniform float uEnterAlpha;          // global element alpha (enterProgress, 0..1)
// Highlight generation distance multiplier. The SDF-texture shader computes
// intensity = circleMap(1.0 - min(1.0, -sd * uSdfHighlightScale)) where sd is
// the normalized signed distance (-1 deep inside, 0 at edge, +1 far outside).
// The intensity field drives BOTH the refraction offset AND the bevel-lighting
// contribution. Physically it controls the WIDTH of the edge band where the
// glass effect transitions from full (at the edge) to zero (interior):
//   higher scale = narrower/sharper edge band (thinner glass edge feel)
//   lower scale  = wider/gentler edge band (thicker glass edge feel)
// Exposed as "玻璃厚度" (glass thickness) in the TextGlass UI. Default 1.5
// matches the original hardcoded constant in SdfShader.kt.
uniform float uSdfHighlightScale;   // default 1.5
// Bevel lighting on/off (0 or 1). When 0, the shader still computes
// intensity (so refraction — the glass distortion of the backdrop — still
// uses uSdfHighlightScale and stays fully adjustable), but the BEVEL
// brightness contribution (color *= 1 + 0.5 * intensity * bevel) is
// skipped entirely. This lets the TextGlass 光影 toggle turn the
// light/shadow layer on/off WITHOUT zeroing the thickness slider's shader
// value (so the slider is never dead). The base brightness dim (−0.1) is
// controlled separately via uBrightness on the JS side.
uniform float uSdfBevelEnabled;     // default 1 (on)
// Whole-glass tint dye hue (0..360 degrees). The TextGlass 染色 slider picks
// a hue; the ENTIRE glass body takes on that hue via BlendMode.Hue (faithful
// to Skia's non-separable Hue blend: result takes hue from the tint src, keeps
// the glass's own saturation + value). This is NOT a flat color overlay or CSS
// hue-rotate filter — it's a proper hue replacement that preserves the glass's
// luminance and saturation, so a dyed glass still looks like glass, just tinted.
// 0 = OFF (no tint — the slider's leftmost position). 1..360 = hue degrees
// (1 = red-ish, 120 = green, 240 = blue, 360 = red). The off-state is checked
// via uSdfGlassTintHue > 0.5 so the slider's leftmost (0) disables the tint
// entirely. Independent of the 光影 (bevel) toggle — dyes the whole glass body
// regardless of whether the edge lighting layer is on.
uniform float uSdfGlassTintHue;     // default 0 (off); 1..360 = hue
// Glass tint master switch (0 or 1). Gates BOTH the color-mix filter (below)
// AND the hue-dye (above). When OFF, no tint of any kind is applied regardless
// of uSdfGlassTintHue / uSdfGlassTintMix. Faithful to "染色加一个开关".
uniform float uSdfGlassTintEnabled; // default 0 (off)
// Color-mix filter strength (0..1). BEFORE the hue-dye, the glass body is
// mixed toward a flat color (the pure saturated hue color) by this amount.
// This is a "color mix" filter (SrcOver-style blend toward a solid color) —
// distinct from the hue-dye which replaces hue but preserves S/V. 0 = no
// color-mix (only the hue-dye applies); 1 = full color overlay. Faithful to
// "染色前加一个滤镜（颜色混合）混合强度要可以调".
uniform float uSdfGlassTintMix;     // default 0 (off); 0..1 = mix strength
// Hue-dye strength (0..1, default 0.85). Controls how strongly the
// BlendMode.Hue dye is applied to the glass body. 0 = no hue-dye (only the
// color-mix filter applies if any); 1 = full hue replacement. Originally
// hardcoded at 0.85 (matching the original's constant), now exposed as a
// slider so the user can tune the dye intensity independently of the
// color-mix filter. Faithful to "加一个调染色强度的".
uniform float uSdfGlassTintStrength; // default 0.85; 0..1 = dye strength
// Edge matte (0 or 1). When 1, the SDF edge band (where intensity is high,
// i.e. near the text boundary) is desaturated toward luminance AND slightly
// darkened — a frosted/matte rim. The edge band factor is intensity itself
// (1 at the very edge, 0 in the interior), so the matte effect fades smoothly
// into the clear glass interior. Faithful to the user request: "用sdf渲染边缘，
// 然后给边缘降低提亮与饱和度" (render the edge with SDF, then reduce the
// edge's brightness and saturation). Independent of the bevel toggle.
uniform float uSdfEdgeMatteEnabled; // default 0 (off)
// Edge matte target bitmask (default 7 = all). Controls WHICH layers the
// matte desaturate+darken applies to. bit 0 (1) = bevel (光影 highlight),
// bit 1 (2) = tint (染色), bit 2 (4) = base (refraction/body). When a bit is
// unset, that layer's edge contribution is preserved (not matted). The
// shader checks each bit independently so the user can matte only the bevel
// edge, or only the tint edge, etc. Faithful to "哑光层可以调是否作用于某
// 些层" (the matte layer can be tuned to apply to certain layers).
uniform float uSdfEdgeMatteTargets; // default 7 (all three layers)
// Per-layer matte tuning parameters. Each vec2 = (range, min):
//   range (0..1, default 1.0) — how far the matte effect extends from the
//     text boundary inward. 1.0 = the matte fades across the FULL intensity
//     field (edge = full strength, interior = zero, original behavior);
//     0.5 = the matte reaches full strength at intensity=0.5 and stays full
//     for intensity > 0.5 (a sharper/narrower matte band right at the rim);
//     small values = very thin matte rim. The edge factor is computed as
//     clamp(intensity / max(range, 0.001), 0.0, 1.0).
//   min (0..1, default 0.0) — minimum matte amount applied even in the deep
//     interior (where intensity → 0). 0 = interior is clear (no matte);
//     0.3 = interior always has at least 30% matte. The final edge factor is
//     edgeClamped * (1.0 - min) + min. Faithful to "给哑光每层加上作用参数
//     调节，比如范围，最小值".
// One vec2 per layer: bevel (bit 0), tint (bit 1), base (bit 2), brighten
// (bit 3). When the overall uSdfEdgeMatteEnabled is OFF, these are ignored.
// When a layer's bit in uSdfEdgeMatteTargets is unset, that layer's params
// are also ignored.
uniform vec2  uSdfEdgeMatteBevelParams; // (range, min) for bevel layer
uniform vec2  uSdfEdgeMatteTintParams;  // (range, min) for tint layer
uniform vec2  uSdfEdgeMatteBaseParams;  // (range, min) for base layer
uniform vec2  uSdfEdgeMatteBrightenParams; // (range, min) for brighten layer
// Per-layer matte STRENGTH (0..2, default 1.0). Scales the desaturate amount
// (matteStrength 0.65) AND the darken amount (matteDarken 0.18) for that
// layer. 0 = no matte effect at all (even at full edge); 1 = original
// strength; 2 = doubled. Independent per layer so the user can crank the
// bevel matte without affecting the tint/base matte. Faithful to "调整提亮
// 层哑光的".
uniform float uSdfEdgeMatteBevelStrength; // default 1.0
uniform float uSdfEdgeMatteTintStrength;  // default 1.0
uniform float uSdfEdgeMatteBaseStrength;  // default 1.0
uniform float uSdfEdgeMatteBrightenStrength; // default 1.0
// Raw SDF debug render — when > 0.5, the SDF-texture glass path bypasses all
// glass effects and outputs the SDF's R channel directly as grayscale
// (inside = white, outside = black, AA via A channel). Used by TextGlass to
// inspect texture quality / aliasing / padding.
uniform float uSdfDebugMode;        // 0 or 1
// Coverage (A channel) → mask smoothstep range. The clock_sdf.webp texture
// uses (0.5, 1.0) — its A channel is 0 outside, 255 inside with a 1px AA
// edge, so smoothstep(0.5, 1.0) gives a 0.5px AA edge. The text SDF texture
// stores the raw Canvas2D alpha (0..255 with a 1-2px AA edge); using
// (0.5, 1.0) clips the lower half of the AA range → hard aliased edges,
// especially on small text. For text SDF, we widen to (0.0, 1.0) so the
// full Canvas2D AA gradient is preserved → smooth edges at all sizes.
uniform float uSdfAaMin;            // default 0.5 (clock_sdf); 0.0 for text SDF
// --- Per-element FBO optimization ---
// When uUsePerElementFbo > 0.5, the element is being rendered into a small
// bbox-sized FBO (NOT the fullscreen scene FBO). In that case gl_FragCoord
// ranges over [0..uElFboSize], so screenCoord must be reconstructed as
// uSceneRectOffset + (gl_FragCoord with Y flipped by uElFboSize.y) to map
// back into the full-canvas top-left-origin coordinate space that the rest
// of the shader (sampleBackdrop, coverUv, SDF, etc.) expects.
uniform float uUsePerElementFbo;    // 0 or 1
uniform vec2  uSceneRectOffset;     // element bbox top-left in canvas px (top-left origin, device px)
uniform vec2  uElFboSize;           // per-element FBO size in device px
// DEPRECATED: uBackdropRect was used by the old PEF path that sampled a
// cropped backdrop texture. The current PEF path samples the FULLSCREEN
// scene texture (same as ping-pong), so sceneUv no longer reads this.
// Kept in the uniform list for cache-index compatibility; not referenced
// by any shader code. Safe to remove once the uniform-cache list is cleaned.
uniform vec4  uBackdropRect;        // (x, y, w, h) top-left origin, scene device px (UNUSED)
// When 1.0, skip applyColorControls in the element shader (colorControls was
// already applied as a fullscreen pass BEFORE the 2-pass blur on the backdrop
// FBO, matching the original's colorControls→blur→lens order). Used by
// backdropFbo + useSeparableBlur elements (dialog card).
uniform float uSkipColorControls;   // 0 or 1
// (uNoContinuousSdfInRefraction is declared in SDF_GLSL — included by element.ts.
//  When 1.0, the refraction/lens computation forces analytic sdRoundedRect,
//  stripping the G2 SDF texture out of the glass-body refraction. The clip
//  mask is NOT affected — capsuleShape still controls the edge.)
// --- Magnifier glass (faithful to MagnifierContent.kt) ---
uniform float uUseMagnifier;        // 0 or 1
uniform float uMagnifierZoom;       // zoom factor (1.5)
uniform float uMagnifierOffsetY;    // sample Y offset to cursor (80dp, device px)
// --- Sample wallpaper directly (bypass scene FBO) ---
// When 1.0, sampleBackdrop uses coverUv + uWallpaperSampler (clean wallpaper)
// instead of sceneUv + uBackdrop (scene FBO). Used by elements that sit over
// a scrim/dim (Dialog card, ControlCenter tiles) so the glass refracts the
// clean wallpaper instead of the alpha-decayed scene FBO. Faithful to the
// original where LayerBackdrop captures the wallpaper Image (alpha=1).
uniform float uSampleWallpaper;     // 0 or 1
// --- Scrim color (applied to the wallpaper BEFORE colorControls/blur/lens) ---
// Faithful to DialogContent.kt / ControlCenterContent.kt where the scrim
// (drawRect(dimColor)) is painted onto the wallpaper Image (via
// BackdropDemoScaffold's modifier = drawWithContent { drawContent(); drawRect(dimColor) }),
// so the LayerBackdrop captures wallpaper+scrim as one opaque layer.
// In the port, when uSampleWallpaper=1 (clean wallpaper), we apply the scrim
// here in the shader to replicate that composited backdrop. uScrimColor.a=0
// means no scrim. Applied as SrcOver: backdrop.rgb = scrim.rgb*scrim.a + backdrop.rgb*(1-scrim.a).
uniform vec4 uScrimColor;           // rgba 0..1; a=0 = no scrim
// --- 内层背景板 rim highlight stroke mask (Canvas2D, same approach as outer rim) ---
// When uIndicatorBackdrop=1, the inner backdrop plate's rim highlight is sampled
// from this pre-rasterized Canvas2D stroke mask instead of computed analytically.
// The mask is drawn for the 内层背景板 capsule shape (uContainerRect dimensions)
// with clip(stroke) + BlurMaskFilter, giving browser-native Skia AA.
uniform sampler2D uInnerStrokeMask;   // Canvas2D stroke mask texture for inner backdrop highlight
uniform vec2  uInnerStrokeMaskOffset; // margin (strokeMargin) in device px — UV offset
uniform vec2  uInnerStrokeMaskSize;   // (maskW, maskH) in device px — total mask texture size
`;

// src/cdn/core/shaders/element-utils.ts
function generateGaussianDisc(tapCount) {
  const taps = [];
  if (tapCount <= 1) {
    taps.push({ x: 0, y: 0, w: 1 });
    return taps;
  }
  const goldenAngle = Math.PI * (3 - Math.sqrt(5));
  const maxRadius = 3;
  let totalW = 0;
  for (let i = 0;i < tapCount; i++) {
    const t = (i + 0.5) / tapCount;
    const r = maxRadius * Math.sqrt(t);
    const angle = i * goldenAngle;
    const x = r * Math.cos(angle);
    const y = r * Math.sin(angle);
    const dist2 = x * x + y * y;
    const w = Math.exp(-0.5 * dist2);
    taps.push({ x, y, w });
    totalW += w;
  }
  if (totalW > 0) {
    for (const t of taps)
      t.w /= totalW;
  }
  return taps;
}
function generateBlurGLSL(taps, sampler, uvVar, pxToUvExpr) {
  if (taps.length === 1) {
    return `    return texture2D(${sampler}, ${uvVar});
`;
  }
  let code = "";
  for (const t of taps) {
    const ox = t.x.toFixed(6);
    const oy = t.y.toFixed(6);
    const w = t.w.toFixed(8);
    code += `    sum += texture2D(${sampler}, ${uvVar} + vec2(${ox}, ${oy}) * ${pxToUvExpr}) * ${w};
`;
  }
  return code;
}
var DEFAULT_BLUR_TAPS = 16;
function generateElementUtilsGLSL(tapCount = DEFAULT_BLUR_TAPS) {
  const taps = generateGaussianDisc(tapCount);
  const backdropBlurCode = generateBlurGLSL(taps, "uBackdrop", "uv", "pxToUv");
  const wallpaperBlurCode = generateBlurGLSL(taps, "uWallpaperSampler", "uv", "pxToUv");
  return `
// Forward declarations — blendHue/rgb2hsv/hsv2rgb are defined later but used
// by sampleIndicatorBackdrop (which must come before sampleToggleBackdrop in
// the file for readability). GLSL ES 1.00 requires declaration before use.
vec3 rgb2hsv(vec3 c);
vec3 hsv2rgb(vec3 c);
vec3 blendHue(vec3 dst, vec3 src);

float circleMap(float x) {
    return 1.0 - sqrt(1.0 - x * x);
}

// SDF-texture glass sampling (faithful to SdfShader.kt).
// Samples the clock_sdf texture at element-local coords.
// Returns vec4(intensity, maskAlpha, normalX, normalY); zeroes if outside.
//
// uSdfHighlightScale controls how far from the text edge the bevel highlight
// extends into the interior. Original hardcoded constant was 1.5; exposed as
// a uniform so the TextGlass page can tune it live via a slider.
//
// uSdfAaMin controls the coverage→mask smoothstep lower bound. clock_sdf uses
// 0.5 (narrow AA); text SDF uses 0.0 (full Canvas2D AA gradient → smooth at
// all sizes, no aliasing on small text).
vec4 sampleSdfTexture(vec2 localPx) {
    vec2 uv = vec2(localPx.x / uOriginalSize.x,
                   localPx.y / uOriginalSize.y);
    if (uv.x < 0.0 || uv.y < 0.0 || uv.x > 1.0 || uv.y > 1.0) {
        return vec4(0.0);
    }
    vec4 v = texture2D(uSdfTexSampler, uv);
    float sd = v.r * 2.0 - 1.0;
    float mask = smoothstep(uSdfAaMin, 1.0, v.a);
    if (mask <= 0.0) return vec4(0.0);
    if (mask < 1.0) sd = 0.0;
    vec2 normal = normalize(v.gb * 2.0 - 1.0);
    float intensity = circleMap(1.0 - min(1.0, -sd * uSdfHighlightScale));
    return vec4(intensity, mask, normal.x, normal.y);
}

// Convert a canvas-pixel coordinate (top-left origin) to scene-texture UV.
// The scene texture is the same size as the canvas, and is rendered with
// gl_FragCoord (bottom-left origin). So UV = (canvasPx.x / canvasW, 1 -
// canvasPx.y / canvasH). The Y flip happens here so the rest of the shader
// can work in top-left-origin canvas px.
//
// This is used by BOTH the ping-pong path and the per-element FBO path.
// In the PEF path, the element pass still samples the FULLSCREEN scene
// texture (uBackdrop = curTex or blurFboBTex), NOT a cropped region. The
// only PEF-specific work happens in element.ts's main(), where screenCoord
// is reconstructed from gl_FragCoord via uSceneRectOffset/uElFboSize. Once
// screenCoord is in canvas-px space, this function maps it to UV identically
// for both paths — keeping the shader's non-local reads (refraction offset,
// chromatic 7-tap spread, blur kernel) hitting real neighbor content.
vec2 sceneUv(vec2 canvasPx) {
    return vec2(canvasPx.x / uCanvasSize.x, 1.0 - canvasPx.y / uCanvasSize.y);
}

// Gaussian disc blur — ${tapCount} taps, dynamically generated in JS.
// Offsets are in units of radius (sigma = radius), scaled at runtime.
// radius < 0.5 falls back to single tap (no visible blur).
//
// When uSampleWallpaper > 0.5, samples the CLEAN wallpaper (uWallpaperSampler
// via coverUv) instead of the scene FBO (uBackdrop via sceneUv), AND applies
// the scrim (uScrimColor) to replicate the original's wallpaper+scrim composited
// LayerBackdrop. The scrim is applied INSIDE sampleBackdrop so EVERY sampling
// site — the initial backdrop sample, the refraction re-sample, and each
// chromatic-aberration channel — gets the same wallpaper+scrim composite.
// This fixes the "scrim not applied at edges" bug where the refraction band
// re-sampled the clean wallpaper (without scrim), making the edge brighter
// than the interior.
vec4 sampleBackdrop(vec2 canvasPx, float radius) {
    if (uSampleWallpaper > 0.5) {
        vec2 uv = coverUv(canvasPx);
        vec4 c;
        if (radius < 0.5) {
            c = texture2D(uWallpaperSampler, uv);
        } else {
            vec2 pxToUv = radius * canvasPxToUvScale();
            vec4 sum = vec4(0.0);
${wallpaperBlurCode}            c = sum;
        }
        // Apply scrim (SrcOver) so the backdrop = wallpaper+scrim, opaque.
        if (uScrimColor.a > 0.001) {
            c.rgb = uScrimColor.rgb * uScrimColor.a + c.rgb * (1.0 - uScrimColor.a);
            c.a = 1.0;
        }
        return c;
    }
    vec2 uv = sceneUv(canvasPx);
    // uBackdrop may be a bbox-sized texture (when blur ran in a bbox FBO via
    // cropAndBlurBackdrop). uBackdropBbox = (offsetX, offsetY, sizeX, sizeY)
    // in normalized UV [0,1] — the region of the fullscreen scene the bbox
    // texture covers. Map sceneUv into that region, clamp to avoid bleeding
    // at bbox edges. When uBackdropBbox.zw > 1.0 (sentinel = fullscreen),
    // the mapping is identity (uv unchanged).
    uv = (uv - uBackdropBbox.xy) / uBackdropBbox.zw;
    uv = clamp(uv, vec2(0.0), vec2(1.0));
    if (radius < 0.5) {
        return texture2D(uBackdrop, uv);
    }
    // Backdrop is always the fullscreen scene texture (both ping-pong and
    // PEF paths), so blur offsets scale by the canvas size.
    vec2 pxToUv = radius / uCanvasSize;
    vec4 sum = vec4(0.0);
${backdropBlurCode}    return sum;
}

// Gaussian disc blur of the WALLPAPER (uWallpaperSampler via coverUv).
// Used by the SDF-texture glass path (LockScreen) — faithful to the original's
// blur(2dp) effect applied before the SDF shader.
vec4 sampleWallpaperBlurred(vec2 canvasPx, float radius) {
    vec2 uv = coverUv(canvasPx);
    if (radius < 0.5) {
        return texture2D(uWallpaperSampler, uv);
    }
    vec2 pxToUv = radius * canvasPxToUvScale();
    vec4 sum = vec4(0.0);
${wallpaperBlurCode}    return sum;
}

// --- Toggle knob CombinedBackdrop sampling (faithful to LiquidToggle.kt) ---
// The knob's backdrop is a CombinedBackdrop of:
//   1. Outer backdrop:
//      - LayerBackdrop (wallpaper) for t1 → sample uWallpaperSampler
//      - CanvasBackdrop (solid color) for t2 → use uSolidBackdropColor
//   2. Scaled trackBackdrop (track color rect, clipped to Capsule, scaled
//      by lerp(2/3, 0.75, pressProgress) x lerp(0, 0.75, pressProgress)
//      around the knob's center)
//
// This function samples the outer backdrop (wallpaper OR solid color) with blur,
// then composites the scaled track color on top using a rounded-rect SDF
// at the uTrackRect position (center + half-size + corner radius).
//
// The track color SDF is also blurred by approximating the blur as a
// smoothstep over uBlurRadius — this matches the original where the blur
// effect is applied to the CombinedBackdrop (outer + track color).
vec4 sampleToggleBackdrop(vec2 canvasPx, float radius) {
    // 1. Sample outer backdrop with blur.
    vec4 wp;
    if (uUseSolidBackdrop > 0.5) {
        // CanvasBackdrop case (t2): solid color fills the entire knob area.
        // Faithful to: rememberCanvasBackdrop { drawRect(backgroundColor) }
        // The drawRect fills the DrawScope (knob's bounds) with the color,
        // so every pixel of the knob's backdrop is the solid color.
        wp = uSolidBackdropColor;
    } else if (radius < 0.5) {
        // LayerBackdrop case (t1): sample wallpaper texture unscaled.
        // IMPORTANT: use coverUv (cover-fit) to match the wallpaper background
        // pass (WALLPAPER_FRAGMENT_SHADER). Using sceneUv (raw normalization)
        // here would sample the wrong texel when the wallpaper aspect ratio
        // differs from the canvas — causing the knob to see a shifted/misaligned
        // wallpaper that doesn't match what's displayed behind it.
        vec2 uv = coverUv(canvasPx);
        wp = texture2D(uWallpaperSampler, uv);
    } else {
        // LayerBackdrop case (t1) with blur: 9-tap poisson disc on wallpaper.
        // Use coverUv for the center sample, and convert the blur radius from
        // canvas px to UV-space using canvasPxToUvScale() (which accounts for
        // the cover-fit aspect ratio cropping).
        vec2 uv = coverUv(canvasPx);
        vec2 pxToUv = radius * canvasPxToUvScale();
        vec4 sum = vec4(0.0);
        float total = 0.0;
        sum += texture2D(uWallpaperSampler, uv) * 0.25; total += 0.25;
        sum += texture2D(uWallpaperSampler, uv + vec2( 1.000,  0.000) * pxToUv) * 0.12; total += 0.12;
        sum += texture2D(uWallpaperSampler, uv + vec2(-1.000,  0.000) * pxToUv) * 0.12; total += 0.12;
        sum += texture2D(uWallpaperSampler, uv + vec2( 0.000,  1.000) * pxToUv) * 0.12; total += 0.12;
        sum += texture2D(uWallpaperSampler, uv + vec2( 0.000, -1.000) * pxToUv) * 0.12; total += 0.12;
        sum += texture2D(uWallpaperSampler, uv + vec2( 0.707,  0.707) * pxToUv) * 0.0675; total += 0.0675;
        sum += texture2D(uWallpaperSampler, uv + vec2( 0.707, -0.707) * pxToUv) * 0.0675; total += 0.0675;
        sum += texture2D(uWallpaperSampler, uv + vec2(-0.707,  0.707) * pxToUv) * 0.0675; total += 0.0675;
        sum += texture2D(uWallpaperSampler, uv + vec2(-0.707, -0.707) * pxToUv) * 0.0675; total += 0.0675;
        wp = sum / total;
    }

    // 2. Composite scaled track color on top.
    // The track rect is centered at uTrackRect.xy with half-size uTrackRect.zw,
    // and corner radius uTrackCornerRadius. We compute the SDF of this
    // rounded rect at canvasPx, then apply a smoothstep for edge AA + blur.
    // If uTrackColor.a == 0.0 OR the track rect is degenerate (halfW or
    // halfH < 0.5px, which happens at rest when scaleY=0), skip compositing.
    // Faithful to original: scale(scaleX, 0) { drawRect() } draws nothing.
    if (uTrackColor.a > 0.001 && uTrackRect.z > 0.5 && uTrackRect.w > 0.5) {
        vec2 trackCenter = uTrackRect.xy;
        vec2 trackHalf = uTrackRect.zw;
        vec2 trackLocal = canvasPx - trackCenter;
        // sdRoundedRect expects centered coord (relative to center).
        // Use uniform corner radius = uTrackCornerRadius.
        float tr = uTrackCornerRadius;
        // Approximate the rounded-rect SDF (matches sdRoundedRect from SDF_GLSL).
        vec2 q = abs(trackLocal) - trackHalf + vec2(tr);
        float trackSd = length(max(q, vec2(0.0))) + min(max(q.x, q.y), 0.0) - tr;
        // Blur the edge by uBlurRadius (approximate Gaussian edge feather).
        // Inside (trackSd < -radius) → mask=1; outside (trackSd > radius) → mask=0.
        // Use max(radius, 1.0) to guarantee at least 1px smoothstep for AA
        // — when fully pressed, blurRadius=0, but edges must still be smooth.
        float aaRadius = max(radius, 1.0);
        float mask = 1.0 - smoothstep(-aaRadius, aaRadius, trackSd);
        // Composite: srcOver (track color over outer backdrop).
        float a = mask * uTrackColor.a;
        wp.rgb = mix(wp.rgb, uTrackColor.rgb, a);
        wp.a = mix(wp.a, 1.0, a);
    }
    return wp;
}

// sampleIndicatorBackdrop — faithful to LiquidBottomTabs.kt indicator.
//
// Naming convention (used throughout the bottom-tabs code):
//   - 容器 (Container)  = outer visible glass bar (64dp), Container Row in Kotlin
//   - 指示器 (Indicator) = selected sliding glass capsule (56dp), Indicator Box in Kotlin
//   - 内层背景板 (Inner backdrop) = hidden 56dp glass captured by tabsBackdrop,
//     tinted blue by ColorFilter.tint(accentColor), sampled by the indicator
//   - 标签内容 (Tab content) = icon + label inside each tab slot
//
// Original: indicator.drawBackdrop(backdrop = rememberCombinedBackdrop(backdrop, tabsBackdrop))
//   - backdrop (outer) = LayerBackdrop = wallpaper (sampled via coverUv)
//   - tabsBackdrop (inner) = hidden Row's 56dp glass, inset 4dp from the
//     indicator's draw area on all sides.
//
// Implementation (mirrors sampleToggleBackdrop):
//   1. Sample wallpaper (outer backdrop) with blur — same as toggle's outer.
//   2. Composite the scene FBO (uBackdrop = container glass + content)
//      inside an INSET capsule SDF (containerRect shrunk 4dp each side).
//      This is the "smaller background plate" refracted inside the indicator.
vec4 sampleIndicatorBackdrop(vec2 canvasPx, float radius) {
    // 1. Sample wallpaper (outer LayerBackdrop) via coverUv (cover-fit).
    vec4 wp;
    if (radius < 0.5) {
        vec2 uv = coverUv(canvasPx);
        wp = texture2D(uWallpaperSampler, uv);
    } else {
        vec2 uv = coverUv(canvasPx);
        vec2 pxToUv = radius * canvasPxToUvScale();
        vec4 sum = vec4(0.0);
        float total = 0.0;
        sum += texture2D(uWallpaperSampler, uv) * 0.25; total += 0.25;
        sum += texture2D(uWallpaperSampler, uv + vec2( 1.000,  0.000) * pxToUv) * 0.12; total += 0.12;
        sum += texture2D(uWallpaperSampler, uv + vec2(-1.000,  0.000) * pxToUv) * 0.12; total += 0.12;
        sum += texture2D(uWallpaperSampler, uv + vec2( 0.000,  1.000) * pxToUv) * 0.12; total += 0.12;
        sum += texture2D(uWallpaperSampler, uv + vec2( 0.000, -1.000) * pxToUv) * 0.12; total += 0.12;
        sum += texture2D(uWallpaperSampler, uv + vec2( 0.707,  0.707) * pxToUv) * 0.0675; total += 0.0675;
        sum += texture2D(uWallpaperSampler, uv + vec2( 0.707, -0.707) * pxToUv) * 0.0675; total += 0.0675;
        sum += texture2D(uWallpaperSampler, uv + vec2(-0.707,  0.707) * pxToUv) * 0.0675; total += 0.0675;
        sum += texture2D(uWallpaperSampler, uv + vec2(-0.707, -0.707) * pxToUv) * 0.0675; total += 0.0675;
        wp = sum / total;
    }

    // 2. 内层背景板 (Inner backdrop) SDF — the hidden Row's 56dp glass capsule.
    //    Faithful to LiquidBottomTabs.kt: the hidden Row has NO layerBlock,
    //    so its glass does NOT scale with the container. Only panelOffset
    //    shifts it (translationX = panelOffset).
    vec2 capsuleHalf = max(uContainerRect.zw, vec2(0.0));
    float cr = max(uContainerCornerRadius, 0.0);
    // Center = rectCenter + panelOffset (NO container scale).
    vec2 scaledCenter = uContainerRect.xy + vec2(uIndicatorPanelOffset, 0.0);
    vec2 capsuleLocal = canvasPx - scaledCenter;
    vec2 cq = abs(capsuleLocal) - capsuleHalf + vec2(cr);
    float capsuleSd = length(max(cq, vec2(0.0))) + min(max(cq.x, cq.y), 0.0) - cr;
    // Mask: interpolate between 1.0 (at rest) and smoothstep (when pressed).
    // At rest (progress=0): mask=1.0 — no separate smoothstep transition at
    // the containerRect boundary, because it overlaps with the indicator's own
    // edge (both 56dp capsules). A second smoothstep here would reveal raw
    // wallpaper at the indicator edge, causing jagged aliasing. With mask=1.0,
    // the indicator always shows the glass scene inside its shape, and edgeAlpha
    // smoothly fades to transparent — matching the container glass behind it.
    // When pressed (progress=1): restore the original smoothstep mask for the
    // CombinedBackdrop clipping. Refraction displaces samples away from the
    // shared edge, so the smoothstep no longer causes jaggies; and the inner
    // backdrop capsule clip preserves the correct CombinedBackdrop visual
    // (scene inside capsule, wallpaper outside).
    float indicatorAaRadius = max(radius, 1.0);
    float smoothstepMask = 1.0 - smoothstep(-indicatorAaRadius, indicatorAaRadius, capsuleSd);
    float mask = mix(1.0, smoothstepMask, uIndicatorPressProgress);

    // 2b. 内层背景板 shadow (Shadow.Default) — faithful to LiquidBottomTabs.kt
    //     hidden Row's drawBackdrop: shadow defaults to Shadow.Default when not specified.
    //     Shadow.Default: radius=24dp, offset=DpOffset(0, radius/6=4dp), color=Black@0.1, alpha=1.
    //     In the CombinedBackdrop, the shadow is composited between wallpaper (outer)
    //     and glass body (inner). Through the semi-transparent glass body, this shadow
    //     bleeds through near the capsule edges — most visible near the top edge where
    //     the shadow offset (0, +4dp) makes those pixels "outside" the shadow capsule
    //     (shadow capsule top = original top + 4dp, so original top is outside it).
    //     Implementation mirrors ShadowModifier.kt:
    //       1. Shift capsule by shadow offset → shadow shape SDF
    //       2. Gaussian falloff (MaskFilter.makeBlur sigma = radius directly)
    //       3. Mask inside original capsule (ShadowMaskPaint BlendMode.Clear)
    //       4. Darken wallpaper by Black@0.1 × shadowIntensity
    float shadowOffsetYpx = (24.0 / 6.0) * uDpr; // DpOffset(0, radius/6) in device px
    vec2 shadowLocal = capsuleLocal - vec2(0.0, shadowOffsetYpx);
    vec2 shadowCq2 = abs(shadowLocal) - capsuleHalf + vec2(cr);
    float shadowSd = length(max(shadowCq2, vec2(0.0))) + min(max(shadowCq2.x, shadowCq2.y), 0.0) - cr;
    // Shadow intensity: Gaussian falloff from shadow shape edge.
    // MaskFilter.makeBlur(FilterBlurMode.NORMAL, radius) takes sigma = radius directly.
    float shadowSigma = max(24.0 * uDpr, 1.0); // sigma = 24dp in device px
    float shadowIntensity = 0.5 * exp(-shadowSd * shadowSd / (2.0 * shadowSigma * shadowSigma));
    // Mask shadow inside the original capsule (ShadowMaskPaint BlendMode.Clear
    // removes shadow where the shape itself is drawn, so shadow only appears outside).
    shadowIntensity *= smoothstep(-1.0, 1.0, capsuleSd);
    // Darken wallpaper by Black@0.1 × shadowIntensity (SrcOver compositing).
    wp.rgb *= (1.0 - shadowIntensity * 0.1);

    // 3. Sample the GLASS LAYER FBO (wallpaper + container glass, NO tab text).
    //    This is a snapshot taken after the container glass is rendered but
    //    before tab-content is drawn — so it has no white/black text to bleed
    //    through. The blue tab text is drawn on top via fgTexture (step 4).
    vec2 sceneUv2 = sceneUv(canvasPx - vec2(uIndicatorPanelOffset, 0.0));
    vec4 scene = texture2D(uTabsGlassLayer, sceneUv2);

    // 4. Draw blue 标签内容 (tab content: icons/labels) on top of the glass layer.
    //    Use each tab's fgTexture alpha as a hard mask (step) — pixels inside
    //    the icon/label shape become blue, everything else stays the glass
    //    layer's natural color. No white edges (hard replace, no mix).
    //    Faithful to LiquidBottomTabs.kt: the hidden Row's tab content gets
    //    LocalLiquidBottomTabScale = lerp(1, 1.2, pressProgress) + panelOffset
    //    (NOT the container scale — the hidden Row is a sibling of the
    //    container, not a child, so the container layerBlock doesn't apply).
    float contentScale = 1.0 + 0.2 * uIndicatorPressProgress;
    float tabMask = 0.0;
    for (int i = 0; i < 8; i++) {
        if (float(i) >= uTabContentCount) break;
        vec4 r = uTabContentRects[i];
        if (r.z > 0.5 && r.w > 0.5) {
            // Tab content scales around its OWN center (not container center)
            // by contentScale, then shifts by panelOffset.
            vec2 tabCenter = r.xy + vec2(uIndicatorPanelOffset, 0.0);
            vec2 scaledHalf = r.zw * contentScale;
            vec2 localPx = canvasPx - (tabCenter - scaledHalf);
            vec2 uv = localPx / (scaledHalf * 2.0);
            if (all(greaterThanEqual(uv, vec2(0.0))) && all(lessThanEqual(uv, vec2(1.0)))) {
                float a = 0.0;
                if (i == 0) a = texture2D(uTabContentTex0, uv).a;
                else if (i == 1) a = texture2D(uTabContentTex1, uv).a;
                else if (i == 2) a = texture2D(uTabContentTex2, uv).a;
                else if (i == 3) a = texture2D(uTabContentTex3, uv).a;
                else if (i == 4) a = texture2D(uTabContentTex4, uv).a;
                else if (i == 5) a = texture2D(uTabContentTex5, uv).a;
                else if (i == 6) a = texture2D(uTabContentTex6, uv).a;
                else if (i == 7) a = texture2D(uTabContentTex7, uv).a;
                tabMask = max(tabMask, a);
            }
        }
    }
    // Use fgTexture alpha directly as the blue compositing factor. fgTexture
    // is LINEAR-filtered so its alpha has smooth AA edges — no smoothstep
    // threshold needed (which caused jaggies by hard-clipping the AA gradient).
    vec3 sceneColor = mix(scene.rgb, uIndicatorAccent.rgb, tabMask);

    // 5. Composite scene over wallpaper (SrcOver).
    //    At rest (mask≈1.0): a ≈ scene.a — glass scene composited at natural opacity.
    //    When pressed (mask=smoothstep): a = scene.a * mask — CombinedBackdrop clip.
    float a = scene.a * mask;
    vec3 resultRgb = mix(wp.rgb, sceneColor, a);

    // 6. 内层背景板 rim highlight — faithful to LiquidBottomTabs.kt hidden Row:
    //    highlight = { Highlight.Default.copy(alpha = progress) }
    //    The HighlightModifier draws a STROKE (width=0.5dp, strokeWidth=2px)
    //    blurred by 0.25dp, clipped inside the capsule, colored by the
    //    DefaultHighlightShaderString AGSL shader:
    //      float2 grad = gradSdRoundedRect(centeredCoord, halfSize, gradRadius);
    //      float2 normal = float2(cos(angle), sin(angle));
    //      float d = dot(grad, normal);
    //      float intensity = pow(abs(d), falloff);
    //      return color * intensity;   // color = White(1.0), alpha=1*progress
    //    with angle=45°, falloff=1, gradRadius = min(radius*1.5, min(halfW, halfH)).
    //    The stroke's outward half (capsuleSd > 0) is clipped, leaving the inner
    //    half. Final contribution = White(1.0) * intensity * strokeMask * progress,
    //    added with Plus blend (additive).
    //    NOTE: this is the SAME as the 指示器's own rim highlight (step 2f in
    //    post-passes) — both use Highlight.Default. The only difference is the
    //    SDF: here it's the 内层背景板 capsule (inset 4dp), there it's the
    //    指示器's own capsule. The shader math is identical.
    //
    //    The stroke mask is now sampled from a pre-rasterized Canvas2D texture
    //    (uInnerStrokeMask) instead of computed analytically (65-tap Gaussian
    //    convolution of a hard-edge stroke band). This gives browser-native Skia
    //    hardware coverage AA — identical quality to the outer indicator rim
    //    highlight. The Canvas2D pipeline does ctx.clip(path) → ctx.stroke(path)
    //    → ctx.filter=blur, which naturally removes the outer half and provides
    //    sub-pixel AA. No per-pixel SDF loops, no smoothstep clipAA needed.
    float highlightAlpha = uIndicatorPressProgress;
    if (highlightAlpha > 0.001) {
        // SDF gradient + Default highlight intensity (angle=45°, falloff=1).
        // This part is identical to the AGSL DefaultHighlightShaderString.
        float indRadius = max(cr, 0.0);
        float indHalfMin = min(capsuleHalf.x, capsuleHalf.y);
        float gradRadius = min(indRadius * 1.5, indHalfMin);
        vec2 grad = gradSdRoundedRect(capsuleLocal, capsuleHalf, gradRadius);
        vec2 normal = vec2(0.70710678, 0.70710678); // cos(45°), sin(45°)
        float d = dot(grad, normal);
        float intensity = pow(abs(d), 1.0);

        // Sample the pre-rasterized Canvas2D stroke mask texture.
        // UV mapping: capsuleLocal (centered, -halfW..+halfW) → element-local
        // (0..2*halfW) by adding capsuleHalf → add margin offset → divide
        // by maskSize. This is the same convention as the outer indicator
        // stroke mask (STROKE_MASK_COMPOSITE_FRAGMENT_SHADER).
        vec2 innerLocal = capsuleLocal + capsuleHalf;
        vec2 innerMaskUv = (innerLocal + uInnerStrokeMaskOffset) / uInnerStrokeMaskSize;
        // Bounds check — discard samples outside the mask texture.
        float innerMask = 0.0;
        if (innerMaskUv.x >= 0.0 && innerMaskUv.x <= 1.0 &&
            innerMaskUv.y >= 0.0 && innerMaskUv.y <= 1.0) {
            innerMask = texture2D(uInnerStrokeMask, innerMaskUv).a;
        }

        // White(0.5) * intensity * innerMask * progress, Plus blend (additive).
        // Faithful to HighlightStyle.Default: color = White.copy(alpha=0.5f).
        // The AGSL shader uses this 0.5 alpha, NOT color.copy(alpha=1f).
        // Same fix as DEFAULT_HIGHLIGHT.alpha = 0.5 (was previously 1.0).
        // No clipAA needed — the Canvas2D clip(path) before stroke already removes
        // the outer half, and Skia hardware coverage provides AA.
        resultRgb += vec3(0.5) * intensity * innerMask * highlightAlpha;
    }

    return vec4(resultRgb, 1.0);
}

// Magnifier backdrop sampling — faithful to MagnifierContent.kt's
// onDrawBackdrop: withTransform({ scale(1.5); translate(top=-80dp) }, drawBackdrop).
// Zoom around the magnifier center, then offset Y toward cursor.
vec4 sampleMagnifier(vec2 canvasPx, float radius) {
    vec2 magCenter = uElementOffset + uElementSize * 0.5;
    vec2 zoomedCoord = magCenter + (canvasPx - magCenter) / uMagnifierZoom;
    vec2 cursorCoord = vec2(zoomedCoord.x, zoomedCoord.y + uMagnifierOffsetY);
    return sampleBackdrop(cursorCoord, radius);
}

// colorControls — exact port of ColorFilter.kt colorControlsColorFilter.
// saturation 1.5, brightness 0, contrast 1 -> pure saturation boost.
vec3 applyColorControls(vec3 c, float brightness, float contrast, float saturation) {
    float invSat = 1.0 - saturation;
    float r = 0.213 * invSat;
    float g = 0.715 * invSat;
    float b = 0.072 * invSat;
    float t = (0.5 - contrast * 0.5 + brightness) * 255.0;
    float cs = contrast * saturation;
    float cr = contrast * r;
    float cg = contrast * g;
    float cb = contrast * b;
    vec3 outc;
    outc.r = (cr + cs) * c.r + cg * c.g + cb * c.b + t / 255.0;
    outc.g = cr * c.r + (cg + cs) * c.g + cb * c.b + t / 255.0;
    outc.b = cr * c.r + cg * c.g + (cb + cs) * c.b + t / 255.0;
    return outc;
}

// --- HSV conversion + BlendMode.Hue ---------------------------
// Faithful port of Skia's BlendMode.Hue (non-separable blend).
// Hue blend: result takes hue from src, saturation+value from dst.
// Used by drawRect(tint, BlendMode.Hue) in onDrawSurface.
vec3 rgb2hsv(vec3 c) {
    float maxC = max(c.r, max(c.g, c.b));
    float minC = min(c.r, min(c.g, c.b));
    float delta = maxC - minC;
    float v = maxC;
    float s = maxC < 1e-6 ? 0.0 : delta / maxC;
    float h = 0.0;
    if (delta > 1e-6) {
        if (maxC == c.r) {
            h = mod((c.g - c.b) / delta, 6.0);
        } else if (maxC == c.g) {
            h = (c.b - c.r) / delta + 2.0;
        } else {
            h = (c.r - c.g) / delta + 4.0;
        }
        h *= 60.0;
        if (h < 0.0) h += 360.0;
    }
    return vec3(h / 360.0, s, v);
}

vec3 hsv2rgb(vec3 c) {
    float h = c.x * 6.0;
    float s = c.y;
    float v = c.z;
    float i = floor(h);
    float f = h - i;
    float p = v * (1.0 - s);
    float q = v * (1.0 - s * f);
    float t = v * (1.0 - s * (1.0 - f));
    i = mod(i, 6.0);
    if (i < 1.0) return vec3(v, t, p);
    if (i < 2.0) return vec3(q, v, p);
    if (i < 3.0) return vec3(p, v, t);
    if (i < 4.0) return vec3(p, q, v);
    if (i < 5.0) return vec3(t, p, v);
    return vec3(v, p, q);
}

// BlendMode.Hue: take hue from src, sat+val from dst.
vec3 blendHue(vec3 dst, vec3 src) {
    vec3 dh = rgb2hsv(dst);
    vec3 sh = rgb2hsv(src);
    return hsv2rgb(vec3(sh.x, dh.y, dh.z));
}
`;
}

// src/cdn/core/shaders/element.ts
function generateElementFragmentShader(tapCount = DEFAULT_BLUR_TAPS) {
  const utilsGlsl = generateElementUtilsGLSL(tapCount);
  return `
precision highp float;

${ELEMENT_UNIFORMS_GLSL}

${SDF_GLSL}

${COVER_GLSL}

${utilsGlsl}

void main() {
    // --- Coordinate reconstruction ---
    // Two paths: PEF (elFbo at BASELINE resolution) vs ping-pong (fullscreen).
    //
    // PEF path: elFbo is at baseline (origW*dpr + pad), NOT scaled by zoom.
    // gl_FragCoord ranges over [0, uElFboSize]. We compute:
    //   1. centeredOrigRot — un-rotated original-space coord (for SDF)
    //   2. screenCoord — rotated+scaled canvas position (for backdrop sampling)
    // The elFbo contains UN-ROTATED glass; rotation is applied at composite.
    // Backdrop sampling still needs the correct (rotated) screen position.
    //
    // Ping-pong path: fullscreen, rotation baked in shader (legacy).
    vec2 screenCoord;
    vec2 centeredOrigRot;  // un-rotated original-space coord for SDF
    vec2 elementCenter = uElementOffset + uElementSize * 0.5;
    vec2 layerScale = max(uLayerScale, vec2(1e-4));
    float rot = uElementRotation;

    if (uUsePerElementFbo > 0.5) {
        // elFbo fragment → centered local coord (Y-down, elFbo px)
        vec2 fboCenter = uElFboSize * 0.5;
        vec2 localUp = gl_FragCoord.xy - fboCenter;  // Y-up (gl_FragCoord BL origin)
        vec2 localDown = vec2(localUp.x, -localUp.y);  // Y-down (top-left origin)
        // Scale elFbo px → original px (accounts for AA pad: elFbo > origSize)
        vec2 origScale = uOriginalSize / uElFboSize;
        centeredOrigRot = localDown * origScale;  // un-rotated original space
        // Map to screen for backdrop sampling. When rot≈0 (common case), skip
        // rotateBy entirely (4 mul + cos/sin per fragment saved). When rot≠0,
        // apply rotation to map local-space coord to screen-space sample point.
        if (abs(rot) > 0.001) {
            screenCoord = elementCenter + rotateBy(centeredOrigRot, rot) * layerScale;
        } else {
            screenCoord = elementCenter + centeredOrigRot * layerScale;
        }
    } else {
        // Ping-pong: fullscreen, rotation in shader (legacy path)
        screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
        vec2 centeredScreen = screenCoord - elementCenter;
        vec2 centeredOrig = centeredScreen / layerScale;
        if (abs(rot) > 0.001) {
            centeredOrigRot = rotateBy(centeredOrig, -rot);
        } else {
            centeredOrigRot = centeredOrig;
        }
    }

    // Content scale (non-uniform): when < 1.0, compress the backdrop UV toward
    // the element center. Faithful to LiquidToggle.kt / LiquidSlider.kt.
    vec2 contentScale = vec2(uContentScaleX, uContentScaleY);
    vec2 sampleCoord = screenCoord;
    if (uContentScaleX < 0.999 || uContentScaleY < 0.999) {
        sampleCoord = elementCenter + (screenCoord - elementCenter) * contentScale;
    }

    vec2 origHalfSize = uOriginalSize * 0.5;
    float origRadius = uOriginalCornerRadius;

    // --- SDF-texture glass path (faithful to SdfShader.kt) ---
    if (uUseSdfTexture > 0.5) {
        vec2 localPx = centeredOrigRot + uOriginalSize * 0.5;
        vec4 sdfData = sampleSdfTexture(localPx);
        if (sdfData.y <= 0.0) discard;
        float intensity = sdfData.x;
        float sdfMask = sdfData.y;
        vec2 normal = sdfData.zw;

        // --- Raw SDF debug render -----------------------------------
        // Bypass all glass effects and output the SDF texture's R channel
        // directly as grayscale. Inside (sd<0) → white, edge (sd=0) → 0.5,
        // outside (sd>0) → black. The A channel is preserved for AA. This
        // makes SDF quality / padding / aliasing directly visible — useful
        // when tuning DPR-adapted generation or highlight scale.
        if (uSdfDebugMode > 0.5) {
            vec2 uv = vec2(localPx.x / uOriginalSize.x,
                           localPx.y / uOriginalSize.y);
            vec4 v = texture2D(uSdfTexSampler, uv);
            // Decode R back to [-1,1]: negative = inside, positive = outside.
            float sd = v.r * 2.0 - 1.0;
            // Map sd ∈ [-1, 1] → gray ∈ [1, 0] (inside white, outside black).
            float gray = clamp(0.5 - sd * 0.5, 0.0, 1.0);
            // Overlay the normal as a faint RGB tint (so gradient direction is
            // visible). Multiplied by 0.15 so it doesn't swamp the gray.
            vec3 normalTint = vec3(v.g * 2.0 - 1.0, v.b * 2.0 - 1.0, 0.0) * 0.15;
            vec3 dbg = vec3(gray) + normalTint;
            // Use the same AA range as the non-debug path so the debug view
            // shows the real edge quality (not a hard threshold).
            float mask = smoothstep(uSdfAaMin, 1.0, v.a);
            float coverage = mask * uEnterAlpha;
            gl_FragColor = vec4(dbg * coverage, coverage);
            return;
        }

        // Compute the refracted sampling coordinate (SDF displacement).
        vec2 refractedOffsetOrig = intensity * uRefractionHeight * normal;
        vec2 refractedOffsetScreen = refractedOffsetOrig * layerScale;
        vec2 refractedScreen = screenCoord - refractedOffsetScreen;

        // Faithful to SdfShader.kt: color = content.eval(refractedCoord) * v.a
        // The content is the wallpaper after colorControls + blur(2dp).
        // FAITHFUL ORDERING: the original's onDrawBackdrop draws the wallpaper
        // AND drawRect(White 0.25) into the same buffer, THEN applies the
        // RenderEffect chain (colorControls, blur, SDF shader). So the white
        // overlay is PART of the SDF shader content input, and colorControls
        // is applied to the COMBINED (wallpaper + white) buffer.
        // We replicate: mix white into raw wallpaper FIRST, then apply
        // colorControls — so colorControls darkens the white too (matching
        // the original where contrast=0.75, brightness=-0.1 dims the white).
        //
        // TWO BACKDROP PATHS (adapted to global 2-pass blur):
        //   1. uSampleWallpaper > 0.5 (default / global-blur-OFF):
        //      Sample the WALLPAPER directly (uWallpaperSampler via coverUv)
        //      with inline poisson-disc blur (uBlurRadius). Faithful to the
        //      original's LayerBackdrop + blur(2dp).
        //   2. uSampleWallpaper < 0.5 (global-blur-ON, resolveBackdropTex has
        //      pre-blurred the cover-fitted wallpaper into uBackdrop):
        //      Sample uBackdrop via sceneUv with NO inline blur (it's already
        //      blurred by the 2-pass Gaussian pipeline). This adapts the SDF
        //      glass to the global separable blur setting, so the TextGlass
        //      respects blurDownsample / blurTapCap / dynamicBlurDownsample
        //      just like every other glass element. The cover-fitted wallpaper
        //      was rendered into wallpaperBlurFbo (canvas-sized) then 2-pass
        //      blurred, so sceneUv(refractedScreen) maps correctly.
        vec4 content;
        if (uSampleWallpaper > 0.5) {
            content = sampleWallpaperBlurred(refractedScreen, uBlurRadius);
        } else {
            content = sampleBackdrop(refractedScreen, 0.0);
        }
        vec3 rawContent = content.rgb;
        // Mix in white overlay (White 0.25 SrcOver) on RAW wallpaper first.
        if (uSurfaceColor.a > 0.001) {
            rawContent = uSurfaceColor.rgb * uSurfaceColor.a + rawContent * (1.0 - uSurfaceColor.a);
        }
        // THEN apply colorControls to the combined buffer.
        vec3 contentColor = applyColorControls(rawContent, uBrightness, uContrast, uSaturation);
        // Multiply by sdfMask (v.a) — faithful to content * v.a.
        vec3 color = contentColor * sdfMask;

        // Edge matte helpers — computed PER LAYER so each can be tuned
        // independently via uSdfEdgeMatte{Bevel,Tint,Base}Params. The base
        // edge factor is intensity (1 at the text boundary, →0 interior).
        // Per-layer params (vec2 = range, min) shape that into the final
        // matte weight:
        //   edge = clamp(intensity / max(range, 0.001), 0, 1) * (1 - min) + min
        //   range (0..1): how far the matte extends inward. 1 = full fade
        //     across the whole intensity field (original behavior); 0.5 =
        //     full strength by intensity=0.5 then flat (narrower rim); small
        //     = very thin matte line.
        //   min (0..1): floor matte amount in the deep interior. 0 = interior
        //     clear; 0.3 = interior always ≥30% matte.
        // bit 0 = bevel (光影), bit 1 = tint (染色), bit 2 = base (折射/底色).
        // When the overall uSdfEdgeMatteEnabled is OFF, no matte is applied
        // regardless of the bitmask. Faithful to "哑光层可以调是否作用于某些层"
        // + "给哑光每层加上作用参数调节，比如范围，最小值".
        float matteStrength = 0.65;   // desaturate toward luminance
        float matteDarken = 0.18;     // darken
        bool matteOn = uSdfEdgeMatteEnabled > 0.5;
        // bit 0 (bevel/提亮): targets mod 2. The previous code used
        // (targets - 8.0 * floor(targets / 8.0)) which is targets mod 8 —
        // that returns a non-zero value for ANY non-zero targets (1..7), so
        // the bevel matte was ALWAYS on whenever matteOn was true, regardless
        // of whether bit 0 was actually set. This made the bevel matte toggle
        // ineffective — turning off bit 0 (bevel) still left the bevel matte
        // active. Fixed to use targets mod 2 which correctly extracts ONLY
        // bit 0.
        float t1 = floor(uSdfEdgeMatteTargets / 1.0);  // = targets
        bool matteBevel = matteOn && (t1 - 2.0 * floor(t1 / 2.0)) >= 1.0;
        // bit 1 (tint): floor(targets/2) mod 2
        float t2 = floor(uSdfEdgeMatteTargets / 2.0);
        bool matteTint = matteOn && (t2 - 2.0 * floor(t2 / 2.0)) >= 1.0;
        // bit 2 (base): floor(targets/4) mod 2
        float t4 = floor(uSdfEdgeMatteTargets / 4.0);
        bool matteBase = matteOn && (t4 - 2.0 * floor(t4 / 2.0)) >= 1.0;
        // bit 3 (brighten/提亮): floor(targets/8) mod 2. The brighten layer
        // is the overall brightness increment (uBrightness from the 提亮
        // slider). When matteBrighten is true, the edge is pulled back toward
        // the pre-brightness rawContent — i.e. the edge gets LESS brightening
        // than the interior, producing a matte rim on the brightness layer.
        float t8 = floor(uSdfEdgeMatteTargets / 8.0);
        bool matteBrighten = matteOn && (t8 - 2.0 * floor(t8 / 2.0)) >= 1.0;
        // Per-layer matte edge factor — shaped by (range, min) params.
        float matteEdgeBase = clamp(intensity / max(uSdfEdgeMatteBaseParams.x, 0.001), 0.0, 1.0)
            * (1.0 - uSdfEdgeMatteBaseParams.y) + uSdfEdgeMatteBaseParams.y;
        // Brighten layer edge factor — shaped by the BRIGHTEN layer's params.
        float matteEdgeBrighten = clamp(intensity / max(uSdfEdgeMatteBrightenParams.x, 0.001), 0.0, 1.0)
            * (1.0 - uSdfEdgeMatteBrightenParams.y) + uSdfEdgeMatteBrightenParams.y;
        // Bevel / tint edge factors computed where they're used (below).

        // --- Brighten layer matte (bit 3) ---
        // 提亮哑光: the brighten (uBrightness) amount is ATTENUATED at the
        // edge by edgeFactor × strength. So the edge gets LESS brightening
        // than the interior — a PURE brightness cut at the rim, NOT
        // desaturation. We re-apply colorControls with an attenuated
        // brightness (full interior → 0 at edge when s=1); contrast +
        // saturation stay fully applied everywhere (NO saturation cut).
        // Faithful to "为什么会同时削减饱和度层" — fixed: only brightness is
        // cut, saturation + contrast untouched.
        if (matteBrighten) {
            float s = uSdfEdgeMatteBrightenStrength;
            float attBrightness = uBrightness * (1.0 - matteEdgeBrighten * s);
            vec3 attenuated = applyColorControls(rawContent, attBrightness, uContrast, uSaturation);
            color.rgb = attenuated * sdfMask;
        }

        // --- Base layer matte (bit 2) ---
        // Desaturate + darken the base refraction/body color at the edge.
        // Strength scales both the desaturate and darken amounts.
        if (matteBase) {
            float s = uSdfEdgeMatteBaseStrength;
            float lum = dot(color.rgb, vec3(0.213, 0.715, 0.072));
            color.rgb = mix(color.rgb, vec3(lum), matteEdgeBase * matteStrength * s);
            color.rgb *= 1.0 - matteEdgeBase * matteDarken * s;
        }

        // Bevel lighting — gated by uSdfBevelEnabled so the TextGlass "光影"
        // toggle can turn the light/shadow layer off WITHOUT zeroing
        // uSdfHighlightScale (which would also kill the refraction, since
        // intensity drives both). When bevel is off, the glass still refracts
        // the backdrop using the thickness slider's value — only the edge
        // brightness highlight is removed. The base dim is handled separately
        // via uBrightness on the JS side.
        // The bevel highlight is always pure white (no dye) — the whole-glass
        // tint (uSdfGlassTintHue) is applied separately below and affects the
        // ENTIRE glass body, not just the bevel band.
        // Edge matte (bit 0): when matteBevel is true, TWO visible effects
        // happen at the bevel band's edge, BOTH scaled by bevelMatteS (the
        // per-layer strength slider) so the user can actually SEE the matte
        //调节:
        //   1. Weaken the bevel brightening (less shiny highlight at edge).
        //   2. APPLY a desaturate + darken to the color at the edge — this
        //      produces the visible frosted/matte rim. Without this, a small
        //      bevel value (e.g. 0.32) makes the weakening nearly invisible,
        //      so the strength slider appeared to "do nothing". Now both
        //      effects are driven by the same strength so the slider is
        //      always visually responsive.
        // The edge factor is shaped by the BEVEL layer's (range, min) params.
        float matteEdgeBevel = clamp(intensity / max(uSdfEdgeMatteBevelParams.x, 0.001), 0.0, 1.0)
            * (1.0 - uSdfEdgeMatteBevelParams.y) + uSdfEdgeMatteBevelParams.y;
        // Bevel matte strength — scales BOTH the weakening and the matte rim.
        float bevelMatteS = uSdfEdgeMatteBevelStrength;
        if (uSdfBevelEnabled > 0.5) {
            float angleRad = uSdfLightAngle * 3.1415926 / 180.0;
            vec2 lightDir = vec2(cos(angleRad), sin(angleRad));
            float bevel1 = clamp(dot(normal, lightDir), 0.0, 1.0);
            float bevel1Amt = 0.5 * intensity * bevel1;
            if (matteBevel) {
                // (1) Weaken the bevel brightening at the edge.
                bevel1Amt *= 1.0 - matteEdgeBevel * (matteStrength + matteDarken) * bevelMatteS;
            }
            color.rgb *= 1.0 + bevel1Amt;
            float bevel2 = clamp(dot(normal, -lightDir), 0.0, 1.0);
            float bevel2Amt = 0.5 * bevel2 * min(1.0, smoothstep(1.0, 0.0, abs(intensity - 0.25) * 6.0));
            if (matteBevel) {
                bevel2Amt *= 1.0 - matteEdgeBevel * (matteStrength + matteDarken) * bevelMatteS;
            }
            color.rgb *= 1.0 + bevel2Amt;
            // (2) APPLY the matte rim: desaturate toward luminance + darken at
            // the edge. This is the VISIBLE matte effect on the bevel layer —
            // without it the strength slider had no visible feedback when the
            // bevel value was small. Faithful to "我要能调提亮层的哑光".
            if (matteBevel) {
                float lum = dot(color.rgb, vec3(0.213, 0.715, 0.072));
                color.rgb = mix(color.rgb, vec3(lum), matteEdgeBevel * matteStrength * bevelMatteS);
                color.rgb *= 1.0 - matteEdgeBevel * matteDarken * bevelMatteS;
            }
        }

        // Whole-glass tint (染色) — gated by uSdfGlassTintEnabled master switch.
        // Two stages, both using the same hue:
        //   1. Color-mix filter (染色前滤镜): mixes the glass body toward the
        //      pure saturated hue color by uSdfGlassTintMix amount (SrcOver-
        //      style blend toward a solid color). This is a "color mix" filter
        //      — distinct from the hue-dye. 0 = skip; 1 = full color overlay.
        //   2. Hue-dye: applies BlendMode.Hue (Skia non-separable Hue blend) at
        //      uSdfGlassTintStrength (default 0.85, adjustable) — takes hue from
        //      the tint source, keeps the glass's own saturation + value. So a
        //      dyed glass still looks like glass (luminance/sat preserved) just
        //      tinted. The strength slider lets the user tune how strong the
        //      dye is (0 = no dye, 1 = full hue replacement).
        // Both stages apply to the ENTIRE glass body (not just the bevel band).
        // Independent of the 光影 (bevel) toggle.
        // Edge matte (bit 1): when matteTint is true, the tint's blend factor
        // is reduced at the edge — the rim keeps more of the desaturated base
        // color instead of the dyed hue, so the edge looks matte while the
        // interior stays fully dyed. The edge factor is shaped by the TINT
        // layer's (range, min) params.
        float matteEdgeTint = clamp(intensity / max(uSdfEdgeMatteTintParams.x, 0.001), 0.0, 1.0)
            * (1.0 - uSdfEdgeMatteTintParams.y) + uSdfEdgeMatteTintParams.y;
        // Tint matte strength — scales how much the tint is suppressed at edge.
        float tintMatteS = uSdfEdgeMatteTintStrength;
        if (uSdfGlassTintEnabled > 0.5 && uSdfGlassTintHue > 0.5) {
            vec3 tintSrc = hsv2rgb(vec3(uSdfGlassTintHue / 360.0, 1.0, 1.0));
            // Stage 1: color-mix filter (before hue-dye).
            if (uSdfGlassTintMix > 0.001) {
                float mixAmt = uSdfGlassTintMix;
                if (matteTint) {
                    mixAmt *= 1.0 - matteEdgeTint * matteStrength * tintMatteS;
                }
                color.rgb = mix(color.rgb, tintSrc, mixAmt);
            }
            // Stage 2: hue-dye (BlendMode.Hue at uSdfGlassTintStrength).
            // The dye strength is now adjustable (default 0.85, matching the
            // original's hardcoded constant). 0 = no hue-dye; 1 = full hue
            // replacement. Faithful to "加一个调染色强度的".
            vec3 hueBlended = blendHue(color, tintSrc);
            float tintMix = uSdfGlassTintStrength;
            if (matteTint) {
                tintMix *= 1.0 - matteEdgeTint * matteStrength * tintMatteS;
            }
            color.rgb = mix(color.rgb, hueBlended, tintMix);
        }

        // NOTE: the old unconditional edge-matte block (which applied a single
        // global desaturate+darken to the composited color) has been replaced
        // by the per-layer matte applications above (base / bevel / tint),
        // each gated by its bit in uSdfEdgeMatteTargets.

        // PREMULTIPLIED output: RGB = color * coverage, A = coverage.
        // 'color' already includes '* sdfMask' (line above), so we only need
        // to also factor in uEnterAlpha to keep RGB and A consistent.
        // Premultiplied storage is REQUIRED for the elFbo: its texture uses
        // LINEAR filtering, and bilinear interpolation of non-premultiplied
        // alpha darkens RGB at the coverage boundary (the classic
        // "non-premult + bilinear" artifact that produces a dark fringe).
        // The composite pass then uses premult SrcOver (ONE, ONE_MINUS_SRC_ALPHA).
        float sdfCoverage = sdfMask * uEnterAlpha;
        gl_FragColor = vec4(color * uEnterAlpha, sdfCoverage);
        return;
    }

    // SDF for refraction/highlight — sdShape() dispatches to the G2 SDF
    // texture (sampleClipSdf) when uUseContinuousSdf=1 AND
    // uNoContinuousSdfInRefraction=0, else the analytic sdRoundedRect.
    float sd = sdShape(centeredOrigRot, origHalfSize, origRadius);
    // Clip + edgeAA: alpha mask (browser-native AA) when capsule enabled.
    float edgeAlpha;
    if (uUseContinuousSdf > 0.5) {
        float mask = sampleClipMask(centeredOrigRot, origHalfSize, origRadius);
        if (mask < 0.01) discard;
        edgeAlpha = mask;
    } else {
        if (sd > 0.5) discard;
        edgeAlpha = 1.0 - smoothstep(-0.5, 0.5, sd);
    }

    // --- 1. Backdrop sample (before refraction) -------------------
    // Use sampleCoord (content-scaled) so the backdrop shrinks inward when
    // uContentScaleX/Y < 1.0 (toggle/slider knob press effect).
    vec4 backdrop;
    if (uIndicatorBackdrop > 0.5) {
        backdrop = sampleIndicatorBackdrop(screenCoord, uBlurRadius);
    } else if (uUseToggleBackdrop > 0.5) {
        backdrop = sampleToggleBackdrop(screenCoord, uBlurRadius);
    } else if (uUseMagnifier > 0.5) {
        backdrop = sampleMagnifier(screenCoord, uBlurRadius);
    } else {
        backdrop = sampleBackdrop(sampleCoord, uBlurRadius);
    }
    // colorControls: for backdropFbo+useSeparableBlur elements, cc was already
    // applied as a fullscreen pass BEFORE the 2-pass blur (uSkipColorControls=1),
    // matching the original's colorControls→blur order. Skip here to avoid
    // double-applying. For inline-blur elements, apply here.
    vec3 color = (uSkipColorControls > 0.5) ? backdrop.rgb : applyColorControls(backdrop.rgb, uBrightness, uContrast, uSaturation);
    // Magnifier glass is always OPAQUE — faithful to the original which
    // samples rememberCombinedBackdrop (wallpaper + content + cursor all
    // composited onto the opaque wallpaper). The port's scene texture may
    // carry partial alpha (e.g. card 0.9), which would make the glass
    // translucent. Force alpha=1 for magnifier.
    float alpha = (uUseMagnifier > 0.5) ? 1.0 : backdrop.a;

    // --- 2. Lens refraction (SDF + circleMap) ---------------------
    // Faithful port of RoundedRectRefractionWithDispersionShaderString.
    // SDF/grad computed in ORIGINAL space; uRefractionHeight/Amount are in
    // original px (NOT scaled by layerScale — the original AGSL shader receives
    // the original size and the graphicsLayer scales the OUTPUT, not the params).
    // Early-out: if we're deeper than refractionHeight from the edge,
    // skip refraction entirely (the lens doesn't reach here).
    if (uRefractionHeight > 0.5 && (-sd) < uRefractionHeight) {
        float sdClamped = min(sd, 0.0);
        float d = circleMap(1.0 - (-sdClamped) / uRefractionHeight) * uRefractionAmount;

        float gradRadius = min(origRadius * 1.5, min(origHalfSize.x, origHalfSize.y));
        vec2 grad = gradSdRoundedRect(centeredOrigRot, origHalfSize, gradRadius);
        // AGSL: normalize(grad + depthEffect * normalize(centeredCoord))
        vec2 depthVec = vec2(0.0);
        if (uDepthEffect > 0.5) {
            float dirLen = length(centeredOrigRot);
            if (dirLen > 1e-6) depthVec = centeredOrigRot / dirLen;
        }
        vec2 gradSum = grad + uDepthEffect * depthVec;
        float gradLen = length(gradSum);
        if (gradLen > 1e-6) grad = gradSum / gradLen;

        // Refraction offset in ORIGINAL space, then map to SCREEN space.
        //   offset_orig = d * grad          (original px)
        //   offset_screen = offset_orig * layerScale  (screen px, for sampling)
        // Faithful to: AGSL computes offset in original space, then graphicsLayer
        // scales the rendered output — so a pixel at original position p samples
        // the backdrop at p + offset_orig, and the result appears at screen
        // position center + p*layerScale. The backdrop sample position in screen
        // space is therefore center + (p + offset_orig)*layerScale
        // = screenCoord + offset_orig * layerScale.
        vec2 refractedOffsetOrig = d * grad;
        // Rotate the local-space offset BACK to screen space (by +rotation),
        // then scale by layerScale. Without the rotation, refraction points
        // in the wrong direction when the element is rotated.
        vec2 refractedOffsetScreen = rotateBy(refractedOffsetOrig, rot) * layerScale;
        vec2 refractedScreen = screenCoord + refractedOffsetScreen;
        vec2 refractedSampleCoord = refractedScreen;
        if (uIndicatorBackdrop < 0.5 && uUseToggleBackdrop < 0.5 &&
            (uContentScaleX < 0.999 || uContentScaleY < 0.999)) {
            refractedSampleCoord = elementCenter + (refractedScreen - elementCenter) * contentScale;
        }

        if (uChromaticAberration > 0.5) {
            // Faithful 7-path chromatic dispersion (ROYGBV + purple).
            // Original AGSL: dispersionIntensity = chromaticAberration * (cx*cy)/(hx*hy)
            //                dispersedCoord = d * grad * dispersionIntensity
            // 7 samples at dispersedCoord * {1, 2/3, 1/3, 0, -1/3, -2/3, -1}
            // with weighted channel accumulation.
            float dispersionIntensity = 1.0 * ((centeredOrigRot.x * centeredOrigRot.y) / (origHalfSize.x * origHalfSize.y));
            vec2 dispersedOffsetOrig = refractedOffsetOrig * dispersionIntensity;
            vec2 dispersedOffsetScreen = rotateBy(dispersedOffsetOrig, rot) * layerScale;

            // Sample helper — pick the right backdrop sampler.
            #define SAMPLE_DISPERSED(offset)                 (uIndicatorBackdrop > 0.5 ? sampleIndicatorBackdrop(refractedScreen + (offset), uBlurRadius) :                  uUseToggleBackdrop > 0.5 ? sampleToggleBackdrop(refractedScreen + (offset), uBlurRadius) :                  uUseMagnifier > 0.5 ? sampleMagnifier(refractedScreen + (offset), uBlurRadius) :                  sampleBackdrop(refractedSampleCoord + (offset), uBlurRadius))

            vec4 sRed    = SAMPLE_DISPERSED(+dispersedOffsetScreen);
            vec4 sOrange = SAMPLE_DISPERSED(+dispersedOffsetScreen * (2.0 / 3.0));
            vec4 sYellow = SAMPLE_DISPERSED(+dispersedOffsetScreen * (1.0 / 3.0));
            vec4 sGreen  = SAMPLE_DISPERSED(vec2(0.0));
            vec4 sCyan   = SAMPLE_DISPERSED(-dispersedOffsetScreen * (1.0 / 3.0));
            vec4 sBlue   = SAMPLE_DISPERSED(-dispersedOffsetScreen * (2.0 / 3.0));
            vec4 sPurple = SAMPLE_DISPERSED(-dispersedOffsetScreen);

            #undef SAMPLE_DISPERSED

            // Faithful channel weighting from the original AGSL shader.
            vec3 dispColor = vec3(0.0);
            float dispAlpha = 0.0;
            // red
            dispColor.r += sRed.r / 3.5;
            dispAlpha  += sRed.a / 7.0;
            // orange
            dispColor.r += sOrange.r / 3.5;
            dispColor.g += sOrange.g / 7.0;
            dispAlpha  += sOrange.a / 7.0;
            // yellow
            dispColor.r += sYellow.r / 3.5;
            dispColor.g += sYellow.g / 3.5;
            dispAlpha  += sYellow.a / 7.0;
            // green
            dispColor.g += sGreen.g / 3.5;
            dispAlpha  += sGreen.a / 7.0;
            // cyan
            dispColor.g += sCyan.g / 3.5;
            dispColor.b += sCyan.b / 3.0;
            dispAlpha  += sCyan.a / 7.0;
            // blue
            dispColor.b += sBlue.b / 3.0;
            dispAlpha  += sBlue.a / 7.0;
            // purple
            dispColor.r += sPurple.r / 7.0;
            dispColor.b += sPurple.b / 3.0;
            dispAlpha  += sPurple.a / 7.0;

            color = (uSkipColorControls > 0.5) ? dispColor : applyColorControls(dispColor, uBrightness, uContrast, uSaturation);
            // Magnifier chromatic aberration also forces opaque.
            alpha = (uUseMagnifier > 0.5) ? 1.0 : dispAlpha;
        } else {
            vec4 refracted;
            if (uIndicatorBackdrop > 0.5) {
                refracted = sampleIndicatorBackdrop(refractedScreen, uBlurRadius);
            } else if (uUseToggleBackdrop > 0.5) {
                refracted = sampleToggleBackdrop(refractedScreen, uBlurRadius);
            } else if (uUseMagnifier > 0.5) {
                refracted = sampleMagnifier(refractedScreen, uBlurRadius);
            } else {
                refracted = sampleBackdrop(refractedSampleCoord, uBlurRadius);
            }
            color = (uSkipColorControls > 0.5) ? refracted.rgb : applyColorControls(refracted.rgb, uBrightness, uContrast, uSaturation);
            // Magnifier refraction also forces opaque (see backdrop sample above).
            alpha = (uUseMagnifier > 0.5) ? 1.0 : refracted.a;
        }
    }

    // --- 3. onDrawSurface: tint (BlendMode.Hue + 0.75 alpha) -----
    // Faithful port of LiquidButton.kt onDrawSurface:
    //   drawRect(tint, blendMode = BlendMode.Hue)
    //   drawRect(tint.copy(alpha = 0.75f))
    // First pass: replace backdrop hue with tint hue (Hue blend, alpha = tint.a).
    // Second pass: overlay tint color at 0.75*alpha (SrcOver blend).
    if (uTintColor.a > 0.001) {
        vec3 hueBlended = blendHue(color, uTintColor.rgb);
        color = mix(color, hueBlended, uTintColor.a);
        color = mix(color, uTintColor.rgb, 0.75 * uTintColor.a);
    }

    // --- 4. onDrawSurface: surfaceColor (drawRect(surfaceColor)) --
    if (uSurfaceColor.a > 0.001) {
        color = mix(color, uSurfaceColor.rgb, uSurfaceColor.a);
    }

    // --- 5. Highlight (edge specular) -----------------------------
    // NOTE: The rim highlight is drawn as a SEPARATE pass (see
    // RIM_HIGHLIGHT_FRAGMENT_SHADER) with true Plus/SrcOver blend,
    // matching the original HighlightModifier.kt which records a separate
    // graphics layer. Doing it inline here would dim the highlight via the
    // element's edge AA, which is wrong — the highlight layer is composited
    // on top with its own blend mode.

    // --- 7. Edge anti-aliasing -----------------------------------
    // edgeAlpha was computed earlier (mask mode: direct coverage, analytic: smoothstep).
    //
    // PREMULTIPLIED output: RGB = color * coverage, A = coverage.
    // The elFbo texture uses LINEAR filtering; storing non-premultiplied
    // (color, coverage) causes bilinear interpolation between an edge texel
    // (color, 0.5) and the cleared-outside texel (0,0,0,0) to produce
    // ((1-t)*color, (1-t)*0.5) — RGB darkened by (1-t). The composite's
    // SrcOver blend then multiplies RGB by alpha AGAIN, squaring the
    // darkening → dark fringe at the glass edge.
    // Premultiplying here makes the linear filter mathematically correct:
    // lerp((color*a, a), (0,0,0,0), t) = ((1-t)*color*a, (1-t)*a), which
    // composites correctly with premult SrcOver (ONE, ONE_MINUS_SRC_ALPHA).
    float coverage = alpha * edgeAlpha * uEnterAlpha;
    gl_FragColor = vec4(color * coverage, coverage);
}
`;
}
var ELEMENT_FRAGMENT_SHADER = generateElementFragmentShader(DEFAULT_BLUR_TAPS);
// src/cdn/core/shaders/shadow.ts
var SHADOW_FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uCanvasSize;
uniform vec2  uElementOffset;   // SCALED rect top-left (where the quad is drawn)
uniform vec2  uElementSize;     // SCALED size (includes graphicsLayer scale)
uniform vec4  uCornerRadii;     // SCALED corner radii
uniform float uShadowRadius;    // ORIGINAL px (NOT scaled — faithful to BlurMaskFilter at original size)
uniform vec2  uShadowOffset;    // ORIGINAL px (offsetX, offsetY; +Y = downward)
uniform vec4  uShadowColor;     // rgba
// --- ORIGINAL-SPACE SDF (faithful to graphicsLayer { scaleX, scaleY }) ---
// Same approach as the element shader: compute the shadow SDF in ORIGINAL
// space (shape is a correct capsule, not stretched), then the graphicsLayer
// scales the entire shadow layer by (scaleX, scaleY). The shadow offset is
// in ORIGINAL px; we multiply by uLayerScale to map it to screen space for
// the SDF evaluation (offset_screen = offset_orig * layerScale). The shadow
// radius (blur sigma) stays in ORIGINAL px because the Gaussian falloff is
// computed in original space — the graphicsLayer then stretches the blurred
// result, which is the faithful behavior (BlurMaskFilter blurs at original
// resolution, then graphicsLayer scales the blurred pixels).
uniform vec2  uOriginalSize;        // element size in px (ORIGINAL, unscaled)
uniform float uOriginalCornerRadius; // corner radius in px (ORIGINAL, unscaled)
uniform vec2  uLayerScale;          // (scaleX, scaleY) from graphicsLayer
uniform float uElementRotation;     // rotation in radians (graphicsLayer rotationZ)

${SDF_GLSL}

void main() {
    // Flip gl_FragCoord (bottom-left origin) to top-left origin, so +Y
    // points downward — matching CSS convention.
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    // elementCenter is the SAME for scaled and original rects (scaling is
    // around the center), so uElementOffset + uElementSize*0.5 gives the
    // correct center.
    vec2 elementCenter = uElementOffset + uElementSize * 0.5;
    vec2 centeredScreen = screenCoord - elementCenter;
    // Map to ORIGINAL space (guard against divide-by-zero).
    vec2 layerScale = max(uLayerScale, vec2(1e-4));
    vec2 centeredOrig = centeredScreen / layerScale;
    // Un-rotate into local space so the shadow shape rotates with the element.
    // Also rotate the shadow offset into local space so it stays consistent.
    vec2 centeredOrigRot = rotateBy(centeredOrig, -uElementRotation);
    vec2 shadowOffsetRot = rotateBy(uShadowOffset, -uElementRotation);

    vec2 origHalfSize = uOriginalSize * 0.5;
    float origRadius = uOriginalCornerRadius;

    // Shadow offset: defined in ORIGINAL px, applied in screen space.
    // The original draws the shadow at original size with this offset, then
    // graphicsLayer scales the whole layer — so the offset effectively
    // becomes offset_orig * layerScale in screen space. We map it back to
    // original space for the SDF: offset_orig = offset_screen / layerScale,
    // which cancels — so we use uShadowOffset directly in original space.
    vec2 shadowCenteredOrig = centeredOrigRot - shadowOffsetRot;
    float sd = sdShape(shadowCenteredOrig, origHalfSize, origRadius);
    // SDF of the element itself (not offset) — used to mask the shadow
    // inside the element so it doesn't bleed through the AA edge.
    float elementSd = sdShape(centeredOrigRot, origHalfSize, origRadius);

    // Shadow intensity: Gaussian falloff from the shadow shape's edge.
    // uShadowRadius is in ORIGINAL px (faithful to BlurMaskFilter at original
    // size). sigma = radius/3 matches the BlurMaskFilter spread.
    float sigma = max(uShadowRadius / 3.0, 1.0);
    float shadow = 0.5 * exp(-sd * sd / (2.0 * sigma * sigma));
    // Mask out the shadow inside the element (the element covers it).
    shadow *= smoothstep(-1.0, 1.0, elementSd);

    gl_FragColor = vec4(uShadowColor.rgb, uShadowColor.a * shadow);
}
`;
var INNER_SHADOW_FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uCanvasSize;
uniform vec2  uElementOffset;
uniform vec2  uElementSize;
uniform vec4  uCornerRadii;
uniform float uInnerShadowRadius;
uniform float uInnerShadowAlpha;
uniform vec2  uInnerShadowOffset;

${SDF_GLSL}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    vec2 localCoord = screenCoord - uElementOffset;
    vec2 halfSize = uElementSize * 0.5;
    vec2 centeredCoord = localCoord - halfSize;

    float radius = radiusAt(centeredCoord, uCornerRadii);
    float sd = sdShape(centeredCoord, halfSize, radius);
    if (sd > 0.5) discard;

    vec2 innerCentered = centeredCoord - uInnerShadowOffset;
    float innerSd = sdShape(innerCentered, halfSize, radius);
    float band = smoothstep(uInnerShadowRadius, 0.0, innerSd);
    band *= step(0.0, innerSd);
    gl_FragColor = vec4(0.0, 0.0, 0.0, band * uInnerShadowAlpha * 0.5);
}
`;
// src/cdn/core/shaders/highlight.ts
var HIGHLIGHT_FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uCanvasSize;
uniform vec2  uOffset;       // element top-left in canvas px (top-left origin) — SCALED rect
uniform vec2  uSize;         // element size in canvas px — SCALED
uniform vec4  uCornerRadii;  // capsule radii (topLeft, topRight, bottomRight, bottomLeft) in px — SCALED
uniform vec4  uColor;        // rgba; usually white * (alpha = 0.15 * progress)
uniform float uRadius;       // glow radius in canvas px (= minDim * 1.5, SCALED space)
uniform vec2  uPosition;     // finger position in element-local px (top-left origin, SCALED space)
// --- ORIGINAL-SPACE SDF clip (faithful to graphicsLayer { scaleX, scaleY }) ---
// The press glow (InteractiveHighlight) is drawn INSIDE the graphicsLayer, so
// it is clipped to the ORIGINAL capsule shape, then scaled with the layer.
// The glow position + radius are in SCALED space (they track the finger in
// screen px), but the clip SDF is in original space so the capsule clip stays
// correct when the button is stretched.
uniform vec2  uOriginalSize;
uniform float uOriginalCornerRadius;
uniform vec2  uLayerScale;
uniform float uElementRotation;

${SDF_GLSL}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    vec2 localCoord = screenCoord - uOffset;

    // --- Capsule clip in ORIGINAL space (faithful to graphicsLayer clip) ---
    vec2 elementCenter = uOffset + uSize * 0.5;
    vec2 centeredScreen = screenCoord - elementCenter;
    vec2 layerScale = max(uLayerScale, vec2(1e-4));
    vec2 centeredOrig = centeredScreen / layerScale;
    vec2 origHalfSize = uOriginalSize * 0.5;
    float sd = sdShape(rotateBy(centeredOrig, -uElementRotation), origHalfSize, uOriginalCornerRadius);
    if (sd > 0.5) discard;
    float clipAlpha = 1.0 - smoothstep(-0.5, 0.5, sd);

    // Faithful AGSL port: smoothstep(radius, radius*0.5, dist) means
    // intensity = 1 at dist <= radius*0.5, fading to 0 at dist >= radius.
    // dist + uPosition are in SCALED local space (finger tracks screen px).
    float dist = distance(localCoord, uPosition);
    float intensity = smoothstep(uRadius, uRadius * 0.5, dist);

    // Premultiplied Plus-blend contribution. Renderer uses blendFunc(ONE, ONE)
    // so result.rgb = contribution + dst.rgb (clamped to 1).
    vec3 contribution = uColor.rgb * uColor.a * intensity * clipAlpha;
    gl_FragColor = vec4(contribution, 1.0);
}
`;
var TINT_FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uCanvasSize;
uniform vec2  uOffset;
uniform vec2  uSize;
uniform vec4  uCornerRadii;
uniform vec4  uColor;
// --- ORIGINAL-SPACE SDF clip (faithful to graphicsLayer { scaleX, scaleY }) ---
// The white overlay (onDrawSurface drawRect) is drawn INSIDE the graphicsLayer,
// so it is clipped to the ORIGINAL capsule shape, then scaled with the layer.
// Computing the clip SDF in original space keeps the capsule clip correct when
// the button is stretched (no corner bleed, no stretched-clip artifacts).
uniform vec2  uOriginalSize;
uniform float uOriginalCornerRadius;
uniform vec2  uLayerScale;
uniform float uElementRotation;

${SDF_GLSL}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    vec2 elementCenter = uOffset + uSize * 0.5;
    vec2 centeredScreen = screenCoord - elementCenter;
    vec2 layerScale = max(uLayerScale, vec2(1e-4));
    vec2 centeredOrig = centeredScreen / layerScale;
    vec2 origHalfSize = uOriginalSize * 0.5;
    vec2 centeredOrigRot = rotateBy(centeredOrig, -uElementRotation);

    // CLIP: for continuous-curvature (G2) elements, sample the R channel
    // (browser-native AA coverage) directly — this gives the most accurate
    // edge with NO exterior半透明 band. Using the G channel (SDF) with
    // smoothstep(-0.5, 0.5) leaves a ~1px half-transparent fringe OUTSIDE
    // the true shape edge (sd ∈ [0, 0.5] is not discarded but has < 1
    // alpha), which lets the underlying glass body / shadow leak through
    // as a thin dark line ("capsule 黑边"). R coverage is 0 outside the
    // shape (browser AA only rasterizes the interior + edge), so the
    // fringe is eliminated and the clip is pixel-tight.
    // For G1 (analytic) elements, keep the SDF smoothstep — it's the
    // only shape source available.
    float clipAlpha;
    if (uUseContinuousSdf > 0.5) {
        clipAlpha = sampleClipMask(centeredOrigRot, origHalfSize, uOriginalCornerRadius);
    } else {
        float sd = sdRoundedRect(centeredOrigRot, origHalfSize, uOriginalCornerRadius);
        if (sd > 0.5) discard;
        clipAlpha = 1.0 - smoothstep(-0.5, 0.5, sd);
    }
    if (clipAlpha < 0.001) discard;

    gl_FragColor = vec4(uColor.rgb, uColor.a * clipAlpha);
}
`;
var RIM_HIGHLIGHT_FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uCanvasSize;
uniform vec2  uOffset;          // element top-left in canvas px (top-left origin) — SCALED rect
uniform vec2  uSize;            // element size in canvas px — SCALED (includes graphicsLayer scale)
uniform vec4  uCornerRadii;     // (topLeft, topRight, bottomRight, bottomLeft) in px — SCALED
uniform vec4  uHighlightColor;  // rgb + 1.0
uniform float uHighlightAngle;  // radians
uniform float uHighlightFalloff;
uniform float uHighlightAlpha;
uniform float uHighlightMode;     // 0=Default, 1=Ambient, 2=Plain
uniform float uHighlightStrokeWidth;
uniform float uHighlightBlur;
// --- ORIGINAL-SPACE SDF (faithful to graphicsLayer { scaleX, scaleY }) ---
// Same approach as the element shader: compute SDF/stroke in ORIGINAL space
// (shape is correct, not stretched), so the highlight clip + stroke remain a
// correct capsule shape that is then scaled by graphicsLayer. Without this,
// a horizontally-stretched button would stretch the highlight clip too,
// making the stroke band uneven. See element.ts for the full rationale.
uniform vec2  uOriginalSize;        // element size in px (ORIGINAL, unscaled)
uniform float uOriginalCornerRadius; // corner radius in px (ORIGINAL, unscaled)
uniform vec2  uLayerScale;          // (scaleX, scaleY) from graphicsLayer
uniform float uElementRotation;     // rotation in radians (graphicsLayer rotationZ)

${SDF_GLSL}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    // elementCenter is the SAME for scaled and original rects (scaling is
    // around the center), so uOffset + uSize*0.5 gives the correct center.
    vec2 elementCenter = uOffset + uSize * 0.5;
    vec2 centeredScreen = screenCoord - elementCenter;
    // Map to ORIGINAL space (guard against divide-by-zero).
    vec2 layerScale = max(uLayerScale, vec2(1e-4));
    vec2 centeredOrig = centeredScreen / layerScale;
    // Un-rotate into the element's local space so the SDF shape rotates.
    vec2 centeredOrigRot = rotateBy(centeredOrig, -uElementRotation);

    vec2 origHalfSize = uOriginalSize * 0.5;
    float origRadius = uOriginalCornerRadius;

    // SDF for stroke — analytic sdRoundedRect (matches the pre-capsule
    // highlight implementation). When capsule is OFF, this is the exact
    // shape. When capsule is ON, this is a close approximation (circular
    // arc vs G2 Bezier — the difference is sub-pixel within the 2px stroke
    // band, invisible in the highlight).
    float sd = sdRoundedRect(centeredOrigRot, origHalfSize, origRadius);

    // Outside the shape — clip (hard discard, matching pre-capsule behavior).
    if (sd > 0.0) discard;

    // Stroke mask — faithful to HighlightModifier.kt:
    //   paint.style = Stroke
    //   paint.strokeWidth = ceil(width.toPx()) * 2     // full stroke, centered on edge
    //   paint.blur(blurRadius.toPx())                   // BlurMaskFilter, Blur.NORMAL
    //   canvas.clipOutline(outline)                     // clip to inside the shape
    //   canvas.drawOutline(outline, paint)              // stroke centered on edge
    //
    // Implementation: first compute a HARD-EDGE stroke mask (1.0 inside the
    // stroke band, 0.0 outside), then convolve it with a Gaussian kernel by
    // sampling the SDF at multiple offsets along the gradient direction.
    // This mirrors the original's two-step process (draw stroke → blur),
    // rather than using an analytic erf approximation.
    //
    // The hard stroke band: sd in [-strokeHalf, +strokeHalf].
    // After clip (sd > 0 discarded by the outer if), only [-strokeHalf, 0] shows.
    //
    // Faithful to the original BlurMaskFilter:
    //   paint.blur(blurRadius.toPx())  →  BlurMaskFilter(NORMAL, sigma=blurRadius_px)
    // In Skia/Android, BlurMaskFilter's radius param IS the Gaussian sigma
    // (not radius/3). blurRadius = width/2 = 0.25dp, so sigma = 0.25*dpr px.
    // uHighlightBlur is already in device px (set by the renderer as widthDp*dpr*0.5).
    float strokeHalf = uHighlightStrokeWidth * 0.5;
    float sigma = max(uHighlightBlur, 0.1);

    // Gaussian convolution of the hard stroke mask — 3-tap (σ-spaced).
    // The original's BlurMaskFilter has σ = blurRadius = 0.25dp → 0.25px at
    // dpr=1. At this sub-pixel sigma, only 3 taps (at -σ, 0, +σ) are needed
    // — the Gaussian weight at ±2σ is exp(-2) ≈ 0.14, negligible. This
    // replaces the old 65-tap loop (which computed 65 exp() calls per pixel,
    // ~650 cycles — the single biggest shader cost). 3 taps = 3 exp() = ~30
    // cycles, a 20× reduction with identical visual result at σ=0.25.
    //   hardMask(sd) = 1.0 if |sd| < strokeHalf, else 0.0
    //   blurred(sd) = Σ hardMask(sd - offset_k) * gauss(offset_k, σ)
    // CLIP HALVING: the stroke is centered on sd=0; clip removes sd>0 (outer
    // half), so peak ≈ 0.5. We halve to match.
    float strokeMask = 0.0;
    float wSum = 0.0;
    for (int i = -1; i <= 1; i++) {
        float offset = float(i) * sigma;  // taps at -σ, 0, +σ
        float sampleSd = sd - offset;
        float hard = (abs(sampleSd) < strokeHalf) ? 1.0 : 0.0;
        float w = exp(-0.5 * (offset * offset) / (sigma * sigma));
        strokeMask += hard * w;
        wSum += w;
    }
    strokeMask /= wSum;
    strokeMask *= 0.5;  // clip halves the symmetric stroke at the edge

    if (uHighlightMode < 0.5) {
        // Default — shader returns color * intensity, Plus blend.
        float gradRadius = min(origRadius * 1.5, min(origHalfSize.x, origHalfSize.y));
        vec2 grad = gradSdRoundedRect(centeredOrigRot, origHalfSize, gradRadius);
        vec2 normal = vec2(cos(uHighlightAngle), sin(uHighlightAngle));
        float d = dot(grad, normal);
        float intensity = pow(abs(d), uHighlightFalloff);
        vec3 c = uHighlightColor.rgb * intensity * strokeMask * uHighlightAlpha;
        gl_FragColor = vec4(c, 1.0);
    } else if (uHighlightMode < 1.5) {
        // Ambient — premultiplied SrcOver blend (renderer uses ONE, ONE_MINUS_SRC_ALPHA).
        // Faithful to AmbientHighlightShaderString:
        //   float d = dot(grad, normal);
        //   float intensity = pow(abs(d), falloff);
        //   float t = step(0.0, d);  ← half-black-half-white split
        //   return half4(t, t, t, 1.0) * intensity;
        // Output is premultiplied: vec4(color.rgb * t * i, i).
        // Bright side: adds white light. Dark side: dims scene → 3D sphere.
        // paint.color(0.38) is overridden by shader; alpha = 1.0 not 0.38.
        float gradRadius = min(origRadius * 1.5, min(origHalfSize.x, origHalfSize.y));
        vec2 grad = gradSdRoundedRect(centeredOrigRot, origHalfSize, gradRadius);
        vec2 normal = vec2(cos(uHighlightAngle), sin(uHighlightAngle));
        float d = dot(grad, normal);
        float intensity = pow(abs(d), uHighlightFalloff);
        float t = step(0.0, d);  // 0 on dark side (d<0), 1 on bright side (d>=0)
        float i = intensity * strokeMask * uHighlightAlpha;
        gl_FragColor = vec4(uHighlightColor.rgb * t * i, i);
    } else {
        // Plain — even stroke, paint.color, Plus blend.
        vec3 c = uHighlightColor.rgb * strokeMask * uHighlightAlpha;
        gl_FragColor = vec4(c, 1.0);
    }
}
`;
var HIGHLIGHT_STROKE_FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uCanvasSize;
uniform vec2  uOffset;          // element top-left (top-left origin) — SCALED
uniform vec2  uSize;            // element size — SCALED
uniform vec4  uCornerRadii;     // SCALED
uniform float uHighlightStrokeWidth;  // ceil(width*dpr)*2, device px
uniform vec2  uOriginalSize;
uniform float uOriginalCornerRadius;
uniform vec2  uLayerScale;
uniform float uElementRotation;
// uCornerStyle, uUseContinuousSdf, uContinuousSdf, uContinuousSdfTexSize,
// uContinuousSdfElementSize are declared in SDF_GLSL (do NOT redeclare here).

${SDF_GLSL}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    vec2 elementCenter = uOffset + uSize * 0.5;
    vec2 centeredScreen = screenCoord - elementCenter;
    vec2 layerScale = max(uLayerScale, vec2(1e-4));
    vec2 centeredOrig = centeredScreen / layerScale;
    vec2 centeredOrigRot = rotateBy(centeredOrig, -uElementRotation);

    vec2 origHalfSize = uOriginalSize * 0.5;
    float origRadius = uOriginalCornerRadius;

    float sd = sdShape(centeredOrigRot, origHalfSize, origRadius);

    // clipOutline — clip to INSIDE the shape. Outside (sd > 0) is discarded.
    float edgeAA;
    if (uUseContinuousSdf > 0.5) {
        float mask = sampleClipMask(centeredOrigRot, origHalfSize, origRadius);
        if (mask < 0.01) discard;
        edgeAA = mask;
    } else {
        if (sd > 0.0) discard;
        edgeAA = 1.0 - smoothstep(-0.5, 0.5, sd);
    }

    // Stroke band centered on the edge (sd = 0), with 0.5px coverage AA on
    // the inner boundary. The outer boundary (sd = +strokeHalf) is clipped
    // away by edgeAA above. Faithful to Skia Paint.Stroke's coverage AA.
    // The BlurMaskFilter pass (when sigma >= 0.5px) softens this further;
    // at sub-pixel sigma (0.25px) the blur is skipped and this 0.5px AA
    // is what matches the original's look (Skia's 0.25px blur is negligibly
    // soft — essentially just AA).
    float strokeHalf = uHighlightStrokeWidth * 0.5;
    float strokeAA = 1.0 - smoothstep(strokeHalf - 0.5, strokeHalf, abs(sd));

    gl_FragColor = vec4(0.0, 0.0, 0.0, strokeAA * edgeAA);
}
`;
var HIGHLIGHT_COMPOSITE_FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uCanvasSize;
uniform vec2  uOffset;
uniform vec2  uSize;
uniform vec4  uCornerRadii;
uniform sampler2D uBlurredMask;   // the 2-pass-blurred stroke mask FBO
uniform vec2  uMaskTexSize;       // size of the mask FBO (= canvas size)
uniform vec4  uHighlightColor;    // rgb + 1.0
uniform float uHighlightAngle;
uniform float uHighlightFalloff;
uniform float uHighlightAlpha;
uniform float uHighlightMode;     // 0=Default, 1=Ambient, 2=Plain
uniform vec2  uOriginalSize;
uniform float uOriginalCornerRadius;
uniform vec2  uLayerScale;
uniform float uElementRotation;
// uCornerStyle, uUseContinuousSdf, uContinuousSdf, uContinuousSdfTexSize,
// uContinuousSdfElementSize are declared in SDF_GLSL (do NOT redeclare here).

${SDF_GLSL}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);

    // Sample the blurred stroke mask at this pixel. The mask FBO covers the
    // full canvas (same size), so UV = gl_FragCoord / maskTexSize.
    // Mask FBO is Y-down (top-left origin, like our scene FBOs), so flip Y
    // to match the screenCoord convention.
    vec2 maskUv = vec2(gl_FragCoord.x / uMaskTexSize.x, gl_FragCoord.y / uMaskTexSize.y);
    float mask = texture2D(uBlurredMask, maskUv).a;
    if (mask < 0.001) discard;

    // Compute intensity from the SDF gradient (AGSL DefaultHighlightShaderString).
    vec2 elementCenter = uOffset + uSize * 0.5;
    vec2 centeredScreen = screenCoord - elementCenter;
    vec2 layerScale = max(uLayerScale, vec2(1e-4));
    vec2 centeredOrig = centeredScreen / layerScale;
    vec2 centeredOrigRot = rotateBy(centeredOrig, -uElementRotation);
    vec2 origHalfSize = uOriginalSize * 0.5;
    float origRadius = uOriginalCornerRadius;

    // Faithful clip-after-blur: the original does clipOutline → stroke(blur),
    // but Skia applies clip at the canvas level AFTER the BlurMaskFilter
    // spreads alpha. So alpha that blurred OUTSIDE the shape is clipped away.
    // Our stroke shader clips before blur (discard sd>0), then blur spreads
    // alpha back outside — we must clip AGAIN here to match. Without this,
    // the highlight "leaks" outside the shape, making it brighter than the
    // original (which has zero contribution outside the clip region).
    float sd = sdShape(centeredOrigRot, origHalfSize, origRadius);
    float clipAA;
    if (uUseContinuousSdf > 0.5) {
        clipAA = sampleClipMask(centeredOrigRot, origHalfSize, origRadius);
    } else {
        clipAA = 1.0 - smoothstep(-0.5, 0.5, sd);
    }
    mask *= clipAA;
    if (mask < 0.001) discard;

    // Compute d (with sign) for Default + Ambient modes — needed for
    // Ambient's step(0,d) half-black-half-white split.
    float d = 0.0;  // signed dot(grad, normal) — 0 for Plain mode
    float intensity;
    if (uHighlightMode < 1.5) {
        // Default + Ambient use the SDF gradient · normal.
        float gradRadius = min(origRadius * 1.5, min(origHalfSize.x, origHalfSize.y));
        vec2 grad = gradSdRoundedRect(centeredOrigRot, origHalfSize, gradRadius);
        vec2 normal = vec2(cos(uHighlightAngle), sin(uHighlightAngle));
        d = dot(grad, normal);
        intensity = pow(abs(d), uHighlightFalloff);
    } else {
        // Plain — no directional intensity (even stroke).
        intensity = 1.0;
    }

    float a = mask * uHighlightAlpha;

    if (uHighlightMode < 0.5) {
        // Default — Plus blend. Output premultiplied rgb (alpha=1 so blendFunc
        // (ONE, ONE) adds rgb directly).
        vec3 c = uHighlightColor.rgb * intensity * a;
        gl_FragColor = vec4(c, 1.0);
    } else if (uHighlightMode < 1.5) {
        // Ambient — PREMULTIPLIED SrcOver blend (renderer uses ONE, ONE_MINUS_SRC_ALPHA).
        // Faithful to AmbientHighlightShaderString:
        //   float t = step(0.0, d);  ← half-black-half-white split
        // Bright side (d>=0): t=1 → white highlight. Dark side (d<0): t=0 →
        // black overlay that reduces scene brightness via premultiplied SrcOver → 3D sphere.
        // Output is premultiplied: vec4(color.rgb * t * i, i).
        // IMPORTANT: paint.color = White(0.38) is overridden by the shader.
        // The 0.38 does NOT scale the output; layer alpha (Highlight.alpha) is the
        // only modulation. For Ambient highlight, alpha = 1.0 (not 0.38).
        float t = step(0.0, d);
        float i = intensity * a;
        gl_FragColor = vec4(uHighlightColor.rgb * t * i, i);
    } else {
        // Plain — Plus blend, no intensity.
        vec3 c = uHighlightColor.rgb * a;
        gl_FragColor = vec4(c, 1.0);
    }
}
`;
var STROKE_MASK_COMPOSITE_FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uCanvasSize;
uniform vec2  uOffset;
uniform vec2  uSize;
uniform vec4  uCornerRadii;
uniform sampler2D uStrokeMask;
uniform vec2  uMaskOffset;
uniform vec2  uMaskSize;
uniform vec4  uHighlightColor;
uniform float uHighlightAngle;
uniform float uHighlightFalloff;
uniform float uHighlightAlpha;
uniform float uHighlightMode;
uniform vec2  uOriginalSize;
uniform float uOriginalCornerRadius;
uniform vec2  uLayerScale;
uniform float uElementRotation;

${SDF_GLSL}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);

    // Map screen coord → element-local ORIGINAL space (un-scale, un-rotate).
    // The stroke mask is drawn in original space (origSizeX × origSizeY + margin).
    // elementCenter is the same in scaled and original space (scaling is around center).
    vec2 elementCenter = uOffset + uSize * 0.5;
    vec2 centeredScreen = screenCoord - elementCenter;
    vec2 layerScale = max(uLayerScale, vec2(1e-4));
    vec2 centeredOrig = centeredScreen / layerScale;
    vec2 centeredOrigRot = rotateBy(centeredOrig, -uElementRotation);

    // Mask UV: map original-space coord → mask texture UV.
    // The mask was drawn with translate(margin, margin), so mask (0,0) =
    // element-local (-margin). Element-local coord 0..origSize maps to
    // mask UV (0+margin)/maskSize .. (origSize+margin)/maskSize.
    // uMaskOffset = margin (scalar, passed as vec2 for convenience).
    // uMaskSize = (origSize + 2*margin).
    vec2 origHalfSize = uOriginalSize * 0.5;
    vec2 maskTexCoord = centeredOrigRot + origHalfSize;  // 0..origSize (element-local)
    vec2 maskUv = (maskTexCoord + uMaskOffset) / uMaskSize;
    if (maskUv.x < 0.0 || maskUv.x > 1.0 || maskUv.y < 0.0 || maskUv.y > 1.0) discard;
    float mask = texture2D(uStrokeMask, maskUv).a;
    if (mask < 0.001) discard;

    float origRadius = uOriginalCornerRadius;

    // Compute d (with sign) for Default + Ambient modes — needed for
    // Ambient's step(0,d) half-black-half-white split.
    float d = 0.0;  // signed dot(grad, normal) — 0 for Plain mode
    float intensity;
    if (uHighlightMode < 1.5) {
        float gradRadius = min(origRadius * 1.5, min(origHalfSize.x, origHalfSize.y));
        vec2 grad = gradSdRoundedRect(centeredOrigRot, origHalfSize, gradRadius);
        vec2 normal = vec2(cos(uHighlightAngle), sin(uHighlightAngle));
        d = dot(grad, normal);
        intensity = pow(abs(d), uHighlightFalloff);
    } else {
        intensity = 1.0;
    }

    float a = mask * uHighlightAlpha;
    if (uHighlightMode < 0.5) {
        gl_FragColor = vec4(uHighlightColor.rgb * intensity * a, 1.0);
    } else if (uHighlightMode < 1.5) {
        // Ambient — premultiplied SrcOver (renderer uses ONE, ONE_MINUS_SRC_ALPHA).
        // Faithful to AmbientHighlightShaderString:
        //   float t = step(0.0, d);  ← bright/dark split
        // Bright side: t=1 → white highlight. Dark side: t=0 → dims scene.
        // Output is premultiplied: vec4(color.rgb * t * i, i).
        // paint.color(0.38) is overridden by shader; alpha should be 1.0 not 0.38.
        float t = step(0.0, d);
        float i = intensity * a;
        gl_FragColor = vec4(uHighlightColor.rgb * t * i, i);
    } else {
        gl_FragColor = vec4(uHighlightColor.rgb * a, 1.0);
    }
}
`;
// src/cdn/core/shaders/inner-shadow.ts
var INNER_SHADOW_MASK_COMPOSITE_FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uCanvasSize;
uniform vec2  uOffset;           // element top-left in canvas px (top-left origin) — SCALED rect
uniform vec2  uSize;             // element size in canvas px — SCALED
uniform vec4  uCornerRadii;      // (topLeft, topRight, bottomRight, bottomLeft) — SCALED
uniform sampler2D uInnerShadowMask; // Canvas2D-generated blurred ring mask
uniform vec2  uMaskOffset;       // margin in device px (for UV mapping: element-local → mask UV)
uniform vec2  uMaskSize;         // total mask size in device px (w+2*margin, h+2*margin)
uniform vec3  uInnerShadowColor; // shadow color RGB
uniform float uInnerShadowAlpha; // shadow alpha
// --- ORIGINAL-SPACE SDF clip (faithful to graphicsLayer { scaleX, scaleY }) ---
uniform vec2  uOriginalSize;        // element size in px (ORIGINAL, unscaled)
uniform float uOriginalCornerRadius; // corner radius in px (ORIGINAL, unscaled)
uniform vec2  uLayerScale;          // (scaleX, scaleY) from graphicsLayer
uniform float uElementRotation;     // rotation in radians (graphicsLayer rotationZ)

${SDF_GLSL}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);

    // Map screen coord → element-local ORIGINAL space (un-scale, un-rotate).
    // The inner shadow mask is drawn in original space (origSize + margin).
    // elementCenter is the same in scaled and original space (scaling is
    // around center).
    vec2 elementCenter = uOffset + uSize * 0.5;
    vec2 centeredScreen = screenCoord - elementCenter;
    vec2 layerScale = max(uLayerScale, vec2(1e-4));
    vec2 centeredOrig = centeredScreen / layerScale;
    vec2 centeredOrigRot = rotateBy(centeredOrig, -uElementRotation);

    // SDF for shape clip — faithful to InnerShadowModifier.kt's final
    // clipOutline call before drawLayer. The original uses Skia's
    // geometric clip with smooth AA (sub-pixel transition).
    // We replicate with smoothstep — NO hard discard.
    vec2 origHalfSize = uOriginalSize * 0.5;
    float sd = sdShape(centeredOrigRot, origHalfSize, uOriginalCornerRadius);

    // Smooth clipAlpha: 1.0 fully inside (sd ≤ 0), smoothly fading
    // across the boundary (sd 0→1.5), 0.0 outside (sd ≥ 1.5).
    // The 1.5px transition width matches Skia's clipOutline AA behavior
    // — pixels at the exact boundary (sd=0) retain FULL intensity, with
    // a gentle fade that removes outward blur leakage smoothly.
    // This is NOT a hard discard — it's a smooth clip that matches the
    // original's geometric clipOutline exactly.
    float clipAlpha = 1.0 - smoothstep(0.0, 1.5, sd);

    // Skip truly invisible pixels for performance (not a visual clip)
    if (clipAlpha < 0.004) discard;

    // Map to mask UV: original-space coord → mask texture UV.
    vec2 maskTexCoord = centeredOrigRot + origHalfSize;  // 0..origSize (element-local)
    vec2 maskUv = (maskTexCoord + uMaskOffset) / uMaskSize;

    // Sample the mask texture. CLAMP_TO_EDGE wrapping handles UV values
    // slightly outside (0..1) gracefully — returns transparent at edges.
    float mask = texture2D(uInnerShadowMask, maskUv).a;

    // Skip truly invisible pixels for performance (not a visual clip)
    // Threshold is very low to avoid cutting off faint but visible shadow edges.
    if (mask < 0.003) discard;

    // Premultiplied SrcOver composite: shadowColor × mask × shadowAlpha × clipAlpha.
    // clipAlpha provides smooth shape-boundary transition (faithful to original's
    // clipOutline AA). Output is premultiplied (rgb = color * alpha).
    // Renderer uses gl.blendFunc(ONE, ONE_MINUS_SRC_ALPHA) — premultiplied SrcOver.
    float a = mask * uInnerShadowAlpha * clipAlpha;
    gl_FragColor = vec4(uInnerShadowColor * a, a);
}
`;
// src/cdn/core/shaders/scene-bg.ts
var VERTEX_SHADER = `
attribute vec2 aPos;
void main() {
    gl_Position = vec4(aPos, 0.0, 1.0);
}
`;
var WALLPAPER_FRAGMENT_SHADER = `
precision highp float;

uniform sampler2D uBackdrop;
uniform vec2 uCanvasSize;
uniform vec2 uWallpaperSize;

${COVER_GLSL}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    vec2 uv = coverUv(screenCoord);
    gl_FragColor = texture2D(uBackdrop, uv);
}
`;
var COPY_FRAGMENT_SHADER = `
precision highp float;

uniform sampler2D uTexture;
uniform vec2 uCanvasSize;

void main() {
    vec2 uv = vec2(gl_FragCoord.x / uCanvasSize.x, gl_FragCoord.y / uCanvasSize.y);
    gl_FragColor = texture2D(uTexture, uv);
}
`;
var SOLID_FILL_FRAGMENT_SHADER = `
precision highp float;

uniform vec4 uColor;

void main() {
    gl_FragColor = uColor;
}
`;
var EL_FBO_CROP_FRAGMENT_SHADER = `
precision highp float;

uniform sampler2D uTexture;
uniform vec2 uSrcOffset;   // region top-left in source texture (top-left origin, device px)
uniform vec2 uSrcSize;     // fullscreen source texture size (device px)
uniform vec2 uDstSize;     // destination (small) FBO size = region size (device px)

void main() {
    vec2 localTopLeft = vec2(gl_FragCoord.x, uDstSize.y - gl_FragCoord.y);
    vec2 srcTopLeft = uSrcOffset + localTopLeft;
    vec2 uv = vec2(srcTopLeft.x / uSrcSize.x, 1.0 - srcTopLeft.y / uSrcSize.y);
    gl_FragColor = texture2D(uTexture, uv);
}
`;
var EL_FBO_COMPOSITE_FRAGMENT_SHADER = `
precision highp float;

uniform sampler2D uTexture;
uniform vec2 uCanvasSize;     // bound FBO size in device px
uniform vec2 uElementCenter;  // element center (top-left origin, device px)
uniform vec2 uElementSize;    // SCALED element size (device px)
uniform float uRotation;      // element rotation in radians
uniform vec2 uSrcSize;        // elFbo texture size (baseline, device px)

// rotateBy — standard 2D rotation (counter-clockwise, math convention).
// Used consistently in Y-down (top-left origin) space — the Y-flip cancels
// because both element shader and composite use the same convention.
vec2 rotateBy(vec2 v, float angle) {
    float c = cos(angle);
    float s = sin(angle);
    return vec2(v.x * c - v.y * s, v.x * s + v.y * c);
}

void main() {
    // gl_FragCoord: bottom-left origin. Convert to top-left origin (Y-down).
    vec2 fragTopLeft = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    // Offset from element center (Y-down, screen px)
    vec2 centered = fragTopLeft - uElementCenter;
    // Un-rotate: screen → local (undo the element's rotation).
    // When rot≈0 (common case — all non-GP elements), skip rotateBy entirely
    // (4 mul + cos/sin per fragment saved). This makes the composite shader
    // as cheap as the old 1:1 blit for the vast majority of elements.
    vec2 localCentered;
    if (abs(uRotation) > 0.001) {
        localCentered = rotateBy(centered, -uRotation);
    } else {
        localCentered = centered;
    }
    // Un-scale: screen px → elFbo px (baseline). Ratio = srcSize / elementSize.
    vec2 srcCentered = localCentered * uSrcSize / uElementSize;
    // Bounds check: discard if outside elFbo
    vec2 halfSrc = uSrcSize * 0.5;
    if (abs(srcCentered.x) > halfSrc.x || abs(srcCentered.y) > halfSrc.y) discard;
    // Map to UV. elFbo texture: UV (0,0) = gl_FragCoord (0,0) = bottom-left.
    // srcCentered is Y-down (top-left origin). Flip Y for texture UV.
    vec2 uv = vec2(
        (srcCentered.x + halfSrc.x) / uSrcSize.x,
        (halfSrc.y - srcCentered.y) / uSrcSize.y
    );
    gl_FragColor = texture2D(uTexture, uv);
}
`;
var COLOR_CONTROLS_FRAGMENT_SHADER = `
precision highp float;

uniform sampler2D uTexture;
uniform vec2 uTexSize;
uniform float uBrightness;
uniform float uContrast;
uniform float uSaturation;

void main() {
    vec2 uv = vec2(gl_FragCoord.x / uTexSize.x, gl_FragCoord.y / uTexSize.y);
    vec4 c = texture2D(uTexture, uv);
    float invSat = 1.0 - uSaturation;
    float r = 0.213 * invSat;
    float g = 0.715 * invSat;
    float b = 0.072 * invSat;
    float t = (0.5 - uContrast * 0.5 + uBrightness);
    float cs = uContrast * uSaturation;
    float cr = uContrast * r;
    float cg = uContrast * g;
    float cb = uContrast * b;
    vec3 outc;
    outc.r = (cr + cs) * c.r + cg * c.g + cb * c.b + t;
    outc.g = cr * c.r + (cg + cs) * c.g + cb * c.b + t;
    outc.b = cr * c.r + cg * c.g + (cb + cs) * c.b + t;
    gl_FragColor = vec4(outc, c.a);
}
`;
var SCENE_TINT_FRAGMENT_SHADER = `
precision highp float;

uniform sampler2D uTexture;
uniform vec2 uCanvasSize;
uniform vec3 uTintColor;   // rgb 0..1 (accentColor)

// ColorFilter.tint(color, blendMode = BlendMode.SrcIn):
//   result.rgb = src.rgb (the tint color)
//   result.a   = dst.a * src.a
// SrcIn replaces the destination's RGB with the tint color while
// preserving its alpha — opaque content becomes solid tint, transparent
// areas stay transparent. This matches Compose's ColorFilter.tint default.
void main() {
    vec2 uv = vec2(gl_FragCoord.x / uCanvasSize.x, gl_FragCoord.y / uCanvasSize.y);
    vec4 src = texture2D(uTexture, uv);
    gl_FragColor = vec4(uTintColor, src.a);
}
`;
// src/cdn/core/shaders/scene-fg.ts
var FOREGROUND_FRAGMENT_SHADER = `
precision highp float;

uniform sampler2D uTexture;
uniform vec2 uCanvasSize;
uniform vec2 uOffset;   // foreground texture top-left in canvas px (top-left origin) — SCALED rect
uniform vec2 uSize;     // foreground texture size in canvas px — SCALED
uniform vec4 uCornerRadii;  // capsule radii (topLeft, topRight, bottomRight, bottomLeft) in px — SCALED
uniform float uAlpha;   // global alpha multiplier (used for press fade)
// --- ORIGINAL-SPACE SDF clip (faithful to graphicsLayer { scaleX, scaleY }) ---
// The original wraps everything (text included) in a graphicsLayer clipped to
// the capsule shape, THEN scales the layer. So the clip shape is the ORIGINAL
// capsule, not the stretched one. We compute the clip SDF in original space so
// a stretched button keeps correct capsule clipping (no corner bleed). The
// texture UV still uses the scaled rect (uOffset/uSize) since the foreground
// texture is rendered at the element's scaled on-screen size.
uniform vec2  uOriginalSize;        // element size in px (ORIGINAL, unscaled)
uniform float uOriginalCornerRadius; // corner radius in px (ORIGINAL, unscaled)
uniform vec2  uLayerScale;          // (scaleX, scaleY) from graphicsLayer

${SDF_GLSL}

void main() {
    // gl_FragCoord is bottom-left origin in WebGL framebuffer space.
    // Flip Y to get top-left origin (matching CSS / 2D canvas convention).
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    vec2 localCoord = screenCoord - uOffset;
    // Scissor to the (scaled) foreground rectangle.
    if (localCoord.x < 0.0 || localCoord.x > uSize.x ||
        localCoord.y < 0.0 || localCoord.y > uSize.y) {
        discard;
    }

    // --- Capsule clip in ORIGINAL space (faithful to graphicsLayer clip) ---
    // elementCenter is the SAME for scaled and original rects (scaling is
    // around the center). Map screen coord → original space for the SDF so
    // the clip shape is the original capsule, not the stretched one.
    vec2 elementCenter = uOffset + uSize * 0.5;
    vec2 centeredScreen = screenCoord - elementCenter;
    vec2 layerScale = max(uLayerScale, vec2(1e-4));
    vec2 centeredOrig = centeredScreen / layerScale;
    vec2 origHalfSize = uOriginalSize * 0.5;
    float clipAlpha;
    if (uUseContinuousSdf > 0.5) {
        float mask = sampleClipMask(centeredOrig, origHalfSize, uOriginalCornerRadius);
        if (mask < 0.01) discard;
        clipAlpha = mask;
    } else {
        float sdClip = sdClipShape(centeredOrig, origHalfSize, uOriginalCornerRadius);
        if (sdClip > 0.5) discard;
        clipAlpha = 1.0 - smoothstep(-0.5, 0.5, sdClip);
    }

    // The texture is uploaded from a 2D canvas with UNPACK_FLIP_Y_WEBGL=false,
    // so texture row 0 (= v=0) is the TOP row of the source canvas. Combined
    // with the Y flip above, uv.y=0 corresponds to the top of the button rect
    // (which is what we want — text drawn at the middle of the source canvas
    // appears at the middle of the button).
    //
    // The texture is uploaded with UNPACK_PREMULTIPLY_ALPHA_WEBGL=true, so
    // c is already in premultiplied form (c.rgb <= c.a). We scale both
    // rgb and a by uAlpha * clipAlpha and output premultiplied rgba, paired
    // with blendFunc(ONE, ONE_MINUS_SRC_ALPHA) at the draw site.
    vec2 uv = localCoord / uSize;
    vec4 c = texture2D(uTexture, uv);
    float a = c.a * uAlpha * clipAlpha;
    gl_FragColor = vec4(c.rgb * uAlpha * clipAlpha, a);
}
`;
var PLAIN_RECT_FRAGMENT_SHADER = `
precision highp float;

uniform vec2  uCanvasSize;
uniform vec2  uOffset;
uniform vec2  uSize;
uniform vec4  uCornerRadii;
uniform vec4  uColor;       // rgba (premultiplied not required; alpha used as-is)

${SDF_GLSL}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    vec2 localCoord = screenCoord - uOffset;
    vec2 halfSize = uSize * 0.5;
    vec2 centeredCoord = localCoord - halfSize;

    float radius = radiusAt(centeredCoord, uCornerRadii);
    float alpha;
    if (uUseContinuousSdf > 0.5) {
        float mask = sampleClipMask(centeredCoord, halfSize, radius);
        if (mask < 0.01) discard;
        alpha = mask;
    } else {
        float sdClip = sdClipShape(centeredCoord, halfSize, radius);
        if (sdClip > 0.5) discard;
        alpha = 1.0 - smoothstep(-0.5, 0.5, sdClip);
    }
    gl_FragColor = vec4(uColor.rgb, uColor.a * alpha);
}
`;
var PROGRESSIVE_BLUR_FRAGMENT_SHADER = `
precision highp float;

uniform sampler2D uBackdrop;
uniform vec2  uCanvasSize;
uniform vec2  uWallpaperSize;
uniform vec2  uOffset;          // band top-left in canvas px (top-left origin)
uniform vec2  uSize;            // band size in canvas px
uniform float uBlurRadius;      // px in canvas space
uniform vec4  uTintColor;       // rgba
uniform float uTintIntensity;   // 0..1

${COVER_GLSL}

// 9-tap poisson disc — offsets are inlined because GLSL ES 1.00 (WebGL 1)
// does not support array constructors or const-array initializers.
// The offsets are normalized (unit disc), multiplied by step (radius in UV).
vec4 sampleBackdrop(vec2 canvasPx, float radius) {
    vec2 uvScale = canvasPxToUvScale();
    vec2 uv = coverUv(canvasPx);
    vec2 st = radius * uvScale;
    vec4 sum = vec4(0.0);
    sum += texture2D(uBackdrop, uv + vec2( 0.0000,  0.0000) * st);
    sum += texture2D(uBackdrop, uv + vec2( 0.5000,  0.0000) * st);
    sum += texture2D(uBackdrop, uv + vec2(-0.5000,  0.0000) * st);
    sum += texture2D(uBackdrop, uv + vec2( 0.0000,  0.5000) * st);
    sum += texture2D(uBackdrop, uv + vec2( 0.0000, -0.5000) * st);
    sum += texture2D(uBackdrop, uv + vec2( 0.3536,  0.3536) * st);
    sum += texture2D(uBackdrop, uv + vec2(-0.3536,  0.3536) * st);
    sum += texture2D(uBackdrop, uv + vec2( 0.3536, -0.3536) * st);
    sum += texture2D(uBackdrop, uv + vec2(-0.3536, -0.3536) * st);
    return sum / 9.0;
}

void main() {
    vec2 screenCoord = vec2(gl_FragCoord.x, uCanvasSize.y - gl_FragCoord.y);
    vec2 localCoord = screenCoord - uOffset;
    // Outside the band — nothing to draw.
    if (localCoord.x < 0.0 || localCoord.x > uSize.x ||
        localCoord.y < 0.0 || localCoord.y > uSize.y) {
        discard;
    }

    // Alpha mask: opaque at top (coord.y = size.y, i.e. BOTTOM in top-left
    // origin = size.y in AGSL coord), transparent at bottom. Matches the
    // Kotlin smoothstep(size.y, size.y * 0.5, coord.y).
    float a = smoothstep(uSize.y, uSize.y * 0.5, localCoord.y);

    // Sample the (cover-fit) backdrop at the canvas pixel, blurred.
    vec4 blurred = sampleBackdrop(screenCoord, uBlurRadius);

    // Faithful to AlphaMask shader: mix(content * blurAlpha, tint * tintAlpha, tintIntensity)
    // This is PREMULTIPLIED (rgb already scaled by alpha). The renderer uses
    // premultiplied alpha blending for the progressive blur pass, so we output
    // premultiplied rgb with the mask alpha.
    vec3 premulRgb = mix(blurred.rgb * a, uTintColor.rgb * a, uTintIntensity);
    gl_FragColor = vec4(premulRgb, a);
}
`;
// src/cdn/core/shaders/separable-blur.ts
function generateGaussianKernel1D(tapCount) {
  if (tapCount <= 1)
    return [{ offset: 0, weight: 1 }];
  const taps = [];
  const half = Math.floor(tapCount / 2);
  const maxOffset = 3;
  let totalW = 0;
  for (let i = 0;i < tapCount; i++) {
    const t = tapCount % 2 === 1 ? i - half : i - half + 0.5;
    const offset = t / half * maxOffset;
    const w = Math.exp(-0.5 * offset * offset);
    taps.push({ offset, weight: w });
    totalW += w;
  }
  if (totalW > 0) {
    for (const t of taps)
      t.weight /= totalW;
  }
  return taps;
}
function generateSeparableBlurShader(tapCount, direction) {
  const kernel = generateGaussianKernel1D(tapCount);
  const isH = direction === "horizontal";
  const dirVec = isH ? "vec2(1.0, 0.0)" : "vec2(0.0, 1.0)";
  let sampleCode = "";
  if (kernel.length === 1) {
    sampleCode = `    gl_FragColor = texture2D(uTexture, uv);
`;
  } else {
    sampleCode = `    vec3 rgbSum = vec3(0.0);
    float rgbW = 0.0;
`;
    for (const t of kernel) {
      const off = t.offset.toFixed(6);
      const w = t.weight.toFixed(8);
      sampleCode += `    { vec4 s = texture2D(uTexture, uv + ${dirVec} * ${off} * pxToUv); float aw = s.a * ${w}; rgbSum += s.rgb * aw; rgbW += aw; }
`;
    }
    sampleCode += `    float origA = texture2D(uTexture, uv).a;
    gl_FragColor = vec4(rgbW > 0.001 ? rgbSum / rgbW : vec3(0.0), origA);
`;
  }
  return `
precision highp float;

uniform sampler2D uTexture;
uniform vec2 uTexSize;
uniform float uRadius;

void main() {
    vec2 uv = vec2(gl_FragCoord.x / uTexSize.x, gl_FragCoord.y / uTexSize.y);
    if (uRadius < 0.5) {
        gl_FragColor = texture2D(uTexture, uv);
        return;
    }
    vec2 pxToUv = vec2(uRadius / uTexSize.x, uRadius / uTexSize.y);
${sampleCode}}
`;
}
function computeBlur1DTapCount(blurRadiusPx) {
  if (blurRadiusPx < 0.5)
    return 1;
  const sigma = blurRadiusPx * 0.57735 + 0.5;
  const n = 2 * Math.ceil(3 * sigma) + 1;
  return Math.min(33, Math.max(1, n));
}
function generateHighlightBlurKernel1D(tapCount) {
  if (tapCount <= 1)
    return [{ offset: 0, weight: 1 }];
  const taps = [];
  const half = Math.floor(tapCount / 2);
  let totalW = 0;
  for (let i = 0;i < tapCount; i++) {
    const offset = i - half;
    const w = Math.exp(-0.5 * offset * offset);
    taps.push({ offset, weight: w });
    totalW += w;
  }
  if (totalW > 0) {
    for (const t of taps)
      t.weight /= totalW;
  }
  return taps;
}
function generateHighlightBlurShader(tapCount, direction) {
  const kernel = generateHighlightBlurKernel1D(tapCount);
  const isH = direction === "horizontal";
  const dirVec = isH ? "vec2(1.0, 0.0)" : "vec2(0.0, 1.0)";
  let sampleCode = "";
  if (kernel.length === 1) {
    sampleCode = `    gl_FragColor = texture2D(uTexture, uv);
`;
  } else {
    sampleCode = `    float aSum = 0.0;
`;
    for (const t of kernel) {
      const off = t.offset.toFixed(6);
      const w = t.weight.toFixed(8);
      sampleCode += `    aSum += texture2D(uTexture, uv + ${dirVec} * ${off} * pxToUv).a * ${w};
`;
    }
    sampleCode += `    gl_FragColor = vec4(0.0, 0.0, 0.0, aSum);
`;
  }
  return `
precision highp float;

uniform sampler2D uTexture;
uniform vec2 uTexSize;
uniform float uRadius;  // Gaussian sigma in pixels (Android BlurMaskFilter semantics)

void main() {
    vec2 uv = vec2(gl_FragCoord.x / uTexSize.x, gl_FragCoord.y / uTexSize.y);
    if (uRadius < 0.01) {
        gl_FragColor = texture2D(uTexture, uv);
        return;
    }
    // pxToUv converts a pixel offset to a UV offset. offset (in σ units) *
    // sigma_px = pixel offset; / uTexSize = UV offset.
    vec2 pxToUv = vec2(uRadius / uTexSize.x, uRadius / uTexSize.y);
${sampleCode}}
`;
}
function computeHighlightBlurTapCount(sigmaPx) {
  if (sigmaPx < 0.01)
    return 1;
  const n = 2 * Math.ceil(3 * sigmaPx) + 1;
  return Math.min(33, Math.max(3, n));
}
// src/cdn/core/shaders/kawase-blur.ts
var MAX_KAWASE_ITERS = 8;
var MIN_KAWASE_ITERS = 2;
function kawaseIterationsForRadius(radius, quality = 1) {
  let base;
  if (radius < 1.5)
    base = 2;
  else if (radius < 3)
    base = 3;
  else if (radius < 6)
    base = 4;
  else if (radius < 12)
    base = 6;
  else
    base = 8;
  const scaled = Math.round(base * quality);
  return Math.max(MIN_KAWASE_ITERS, Math.min(MAX_KAWASE_ITERS, scaled));
}
function generateKawaseBlurShader() {
  return `precision highp float;
uniform sampler2D uTexture;
uniform vec2 uTexSize;
uniform float uRadius;      // target Gaussian σ (px) — Kawase accumulates to match
uniform float uIteration;   // current iteration index, 0-based
uniform float uTotalIters;  // total iteration count N
void main() {
    vec2 uv = vec2(gl_FragCoord.x / uTexSize.x, gl_FragCoord.y / uTexSize.y);
    vec2 pxToUv = vec2(1.0 / uTexSize.x, 1.0 / uTexSize.y);
    // d_max = radius × √(6N / ((N+1)(2N+1))) — variance-matched to Gaussian σ.
    // d_i = d_max × (i+1)/N.
    float N = uTotalIters;
    float dMax = uRadius * sqrt(6.0 * N / ((N + 1.0) * (2.0 * N + 1.0)));
    float d = dMax * (uIteration + 1.0) / N;
    vec2 off = vec2(d, d) * pxToUv;
    // 4 diagonal taps (Kawase original): equal weight 0.25 each.
    vec4 s1 = texture2D(uTexture, uv + off);
    vec4 s2 = texture2D(uTexture, uv - off);
    vec4 s3 = texture2D(uTexture, uv + vec2(off.x, -off.y));
    vec4 s4 = texture2D(uTexture, uv + vec2(-off.x, off.y));
    // Premul-aware: RGB weighted by sample alpha, alpha = center.
    float aw1 = s1.a, aw2 = s2.a, aw3 = s3.a, aw4 = s4.a;
    float awSum = aw1 + aw2 + aw3 + aw4;
    vec3 rgb = awSum > 0.001 ? (s1.rgb * aw1 + s2.rgb * aw2 + s3.rgb * aw3 + s4.rgb * aw4) / awSum : vec3(0.0);
    float origA = texture2D(uTexture, uv).a;
    gl_FragColor = vec4(rgb, origA);
}
`;
}
// src/cdn/core/renderer/gl-utils.ts
function compileShader(gl, type, src) {
  const sh = gl.createShader(type);
  gl.shaderSource(sh, src);
  gl.compileShader(sh);
  if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) {
    const log = gl.getShaderInfoLog(sh);
    gl.deleteShader(sh);
    throw new Error("Shader compile error: " + log);
  }
  return sh;
}
function createProgram(gl, vsSrc, fsSrc) {
  const vs = compileShader(gl, gl.VERTEX_SHADER, vsSrc);
  const fs = compileShader(gl, gl.FRAGMENT_SHADER, fsSrc);
  const p = gl.createProgram();
  gl.attachShader(p, vs);
  gl.attachShader(p, fs);
  gl.linkProgram(p);
  if (!gl.getProgramParameter(p, gl.LINK_STATUS)) {
    const log = gl.getProgramInfoLog(p);
    gl.deleteProgram(p);
    throw new Error("Program link error: " + log);
  }
  return p;
}
function wrapText(ctx, text, maxW) {
  const tokens = text.split(/\s+/).filter((t) => t.length > 0);
  const lines = [];
  let cur = "";
  for (const token of tokens) {
    const test = cur ? cur + " " + token : token;
    if (ctx.measureText(test).width <= maxW) {
      cur = test;
      continue;
    }
    if (cur) {
      lines.push(cur);
      cur = "";
    }
    for (const ch of token) {
      const t = cur + ch;
      if (ctx.measureText(t).width <= maxW || !cur) {
        cur = t;
      } else {
        lines.push(cur);
        cur = ch;
      }
    }
  }
  if (cur)
    lines.push(cur);
  return lines;
}
function easeIn(t) {
  if (t <= 0)
    return 0;
  if (t >= 1)
    return 1;
  const x1 = 0.42, y1 = 0, x2 = 1, y2 = 1;
  let s = t;
  for (let i = 0;i < 8; i++) {
    const xs = 3 * (1 - s) * (1 - s) * s * x1 + 3 * (1 - s) * s * s * x2 + s * s * s;
    const dxs = 3 * (1 - s) * (1 - s) * x1 + 6 * (1 - s) * s * (x2 - x1) + 3 * s * s * (1 - x2);
    if (Math.abs(xs - t) < 0.001)
      break;
    if (Math.abs(dxs) < 0.000001)
      break;
    s -= (xs - t) / dxs;
    s = Math.max(0, Math.min(1, s));
  }
  return 3 * (1 - s) * (1 - s) * s * y1 + 3 * (1 - s) * s * s * y2 + s * s * s;
}

// src/cdn/core/renderer/perf-monitor.ts
class PerfMonitor {
  enabled = false;
  gl = null;
  HISTORY_SIZE = 240;
  frameTimes = new Float32Array(this.HISTORY_SIZE);
  frameTimeIdx = 0;
  frameTimeCount = 0;
  prevFrameEndTime = 0;
  totalFrames = 0;
  jank16Count = 0;
  jank33Count = 0;
  drawCalls = 0;
  glassElements = 0;
  perElementFboCount = 0;
  pingPongCount = 0;
  nonGlassElements = 0;
  blurPasses = 0;
  dirtyElements = 0;
  totalElements = 0;
  cachedElements = 0;
  lastDrawCalls = 0;
  lastGlassElements = 0;
  lastPerElementFboCount = 0;
  lastPingPongCount = 0;
  lastNonGlassElements = 0;
  lastBlurPasses = 0;
  lastDirtyElements = 0;
  lastTotalElements = 0;
  lastCachedElements = 0;
  lastFrameTimeMs = 0;
  gpuInfoCollected = false;
  gpuVendor = "";
  gpuRenderer = "";
  maxTextureSize = 0;
  extensionCount = 0;
  isSoftwareRenderer = false;
  canvasCssW = 0;
  canvasCssH = 0;
  canvasDevW = 0;
  canvasDevH = 0;
  dpr = 0;
  deviceDpr = 0;
  attachGl(gl) {
    this.gl = gl;
  }
  collectGpuInfo() {
    if (!this.gl || this.gpuInfoCollected)
      return;
    this.gpuInfoCollected = true;
    const gl = this.gl;
    const dbgExt = gl.getExtension("WEBGL_debug_renderer_info");
    try {
      this.gpuVendor = dbgExt ? String(gl.getParameter(dbgExt.UNMASKED_VENDOR_WEBGL) || "") : String(gl.getParameter(gl.VENDOR) || "");
      this.gpuRenderer = dbgExt ? String(gl.getParameter(dbgExt.UNMASKED_RENDERER_WEBGL) || "") : String(gl.getParameter(gl.RENDERER) || "");
      this.maxTextureSize = Number(gl.getParameter(gl.MAX_TEXTURE_SIZE)) || 0;
      const exts = gl.getSupportedExtensions() || [];
      this.extensionCount = exts.length;
    } catch {}
  }
  frameStart() {
    if (!this.enabled)
      return;
    this.collectGpuInfo();
    this.drawCalls = 0;
    this.glassElements = 0;
    this.perElementFboCount = 0;
    this.pingPongCount = 0;
    this.nonGlassElements = 0;
    this.blurPasses = 0;
    this.dirtyElements = 0;
    this.totalElements = 0;
    this.cachedElements = 0;
  }
  frameEnd() {
    if (!this.enabled)
      return;
    const now = performance.now();
    const dt = this.prevFrameEndTime > 0 ? now - this.prevFrameEndTime : 0;
    this.prevFrameEndTime = now;
    if (dt > 0 && dt <= 500) {
      this.lastFrameTimeMs = dt;
      this.frameTimes[this.frameTimeIdx] = dt;
      this.frameTimeIdx = (this.frameTimeIdx + 1) % this.HISTORY_SIZE;
      if (this.frameTimeCount < this.HISTORY_SIZE)
        this.frameTimeCount++;
      if (dt > 16.67)
        this.jank16Count++;
      if (dt > 33.33)
        this.jank33Count++;
    }
    this.totalFrames++;
    this.lastDrawCalls = this.drawCalls;
    this.lastGlassElements = this.glassElements;
    this.lastPerElementFboCount = this.perElementFboCount;
    this.lastPingPongCount = this.pingPongCount;
    this.lastNonGlassElements = this.nonGlassElements;
    this.lastBlurPasses = this.blurPasses;
    this.lastDirtyElements = this.dirtyElements;
    this.lastTotalElements = this.totalElements;
    this.lastCachedElements = this.cachedElements;
  }
  incDrawCall(n = 1) {
    if (this.enabled)
      this.drawCalls += n;
  }
  incGlassElement() {
    if (this.enabled)
      this.glassElements++;
  }
  incPerElementFbo() {
    if (this.enabled)
      this.perElementFboCount++;
  }
  incPingPong() {
    if (this.enabled)
      this.pingPongCount++;
  }
  incNonGlass() {
    if (this.enabled)
      this.nonGlassElements++;
  }
  incBlurPass() {
    if (this.enabled)
      this.blurPasses++;
  }
  incDirty() {
    if (this.enabled)
      this.dirtyElements++;
  }
  incTotal() {
    if (this.enabled)
      this.totalElements++;
  }
  incCachedElement() {
    if (this.enabled)
      this.cachedElements++;
  }
  reset() {
    this.frameTimes.fill(0);
    this.frameTimeIdx = 0;
    this.frameTimeCount = 0;
    this.prevFrameEndTime = 0;
    this.totalFrames = 0;
    this.jank16Count = 0;
    this.jank33Count = 0;
    this.lastFrameTimeMs = 0;
    this.lastDrawCalls = 0;
    this.lastGlassElements = 0;
    this.lastPerElementFboCount = 0;
    this.lastPingPongCount = 0;
    this.lastSkipPingPongCount = 0;
    this.lastNonGlassElements = 0;
    this.lastBlurPasses = 0;
    this.lastDirtyElements = 0;
    this.lastTotalElements = 0;
    this.lastCachedElements = 0;
  }
  getSnapshot() {
    const history = [];
    if (this.frameTimeCount > 0) {
      if (this.frameTimeCount < this.HISTORY_SIZE) {
        for (let i = 0;i < this.frameTimeCount; i++)
          history.push(this.frameTimes[i]);
      } else {
        for (let i = 0;i < this.HISTORY_SIZE; i++) {
          history.push(this.frameTimes[(this.frameTimeIdx + i) % this.HISTORY_SIZE]);
        }
      }
    }
    let sum = 0, mn = Infinity, mx = 0;
    for (const v of history) {
      sum += v;
      if (v < mn)
        mn = v;
      if (v > mx)
        mx = v;
    }
    const n = history.length;
    const avg = n > 0 ? sum / n : 0;
    const last = this.lastFrameTimeMs;
    return {
      frameTimeMs: last,
      avgFrameTimeMs: avg,
      minFrameTimeMs: n > 0 ? mn : 0,
      maxFrameTimeMs: n > 0 ? mx : 0,
      fps: last > 0 ? 1000 / last : 0,
      avgFps: avg > 0 ? 1000 / avg : 0,
      jank16Count: this.jank16Count,
      jank33Count: this.jank33Count,
      totalFrames: this.totalFrames,
      drawCalls: this.lastDrawCalls,
      glassElements: this.lastGlassElements,
      perElementFboCount: this.lastPerElementFboCount,
      pingPongCount: this.lastPingPongCount,
      nonGlassElements: this.lastNonGlassElements,
      blurPasses: this.lastBlurPasses,
      dirtyElements: this.lastDirtyElements,
      totalElements: this.lastTotalElements,
      cachedElements: this.lastCachedElements,
      gpuVendor: this.gpuVendor,
      gpuRenderer: this.gpuRenderer,
      maxTextureSize: this.maxTextureSize,
      extensionCount: this.extensionCount,
      isSoftwareRenderer: this.isSoftwareRenderer,
      canvasCssW: this.canvasCssW,
      canvasCssH: this.canvasCssH,
      canvasDevW: this.canvasDevW,
      canvasDevH: this.canvasDevH,
      dpr: this.dpr,
      deviceDpr: this.deviceDpr,
      pixelsPerFrame: this.canvasDevW * this.canvasDevH,
      history,
      timestamp: performance.now()
    };
  }
}

// src/cdn/core/renderer/methods-fbo.ts
var fboMethods = {
  createFBO(w, h) {
    const gl = this.gl;
    const tex = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, tex);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, w, h, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    const fb = gl.createFramebuffer();
    gl.bindFramebuffer(gl.FRAMEBUFFER, fb);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, tex, 0);
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    return { fb, tex };
  },
  resizeFBOs(w, h, force = false) {
    if (!force && this.fboW === w && this.fboH === h && this.fboA && this.fboB)
      return;
    const gl = this.gl;
    if (this.fboA)
      gl.deleteFramebuffer(this.fboA);
    if (this.fboATex)
      gl.deleteTexture(this.fboATex);
    if (this.fboB)
      gl.deleteFramebuffer(this.fboB);
    if (this.fboBTex)
      gl.deleteTexture(this.fboBTex);
    const a = this.createFBO(w, h);
    const b = this.createFBO(w, h);
    this.fboA = a.fb;
    this.fboATex = a.tex;
    this.fboB = b.fb;
    this.fboBTex = b.tex;
    if (this.tabsBackdropFbo)
      gl.deleteFramebuffer(this.tabsBackdropFbo);
    if (this.tabsBackdropTex)
      gl.deleteTexture(this.tabsBackdropTex);
    const tb = this.createFBO(w, h);
    this.tabsBackdropFbo = tb.fb;
    this.tabsBackdropTex = tb.tex;
    this.tabsBackdropDirty = true;
    if (this.wallpaperBlurFbo)
      gl.deleteFramebuffer(this.wallpaperBlurFbo);
    if (this.wallpaperBlurTex)
      gl.deleteTexture(this.wallpaperBlurTex);
    if (this.blurFboA)
      gl.deleteFramebuffer(this.blurFboA);
    if (this.blurFboATex)
      gl.deleteTexture(this.blurFboATex);
    if (this.blurFboB)
      gl.deleteFramebuffer(this.blurFboB);
    if (this.blurFboBTex)
      gl.deleteTexture(this.blurFboBTex);
    if (this.dsBlurFboA)
      gl.deleteFramebuffer(this.dsBlurFboA);
    if (this.dsBlurFboATex)
      gl.deleteTexture(this.dsBlurFboATex);
    if (this.dsBlurFboB)
      gl.deleteFramebuffer(this.dsBlurFboB);
    if (this.dsBlurFboBTex)
      gl.deleteTexture(this.dsBlurFboBTex);
    for (const lvl of this.dsBlurLevels) {
      gl.deleteFramebuffer(lvl.fboA);
      gl.deleteTexture(lvl.texA);
      gl.deleteFramebuffer(lvl.fboB);
      gl.deleteTexture(lvl.texB);
    }
    this.dsBlurLevels = [];
    const rawDs = Math.max(1, this.blurDownsample);
    const ds = rawDs <= 1 ? 1 : Math.max(1, Math.min(rawDs * (this.dpr || 1), 64));
    this.effectiveBlurDownsample = ds;
    const ge = this.createFBO(w, h);
    const ba = this.createFBO(w, h);
    const bb = this.createFBO(w, h);
    this.wallpaperBlurFbo = ge.fb;
    this.wallpaperBlurTex = ge.tex;
    this.blurFboA = ba.fb;
    this.blurFboATex = ba.tex;
    this.blurFboB = bb.fb;
    this.blurFboBTex = bb.tex;
    const legacyW = Math.max(1, Math.floor(w / ds));
    const legacyH = Math.max(1, Math.floor(h / ds));
    const legacyA = this.createFBO(legacyW, legacyH);
    const legacyB = this.createFBO(legacyW, legacyH);
    this.dsBlurFboA = legacyA.fb;
    this.dsBlurFboATex = legacyA.tex;
    this.dsBlurFboB = legacyB.fb;
    this.dsBlurFboBTex = legacyB.tex;
    this.dsBlurFboW = legacyW;
    this.dsBlurFboH = legacyH;
    const levels = [];
    for (let d = 1;d <= ds; d *= 2)
      levels.push(d);
    for (const d of levels) {
      const lw = Math.max(1, Math.floor(w / d));
      const lh = Math.max(1, Math.floor(h / d));
      const la = this.createFBO(lw, lh);
      const lb = this.createFBO(lw, lh);
      this.dsBlurLevels.push({ ds: d, fboA: la.fb, texA: la.tex, fboB: lb.fb, texB: lb.tex, w: lw, h: lh });
    }
    if (this.highlightMaskFbo)
      gl.deleteFramebuffer(this.highlightMaskFbo);
    if (this.highlightMaskTex)
      gl.deleteTexture(this.highlightMaskTex);
    const hm = this.createFBO(w, h);
    this.highlightMaskFbo = hm.fb;
    this.highlightMaskTex = hm.tex;
    if (this.dialogBackdropFbo)
      gl.deleteFramebuffer(this.dialogBackdropFbo);
    if (this.dialogBackdropTex)
      gl.deleteTexture(this.dialogBackdropTex);
    const db = this.createFBO(w, h);
    this.dialogBackdropFbo = db.fb;
    this.dialogBackdropTex = db.tex;
    this.dialogBackdropKey = null;
    if (this.bgOnlyFbo)
      gl.deleteFramebuffer(this.bgOnlyFbo);
    if (this.bgOnlyTex)
      gl.deleteTexture(this.bgOnlyTex);
    const bg = this.createFBO(w, h);
    this.bgOnlyFbo = bg.fb;
    this.bgOnlyTex = bg.tex;
    const sizeChanged = this.fboW !== w || this.fboH !== h;
    this.fboW = w;
    this.fboH = h;
    if (sizeChanged)
      this.clearBackdropBlurCache();
  },
  clearBackdropBlurCache() {
    const gl = this.gl;
    for (const entry of this.backdropBlurCache.values()) {
      gl.deleteTexture(entry.tex);
      gl.deleteFramebuffer(entry.fb);
    }
    this.backdropBlurCache.clear();
    for (const p of this.backdropBlurCacheFboPool) {
      gl.deleteTexture(p.tex);
      gl.deleteFramebuffer(p.fb);
    }
    this.backdropBlurCacheFboPool.length = 0;
    this.backdropBlurCacheSnapshots.length = 0;
    if (this.cacheCopyReadFbo) {
      gl.deleteFramebuffer(this.cacheCopyReadFbo);
      this.cacheCopyReadFbo = null;
    }
  },
  clearSceneBlurCache() {
    for (const key of [...this.backdropBlurCache.keys()]) {
      if (!key.startsWith("scene_"))
        continue;
      const entry = this.backdropBlurCache.get(key);
      if (entry)
        this.releaseCacheFBO(entry);
      this.backdropBlurCache.delete(key);
    }
  },
  evictBackdropBlurCacheIfNeeded() {
    while (this.backdropBlurCache.size > this.backdropBlurCacheMax) {
      const oldest = this.backdropBlurCache.keys().next().value;
      if (!oldest)
        break;
      const oldEntry = this.backdropBlurCache.get(oldest);
      if (oldEntry)
        this.releaseCacheFBO(oldEntry);
      this.backdropBlurCache.delete(oldest);
    }
    while (this.backdropBlurCacheSnapshots.length > this.backdropBlurCacheMax) {
      this.backdropBlurCacheSnapshots.shift();
    }
  },
  acquireCacheFBO(w, h) {
    const pool = this.backdropBlurCacheFboPool;
    for (let i = pool.length - 1;i >= 0; i--) {
      const p = pool[i];
      if (p.w === w && p.h === h) {
        pool.splice(i, 1);
        return p;
      }
    }
    const fresh = this.createFBO(w, h);
    return { fb: fresh.fb, tex: fresh.tex, w, h };
  },
  releaseCacheFBO(entry) {
    this.backdropBlurCacheFboPool.push(entry);
  },
  bindFBO(fb) {
    const gl = this.gl;
    gl.bindFramebuffer(gl.FRAMEBUFFER, fb);
    gl.viewport(0, 0, this.fboW, this.fboH);
  },
  drawCopy(srcTex) {
    const gl = this.gl;
    gl.useProgram(this.copyProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
    gl.enableVertexAttribArray(this.aPosLocCp);
    gl.vertexAttribPointer(this.aPosLocCp, 2, gl.FLOAT, false, 0, 0);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, srcTex);
    gl.uniform1i(this.uCp["uTexture"], 0);
    gl.uniform2f(this.uCp["uCanvasSize"], this.fboW, this.fboH);
    gl.disable(gl.BLEND);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
  },
  drawSolidFill(r, g, b, a) {
    const gl = this.gl;
    gl.useProgram(this.solidFillProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
    gl.enableVertexAttribArray(this.aPosLocSf);
    gl.vertexAttribPointer(this.aPosLocSf, 2, gl.FLOAT, false, 0, 0);
    gl.uniform4f(this.uSf["uColor"], r, g, b, a);
    gl.disable(gl.BLEND);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
  },
  drawColorControls(srcTex, brightness, contrast, saturation) {
    const gl = this.gl;
    gl.useProgram(this.colorControlsProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
    gl.enableVertexAttribArray(this.aPosLocCc);
    gl.vertexAttribPointer(this.aPosLocCc, 2, gl.FLOAT, false, 0, 0);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, srcTex);
    gl.uniform1i(this.uCc["uTexture"], 0);
    gl.uniform2f(this.uCc["uTexSize"], this.fboW, this.fboH);
    gl.uniform1f(this.uCc["uBrightness"], brightness);
    gl.uniform1f(this.uCc["uContrast"], contrast);
    gl.uniform1f(this.uCc["uSaturation"], saturation);
    gl.disable(gl.BLEND);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
  },
  ensureElementFBO(w, h) {
    const cw = Math.max(1, Math.round(w));
    const ch = Math.max(1, Math.round(h));
    if (this.elFboW === cw && this.elFboH === ch && this.elFbo && this.backdropCropFbo && this.elBlurFboA && this.elBlurFboB) {
      return { w: cw, h: ch };
    }
    const gl = this.gl;
    if (this.elFbo)
      gl.deleteFramebuffer(this.elFbo);
    if (this.elFboTex)
      gl.deleteTexture(this.elFboTex);
    const ef = this.createFBO(cw, ch);
    this.elFbo = ef.fb;
    this.elFboTex = ef.tex;
    if (this.backdropCropFbo)
      gl.deleteFramebuffer(this.backdropCropFbo);
    if (this.backdropCropTex)
      gl.deleteTexture(this.backdropCropTex);
    const bc = this.createFBO(cw, ch);
    this.backdropCropFbo = bc.fb;
    this.backdropCropTex = bc.tex;
    if (this.elBlurFboA)
      gl.deleteFramebuffer(this.elBlurFboA);
    if (this.elBlurFboATex)
      gl.deleteTexture(this.elBlurFboATex);
    if (this.elBlurFboB)
      gl.deleteFramebuffer(this.elBlurFboB);
    if (this.elBlurFboBTex)
      gl.deleteTexture(this.elBlurFboBTex);
    const ba = this.createFBO(cw, ch);
    const bb = this.createFBO(cw, ch);
    this.elBlurFboA = ba.fb;
    this.elBlurFboATex = ba.tex;
    this.elBlurFboB = bb.fb;
    this.elBlurFboBTex = bb.tex;
    this.elFboW = cw;
    this.elFboH = ch;
    return { w: cw, h: ch };
  },
  cropAndBlurBackdrop(srcTex, srcX, srcY, srcW, srcH, blurRadius) {
    const gl = this.gl;
    const dw = this.elFboW;
    const dh = this.elFboH;
    gl.bindFramebuffer(gl.FRAMEBUFFER, this.backdropCropFbo);
    gl.viewport(0, 0, dw, dh);
    gl.disable(gl.BLEND);
    gl.useProgram(this.elFboCropProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
    gl.enableVertexAttribArray(this.aPosLocEc);
    gl.vertexAttribPointer(this.aPosLocEc, 2, gl.FLOAT, false, 0, 0);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, srcTex);
    gl.uniform1i(this.uEc["uTexture"], 0);
    gl.uniform2f(this.uEc["uSrcOffset"], srcX, srcY);
    gl.uniform2f(this.uEc["uSrcSize"], this.fboW, this.fboH);
    gl.uniform2f(this.uEc["uDstSize"], dw, dh);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    if (blurRadius < 0.5) {
      return this.backdropCropTex;
    }
    if (this.useKawaseBlur) {
      const iters = kawaseIterationsForRadius(blurRadius, this.kawaseQuality);
      const dMax = blurRadius * Math.sqrt(6 * iters / ((iters + 1) * (2 * iters + 1)));
      this.lastBlurStats = { type: "kawase", passes: iters, taps: 4 * iters, maxSample: dMax * Math.SQRT2 };
      this.ensureKawaseProgram();
      const kp = this.kawasePrograms;
      const savedFb = gl.getParameter(gl.FRAMEBUFFER_BINDING);
      const savedScissor = gl.isEnabled(gl.SCISSOR_TEST);
      const savedBox = gl.getParameter(gl.SCISSOR_BOX);
      gl.disable(gl.SCISSOR_TEST);
      gl.disable(gl.BLEND);
      let curSrc = this.backdropCropTex;
      for (let i = 0;i < iters; i++) {
        const writeFboA = i % 2 === 0;
        const dstFbo = writeFboA ? this.elBlurFboA : this.elBlurFboB;
        gl.bindFramebuffer(gl.FRAMEBUFFER, dstFbo);
        gl.viewport(0, 0, dw, dh);
        gl.useProgram(kp.prog);
        gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
        gl.enableVertexAttribArray(kp.aPos);
        gl.vertexAttribPointer(kp.aPos, 2, gl.FLOAT, false, 0, 0);
        gl.activeTexture(gl.TEXTURE0);
        gl.bindTexture(gl.TEXTURE_2D, curSrc);
        gl.uniform1i(kp.uTexture, 0);
        gl.uniform2f(kp.uTexSize, dw, dh);
        gl.uniform1f(kp.uRadius, blurRadius);
        gl.uniform1f(kp.uIteration, i);
        gl.uniform1f(kp.uTotalIters, iters);
        gl.drawArrays(gl.TRIANGLES, 0, 6);
        curSrc = writeFboA ? this.elBlurFboATex : this.elBlurFboBTex;
      }
      gl.bindFramebuffer(gl.FRAMEBUFFER, savedFb);
      gl.viewport(0, 0, this.fboW, this.fboH);
      if (savedScissor) {
        gl.enable(gl.SCISSOR_TEST);
        gl.scissor(savedBox[0], savedBox[1], savedBox[2], savedBox[3]);
      }
      const lastWroteA = (iters - 1) % 2 === 0;
      return lastWroteA ? this.elBlurFboATex : this.elBlurFboBTex;
    }
    let taps = computeBlur1DTapCount(blurRadius);
    taps = Math.min(taps, Math.max(1, this.blurTapCap | 0));
    this.lastBlurStats = { type: "gauss", passes: 2, taps, maxSample: 3 * blurRadius };
    return this.runBlurPasses(this.backdropCropTex, this.elBlurFboA, this.elBlurFboATex, this.elBlurFboB, this.elBlurFboBTex, dw, dh, blurRadius, taps, true);
  },
  drawElFboComposite(srcTex, srcW, srcH, elementCenterX, elementCenterY, elementW, elementH, rotation) {
    const gl = this.gl;
    gl.useProgram(this.elFboCompositeProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
    gl.enableVertexAttribArray(this.aPosLocEf);
    gl.vertexAttribPointer(this.aPosLocEf, 2, gl.FLOAT, false, 0, 0);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, srcTex);
    gl.uniform1i(this.uEf["uTexture"], 0);
    gl.uniform2f(this.uEf["uCanvasSize"], this.fboW, this.fboH);
    gl.uniform2f(this.uEf["uElementCenter"], elementCenterX, elementCenterY);
    gl.uniform2f(this.uEf["uElementSize"], elementW, elementH);
    gl.uniform1f(this.uEf["uRotation"], rotation);
    gl.uniform2f(this.uEf["uSrcSize"], srcW, srcH);
    gl.enable(gl.BLEND);
    gl.blendFuncSeparate(gl.ONE, gl.ONE_MINUS_SRC_ALPHA, gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
  },
  intersectClipScissor(el, scX, scY, scW, scH) {
    const clip = el.clipRect;
    if (!clip)
      return { x: scX, y: scY, w: scW, h: scH };
    const clipX = Math.round(clip.x * this.dpr);
    const clipY = Math.round((this.cssHeight - (clip.y + clip.h)) * this.dpr);
    const clipW = Math.round(clip.w * this.dpr);
    const clipH = Math.round(clip.h * this.dpr);
    const ix0 = Math.max(scX, clipX);
    const iy0 = Math.max(scY, clipY);
    const ix1 = Math.min(scX + scW, clipX + clipW);
    const iy1 = Math.min(scY + scH, clipY + clipH);
    return {
      x: ix0,
      y: iy0,
      w: Math.max(0, ix1 - ix0),
      h: Math.max(0, iy1 - iy0)
    };
  }
};

// src/cdn/core/renderer/continuous-curve.ts
var SQRT_2 = 1.4142135623730951;
var FRAC_PI_4 = 0.7853981633974483;
var FRAC_1_SQRT_2 = 0.7071067811865476;
function solveCubicSingle(a, b, c, d) {
  const f = (3 * c / a - b * b / (a * a)) / 3;
  const g = (2 * b * b * b / (a * a * a) - 9 * b * c / (a * a) + 27 * d / a) / 27;
  const h = g * g / 4 + f * f * f / 27;
  const sqrtH = Math.sqrt(h);
  return Math.cbrt(-g / 2 + sqrtH) + Math.cbrt(-g / 2 - sqrtH) - b / (3 * a);
}
function solveDepressedQuarticSingle(p, q, r) {
  const b = -p / 2;
  const c = -r;
  const d = r * p / 2 - q * q / 8;
  const f = (3 * c - b * b) / 3;
  const g = (2 * b * b * b - 9 * b * c + 27 * d) / 27;
  const rVal = Math.sqrt(-f * f * f / 27);
  const phi = Math.acos(-g / (2 * rVal));
  const y = 2 * Math.sqrt(-f / 3) * Math.cos(phi / 3);
  const z = y - b / 3;
  const u = Math.sqrt(2 * z - p);
  return (u - Math.sqrt(u * u - 4 * (z + q / (2 * u)))) / 2;
}

class ContinuousCurvatureRoundedRectangleCornerBuilder {
  extendedFraction;
  arcFraction;
  theta;
  cos;
  sin;
  cot;
  cos2;
  sin2;
  cos3;
  sin3;
  k0;
  k1;
  k2;
  k3;
  constructor(extendedFraction = 2 / 3, arcFraction = 0.5) {
    this.extendedFraction = extendedFraction;
    this.arcFraction = arcFraction;
    this.theta = (1 - arcFraction) * FRAC_PI_4;
    this.cos = Math.cos(this.theta);
    this.sin = Math.sin(this.theta);
    this.cot = 1 / Math.tan(this.theta);
    this.cos2 = this.cos * this.cos;
    this.sin2 = this.sin * this.sin;
    this.cos3 = this.cos2 * this.cos;
    this.sin3 = this.sin2 * this.sin;
    const cos = this.cos;
    const sin = this.sin;
    const cot = this.cot;
    const cos2 = this.cos2;
    const sin2 = this.sin2;
    const cos3 = this.cos3;
    const sin3 = this.sin3;
    this.k0 = 27 * (SQRT_2 - 6 * cos + 6 * SQRT_2 * cos2 - 4 * cos3) * cot + 2 * sin * (-9 + 2 * (SQRT_2 - 2 * sin) * sin3 + 2 * SQRT_2 * cos * (9 + sin2) - 2 * cos2 * (9 + 2 * sin2));
    this.k1 = -81 * (-2 + SQRT_2 + 4 * (-1 + SQRT_2) * cos + 2 * (-2 + SQRT_2) * cos2) * cot - 4 * sin * (-9 + 9 * SQRT_2 + SQRT_2 * sin3 + (-2 + SQRT_2) * cos * (9 + sin2));
    this.k2 = 9 * (9 * (-4 + 3 * SQRT_2 + (-6 + 4 * SQRT_2) * cos) * cot + (-6 + 4 * SQRT_2) * sin);
    this.k3 = 27 * (10 - 7 * SQRT_2) * cot;
  }
  buildEvenCornerBezierPoints(t) {
    const k = this.extendedFraction * t;
    const kappa = solveCubicSingle(this.k3, this.k2, this.k1 + 8 * -k * this.sin3 * this.sin, this.k0);
    const x3 = FRAC_1_SQRT_2 + (-FRAC_1_SQRT_2 + this.sin) / kappa;
    const y3 = 1 - FRAC_1_SQRT_2 + (FRAC_1_SQRT_2 - this.cos) / kappa;
    const x2 = x3 - y3 * this.cot;
    const x1 = x2 - 1.5 * kappa * y3 * y3 / this.sin3;
    const x0 = -k;
    const x6 = 1 - y3;
    const y6 = 1 - x3;
    const y7 = 1 - x2;
    const y8 = 1 - x1;
    const y9 = 1 - x0;
    const a = 1.5 * kappa;
    const g = this.cos2 - this.sin2;
    const x36 = x6 - x3;
    const y36 = y6 - y3;
    const c = -(this.cos * y36 - this.sin * x36);
    const lambda = (-g + Math.sqrt(g * g - 4 * a * c)) / (2 * a);
    const x4 = x3 + lambda * this.cos;
    const y4 = y3 + lambda * this.sin;
    const x5 = x6 - lambda * this.sin;
    const y5 = y6 - lambda * this.cos;
    return [x0, 0, x1, 0, x2, 0, x3, y3, x4, y4, x5, y5, x6, y6, 1, y7, 1, y8, 1, y9];
  }
  buildUnevenCornerBezierPoints(tH, tV) {
    const kH = this.extendedFraction * tH;
    const kV = this.extendedFraction * tV;
    const kappa3 = solveCubicSingle(this.k3, this.k2, this.k1 + 8 * -kH * this.sin3 * this.sin, this.k0);
    const kappa6 = solveCubicSingle(this.k3, this.k2, this.k1 + 8 * -kV * this.sin3 * this.sin, this.k0);
    const x3 = FRAC_1_SQRT_2 + (-FRAC_1_SQRT_2 + this.sin) / kappa3;
    const y3 = 1 - FRAC_1_SQRT_2 + (FRAC_1_SQRT_2 - this.cos) / kappa3;
    const x2 = x3 - y3 * this.cot;
    const x1 = x2 - 1.5 * kappa3 * y3 * y3 / this.sin3;
    const x0 = -kH;
    const x3p = FRAC_1_SQRT_2 + (-FRAC_1_SQRT_2 + this.sin) / kappa6;
    const y3p = 1 - FRAC_1_SQRT_2 + (FRAC_1_SQRT_2 - this.cos) / kappa6;
    const x2p = x3p - y3p * this.cot;
    const x1p = x2p - 1.5 * kappa6 * y3p * y3p / this.sin3;
    const x0p = -kV;
    const x6 = 1 - y3p;
    const y6 = 1 - x3p;
    const y7 = 1 - x2p;
    const y8 = 1 - x1p;
    const y9 = 1 - x0p;
    const a = 1.5 * kappa3;
    const b = 1.5 * kappa6;
    const g = this.cos2 - this.sin2;
    const x36 = x6 - x3;
    const y36 = y6 - y3;
    const c = -(this.cos * y36 - this.sin * x36);
    const d = this.sin * y36 - this.cos * x36;
    const p = 2 * (d / b);
    const q = g * g * g / (a * b * b);
    const r = (a * d * d + c * g * g) / (a * b * b);
    const lambda6 = solveDepressedQuarticSingle(p, q, r);
    const lambda3 = (-d - b * lambda6 * lambda6) / g;
    const x4 = x3 + lambda3 * this.cos;
    const y4 = y3 + lambda3 * this.sin;
    const x5 = x6 - lambda6 * this.sin;
    const y5 = y6 - lambda6 * this.cos;
    return [x0, 0, x1, 0, x2, 0, x3, y3, x4, y4, x5, y5, x6, y6, 1, y7, 1, y8, 1, y9];
  }
  getCornerBezierPoints(tW, tV) {
    const i = tW === 0 ? 0 : tW === 1 ? 1 : -1;
    const j = tV === 0 ? 0 : tV === 1 ? 1 : -1;
    if (i >= 0 && j >= 0) {
      if (i === 0 && j === 0)
        return this.buildEvenCornerBezierPoints(0);
      if (i === 1 && j === 1)
        return this.buildEvenCornerBezierPoints(1);
      return this.buildUnevenCornerBezierPoints(i === 1 ? 1 : 0, j === 1 ? 1 : 0);
    }
    return this.buildUnevenCornerBezierPoints(Math.max(0, Math.min(1, tW)), Math.max(0, Math.min(1, tV)));
  }
}
function continuousCurvatureRoundedRectPath(ctx, w, h, radius) {
  const builder = new ContinuousCurvatureRoundedRectangleCornerBuilder;
  const r = radius;
  const tW = Math.max(0, Math.min(1, (w * 0.5 - r) / r));
  const tH = Math.max(0, Math.min(1, (h * 0.5 - r) / r));
  const p = builder.getCornerBezierPoints(tW, tH);
  if (p.length < 20)
    return new Path2D;
  const path = new Path2D;
  let x = w - r;
  let y = 0;
  path.moveTo(x + p[0] * r, y + p[1] * r);
  path.bezierCurveTo(x + p[2] * r, y + p[3] * r, x + p[4] * r, y + p[5] * r, x + p[6] * r, y + p[7] * r);
  path.bezierCurveTo(x + p[8] * r, y + p[9] * r, x + p[10] * r, y + p[11] * r, x + p[12] * r, y + p[13] * r);
  path.bezierCurveTo(x + p[14] * r, y + p[15] * r, x + p[16] * r, y + p[17] * r, x + p[18] * r, y + p[19] * r);
  x = w - r;
  y = h;
  path.lineTo(x + p[18] * r, y - p[19] * r);
  path.bezierCurveTo(x + p[16] * r, y - p[17] * r, x + p[14] * r, y - p[15] * r, x + p[12] * r, y - p[13] * r);
  path.bezierCurveTo(x + p[10] * r, y - p[11] * r, x + p[8] * r, y - p[9] * r, x + p[6] * r, y - p[7] * r);
  path.bezierCurveTo(x + p[4] * r, y - p[5] * r, x + p[2] * r, y - p[3] * r, x + p[0] * r, y - p[1] * r);
  x = r;
  y = h;
  path.lineTo(x - p[0] * r, y - p[1] * r);
  path.bezierCurveTo(x - p[2] * r, y - p[3] * r, x - p[4] * r, y - p[5] * r, x - p[6] * r, y - p[7] * r);
  path.bezierCurveTo(x - p[8] * r, y - p[9] * r, x - p[10] * r, y - p[11] * r, x - p[12] * r, y - p[13] * r);
  path.bezierCurveTo(x - p[14] * r, y - p[15] * r, x - p[16] * r, y - p[17] * r, x - p[18] * r, y - p[19] * r);
  x = r;
  y = 0;
  path.lineTo(x - p[18] * r, y + p[19] * r);
  path.bezierCurveTo(x - p[16] * r, y + p[17] * r, x - p[14] * r, y + p[15] * r, x - p[12] * r, y + p[13] * r);
  path.bezierCurveTo(x - p[10] * r, y + p[11] * r, x - p[8] * r, y + p[9] * r, x - p[6] * r, y + p[7] * r);
  path.bezierCurveTo(x - p[4] * r, y + p[5] * r, x - p[2] * r, y + p[3] * r, x - p[0] * r, y + p[1] * r);
  path.closePath();
  return path;
}

// src/cdn/core/renderer/continuous-mask.ts
var maskCache = new Map;
var MAX_MASK_CACHE_BYTES = 32 * 1024 * 1024;
var maskCacheBytes = 0;
var _alphaBuf = new Uint8Array(128 * 128);
var _insideBuf = new Int32Array(128 * 128);
var _outsideBuf = new Int32Array(128 * 128);
var _texBuf = new Uint8Array(128 * 128 * 4);
var TIMING_RING_SIZE = 32;
var capsuleSdfTimings = [];
function getMaskCacheEntries() {
  return Array.from(maskCache.entries()).map(([key, v]) => ({
    key,
    tex: v.tex,
    texSize: v.texSize
  }));
}
function generateContinuousCurvatureMask(w, h, radius, dpr = 1, quality = 1, skipSdf = false) {
  const devMaxDim = Math.max(w, h) * (dpr || 1);
  const target = devMaxDim * 2;
  let baseTexSize = 128;
  while (baseTexSize < target && baseTexSize < 1024)
    baseTexSize <<= 1;
  const texSize = Math.max(32, Math.ceil(baseTexSize * quality));
  const key = `${w},${h},${radius},${texSize},s${skipSdf ? 1 : 0}`;
  const cached = maskCache.get(key);
  if (cached) {
    maskCache.delete(key);
    maskCache.set(key, cached);
    if (capsuleSdfTimings.length >= TIMING_RING_SIZE)
      capsuleSdfTimings.shift();
    capsuleSdfTimings.push({
      timestamp: performance.now(),
      key,
      w,
      h,
      radius,
      texSize,
      cacheHit: true,
      stepCanvasSetup: 0,
      stepPathDraw: 0,
      stepGetImageData: 0,
      stepAlphaExtract: 0,
      stepInitArrays: 0,
      stepForwardPass: 0,
      stepBackwardPass: 0,
      stepPack: 0,
      stepTotal: 0
    });
    return { tex: cached.tex, texSize };
  }
  const t0 = performance.now();
  const maxDim = Math.max(w, h);
  const aspectW = w / maxDim;
  const aspectH = h / maxDim;
  const canvas = document.createElement("canvas");
  canvas.width = texSize;
  canvas.height = texSize;
  const ctx = canvas.getContext("2d", { willReadFrequently: true });
  ctx.clearRect(0, 0, texSize, texSize);
  const t1 = performance.now();
  const margin = 4;
  const drawW = (texSize - 2 * margin) * aspectW;
  const drawH = (texSize - 2 * margin) * aspectH;
  const offsetX = (texSize - drawW) / 2;
  const offsetY = (texSize - drawH) / 2;
  const scale = drawW / w;
  const drawRadius = radius * scale;
  const path = continuousCurvatureRoundedRectPath(ctx, drawW, drawH, drawRadius);
  ctx.fillStyle = "white";
  ctx.translate(offsetX, offsetY);
  ctx.fill(path);
  ctx.translate(-offsetX, -offsetY);
  const t2 = performance.now();
  const imageData = ctx.getImageData(0, 0, texSize, texSize);
  const t3 = performance.now();
  const N = texSize * texSize;
  if (_alphaBuf.length < N) {
    _alphaBuf = new Uint8Array(N);
    _insideBuf = new Int32Array(N);
    _outsideBuf = new Int32Array(N);
    _texBuf = new Uint8Array(N * 4);
  }
  const alpha = _alphaBuf;
  const inside = _insideBuf;
  const outside = _outsideBuf;
  const INF = 2147483647;
  const data32 = new Uint32Array(imageData.data.buffer);
  for (let i = 0;i < N; i++) {
    const a = data32[i] >>> 24 & 255;
    alpha[i] = a;
    if (a > 128) {
      inside[i] = 0;
      outside[i] = INF;
    } else {
      inside[i] = INF;
      outside[i] = 0;
    }
  }
  const t4 = performance.now();
  const t5 = t4;
  let t6 = t5;
  let t7 = t5;
  if (!skipSdf) {
    const ts = texSize;
    for (let y = 0;y < ts; y++) {
      for (let x = 0;x < ts; x++) {
        const idx = y * ts + x;
        let ins = inside[idx];
        let out = outside[idx];
        if (x > 0 && y > 1) {
          const k = idx - ts - 1 - ts;
          const v = 11;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        if (x > 0) {
          const k = idx - 1;
          const v = 5;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        if (x > 0 && y > 0) {
          const k = idx - ts - 1;
          const v = 7;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        if (y > 0) {
          const k = idx - ts;
          const v = 5;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        if (x < ts - 1 && y > 0) {
          const k = idx - ts + 1;
          const v = 7;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        if (x < ts - 2 && y > 0) {
          const k = idx - ts + 2;
          const v = 11;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        inside[idx] = ins;
        outside[idx] = out;
      }
    }
    t6 = performance.now();
    for (let y = ts - 1;y >= 0; y--) {
      for (let x = ts - 1;x >= 0; x--) {
        const idx = y * ts + x;
        let ins = inside[idx];
        let out = outside[idx];
        if (x < ts - 1 && y < ts - 2) {
          const k = idx + ts + 1 + ts;
          const v = 11;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        if (x < ts - 1) {
          const k = idx + 1;
          const v = 5;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        if (x < ts - 1 && y < ts - 1) {
          const k = idx + ts + 1;
          const v = 7;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        if (y < ts - 1) {
          const k = idx + ts;
          const v = 5;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        if (x > 0 && y < ts - 1) {
          const k = idx + ts - 1;
          const v = 7;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        if (x > 1 && y < ts - 1) {
          const k = idx + ts - 2;
          const v = 11;
          const ti = inside[k] + v;
          if (ti < ins)
            ins = ti;
          const to = outside[k] + v;
          if (to < out)
            out = to;
        }
        inside[idx] = ins;
        outside[idx] = out;
      }
    }
    t7 = performance.now();
  }
  const refDist = drawRadius;
  const tex = _texBuf;
  const tex32 = new Uint32Array(tex.buffer);
  const ALPHA_OPAQUE = 4278190080;
  if (skipSdf) {
    for (let i = 0;i < N; i++) {
      tex32[i] = ALPHA_OPAQUE | alpha[i];
    }
  } else {
    for (let i = 0;i < N; i++) {
      const sd = (inside[i] - outside[i]) / 5;
      const normalized = sd / refDist > 1 ? 1 : sd / refDist < -1 ? -1 : sd / refDist;
      const g = (normalized * 0.5 + 0.5) * 255 + 0.5 | 0;
      tex32[i] = ALPHA_OPAQUE | g << 8 | alpha[i];
    }
  }
  const t8 = performance.now();
  const texCopy = tex.slice(0, N * 4);
  maskCache.set(key, { tex: texCopy, texSize });
  maskCacheBytes += texCopy.byteLength;
  while (maskCacheBytes > MAX_MASK_CACHE_BYTES && maskCache.size > 1) {
    const oldest = maskCache.keys().next().value;
    if (oldest === undefined)
      break;
    const old = maskCache.get(oldest);
    if (old)
      maskCacheBytes -= old.tex.byteLength;
    maskCache.delete(oldest);
  }
  if (capsuleSdfTimings.length >= TIMING_RING_SIZE)
    capsuleSdfTimings.shift();
  capsuleSdfTimings.push({
    timestamp: t8,
    key,
    w,
    h,
    radius,
    texSize,
    cacheHit: false,
    stepCanvasSetup: t1 - t0,
    stepPathDraw: t2 - t1,
    stepGetImageData: t3 - t2,
    stepAlphaExtract: t4 - t3,
    stepInitArrays: 0,
    stepForwardPass: t6 - t5,
    stepBackwardPass: t7 - t6,
    stepPack: t8 - t7,
    stepTotal: t8 - t0
  });
  return { tex, texSize };
}

// src/cdn/core/renderer/methods-wallpaper.ts
var wallpaperMethods = {
  async loadWallpaper(src) {
    const img = new Image;
    img.crossOrigin = "anonymous";
    await new Promise((resolve, reject) => {
      img.onload = () => resolve();
      img.onerror = () => reject(new Error("Failed to load wallpaper: " + src));
      img.src = src;
    });
    const gl = this.gl;
    if (this.wallpaperTexture)
      gl.deleteTexture(this.wallpaperTexture);
    const tex = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, tex);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, img);
    const w = img.naturalWidth;
    const h = img.naturalHeight;
    const isPOT = (w & w - 1) === 0 && (h & h - 1) === 0;
    if (isPOT) {
      gl.generateMipmap(gl.TEXTURE_2D);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR);
    } else {
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    }
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    this.wallpaperTexture = tex;
    this.wallpaperSize = [w || 1, h || 1];
    this.wallpaperReady = true;
    this.clearBackdropBlurCache();
    this.wallpaperVersion++;
    this.markAllDirty();
    this.requestRender();
  },
  async loadSdfTexture(src) {
    const img = new Image;
    img.crossOrigin = "anonymous";
    await new Promise((resolve, reject) => {
      img.onload = () => resolve();
      img.onerror = () => reject(new Error("Failed to load SDF texture: " + src));
      img.src = src;
    });
    const gl = this.gl;
    if (this.sdfTexture)
      gl.deleteTexture(this.sdfTexture);
    const tex = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, tex);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, img);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    this.sdfTexture = tex;
    this.sdfTextureSize = [img.naturalWidth || 1, img.naturalHeight || 1];
    this.sdfTextureReady = true;
    this.markAllDirty();
    this.requestRender();
  },
  loadSdfTextureFromData(data, w, h) {
    this.loadTextSdfTextureFromData(data, w, h);
  },
  loadTextSdfTextureFromData(data, w, h) {
    if (w < 1 || h < 1)
      return;
    const gl = this.gl;
    if (this.textSdfTexture)
      gl.deleteTexture(this.textSdfTexture);
    const tex = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, tex);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, w, h, 0, gl.RGBA, gl.UNSIGNED_BYTE, data);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    this.textSdfTexture = tex;
    this.textSdfTextureSize = [w, h];
    this.textSdfTextureReady = true;
    this.markAllDirty();
    this.requestRender();
  },
  loadContinuousSdf(w, h, radius) {
    const holeR = this.debugSdfHoleTopLeftR;
    const holeG = this.debugSdfHoleTopLeftG;
    const skipSdf = !!this.noContinuousSdf;
    const q = this.capsuleSdfQuality;
    const key = `${w},${h},${radius},${this.dpr},q${q},s${skipSdf ? 1 : 0},r${holeR ? 1 : 0},g${holeG ? 1 : 0}`;
    let entry = this.continuousSdfPool.get(key);
    if (!entry) {
      const genStart = performance.now();
      const { tex, texSize } = generateContinuousCurvatureMask(w, h, radius, this.dpr, this.capsuleSdfQuality, skipSdf);
      const genEnd = performance.now();
      const gl = this.gl;
      const texObj = gl.createTexture();
      gl.bindTexture(gl.TEXTURE_2D, texObj);
      gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
      let uploadTex = tex;
      if (holeR || holeG) {
        uploadTex = tex.slice();
        const half = texSize >> 1;
        for (let row = 0;row < half; row++) {
          const rowBase = row * texSize * 4;
          for (let col = 0;col < half; col++) {
            const idx = rowBase + col * 4;
            if (holeR)
              uploadTex[idx] = 0;
            if (holeG)
              uploadTex[idx + 1] = 0;
          }
        }
      }
      const uploadStart = performance.now();
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, texSize, texSize, 0, gl.RGBA, gl.UNSIGNED_BYTE, uploadTex);
      gl.finish();
      const uploadEnd = performance.now();
      if (holeR || holeG) {
        this._debugUploadedSdfTexMap.set(key, { tex: uploadTex.slice(), texSize });
      }
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
      entry = { tex: texObj, texSize };
      this.continuousSdfPool.set(key, entry);
      this._lastCapsuleUploadMs = uploadEnd - uploadStart;
      this._lastCapsuleGenMs = genEnd - genStart;
      this._lastCapsuleKey = key;
      if (this.continuousSdfPool.size > 16) {
        const oldest = this.continuousSdfPool.keys().next().value;
        if (oldest) {
          const old = this.continuousSdfPool.get(oldest);
          if (old)
            gl.deleteTexture(old.tex);
          this.continuousSdfPool.delete(oldest);
        }
      }
    } else {
      this._lastCapsuleUploadMs = 0;
      this._lastCapsuleGenMs = 0;
      this._lastCapsuleKey = key + " (pool hit)";
    }
    this.continuousSdfTexture = entry.tex;
    this.continuousSdfTexSize = [entry.texSize, entry.texSize];
    this.continuousSdfKey = key;
  },
  resize(cssW, cssH) {
    if (this.dpr <= 0) {
      this.dpr = Math.min(window.devicePixelRatio || 1, 3);
    }
    const w = Math.round(cssW * this.dpr);
    const h = Math.round(cssH * this.dpr);
    if (this.canvas.width !== w || this.canvas.height !== h) {
      this.canvas.width = w;
      this.canvas.height = h;
      this.gl.viewport(0, 0, w, h);
      this.resizeFBOs(w, h);
    }
    for (const b of this.buttonConfigs)
      this.fgDirtyIds.add(b.id);
    this.cssWidth = cssW;
    this.cssHeight = cssH;
    if (this.elFboCache.size > 0) {
      const gl = this.gl;
      for (const e of this.elFboCache.values()) {
        gl.deleteFramebuffer(e.fb);
        gl.deleteTexture(e.tex);
      }
      this.elFboCache.clear();
    }
    this.markAllDirty();
    this.requestRender();
  }
};

// src/cdn/core/renderer/methods-scroll.ts
var scrollMethods = {
  setContentHeight(h) {
    this.contentHeight = h;
    this.clampScrollY();
    this.requestRender();
  },
  setScrollY(y) {
    this.scrollVelocity = 0;
    this.scrollY = this.clampScrollValue(y);
    this.requestRender();
  },
  setScrollVelocity(v) {
    const MAX_VEL = 4000;
    this.scrollVelocity = Math.max(-MAX_VEL, Math.min(MAX_VEL, v));
    this.startAnimation();
  },
  getScrollY() {
    return this.scrollY;
  },
  getScrollVelocity() {
    return this.scrollVelocity;
  },
  clampScrollValue(y) {
    const max = Math.max(0, this.contentHeight - this.cssHeight);
    if (y < 0)
      return 0;
    if (y > max)
      return max;
    return y;
  },
  clampScrollY() {
    this.scrollY = this.clampScrollValue(this.scrollY);
  },
  setBackgroundColor(color) {
    if (this.backgroundColor === color)
      return;
    if (this.backgroundColor && color && this.backgroundColor[0] === color[0] && this.backgroundColor[1] === color[1] && this.backgroundColor[2] === color[2])
      return;
    this.backgroundColor = color;
    this.markAllDirty();
    this.requestRender();
  },
  setGravityAngle(angleRad) {
    const THRESHOLD = 0.02;
    if (Math.abs(this.gravityAngle - angleRad) < THRESHOLD)
      return;
    this.gravityAngle = angleRad;
    this.markGravityDirty();
    this.requestRender();
  }
};

// src/cdn/core/renderer/velocity-tracker.ts
var MAX_SAMPLES = 20;

class VelocityTracker1D {
  samples = [];
  resetTracking() {
    this.samples.length = 0;
  }
  addPosition(timeMillis, position) {
    this.samples.push({ t: timeMillis, p: position });
    if (this.samples.length > MAX_SAMPLES) {
      this.samples.shift();
    }
  }
  calculateVelocity(windowMs = 100) {
    const samples = this.samples;
    if (samples.length < 2)
      return 0;
    const now = samples[samples.length - 1].t;
    const cutoff = now - windowMs;
    let n = 0;
    let sumT = 0;
    let sumP = 0;
    let sumTT = 0;
    let sumTP = 0;
    for (let i = samples.length - 1;i >= 0; i--) {
      const s = samples[i];
      if (s.t < cutoff)
        break;
      const tt = (s.t - now) / 1000;
      sumT += tt;
      sumP += s.p;
      sumTT += tt * tt;
      sumTP += tt * s.p;
      n++;
    }
    if (n < 2)
      return 0;
    const denom = n * sumTT - sumT * sumT;
    if (Math.abs(denom) < 0.000000001)
      return 0;
    const b = (n * sumTP - sumT * sumP) / denom;
    return b;
  }
}

// src/cdn/core/renderer/methods-toggle.ts
var toggleMethods = {
  ensureToggleState(groupId, initialFraction, pressedScale = 1.5, valueRangeSpan = 1) {
    let st = this.toggleStates.get(groupId);
    if (!st) {
      st = {
        fraction: initialFraction,
        fractionVelocity: 0,
        targetFraction: initialFraction,
        pressProgress: 0,
        pressVelocity: 0,
        targetPress: 0,
        scaleX: 1,
        scaleXVelocity: 0,
        targetScaleX: 1,
        scaleY: 1,
        scaleYVelocity: 0,
        targetScaleY: 1,
        velocity: 0,
        velocityVelocity: 0,
        targetVelocity: 0,
        isDragging: false,
        trackVelocityAfterRelease: false,
        velocityTracker: new VelocityTracker1D,
        lastFractionForVelocity: initialFraction,
        lastFractionTime: 0,
        pressedScale,
        valueRangeSpan,
        panelOffset: 0,
        panelOffsetVelocity: 0,
        targetPanelOffset: 0
      };
      this.toggleStates.set(groupId, st);
    } else {
      if (pressedScale !== 1.5)
        st.pressedScale = pressedScale;
      if (valueRangeSpan !== 1)
        st.valueRangeSpan = valueRangeSpan;
    }
    return st;
  },
  setToggleTarget(groupId, target) {
    const st = this.ensureToggleState(groupId, target);
    if (st.isDragging)
      return;
    if (st.targetFraction === target)
      return;
    st.targetFraction = target;
    st.trackVelocityAfterRelease = false;
    st.targetVelocity = 0;
    st.velocity = 0;
    st.velocityVelocity = 0;
    st.velocityTracker.resetTracking();
    if (st.targetPress === 0) {
      st.targetPress = 1;
      st.targetScaleX = st.pressedScale;
      st.targetScaleY = st.pressedScale;
    }
    this.markGroupDirty(groupId);
    this.startAnimation();
  },
  beginToggleDrag(groupId, startFraction) {
    const st = this.ensureToggleState(groupId, startFraction);
    st.isDragging = true;
    st.targetPress = 1;
    st.targetScaleX = st.pressedScale;
    st.targetScaleY = st.pressedScale;
    st.velocityTracker.resetTracking();
    st.targetVelocity = 0;
    st.velocity = 0;
    st.velocityVelocity = 0;
    this.markGroupDirty(groupId);
    this.startAnimation();
  },
  dragToggle(groupId, startFraction, currentX, startX, dragWidth) {
    const st = this.ensureToggleState(groupId, startFraction);
    if (!st.isDragging)
      return;
    const delta = (currentX - startX) / Math.max(1, dragWidth);
    const newTarget = Math.max(0, Math.min(1, startFraction + delta));
    st.targetFraction = newTarget;
    this.markGroupDirty(groupId);
    this.startAnimation();
  },
  endToggleDrag(groupId) {
    const st = this.toggleStates.get(groupId);
    if (!st)
      return 0;
    st.isDragging = false;
    const finalTarget = st.targetFraction >= 0.5 ? 1 : 0;
    st.targetFraction = finalTarget;
    st.trackVelocityAfterRelease = true;
    this.markGroupDirty(groupId);
    this.startAnimation();
    return finalTarget;
  },
  endSliderDrag(groupId) {
    const st = this.toggleStates.get(groupId);
    if (!st)
      return 0;
    st.isDragging = false;
    const finalTarget = st.targetFraction;
    st.trackVelocityAfterRelease = true;
    this.markGroupDirty(groupId);
    this.startAnimation();
    return finalTarget;
  },
  getToggleFraction(groupId) {
    return this.toggleStates.get(groupId)?.fraction ?? 0;
  },
  setSliderDragPosition(groupId, fraction) {
    const st = this.toggleStates.get(groupId);
    if (!st)
      return;
    const clamped = Math.max(0, Math.min(1, fraction));
    if (st.targetFraction !== clamped) {
      st.targetFraction = clamped;
      this.markGroupDirty(groupId);
      this.startAnimation();
    }
  },
  getToggleTarget(groupId) {
    return this.toggleStates.get(groupId)?.targetFraction ?? 0;
  }
};

// src/cdn/core/renderer/spring.ts
var DP = 1;
var SPRING_K = 300;
var SPRING_DAMPING_RATIO = 0.5;
var SPRING_OMEGA_N = Math.sqrt(SPRING_K);
var SPRING_OMEGA_D = SPRING_OMEGA_N * Math.sqrt(1 - SPRING_DAMPING_RATIO * SPRING_DAMPING_RATIO);
var SPRING_THRESHOLD = 0.003;
var TOGGLE_VALUE_K = 1000;
var TOGGLE_VALUE_OMEGA_N = Math.sqrt(TOGGLE_VALUE_K);
var TOGGLE_SCALE_X_K = 250;
var TOGGLE_SCALE_X_DAMPING_RATIO = 0.6;
var TOGGLE_SCALE_X_OMEGA_N = Math.sqrt(TOGGLE_SCALE_X_K);
var TOGGLE_SCALE_X_OMEGA_D = TOGGLE_SCALE_X_OMEGA_N * Math.sqrt(1 - TOGGLE_SCALE_X_DAMPING_RATIO * TOGGLE_SCALE_X_DAMPING_RATIO);
var TOGGLE_SCALE_Y_K = 250;
var TOGGLE_SCALE_Y_DAMPING_RATIO = 0.7;
var TOGGLE_SCALE_Y_OMEGA_N = Math.sqrt(TOGGLE_SCALE_Y_K);
var TOGGLE_SCALE_Y_OMEGA_D = TOGGLE_SCALE_Y_OMEGA_N * Math.sqrt(1 - TOGGLE_SCALE_Y_DAMPING_RATIO * TOGGLE_SCALE_Y_DAMPING_RATIO);
var TOGGLE_VELOCITY_K = 300;
var TOGGLE_VELOCITY_DAMPING_RATIO = 0.5;
var TOGGLE_VELOCITY_OMEGA_N = Math.sqrt(TOGGLE_VELOCITY_K);
var TOGGLE_VELOCITY_OMEGA_D = TOGGLE_VELOCITY_OMEGA_N * Math.sqrt(1 - TOGGLE_VELOCITY_DAMPING_RATIO * TOGGLE_VELOCITY_DAMPING_RATIO);
function springStep1D(current, velocity, target, dt) {
  const x0 = current - target;
  const v0 = velocity;
  const decay = Math.exp(-SPRING_DAMPING_RATIO * SPRING_OMEGA_N * dt);
  const cosWd = Math.cos(SPRING_OMEGA_D * dt);
  const sinWd = Math.sin(SPRING_OMEGA_D * dt);
  const offset = x0 * decay * cosWd + (v0 + SPRING_DAMPING_RATIO * SPRING_OMEGA_N * x0) / SPRING_OMEGA_D * decay * sinWd;
  const b0 = (v0 + SPRING_DAMPING_RATIO * SPRING_OMEGA_N * x0) / SPRING_OMEGA_D;
  const newVel = -SPRING_DAMPING_RATIO * SPRING_OMEGA_N * offset + decay * (-x0 * SPRING_OMEGA_D * sinWd + b0 * SPRING_OMEGA_D * cosWd);
  return { current: target + offset, velocity: newVel };
}
function springStepCritical(current, velocity, target, dt, omegaN) {
  const x0 = current - target;
  const v0 = velocity;
  const decay = Math.exp(-omegaN * dt);
  const offset = x0 * decay + (v0 + omegaN * x0) * dt * decay;
  const newVel = -omegaN * x0 * decay + (v0 + omegaN * x0) * (decay - omegaN * dt * decay);
  return { current: target + offset, velocity: newVel };
}
function springStepUnderdamped(current, velocity, target, dt, omegaN, dampingRatio) {
  const x0 = current - target;
  const v0 = velocity;
  const omegaD = omegaN * Math.sqrt(1 - dampingRatio * dampingRatio);
  const decay = Math.exp(-dampingRatio * omegaN * dt);
  const cosWd = Math.cos(omegaD * dt);
  const sinWd = Math.sin(omegaD * dt);
  const offset = x0 * decay * cosWd + (v0 + dampingRatio * omegaN * x0) / omegaD * decay * sinWd;
  const b0 = (v0 + dampingRatio * omegaN * x0) / omegaD;
  const newVel = -dampingRatio * omegaN * offset + decay * (-x0 * omegaD * sinWd + b0 * omegaD * cosWd);
  return { current: target + offset, velocity: newVel };
}

// src/cdn/core/renderer/methods-tabs.ts
var tabsMethods = {
  setTabSelected(groupId, tabIndex, tabsCount) {
    const st = this.ensureToggleState(groupId, tabIndex, LiquidGlassRenderer.TAB_PRESSED_SCALE, tabsCount - 1);
    if (st.isDragging)
      return;
    if (st.targetFraction === tabIndex)
      return;
    st.targetFraction = tabIndex;
    st.trackVelocityAfterRelease = false;
    st.targetVelocity = 0;
    st.velocity = 0;
    st.velocityVelocity = 0;
    st.velocityTracker.resetTracking();
    if (st.targetPress === 0) {
      st.targetPress = 1;
      st.targetScaleX = st.pressedScale;
      st.targetScaleY = st.pressedScale;
    }
    this.markGroupDirty(groupId);
    this.startAnimation();
  },
  beginTabDrag(groupId, startTabIndex, tabsCount) {
    const st = this.ensureToggleState(groupId, startTabIndex, LiquidGlassRenderer.TAB_PRESSED_SCALE, tabsCount - 1);
    st.isDragging = true;
    st.targetPress = 1;
    st.targetScaleX = st.pressedScale;
    st.targetScaleY = st.pressedScale;
    st.velocityTracker.resetTracking();
    st.targetVelocity = 0;
    st.velocity = 0;
    st.velocityVelocity = 0;
    this.markGroupDirty(groupId);
    this.startAnimation();
  },
  dragTab(groupId, startTabIndex, currentX, startX, tabWidth, tabsCount) {
    const st = this.ensureToggleState(groupId, startTabIndex, LiquidGlassRenderer.TAB_PRESSED_SCALE, tabsCount - 1);
    if (!st.isDragging)
      return;
    const delta = (currentX - startX) / Math.max(1, tabWidth);
    const newTarget = Math.max(0, Math.min(tabsCount - 1, startTabIndex + delta));
    st.targetFraction = newTarget;
    const maxWidth = tabWidth * tabsCount;
    const offsetFraction = Math.max(-1, Math.min(1, (currentX - startX) / Math.max(1, maxWidth)));
    const easeOut = 1 - Math.pow(1 - Math.abs(offsetFraction), 2);
    st.targetPanelOffset = 4 * DP * Math.sign(offsetFraction) * easeOut;
    this.markGroupDirty(groupId);
    this.startAnimation();
  },
  endTabDrag(groupId, tabsCount) {
    const st = this.toggleStates.get(groupId);
    if (!st)
      return 0;
    st.isDragging = false;
    const finalTarget = Math.round(st.targetFraction);
    const clamped = Math.max(0, Math.min(tabsCount - 1, finalTarget));
    st.targetFraction = clamped;
    st.velocityTracker.resetTracking();
    st.trackVelocityAfterRelease = false;
    st.targetVelocity = 0;
    st.targetPanelOffset = 0;
    this.markGroupDirty(groupId);
    this.startAnimation();
    return clamped;
  },
  getTabFraction(groupId) {
    return this.toggleStates.get(groupId)?.fraction ?? 0;
  },
  getTabTarget(groupId) {
    return this.toggleStates.get(groupId)?.targetFraction ?? 0;
  }
};

// src/cdn/core/renderer/methods-elements.ts
function elementCacheSignature(el) {
  return JSON.stringify([
    el.rect.w,
    el.rect.h,
    el.cornerRadius,
    el.blurRadius,
    el.useSeparableBlur,
    el.scrimColor,
    el.surfaceColor,
    el.tintColor,
    el.independentBackdrop,
    el.sampleWallpaper,
    el.chromaticAberration,
    el.outerShadow,
    el.highlight,
    el.isMagnifier,
    el.isSdfTexture,
    el.enterProgress,
    el.enterSafeProgress,
    el.enterStretchFactor,
    el.useGravityAngle,
    el.elementRotation,
    el.backdropFbo,
    el.brightness,
    el.contrast,
    el.saturation,
    el.useContinuousSdf,
    el.isToggleKnob,
    el.isToggleTrack,
    el.isSliderFill,
    el.isBottomTabContainer,
    el.isBottomTabContent,
    el.isBottomTabIndicator,
    el.sceneBlurRadius,
    el.refractionHeight,
    el.refractionAmount,
    el.depthEffect
  ]);
}
var elementMethods = {
  setElements(configs) {
    this.setButtons(configs);
  },
  setButtons(configs) {
    const prevIds = new Set(this.buttonConfigs.map((b) => b.id));
    const nextIds = new Set(configs.map((b) => b.id));
    for (const id of nextIds)
      if (!prevIds.has(id))
        this.fgDirtyIds.add(id);
    for (const next of configs) {
      const prev = this.buttonConfigs.find((b) => b.id === next.id);
      if (!prev)
        continue;
      const eq4 = (a, b) => {
        if (!a || !b)
          return a === b;
        if (a.length !== b.length)
          return false;
        for (let i = 0;i < a.length; i++)
          if (a[i] !== b[i])
            return false;
        return true;
      };
      const prevTextIcon = prev.text?.icon;
      const nextTextIcon = next.text?.icon;
      const textIconChanged = !!prevTextIcon !== !!nextTextIcon || prevTextIcon && nextTextIcon && (prevTextIcon.path !== nextTextIcon.path || prevTextIcon.size !== nextTextIcon.size || !eq4(prevTextIcon.color, nextTextIcon.color));
      const prevBtnIcon = prev.icon;
      const nextBtnIcon = next.icon;
      const btnIconChanged = !!prevBtnIcon !== !!nextBtnIcon || prevBtnIcon && nextBtnIcon && (prevBtnIcon.path !== nextBtnIcon.path || prevBtnIcon.size !== nextBtnIcon.size || !eq4(prevBtnIcon.color, nextBtnIcon.color));
      const pt = prev.text;
      const nt = next.text;
      const textPropsChanged = !!pt !== !!nt || pt && nt && (!eq4(pt.color, nt.color) || pt.halo !== nt.halo || pt.fontSizePx !== nt.fontSizePx || pt.fontWeight !== nt.fontWeight || pt.align !== nt.align || pt.wrap !== nt.wrap || pt.paddingPx !== nt.paddingPx || pt.valign !== nt.valign || pt.maxLines !== nt.maxLines);
      if (prev.label !== next.label || !eq4(prev.labelColor, next.labelColor) || prev.showChevron !== next.showChevron || prev.rect.w !== next.rect.w || prev.rect.h !== next.rect.h || next.text && prev.text && prev.text.content !== next.text.content || next.text && !prev.text || !next.text && prev.text || textIconChanged || btnIconChanged || textPropsChanged) {
        this.fgDirtyIds.add(next.id);
      }
    }
    for (const id of prevIds) {
      if (!nextIds.has(id)) {
        this.buttonStates.delete(id);
        const tex = this.fgTextures.get(id);
        if (tex) {
          this.gl.deleteTexture(tex);
          this.fgTextures.delete(id);
        }
        this.fgDirtyIds.delete(id);
        this.deleteElFboCacheEntry(id);
      }
    }
    for (const c of configs) {
      if (!this.buttonStates.has(c.id)) {
        const initValue = 0;
        this.buttonStates.set(c.id, {
          pressProgress: 0,
          pressVelocity: 0,
          targetPress: 0,
          dragX: 0,
          dragY: 0,
          dragVx: 0,
          dragVy: 0,
          targetDragX: 0,
          targetDragY: 0,
          startDragX: 0,
          startDragY: 0,
          interactiveValue: initValue,
          interactiveVelocity: 0,
          targetInteractiveValue: initValue
        });
      }
    }
    const prevSigMap = new Map;
    for (const p of this.buttonConfigs)
      prevSigMap.set(p.id, elementCacheSignature(p));
    for (const next of configs) {
      const prevSig = prevSigMap.get(next.id);
      if (prevSig !== undefined && prevSig !== elementCacheSignature(next)) {
        this.markElementDirty(next.id);
      }
    }
    const hadIndicator = this.buttonConfigs.some((b) => b.isBottomTabIndicator);
    const hasIndicator = configs.some((b) => b.isBottomTabIndicator);
    if (!hadIndicator && hasIndicator) {
      this.pendingExtraRenders = 1;
    }
    this.buttonConfigs = configs;
    this.requestRender();
  },
  setInteractiveValue(id, value) {
    const st = this.buttonStates.get(id);
    if (!st)
      return;
    if (st.targetInteractiveValue !== value) {
      st.targetInteractiveValue = value;
      this.markElementDirty(id);
      this.startAnimation();
      this.requestRender();
    }
  },
  setPressed(id, pressed, position) {
    const st = this.buttonStates.get(id);
    if (!st)
      return;
    if (pressed) {
      const btn = this.buttonConfigs.find((b) => b.id === id);
      if (btn && position) {
        const localX = position.x - btn.rect.x;
        const localY = position.y - btn.rect.y;
        if (st.targetPress === 0) {
          st.startDragX = localX;
          st.startDragY = localY;
          st.dragX = localX;
          st.dragY = localY;
          st.dragVx = 0;
          st.dragVy = 0;
        }
        st.dragX = localX;
        st.dragY = localY;
        st.dragVx = 0;
        st.dragVy = 0;
        st.targetDragX = localX;
        st.targetDragY = localY;
      }
      st.targetPress = 1;
    } else {
      st.targetPress = 0;
      st.targetDragX = st.startDragX;
      st.targetDragY = st.startDragY;
    }
    this.markElementDirty(id);
    this.startAnimation();
  },
  setDragPosition(id, position) {
    const st = this.buttonStates.get(id);
    if (!st || st.targetPress === 0)
      return;
    const btn = this.buttonConfigs.find((b) => b.id === id);
    if (!btn)
      return;
    const localX = position.x - btn.rect.x;
    const localY = position.y - btn.rect.y;
    st.dragX = localX;
    st.dragY = localY;
    st.dragVx = 0;
    st.dragVy = 0;
    st.targetDragX = localX;
    st.targetDragY = localY;
    this.markElementDirty(id);
    this.requestRender();
  }
};

// src/cdn/core/renderer/methods-animation.ts
var animationMethods = {
  startAnimation() {
    if (this.animRafId !== null)
      return;
    let lastTime = performance.now();
    const tick = () => {
      const now = performance.now();
      const dt = Math.min((now - lastTime) / 1000, 0.05);
      lastTime = now;
      let stillAnimating = false;
      for (const [id, st] of this.buttonStates.entries()) {
        let elementDirty = false;
        const pDelta = Math.abs(st.targetPress - st.pressProgress);
        if (pDelta > SPRING_THRESHOLD || Math.abs(st.pressVelocity) > SPRING_THRESHOLD) {
          const r = springStep1D(st.pressProgress, st.pressVelocity, st.targetPress, dt);
          st.pressProgress = r.current;
          st.pressVelocity = r.velocity;
          stillAnimating = true;
          elementDirty = true;
        } else {
          st.pressProgress = st.targetPress;
          st.pressVelocity = 0;
        }
        if (Math.abs(st.targetDragX - st.dragX) > SPRING_THRESHOLD || Math.abs(st.dragVx) > SPRING_THRESHOLD) {
          const r = springStep1D(st.dragX, st.dragVx, st.targetDragX, dt);
          st.dragX = r.current;
          st.dragVx = r.velocity;
          stillAnimating = true;
          elementDirty = true;
        } else {
          st.dragX = st.targetDragX;
          st.dragVx = 0;
        }
        if (Math.abs(st.targetDragY - st.dragY) > SPRING_THRESHOLD || Math.abs(st.dragVy) > SPRING_THRESHOLD) {
          const r = springStep1D(st.dragY, st.dragVy, st.targetDragY, dt);
          st.dragY = r.current;
          st.dragVy = r.velocity;
          stillAnimating = true;
          elementDirty = true;
        } else {
          st.dragY = st.targetDragY;
          st.dragVy = 0;
        }
        const iDelta = Math.abs(st.targetInteractiveValue - st.interactiveValue);
        if (iDelta > SPRING_THRESHOLD || Math.abs(st.interactiveVelocity) > SPRING_THRESHOLD) {
          const r = springStep1D(st.interactiveValue, st.interactiveVelocity, st.targetInteractiveValue, dt);
          st.interactiveValue = r.current;
          st.interactiveVelocity = r.velocity;
          stillAnimating = true;
          elementDirty = true;
        } else {
          st.interactiveValue = st.targetInteractiveValue;
          st.interactiveVelocity = 0;
        }
        if (elementDirty)
          this.markElementDirty(id);
      }
      for (const [groupId, tg] of this.toggleStates) {
        let groupDirty = false;
        if (tg.targetPress === 1 && !tg.isDragging && Math.abs(tg.targetFraction - tg.fraction) < 0.02) {
          tg.targetPress = 0;
          tg.targetScaleX = 1;
          tg.targetScaleY = 1;
          groupDirty = true;
          this.startAnimation();
        }
        const fDelta = Math.abs(tg.targetFraction - tg.fraction);
        if (fDelta > SPRING_THRESHOLD || Math.abs(tg.fractionVelocity) > SPRING_THRESHOLD) {
          const r = springStepCritical(tg.fraction, tg.fractionVelocity, tg.targetFraction, dt, TOGGLE_VALUE_OMEGA_N);
          tg.fraction = r.current;
          tg.fractionVelocity = r.velocity;
          if (tg.trackVelocityAfterRelease || tg.isDragging) {
            const nowMs = performance.now();
            tg.velocityTracker.addPosition(nowMs, tg.fraction);
            const tracked = tg.velocityTracker.calculateVelocity();
            const span = tg.valueRangeSpan || 1;
            tg.targetVelocity = tracked / span;
          }
          stillAnimating = true;
          groupDirty = true;
        } else {
          tg.fraction = tg.targetFraction;
          tg.fractionVelocity = 0;
          if (!tg.isDragging) {
            tg.targetVelocity = 0;
            tg.trackVelocityAfterRelease = false;
            tg.velocityTracker.resetTracking();
          }
        }
        const ppDelta = Math.abs(tg.targetPress - tg.pressProgress);
        if (ppDelta > SPRING_THRESHOLD || Math.abs(tg.pressVelocity) > SPRING_THRESHOLD) {
          const r = springStepCritical(tg.pressProgress, tg.pressVelocity, tg.targetPress, dt, TOGGLE_VALUE_OMEGA_N);
          tg.pressProgress = r.current;
          tg.pressVelocity = r.velocity;
          stillAnimating = true;
          groupDirty = true;
        } else {
          tg.pressProgress = tg.targetPress;
          tg.pressVelocity = 0;
        }
        const sx = Math.abs(tg.targetScaleX - tg.scaleX);
        if (sx > SPRING_THRESHOLD || Math.abs(tg.scaleXVelocity) > SPRING_THRESHOLD) {
          const r = springStepUnderdamped(tg.scaleX, tg.scaleXVelocity, tg.targetScaleX, dt, TOGGLE_SCALE_X_OMEGA_N, TOGGLE_SCALE_X_DAMPING_RATIO);
          tg.scaleX = r.current;
          tg.scaleXVelocity = r.velocity;
          stillAnimating = true;
          groupDirty = true;
        } else {
          tg.scaleX = tg.targetScaleX;
          tg.scaleXVelocity = 0;
        }
        const sy = Math.abs(tg.targetScaleY - tg.scaleY);
        if (sy > SPRING_THRESHOLD || Math.abs(tg.scaleYVelocity) > SPRING_THRESHOLD) {
          const r = springStepUnderdamped(tg.scaleY, tg.scaleYVelocity, tg.targetScaleY, dt, TOGGLE_SCALE_Y_OMEGA_N, TOGGLE_SCALE_Y_DAMPING_RATIO);
          tg.scaleY = r.current;
          tg.scaleYVelocity = r.velocity;
          stillAnimating = true;
          groupDirty = true;
        } else {
          tg.scaleY = tg.targetScaleY;
          tg.scaleYVelocity = 0;
        }
        const vDelta = Math.abs(tg.targetVelocity - tg.velocity);
        if (vDelta > SPRING_THRESHOLD || Math.abs(tg.velocityVelocity) > SPRING_THRESHOLD) {
          const r = springStepUnderdamped(tg.velocity, tg.velocityVelocity, tg.targetVelocity, dt, TOGGLE_VELOCITY_OMEGA_N, TOGGLE_VELOCITY_DAMPING_RATIO);
          tg.velocity = r.current;
          tg.velocityVelocity = r.velocity;
          stillAnimating = true;
          groupDirty = true;
        } else {
          tg.velocity = tg.targetVelocity;
          tg.velocityVelocity = 0;
        }
        const poDelta = Math.abs(tg.targetPanelOffset - tg.panelOffset);
        if (poDelta > SPRING_THRESHOLD || Math.abs(tg.panelOffsetVelocity) > SPRING_THRESHOLD) {
          const r = springStepCritical(tg.panelOffset, tg.panelOffsetVelocity, tg.targetPanelOffset, dt, Math.sqrt(300));
          tg.panelOffset = r.current;
          tg.panelOffsetVelocity = r.velocity;
          stillAnimating = true;
          groupDirty = true;
        } else {
          tg.panelOffset = tg.targetPanelOffset;
          tg.panelOffsetVelocity = 0;
        }
        if (groupDirty)
          this.markGroupDirty(groupId);
      }
      if (Math.abs(this.scrollVelocity) > 0.5) {
        const SCROLL_DECAY = 4;
        const newScrollY = this.scrollY + this.scrollVelocity * dt;
        const clamped = this.clampScrollValue(newScrollY);
        if (clamped !== newScrollY) {
          this.scrollY = clamped;
          this.scrollVelocity = 0;
        } else {
          this.scrollY = clamped;
          this.scrollVelocity *= Math.exp(-SCROLL_DECAY * dt);
        }
        stillAnimating = true;
      } else {
        this.scrollVelocity = 0;
      }
      if (stillAnimating) {
        this.requestRender();
        this.animRafId = requestAnimationFrame(tick);
      } else {
        this.requestRender();
        this.animRafId = null;
      }
    };
    this.animRafId = requestAnimationFrame(tick);
  },
  requestRender() {
    this.needsRedraw = true;
    if (this.rafId !== null)
      return;
    this.rafId = requestAnimationFrame(() => {
      this.rafId = null;
      this.render();
    });
  }
};

// src/cdn/core/renderer/methods-raster.ts
var rasterMethods = {
  rasterizeForeground(cfg) {
    if (cfg.kind === "text" && cfg.text) {
      this.rasterizeText(cfg);
      return;
    }
    if (cfg.kind !== "button" && !cfg.label && !cfg.icon) {
      this.fgDirtyIds.delete(cfg.id);
      return;
    }
    const dpr = this.dpr;
    const w = Math.max(1, Math.round(cfg.rect.w * dpr));
    const h = Math.max(1, Math.round(cfg.rect.h * dpr));
    if (this.fgCanvas.width !== w)
      this.fgCanvas.width = w;
    if (this.fgCanvas.height !== h)
      this.fgCanvas.height = h;
    const ctx = this.fgCtx;
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, w, h);
    ctx.scale(dpr, dpr);
    const cssW = cfg.rect.w;
    const cssH = cfg.rect.h;
    if (cfg.icon) {
      const iconSize = cfg.icon.size;
      const ic = cfg.icon.color;
      ctx.save();
      ctx.translate(cssW / 2 - iconSize / 2, cssH / 2 - iconSize / 2);
      const vp = cfg.icon.viewport ?? 24;
      ctx.scale(iconSize / vp, iconSize / vp);
      const p = new Path2D(cfg.icon.path);
      ctx.fillStyle = `rgba(${Math.round(ic[0] * 255)}, ${Math.round(ic[1] * 255)}, ${Math.round(ic[2] * 255)}, ${ic[3]})`;
      ctx.fill(p);
      ctx.restore();
      this.uploadForegroundTexture(cfg.id);
      this.fgDirtyIds.delete(cfg.id);
      return;
    }
    const fontPx = cfg.labelFontSizePx ?? cssH * (15 / 48);
    const fontFamily = '-apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
    ctx.font = `400 ${fontPx}px ${fontFamily}`;
    ctx.textBaseline = "middle";
    ctx.textAlign = "center";
    const colorStr = `rgba(${Math.round(cfg.labelColor[0] * 255)}, ${Math.round(cfg.labelColor[1] * 255)}, ${Math.round(cfg.labelColor[2] * 255)}, ${cfg.labelColor[3]})`;
    const haloIsLight = cfg.labelColor[0] + cfg.labelColor[1] + cfg.labelColor[2] < 1.5;
    ctx.save();
    ctx.shadowColor = haloIsLight ? "rgba(255,255,255,0.45)" : "rgba(0,0,0,0.15)";
    ctx.shadowBlur = haloIsLight ? fontPx * 0.12 : fontPx * 0.05;
    ctx.fillStyle = colorStr;
    ctx.fillText(cfg.label, cssW / 2, cssH / 2 + 0.5);
    ctx.restore();
    if (cfg.showChevron) {
      const chevronSize = fontPx * 0.93;
      const labelWidth = ctx.measureText(cfg.label).width;
      const cx = cssW / 2 + labelWidth / 2 + fontPx * 0.53 + chevronSize / 2;
      const cy = cssH / 2;
      ctx.save();
      ctx.strokeStyle = colorStr;
      ctx.globalAlpha = 0.6;
      ctx.lineWidth = fontPx * 0.107;
      ctx.lineCap = "round";
      ctx.lineJoin = "round";
      ctx.beginPath();
      ctx.moveTo(cx - chevronSize * 0.3, cy - chevronSize * 0.4);
      ctx.lineTo(cx + chevronSize * 0.2, cy);
      ctx.lineTo(cx - chevronSize * 0.3, cy + chevronSize * 0.4);
      ctx.stroke();
      ctx.restore();
    }
    this.uploadForegroundTexture(cfg.id);
    this.fgDirtyIds.delete(cfg.id);
  },
  rasterizeText(cfg) {
    if (!cfg.text)
      return;
    const dpr = this.dpr;
    const w = Math.max(1, Math.round(cfg.rect.w * dpr));
    const h = Math.max(1, Math.round(cfg.rect.h * dpr));
    if (this.fgCanvas.width !== w)
      this.fgCanvas.width = w;
    if (this.fgCanvas.height !== h)
      this.fgCanvas.height = h;
    const ctx = this.fgCtx;
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, w, h);
    ctx.scale(dpr, dpr);
    const t = cfg.text;
    const cssW = cfg.rect.w;
    const cssH = cfg.rect.h;
    const fontFamily = '-apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
    ctx.font = `${t.fontWeight} ${t.fontSizePx}px ${fontFamily}`;
    ctx.textBaseline = "middle";
    const pad = t.paddingPx ?? 0;
    let halo = "none";
    if (t.halo === "light")
      halo = "light";
    else if (t.halo === "dark")
      halo = "dark";
    else if (t.halo === "auto" || t.halo === undefined) {
      const bright = t.color[0] + t.color[1] + t.color[2];
      halo = bright < 1.5 ? "light" : "dark";
    }
    if (halo === "light") {
      ctx.shadowColor = "rgba(255,255,255,0.55)";
      ctx.shadowBlur = t.fontSizePx * 0.16;
    } else if (halo === "dark") {
      ctx.shadowColor = "rgba(0,0,0,0.28)";
      ctx.shadowBlur = t.fontSizePx * 0.1;
    } else {
      ctx.shadowColor = "transparent";
      ctx.shadowBlur = 0;
    }
    const colorStr = `rgba(${Math.round(t.color[0] * 255)}, ${Math.round(t.color[1] * 255)}, ${Math.round(t.color[2] * 255)}, ${t.color[3]})`;
    ctx.fillStyle = colorStr;
    let textYOffset = 0;
    if (t.icon) {
      const iconDrawSize = t.icon.size;
      const iconLayoutSize = t.icon.layoutSize ?? iconDrawSize;
      const gap = t.content ? 2 : 0;
      const totalBlockH = iconLayoutSize + gap + (t.content ? t.fontSizePx : 0);
      const blockTop = cssH / 2 - totalBlockH / 2;
      const iconCx = cssW / 2;
      const iconCy = blockTop + iconLayoutSize / 2;
      ctx.save();
      ctx.translate(iconCx - iconDrawSize / 2, iconCy - iconDrawSize / 2);
      const vp = t.icon.viewport ?? 24;
      ctx.scale(iconDrawSize / vp, iconDrawSize / vp);
      const p = new Path2D(t.icon.path);
      const ic = t.icon.color;
      ctx.fillStyle = `rgba(${Math.round(ic[0] * 255)}, ${Math.round(ic[1] * 255)}, ${Math.round(ic[2] * 255)}, ${ic[3]})`;
      ctx.fill(p);
      ctx.restore();
      textYOffset = (iconLayoutSize + gap) / 2;
    }
    if (t.align === "center") {
      ctx.textAlign = "center";
      if (t.wrap) {
        let lines = wrapText(ctx, t.content, cssW - pad * 2);
        if (t.maxLines != null && lines.length > t.maxLines) {
          lines = lines.slice(0, t.maxLines);
        }
        const lineH = t.fontSizePx * 1.35;
        const totalH = lineH * lines.length;
        let y;
        if (t.valign === "top") {
          y = lineH / 2 + textYOffset;
        } else if (t.valign === "bottom") {
          y = cssH - totalH + lineH / 2 + textYOffset;
        } else {
          y = cssH / 2 - totalH / 2 + lineH / 2 + textYOffset;
        }
        for (const line of lines) {
          ctx.fillText(line, cssW / 2, y);
          y += lineH;
        }
      } else {
        ctx.fillText(t.content, cssW / 2, cssH / 2 + 0.5 + textYOffset);
      }
    } else if (t.align === "left") {
      ctx.textAlign = "left";
      if (t.wrap) {
        let lines = wrapText(ctx, t.content, cssW - pad * 2);
        if (t.maxLines != null && lines.length > t.maxLines) {
          lines = lines.slice(0, t.maxLines);
        }
        const lineH = t.fontSizePx * 1.35;
        const totalH = lineH * lines.length;
        let y;
        if (t.valign === "top") {
          y = lineH / 2 + textYOffset;
        } else if (t.valign === "bottom") {
          y = cssH - totalH + lineH / 2 + textYOffset;
        } else {
          y = cssH / 2 - totalH / 2 + lineH / 2 + textYOffset;
        }
        for (const line of lines) {
          ctx.fillText(line, pad, y);
          y += lineH;
        }
      } else {
        ctx.fillText(t.content, pad, cssH / 2 + 0.5 + textYOffset);
      }
    } else {
      ctx.textAlign = "right";
      ctx.fillText(t.content, cssW - pad, cssH / 2 + 0.5 + textYOffset);
    }
    this.uploadForegroundTexture(cfg.id);
    this.fgDirtyIds.delete(cfg.id);
  },
  uploadForegroundTexture(id) {
    const gl = this.gl;
    let tex = this.fgTextures.get(id);
    if (!tex) {
      tex = gl.createTexture();
      this.fgTextures.set(id, tex);
    }
    gl.bindTexture(gl.TEXTURE_2D, tex);
    gl.pixelStorei(gl.UNPACK_PREMULTIPLY_ALPHA_WEBGL, true);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, this.fgCanvas);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    gl.pixelStorei(gl.UNPACK_PREMULTIPLY_ALPHA_WEBGL, false);
  }
};

// src/cdn/core/renderer/methods-render-glass-geometry.ts
function computeScissorMarginCss(el, layerScale, toggles) {
  const FLOOR_CSS = 3;
  if (!el.outerShadow || el.outerShadow.radius <= 0.5 || !toggles.outerShadow) {
    return FLOOR_CSS;
  }
  const radius = el.outerShadow.radius;
  const maxOffset = Math.max(Math.abs(el.outerShadow.offsetX), Math.abs(el.outerShadow.offsetY));
  const shadowReachCss = (radius + maxOffset) * layerScale;
  return Math.max(FLOOR_CSS, shadowReachCss + 2);
}
function inflatedOutputRect(el, x, y, w, h, togglePressProgress = 0) {
  const mod = el.isToggleKnob || el.isBottomTabIndicator ? Math.max(0, Math.min(1, togglePressProgress)) : 1;
  let blur = (el.blurRadius || 0) * mod;
  let shadow = 0;
  if (el.outerShadow && el.outerShadow.alpha * mod >= 0.15) {
    shadow = (el.outerShadow.radius + Math.max(Math.abs(el.outerShadow.offsetX), Math.abs(el.outerShadow.offsetY))) * mod;
  }
  if (el.isToggleKnob) {
    blur = (el.blurRadius || 0) * (1 - mod) * 0 + 8 * (1 - mod);
  }
  const m = Math.max(blur, shadow, 3) + 4;
  let rx = x - m, ry = y - m, rw = w + 2 * m, rh = h + 2 * m;
  const rot = el.elementRotation ?? 0;
  if (Math.abs(rot) > 0.001) {
    const cx = x + w / 2;
    const cy = y + h / 2;
    const cosA = Math.abs(Math.cos(rot));
    const sinA = Math.abs(Math.sin(rot));
    const rotW = rw * cosA + rh * sinA;
    const rotH = rw * sinA + rh * cosA;
    rx = cx - rotW / 2;
    ry = cy - rotH / 2;
    rw = rotW;
    rh = rotH;
  }
  return { x: rx, y: ry, w: rw, h: rh };
}
function shadowBboxCss(el, x, y, w, h, layerScaleX, layerScaleY, toggles) {
  if (!el.outerShadow || el.outerShadow.radius <= 0.5)
    return null;
  if (!toggles.outerShadow)
    return null;
  const r = el.outerShadow.radius;
  const ox = el.outerShadow.offsetX;
  const oy = el.outerShadow.offsetY;
  const left = Math.max(0, r - ox) * layerScaleX;
  const right = Math.max(0, r + ox) * layerScaleX;
  const top = Math.max(0, r - oy) * layerScaleY;
  const bottom = Math.max(0, r + oy) * layerScaleY;
  return {
    x: x - left,
    y: y - top,
    w: w + left + right,
    h: h + top + bottom
  };
}
function rectsOverlap(a, b) {
  return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
}

// src/cdn/core/renderer/methods-render-glass-transform.ts
function computeElementTransform(el, st, r) {
  const isButton = el.kind === "button";
  const p = st?.pressProgress ?? 0;
  const PRESS_SCALE_RATIO = 4 / 48;
  let scale = 1;
  let translationX = 0;
  let translationY = 0;
  let scaleX = 1;
  let scaleY = 1;
  if (el.enterProgress != null) {
    const raw = el.enterProgress;
    const derived = raw < 0 ? (1 - Math.exp(-Math.abs(raw))) * -1 : raw <= 1 ? raw : 1 + (1 - Math.exp(-(raw - 1)));
    translationY += -48 * DP * (1 - derived);
    if (el.enterStretchFactor != null && derived > 1) {
      translationY += el.enterStretchFactor * (derived - 1) * 32 * DP;
    }
    const sFactor = 1 + 0.1 * Math.max(0, derived - 1);
    scaleX /= sFactor;
    scaleY *= sFactor;
  }
  if (isButton && el.isInteractive && st) {
    const width = el.rect.w;
    const height = el.rect.h;
    const maxDim = Math.max(width, height);
    const minDim = Math.min(width, height);
    const maxOffset = minDim;
    const initialDerivative = 0.05;
    const maxDragScale = PRESS_SCALE_RATIO;
    scale = 1 + PRESS_SCALE_RATIO * p;
    const dx = st.dragX - st.startDragX;
    const dy = st.dragY - st.startDragY;
    translationX = maxOffset * Math.tanh(initialDerivative * dx / maxOffset);
    translationY = maxOffset * Math.tanh(initialDerivative * dy / maxOffset);
    const offsetAngle = Math.atan2(dy, dx);
    const whCap = Math.min(width / height, 1);
    const hwCap = Math.min(height / width, 1);
    scaleX = scale + maxDragScale * Math.abs(Math.cos(offsetAngle) * dx / maxDim) * whCap;
    scaleY = scale + maxDragScale * Math.abs(Math.sin(offsetAngle) * dy / maxDim) * hwCap;
  } else if (el.enterProgress == null) {
    scaleX = scale;
    scaleY = scale;
  }
  let toggleXOffset = 0;
  let toggleScaleX = 1;
  let toggleScaleY = 1;
  let togglePressProgress = 0;
  if (el.isToggleKnob) {
    const tg = this.toggleStates.get(el.isToggleKnob.groupId);
    if (tg) {
      toggleXOffset = tg.fraction * el.isToggleKnob.dragWidth;
      toggleScaleX = tg.scaleX;
      toggleScaleY = tg.scaleY;
      togglePressProgress = tg.pressProgress;
      const divisor = el.isToggleKnob.velocityDivisor ?? 50;
      const vel = tg.velocity / divisor;
      const velX = Math.max(-0.2, Math.min(0.2, vel * 0.75));
      const velY = Math.max(-0.2, Math.min(0.2, vel * 0.25));
      toggleScaleX = toggleScaleX / (1 - velX);
      toggleScaleY = toggleScaleY * (1 - velY);
    }
  }
  scaleX *= toggleScaleX;
  scaleY *= toggleScaleY;
  if (el.isBottomTabContainer) {
    const tg = this.toggleStates.get(el.isBottomTabContainer.groupId);
    if (tg) {
      const containerScale = 1 + 16 * DP / el.rect.w * tg.pressProgress;
      scaleX *= containerScale;
      scaleY *= containerScale;
      translationX += tg.panelOffset;
      togglePressProgress = tg.pressProgress;
    }
  }
  if (el.isBottomTabContent) {
    const tg = this.toggleStates.get(el.isBottomTabContent.groupId);
    if (tg) {
      const containerW = el.isBottomTabContent.containerWidth ?? el.rect.w;
      const containerScale = 1 + 16 * DP / containerW * tg.pressProgress;
      scaleX *= containerScale;
      const contentScale = 1 + 0.2 * tg.pressProgress;
      scaleX *= contentScale;
      scaleY *= containerScale * contentScale;
      translationX += tg.panelOffset;
    }
  }
  if (el.isBottomTabIndicator) {
    const tg = this.toggleStates.get(el.isBottomTabIndicator.groupId);
    if (tg) {
      toggleXOffset += tg.fraction * el.isBottomTabIndicator.dragWidth;
      toggleXOffset += tg.panelOffset;
      const indScaleX = tg.scaleX;
      const indScaleY = tg.scaleY;
      const vel = tg.velocity / 10;
      const velX = Math.max(-0.2, Math.min(0.2, vel * 0.75));
      const velY = Math.max(-0.2, Math.min(0.2, vel * 0.25));
      const finalIndScaleX = indScaleX / (1 - velX);
      const finalIndScaleY = indScaleY * (1 - velY);
      scaleX *= finalIndScaleX;
      scaleY *= finalIndScaleY;
      togglePressProgress = Math.max(togglePressProgress, tg.pressProgress);
    }
  }
  if (el.elementScaleX != null)
    scaleX *= el.elementScaleX;
  if (el.elementScaleY != null)
    scaleY *= el.elementScaleY;
  const cx = r.x + el.rect.w / 2 + translationX + toggleXOffset;
  const cy = r.y + el.rect.h / 2 + translationY;
  const sw = el.rect.w * scaleX;
  const sh = el.rect.h * scaleY;
  const sx = cx - sw / 2;
  const sy = cy - sh / 2;
  const cornerRadius = el.cornerRadius * Math.min(scaleX, scaleY);
  const radii = [
    cornerRadius,
    cornerRadius,
    cornerRadius,
    cornerRadius
  ];
  const eligibleForDirect = el.independentBackdrop || el.directBackdropSample && this.directBackdropSample;
  const independent = !!(eligibleForDirect && !this.backgroundColor && this.wallpaperTexture);
  return {
    sx,
    sy,
    sw,
    sh,
    radii,
    scaleX,
    scaleY,
    isButton,
    p,
    togglePressProgress,
    translationX,
    translationY,
    independent
  };
}

// src/cdn/core/renderer/methods-render-glass-backdrop.ts
function shouldUseSeparableBlur(el, state) {
  if (el.isToggleKnob || el.isBottomTabIndicator)
    return false;
  if (el.blurRadius < 0.5)
    return false;
  if (el.sampleWallpaper)
    return false;
  if (el.isSdfTexture && !el.isSdfTexture.useSeparableBlur)
    return false;
  return true;
}
function buildGlassRenderState(args) {
  const { el, st, transform, usePerElementFbo, sceneRectOffsetX, sceneRectOffsetY, elFboW, elFboH } = args;
  const {
    sx,
    sy,
    sw,
    sh,
    radii,
    scaleX,
    scaleY,
    isButton,
    p,
    togglePressProgress,
    independent
  } = transform;
  return {
    el,
    st,
    isButton,
    p,
    sx,
    sy,
    sw,
    sh,
    radii,
    togglePressProgress,
    elHighlightAlpha: el.isToggleKnob || el.isBottomTabIndicator ? (el.highlight ? el.highlight.alpha : 0) * togglePressProgress : el.highlight ? el.highlight.alpha : 0,
    enterAlpha: el.enterProgress != null ? easeIn(el.enterSafeProgress != null ? Math.max(0, Math.min(1, el.enterSafeProgress)) : Math.max(0, Math.min(1, el.enterProgress))) : 1,
    layerScaleX: scaleX,
    layerScaleY: scaleY,
    layerScale: Math.min(scaleX, scaleY),
    origW: el.rect.w,
    origH: el.rect.h,
    origCornerRadius: el.cornerRadius,
    elementRotation: el.elementRotation ?? 0,
    independent,
    usePerElementFbo,
    sceneRectOffsetX,
    sceneRectOffsetY,
    elFboW,
    elFboH
  };
}
function resolveBackdropTex(state, curTex, outFbo) {
  const { el, independent, sx, sy, sw, sh, layerScale } = state;
  if (independent && shouldUseSeparableBlur(el, state) && this.quickToggles.backdropBlur) {
    const gl = this.gl;
    const blurRadiusPx = el.blurRadius * layerScale * this.dpr;
    const cssRadius = el.blurRadius * layerScale;
    const qRadius = Math.round(cssRadius * 10) / 10;
    const cacheKey = this.useBlurCache ? `wallpaper_${qRadius}_${this.useKawaseBlur ? "k" : "g"}` : null;
    const entry = cacheKey ? this.backdropBlurCache.get(cacheKey) : undefined;
    let blurred;
    let cacheHit = false;
    if (entry) {
      blurred = entry.tex;
      cacheHit = true;
      this.lastBlurStats = { type: entry.blurType, passes: 0, taps: 0, maxSample: 0, w: entry.w, h: entry.h, progMs: 0, stateMs: 0, drawMs: 0 };
    } else if (!cacheKey) {
      blurred = this.blurTexture(this.wallpaperBlurTex, blurRadiusPx);
    } else {
      if (this._blurCacheMissesThisFrame >= this.blurCacheMissesPerFrame) {
        return { backdropTex: curTex, didBlur: false };
      }
      this._blurCacheMissesThisFrame++;
      const t0 = performance.now();
      const blurResult = this.blurTexture(this.wallpaperBlurTex, blurRadiusPx);
      const t1 = performance.now();
      const progMs = this.lastBlurStats?.progMs ?? 0;
      const stateMs = this.lastBlurStats?.stateMs ?? 0;
      const drawMs = this.lastBlurStats?.drawMs ?? t1 - t0;
      const blurW = this.lastBlurStats?.w ?? this.dsBlurFboW ?? this.fboW;
      const blurH = this.lastBlurStats?.h ?? this.dsBlurFboH ?? this.fboH;
      const cacheFbo = this.acquireCacheFBO(blurW, blurH);
      const gl2 = this.gl;
      const savedFb = gl2.getParameter(gl2.FRAMEBUFFER_BINDING);
      const savedScissor = gl2.isEnabled(gl2.SCISSOR_TEST);
      const savedBox = gl2.getParameter(gl2.SCISSOR_BOX);
      gl2.disable(gl2.SCISSOR_TEST);
      if (!this.cacheCopyReadFbo)
        this.cacheCopyReadFbo = gl2.createFramebuffer();
      gl2.bindFramebuffer(gl2.FRAMEBUFFER, this.cacheCopyReadFbo);
      gl2.framebufferTexture2D(gl2.FRAMEBUFFER, gl2.COLOR_ATTACHMENT0, gl2.TEXTURE_2D, blurResult, 0);
      gl2.activeTexture(gl2.TEXTURE0);
      gl2.bindTexture(gl2.TEXTURE_2D, null);
      gl2.bindTexture(gl2.TEXTURE_2D, cacheFbo.tex);
      gl2.copyTexImage2D(gl2.TEXTURE_2D, 0, gl2.RGBA, 0, 0, blurW, blurH, 0);
      if (this.showBlurCacheCheckerboard) {
        gl2.bindFramebuffer(gl2.FRAMEBUFFER, cacheFbo.fb);
        gl2.viewport(0, 0, blurW, blurH);
        const cellSize = Math.max(8, Math.floor(blurW / 20));
        gl2.enable(gl2.SCISSOR_TEST);
        gl2.clearColor(0, 0, 0, 0);
        for (let cy = 0;cy < blurH; cy += cellSize) {
          for (let cx = 0;cx < blurW; cx += cellSize) {
            if ((Math.floor(cx / cellSize) + Math.floor(cy / cellSize)) % 2 !== 0) {
              gl2.scissor(cx, cy, Math.min(cellSize, blurW - cx), Math.min(cellSize, blurH - cy));
              gl2.clear(gl2.COLOR_BUFFER_BIT);
            }
          }
        }
        gl2.disable(gl2.SCISSOR_TEST);
      }
      const t2 = performance.now();
      const blurMs = t1 - t0;
      const copyMs = t2 - t1;
      let readPixelsMs = 0;
      let scanMs = 0;
      let snapW = 0, snapH = 0;
      let snapBuf = new Uint8Array(0);
      let snapNZ = 0;
      let minX = 0, minY = 0, maxX = 0, maxY = 0;
      if (this.showBlurCachePreview) {
        snapW = blurW;
        snapH = blurH;
        const ta = performance.now();
        snapBuf = new Uint8Array(snapW * snapH * 4);
        const tr0 = performance.now();
        gl2.bindFramebuffer(gl2.FRAMEBUFFER, cacheFbo.fb);
        gl2.readPixels(0, 0, snapW, snapH, gl2.RGBA, gl2.UNSIGNED_BYTE, snapBuf);
        const tr1 = performance.now();
        for (let y = 0;y < snapH; y++) {
          for (let x = 0;x < snapW; x++) {
            const i = (y * snapW + x) * 4;
            if (snapBuf[i] + snapBuf[i + 1] + snapBuf[i + 2] + snapBuf[i + 3] > 0) {
              snapNZ++;
              if (x < minX || snapNZ === 1)
                minX = x;
              if (x > maxX)
                maxX = x;
              if (y < minY || snapNZ === 1)
                minY = y;
              if (y > maxY)
                maxY = y;
            }
          }
        }
        const ts = performance.now();
        readPixelsMs = tr1 - tr0;
        scanMs = ts - tr1 + (tr0 - ta);
      }
      this.backdropBlurCacheSnapshots.push({
        key: snapW > 0 ? `${cacheKey} [${minX},${minY}-${maxX},${maxY}]` : cacheKey,
        w: snapW,
        h: snapH,
        rgba: snapBuf,
        nonZero: snapNZ,
        progMs,
        stateMs,
        drawMs,
        copyMs,
        readPixelsMs,
        scanMs,
        totalMs: progMs + stateMs + drawMs + copyMs + readPixelsMs + scanMs
      });
      this.bindFBO(savedFb);
      if (savedScissor) {
        gl2.enable(gl2.SCISSOR_TEST);
        gl2.scissor(savedBox[0], savedBox[1], savedBox[2], savedBox[3]);
      }
      this.backdropBlurCache.set(cacheKey, {
        fb: cacheFbo.fb,
        tex: cacheFbo.tex,
        w: blurW,
        h: blurH,
        blurType: this.lastBlurStats?.type ?? "gauss"
      });
      this.evictBackdropBlurCacheIfNeeded();
      blurred = cacheFbo.tex;
    }
    if (this.showBlurDebug) {
      const s = this.lastBlurStats;
      this.debugBlurRegions.push({
        x: sx,
        y: sy,
        w: sw,
        h: sh,
        radius: blurRadiusPx,
        ds: this.effectiveBlurDownsample,
        blurW: this.dsBlurFboW,
        blurH: this.dsBlurFboH,
        blurType: s?.type ?? "gauss",
        passes: s?.passes ?? 0,
        taps: s?.taps ?? 0,
        maxSample: s?.maxSample ?? 0,
        cached: cacheHit
      });
    }
    this.perfMonitor.incBlurPass();
    this.perfMonitor.incDrawCall(3);
    gl.enable(gl.BLEND);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
    this.bindFBO(outFbo);
    gl.viewport(0, 0, this.fboW, this.fboH);
    const passState = { ...state, independent: false };
    return { backdropTex: blurred, passState, didBlur: true };
  }
  if (independent) {
    return { backdropTex: curTex, didBlur: false };
  }
  if (shouldUseSeparableBlur(el, state) && this.quickToggles.backdropBlur) {
    const blurRadiusPx = el.blurRadius * layerScale * this.dpr;
    let backdropSrc;
    if (el.backdropFbo && this.dialogBackdropTex) {
      backdropSrc = this.dialogBackdropTex;
    } else if (this.quickToggles.isolateBackdrop && this.bgOnlyTex) {
      backdropSrc = this.bgOnlyTex;
    } else {
      backdropSrc = curTex;
    }
    const canCacheSceneBlur = this.useBlurCache && backdropSrc === curTex && !el.backdropFbo;
    const isScrolling = this.scrollY !== this.lastRenderedScrollY;
    const cssRadius = el.blurRadius * layerScale;
    const qRadius = Math.round(cssRadius * 10) / 10;
    const sceneCacheKey = canCacheSceneBlur && !isScrolling ? `scene_${el.id}_${qRadius}_${this.useKawaseBlur ? "k" : "g"}` : null;
    let blurred;
    let cacheHit = false;
    if (sceneCacheKey) {
      const entry = this.backdropBlurCache.get(sceneCacheKey);
      if (entry) {
        blurred = entry.tex;
        cacheHit = true;
        this.lastBlurStats = { type: entry.blurType, passes: 0, taps: 0, maxSample: 0, w: entry.w, h: entry.h, progMs: 0, stateMs: 0, drawMs: 0 };
      } else {
        if (this._blurCacheMissesThisFrame >= this.blurCacheMissesPerFrame) {
          return { backdropTex: backdropSrc, didBlur: false };
        }
        this._blurCacheMissesThisFrame++;
        const sT0 = performance.now();
        blurred = this.blurTexture(backdropSrc, blurRadiusPx);
        const sT1 = performance.now();
        const sProgMs = this.lastBlurStats?.progMs ?? 0;
        const sStateMs = this.lastBlurStats?.stateMs ?? 0;
        const sDrawMs = this.lastBlurStats?.drawMs ?? sT1 - sT0;
        const blurW = this.lastBlurStats?.w ?? this.dsBlurFboW ?? this.fboW;
        const blurH = this.lastBlurStats?.h ?? this.dsBlurFboH ?? this.fboH;
        const cacheFbo = this.acquireCacheFBO(blurW, blurH);
        const gl2 = this.gl;
        const savedFb = gl2.getParameter(gl2.FRAMEBUFFER_BINDING);
        const savedSc = gl2.isEnabled(gl2.SCISSOR_TEST);
        const savedBox = gl2.getParameter(gl2.SCISSOR_BOX);
        gl2.disable(gl2.SCISSOR_TEST);
        if (!this.cacheCopyReadFbo)
          this.cacheCopyReadFbo = gl2.createFramebuffer();
        gl2.bindFramebuffer(gl2.FRAMEBUFFER, this.cacheCopyReadFbo);
        gl2.framebufferTexture2D(gl2.FRAMEBUFFER, gl2.COLOR_ATTACHMENT0, gl2.TEXTURE_2D, blurred, 0);
        gl2.activeTexture(gl2.TEXTURE0);
        gl2.bindTexture(gl2.TEXTURE_2D, null);
        gl2.bindTexture(gl2.TEXTURE_2D, cacheFbo.tex);
        gl2.copyTexImage2D(gl2.TEXTURE_2D, 0, gl2.RGBA, 0, 0, blurW, blurH, 0);
        if (this.showBlurCacheCheckerboard) {
          gl2.bindFramebuffer(gl2.FRAMEBUFFER, cacheFbo.fb);
          gl2.viewport(0, 0, blurW, blurH);
          const cellSize = Math.max(8, Math.floor(blurW / 20));
          gl2.enable(gl2.SCISSOR_TEST);
          gl2.clearColor(0, 0, 0, 0);
          for (let cy = 0;cy < blurH; cy += cellSize) {
            for (let cx = 0;cx < blurW; cx += cellSize) {
              if ((Math.floor(cx / cellSize) + Math.floor(cy / cellSize)) % 2 !== 0) {
                gl2.scissor(cx, cy, Math.min(cellSize, blurW - cx), Math.min(cellSize, blurH - cy));
                gl2.clear(gl2.COLOR_BUFFER_BIT);
              }
            }
          }
          gl2.disable(gl2.SCISSOR_TEST);
        }
        const sT2 = performance.now();
        const sCopyMs = sT2 - sT1;
        let sReadPixelsMs = 0;
        let sScanMs = 0;
        let sSnapW = 0, sSnapH = 0;
        let sBuf = new Uint8Array(0);
        let sNZ = 0;
        let sMinX = 0, sMinY = 0, sMaxX = 0, sMaxY = 0;
        if (this.showBlurCachePreview) {
          sSnapW = blurW;
          sSnapH = blurH;
          const sTa = performance.now();
          sBuf = new Uint8Array(sSnapW * sSnapH * 4);
          const sTr0 = performance.now();
          gl2.bindFramebuffer(gl2.FRAMEBUFFER, cacheFbo.fb);
          gl2.readPixels(0, 0, sSnapW, sSnapH, gl2.RGBA, gl2.UNSIGNED_BYTE, sBuf);
          const sTr1 = performance.now();
          for (let y = 0;y < sSnapH; y++) {
            for (let x = 0;x < sSnapW; x++) {
              const i = (y * sSnapW + x) * 4;
              if (sBuf[i] + sBuf[i + 1] + sBuf[i + 2] + sBuf[i + 3] > 0) {
                sNZ++;
                if (x < sMinX || sNZ === 1)
                  sMinX = x;
                if (x > sMaxX)
                  sMaxX = x;
                if (y < sMinY || sNZ === 1)
                  sMinY = y;
                if (y > sMaxY)
                  sMaxY = y;
              }
            }
          }
          const sTs = performance.now();
          sReadPixelsMs = sTr1 - sTr0;
          sScanMs = sTs - sTr1 + (sTr0 - sTa);
        }
        this.backdropBlurCacheSnapshots.push({
          key: sSnapW > 0 ? `${sceneCacheKey} [${sMinX},${sMinY}-${sMaxX},${sMaxY}]` : sceneCacheKey,
          w: sSnapW,
          h: sSnapH,
          rgba: sBuf,
          nonZero: sNZ,
          progMs: sProgMs,
          stateMs: sStateMs,
          drawMs: sDrawMs,
          copyMs: sCopyMs,
          readPixelsMs: sReadPixelsMs,
          scanMs: sScanMs,
          totalMs: sProgMs + sStateMs + sDrawMs + sCopyMs + sReadPixelsMs + sScanMs
        });
        gl2.bindFramebuffer(gl2.FRAMEBUFFER, savedFb);
        if (savedSc) {
          gl2.enable(gl2.SCISSOR_TEST);
          gl2.scissor(savedBox[0], savedBox[1], savedBox[2], savedBox[3]);
        }
        this.backdropBlurCache.set(sceneCacheKey, {
          fb: cacheFbo.fb,
          tex: cacheFbo.tex,
          w: blurW,
          h: blurH,
          blurType: this.lastBlurStats?.type ?? "gauss"
        });
        this.evictBackdropBlurCacheIfNeeded();
        blurred = cacheFbo.tex;
      }
    } else {
      if (this.useBlurCache && this._blurCacheMissesThisFrame >= this.blurCacheMissesPerFrame) {
        return { backdropTex: backdropSrc, didBlur: false };
      }
      if (this.useBlurCache)
        this._blurCacheMissesThisFrame++;
      blurred = this.blurTexture(backdropSrc, blurRadiusPx);
    }
    if (this.showBlurDebug) {
      const s = this.lastBlurStats;
      this.debugBlurRegions.push({
        x: sx,
        y: sy,
        w: sw,
        h: sh,
        radius: blurRadiusPx,
        ds: this.effectiveBlurDownsample,
        blurW: this.dsBlurFboW,
        blurH: this.dsBlurFboH,
        blurType: s?.type ?? "gauss",
        passes: s?.passes ?? 0,
        taps: s?.taps ?? 0,
        maxSample: s?.maxSample ?? 0,
        cached: cacheHit
      });
    }
    this.perfMonitor.incBlurPass();
    this.perfMonitor.incDrawCall(2);
    const gl = this.gl;
    gl.enable(gl.BLEND);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
    this.bindFBO(outFbo);
    gl.viewport(0, 0, this.fboW, this.fboH);
    const passState = el.backdropFbo ? { ...state, el: { ...el, backdropFbo: false } } : state;
    return { backdropTex: blurred, passState, didBlur: true };
  }
  if (this.quickToggles.isolateBackdrop && this.bgOnlyTex && !el.backdropFbo) {
    return { backdropTex: this.bgOnlyTex, didBlur: false };
  }
  return { backdropTex: curTex, didBlur: false };
}

// src/cdn/core/renderer/methods-render-glass-pingpong.ts
function renderGlassElement(el, st, curFbo, curTex, otherFbo, otherTex, r) {
  const gl = this.gl;
  const t = computeElementTransform.call(this, el, st, r);
  const { sx, sy, sw, sh, scaleX, scaleY, togglePressProgress } = t;
  if (this.quickToggles.perElementFbo) {
    this.perfMonitor.incGlassElement();
    this.perfMonitor.incPerElementFbo();
    const elDirty = this.allDirty || this.dirtyElementIds.has(el.id);
    return this.renderGlassElementPerFbo(el, st, curFbo, curTex, otherFbo, otherTex, {
      sx,
      sy,
      sw,
      sh,
      radii: t.radii,
      scaleX,
      scaleY,
      isButton: t.isButton,
      p: t.p,
      togglePressProgress,
      independent: t.independent,
      translationX: t.translationX,
      translationY: t.translationY,
      elDirty
    });
  }
  this._dbgLastGlassCacheHit = false;
  if (this.showDirtyMarkers) {
    this.debugCacheMissLog.push({ id: el.id, reason: "ping_pong", x: sx, y: sy, w: sw, h: sh });
  }
  this.dirtyRectsThisFrame.push({
    ...inflatedOutputRect(el, sx, sy, sw, sh, togglePressProgress),
    source: `pingpong:${el.id}`
  });
  this.perfMonitor.incGlassElement();
  this.perfMonitor.incPingPong();
  this.bindFBO(otherFbo);
  this.drawCopy(curTex);
  this.perfMonitor.incDrawCall();
  gl.enable(gl.BLEND);
  gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
  const MARGIN_CSS = computeScissorMarginCss(el, Math.min(scaleX, scaleY), this.quickToggles);
  const scissorX = Math.max(0, Math.round((sx - MARGIN_CSS) * this.dpr));
  const scissorY = Math.max(0, Math.round((this.cssHeight - (sy + sh + MARGIN_CSS)) * this.dpr));
  const scissorW = Math.min(this.fboW - scissorX, Math.round((sw + 2 * MARGIN_CSS) * this.dpr));
  const scissorH = Math.min(this.fboH - scissorY, Math.round((sh + 2 * MARGIN_CSS) * this.dpr));
  const clip = this.intersectClipScissor(el, scissorX, scissorY, scissorW, scissorH);
  gl.enable(gl.SCISSOR_TEST);
  gl.scissor(clip.x, clip.y, clip.w, clip.h);
  if (this.showPefBbox) {
    const pxX = scissorX / this.dpr;
    const pxY = (this.fboH - scissorY - scissorH) / this.dpr;
    this.debugPefBboxes.push({
      x: pxX,
      y: pxY,
      w: scissorW / this.dpr,
      h: scissorH / this.dpr,
      fbo: false
    });
  }
  const state = buildGlassRenderState({
    el,
    st,
    transform: t,
    usePerElementFbo: false,
    sceneRectOffsetX: 0,
    sceneRectOffsetY: 0,
    elFboW: 0,
    elFboH: 0
  });
  this.renderGlassShadowPass(state);
  const backdrop = resolveBackdropTex.call(this, state, curTex, otherFbo);
  this.renderGlassElementPass(backdrop.passState ?? state, backdrop.backdropTex, backdrop.backdropBbox);
  this.renderGlassPostPasses(state);
  gl.disable(gl.SCISSOR_TEST);
  return {
    curFbo: otherFbo,
    curTex: otherTex,
    otherFbo: curFbo,
    otherTex: curTex
  };
}

// src/cdn/core/renderer/methods-render-glass-pef-geometry.ts
function computeElFboGeometry(el, sx, sy, sw, sh, layerScale) {
  const scissorMarginCss = computeScissorMarginCss(el, layerScale, this.quickToggles);
  const ELFBO_PAD_DEVICE = 2;
  const elFboMarginCss = (ELFBO_PAD_DEVICE + 1) / this.dpr;
  const rawBx0 = Math.round((sx - scissorMarginCss) * this.dpr);
  const rawBy0Top = Math.round((sy - scissorMarginCss) * this.dpr);
  const bx0 = Math.max(0, Math.min(this.fboW, rawBx0));
  const by0Top = Math.max(0, Math.min(this.fboH, rawBy0Top));
  const bboxW = Math.max(0, Math.min(this.fboW - bx0, Math.round((sw + 2 * scissorMarginCss) * this.dpr)));
  const bboxH = Math.max(0, Math.min(this.fboH - by0Top, Math.round((sh + 2 * scissorMarginCss) * this.dpr)));
  const bboxScissorY = Math.max(0, this.fboH - by0Top - bboxH);
  const elFboRectW = Math.max(1, Math.round((el.rect.w + 2 * elFboMarginCss) * this.dpr));
  const elFboRectH = Math.max(1, Math.round((el.rect.h + 2 * elFboMarginCss) * this.dpr));
  const rawEx0 = Math.round((sx - elFboMarginCss) * this.dpr);
  const rawEy0Top = Math.round((sy - elFboMarginCss) * this.dpr);
  const ex0 = rawEx0;
  const ey0Top = rawEy0Top;
  const scissorX = Math.max(0, Math.min(this.fboW, rawEx0));
  const scissorYTop = Math.max(0, Math.min(this.fboH, rawEy0Top));
  const scissorW = Math.max(0, Math.min(this.fboW - scissorX, elFboRectW));
  const scissorH = Math.max(0, Math.min(this.fboH - scissorYTop, elFboRectH));
  const elFboScissorY = Math.max(0, this.fboH - scissorYTop - scissorH);
  const sceneOffsetX = rawEx0;
  const sceneOffsetY = rawEy0Top;
  return {
    bx0,
    by0Top,
    bboxW,
    bboxH,
    bboxScissorY,
    elFboRectW,
    elFboRectH,
    ex0,
    ey0Top,
    scissorX,
    scissorYTop,
    scissorW,
    scissorH,
    elFboScissorY,
    sceneOffsetX,
    sceneOffsetY,
    scissorMarginCss
  };
}
// src/cdn/core/renderer/methods-render-glass-pef-cache-flags.ts
function computeCacheFlags(el) {
  const cacheable = !!(this.wallpaperTexture && !el.backdropFbo);
  const positionInvariant = !!(el.isToggleKnob?.solidBackdropColor && !el.backdropFbo);
  const scrollInvariant = !!(el.isToggleKnob && !el.isToggleKnob.solidBackdropColor && !el.isToggleKnob.trackColorOff && this.backgroundColor && !el.backdropFbo);
  return { cacheable, positionInvariant, scrollInvariant };
}
// src/cdn/core/renderer/methods-render-glass-pef-cache-resolve.ts
function resolveElFboCache(el, state, geom, flags) {
  const { sx, sy, sw, sh, togglePressProgress, independent } = state;
  const { elFboRectW, elFboRectH, sceneOffsetX, sceneOffsetY } = geom;
  const { cacheable, positionInvariant, scrollInvariant } = flags;
  const gl = this.gl;
  if (!cacheable) {
    if (this.showDirtyMarkers) {
      const ncReason = !this.wallpaperTexture ? "non_cacheable:no_wp" : el.backdropFbo ? "non_cacheable:backdropFbo" : "non_cacheable:unknown";
      this.debugCacheMissLog.push({ id: el.id, reason: ncReason, x: sx, y: sy, w: sw, h: sh });
    }
    const ensured = this.ensureElementFBO(elFboRectW, elFboRectH);
    return {
      cacheHit: false,
      cacheWrite: false,
      renderFbo: this.elFbo,
      renderTex: this.elFboTex,
      elFboW: ensured.w,
      elFboH: ensured.h
    };
  }
  const entry = this.elFboCache.get(el.id);
  let missReason = null;
  const skipPosition = positionInvariant || scrollInvariant;
  if (!entry) {
    missReason = "no_entry";
  } else if (entry.w !== elFboRectW || entry.h !== elFboRectH) {
    missReason = "size_mismatch";
  } else if (!skipPosition && (entry.ex0 !== sceneOffsetX || entry.ey0Top !== sceneOffsetY)) {
    missReason = "position_mismatch";
  } else if (!entry.valid) {
    missReason = "invalidated";
  } else if (entry.wallpaperVersion !== this.wallpaperVersion) {
    missReason = "wallpaper_version";
  } else if (entry.dpr !== this.dpr) {
    missReason = "dpr";
  } else if (!positionInvariant && !independent) {
    const myRect = inflatedOutputRect(el, sx, sy, sw, sh, togglePressProgress);
    const overlap = this.dirtyRectsThisFrame.find((r) => rectsOverlap(r, myRect) && !(scrollInvariant && r.source === "scroll"));
    if (overlap) {
      missReason = `backdrop_overlap:${overlap.source}`;
    }
  }
  if (missReason && this.showDirtyMarkers) {
    this.debugCacheMissLog.push({ id: el.id, reason: missReason, x: sx, y: sy, w: sw, h: sh });
  }
  if (entry && missReason === null) {
    if (positionInvariant || scrollInvariant) {
      entry.ex0 = sceneOffsetX;
      entry.ey0Top = sceneOffsetY;
    }
    this.perfMonitor.incCachedElement();
    return {
      cacheHit: true,
      cacheWrite: false,
      renderFbo: entry.fb,
      renderTex: entry.tex,
      elFboW: entry.w,
      elFboH: entry.h
    };
  }
  if (!entry) {
    const created = this.createFBO(elFboRectW, elFboRectH);
    this.elFboCache.set(el.id, {
      fb: created.fb,
      tex: created.tex,
      w: elFboRectW,
      h: elFboRectH,
      ex0: sceneOffsetX,
      ey0Top: sceneOffsetY,
      valid: false,
      wallpaperVersion: this.wallpaperVersion,
      dpr: this.dpr
    });
  } else if (entry.w !== elFboRectW || entry.h !== elFboRectH) {
    gl.deleteFramebuffer(entry.fb);
    gl.deleteTexture(entry.tex);
    const created = this.createFBO(elFboRectW, elFboRectH);
    entry.fb = created.fb;
    entry.tex = created.tex;
    entry.w = elFboRectW;
    entry.h = elFboRectH;
  }
  const e = this.elFboCache.get(el.id);
  e.ex0 = sceneOffsetX;
  e.ey0Top = sceneOffsetY;
  e.valid = false;
  e.wallpaperVersion = this.wallpaperVersion;
  e.dpr = this.dpr;
  return {
    cacheHit: false,
    cacheWrite: true,
    renderFbo: e.fb,
    renderTex: e.tex,
    elFboW: e.w,
    elFboH: e.h
  };
}
// src/cdn/core/renderer/methods-render-glass-pef.ts
function renderGlassElementPerFbo(el, st, curFbo, curTex, otherFbo, otherTex, computed) {
  const gl = this.gl;
  const layerScale = Math.min(computed.scaleX, computed.scaleY);
  const geom = computeElFboGeometry.call(this, el, computed.sx, computed.sy, computed.sw, computed.sh, layerScale);
  const rot = el.elementRotation ?? 0;
  const rotCosAbs = Math.abs(Math.cos(rot));
  const rotSinAbs = Math.abs(Math.sin(rot));
  const m = geom.scissorMarginCss;
  const fullW = computed.sw + 2 * m;
  const fullH = computed.sh + 2 * m;
  const rotBboxW = fullW * rotCosAbs + fullH * rotSinAbs;
  const rotBboxH = fullW * rotSinAbs + fullH * rotCosAbs;
  const bboxCx = computed.sx + computed.sw / 2;
  const bboxCy = computed.sy + computed.sh / 2;
  const rotScX = Math.max(0, Math.min(this.fboW, Math.round((bboxCx - rotBboxW / 2) * this.dpr)));
  const rotScY = Math.max(0, Math.min(this.fboH, Math.round((this.cssHeight - (bboxCy + rotBboxH / 2)) * this.dpr)));
  const rotScW = Math.max(0, Math.min(this.fboW - rotScX, Math.round(rotBboxW * this.dpr)));
  const rotScH = Math.max(0, Math.min(this.fboH - rotScY, Math.round(rotBboxH * this.dpr)));
  if (this.showPefBbox) {
    this.debugPefBboxes.push({
      x: geom.ex0 / this.dpr,
      y: geom.ey0Top / this.dpr,
      w: geom.elFboRectW / this.dpr,
      h: geom.elFboRectH / this.dpr,
      fbo: true
    });
  }
  const flags = computeCacheFlags.call(this, el);
  let state = buildGlassRenderState({
    el,
    st,
    transform: computed,
    usePerElementFbo: true,
    sceneRectOffsetX: geom.sceneOffsetX,
    sceneRectOffsetY: geom.sceneOffsetY,
    elFboW: 0,
    elFboH: 0
  });
  const cache = resolveElFboCache.call(this, el, state, geom, flags);
  state = { ...state, elFboW: cache.elFboW, elFboH: cache.elFboH };
  const shadowClip = this.intersectClipScissor(el, rotScX, rotScY, rotScW, rotScH);
  this.bindFBO(curFbo);
  gl.enable(gl.SCISSOR_TEST);
  gl.scissor(shadowClip.x, shadowClip.y, shadowClip.w, shadowClip.h);
  gl.enable(gl.BLEND);
  gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
  this.renderGlassShadowPass(state);
  if (!cache.cacheHit) {
    this.dirtyRectsThisFrame.push({
      ...inflatedOutputRect(el, computed.sx, computed.sy, computed.sw, computed.sh, computed.togglePressProgress),
      source: `glass:${el.id}`
    });
    const backdrop = resolveBackdropTex.call(this, state, curTex, cache.renderFbo);
    gl.bindFramebuffer(gl.FRAMEBUFFER, cache.renderFbo);
    gl.viewport(0, 0, cache.elFboW, cache.elFboH);
    gl.disable(gl.SCISSOR_TEST);
    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.disable(gl.BLEND);
    this.renderGlassElementPass(backdrop.passState ?? state, backdrop.backdropTex, backdrop.backdropBbox);
    if (cache.cacheWrite) {
      const e = this.elFboCache.get(el.id);
      if (e)
        e.valid = true;
    }
  }
  const elemCx = bboxCx;
  const elemCy = bboxCy;
  const compAabbW = computed.sw * rotCosAbs + computed.sh * rotSinAbs;
  const compAabbH = computed.sw * rotSinAbs + computed.sh * rotCosAbs;
  const compScX = Math.max(0, Math.min(this.fboW, Math.round((elemCx - compAabbW / 2) * this.dpr)));
  const compScY = Math.max(0, Math.min(this.fboH, Math.round((this.cssHeight - (elemCy + compAabbH / 2)) * this.dpr)));
  const compScW = Math.max(0, Math.min(this.fboW - compScX, Math.round(compAabbW * this.dpr)));
  const compScH = Math.max(0, Math.min(this.fboH - compScY, Math.round(compAabbH * this.dpr)));
  const compClip = this.intersectClipScissor(el, compScX, compScY, compScW, compScH);
  this.bindFBO(curFbo);
  gl.enable(gl.SCISSOR_TEST);
  gl.scissor(compClip.x, compClip.y, compClip.w, compClip.h);
  this.drawElFboComposite(cache.renderTex, cache.elFboW, cache.elFboH, elemCx * this.dpr, elemCy * this.dpr, computed.sw * this.dpr, computed.sh * this.dpr, rot);
  const postClip = this.intersectClipScissor(el, rotScX, rotScY, rotScW, rotScH);
  gl.scissor(postClip.x, postClip.y, postClip.w, postClip.h);
  this.renderGlassPostPasses(state);
  gl.disable(gl.SCISSOR_TEST);
  this._dbgLastGlassCacheHit = cache.cacheHit;
  if (this.showPefPassDebug) {
    const cssEx0 = geom.ex0 / this.dpr;
    const cssEy0 = geom.ey0Top / this.dpr;
    const cssEw = geom.elFboRectW / this.dpr;
    const cssEh = geom.elFboRectH / this.dpr;
    const cssBx0 = geom.bx0 / this.dpr;
    const cssBy0 = geom.by0Top / this.dpr;
    const cssBw = geom.bboxW / this.dpr;
    const cssBh = geom.bboxH / this.dpr;
    this.debugPefPasses.push({
      id: el.id,
      cacheHit: cache.cacheHit,
      missReason: cache.cacheHit ? null : "MISS",
      composite: { x: cssEx0, y: cssEy0, w: cssEw, h: cssEh },
      postPass: { x: cssBx0, y: cssBy0, w: cssBw, h: cssBh },
      isBottomTabIndicator: !!el.isBottomTabIndicator,
      togglePressProgress: state.togglePressProgress,
      elHighlightAlpha: state.elHighlightAlpha
    });
  }
  return { curFbo, curTex, otherFbo, otherTex };
}

// src/cdn/core/renderer/methods-render-glass-shadow.ts
function renderGlassShadowPass(state) {
  const gl = this.gl;
  const { el, sx, sy, sw, sh, radii } = state;
  if (!el.outerShadow || el.outerShadow.radius <= 0.5)
    return;
  if (!this.quickToggles.outerShadow)
    return;
  let shadowAlpha = el.outerShadow.alpha;
  if (el.isBottomTabIndicator) {
    shadowAlpha *= state.togglePressProgress;
  }
  if (this.showShadowBbox) {
    const bbox = shadowBboxCss(el, sx, sy, sw, sh, state.layerScaleX, state.layerScaleY, this.quickToggles);
    if (bbox) {
      this.debugShadowBboxes.push({
        ...bbox,
        alpha: shadowAlpha,
        skipped: shadowAlpha <= 0.001,
        r: el.outerShadow.radius,
        ox: el.outerShadow.offsetX,
        oy: el.outerShadow.offsetY
      });
    }
  }
  if (shadowAlpha <= 0.001)
    return;
  gl.useProgram(this.shadowProgram);
  gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
  gl.enableVertexAttribArray(this.aPosLocSh);
  gl.vertexAttribPointer(this.aPosLocSh, 2, gl.FLOAT, false, 0, 0);
  gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
  gl.uniform2f(this.uSh["uCanvasSize"], this.canvas.width, this.canvas.height);
  gl.uniform2f(this.uSh["uElementOffset"], sx * this.dpr, sy * this.dpr);
  gl.uniform2f(this.uSh["uElementSize"], sw * this.dpr, sh * this.dpr);
  gl.uniform4f(this.uSh["uCornerRadii"], radii[0] * this.dpr, radii[1] * this.dpr, radii[2] * this.dpr, radii[3] * this.dpr);
  gl.uniform2f(this.uSh["uOriginalSize"], state.origW * this.dpr, state.origH * this.dpr);
  gl.uniform1f(this.uSh["uOriginalCornerRadius"], state.origCornerRadius * this.dpr);
  gl.uniform2f(this.uSh["uLayerScale"], state.layerScaleX, state.layerScaleY);
  gl.uniform1f(this.uSh["uElementRotation"], state.elementRotation);
  gl.uniform1f(this.uSh["uCornerStyle"], this.cornerStyle);
  gl.uniform1f(this.uSh["uShadowRadius"], el.outerShadow.radius * this.dpr);
  gl.uniform2f(this.uSh["uShadowOffset"], el.outerShadow.offsetX * this.dpr, el.outerShadow.offsetY * this.dpr);
  gl.uniform4f(this.uSh["uShadowColor"], el.outerShadow.color[0], el.outerShadow.color[1], el.outerShadow.color[2], shadowAlpha);
  gl.drawArrays(gl.TRIANGLES, 0, 6);
}

// src/cdn/core/renderer/methods-render-glass.ts
var glassRenderMethods = {
  renderGlassElement,
  renderGlassElementPerFbo,
  renderGlassShadowPass
};

// src/cdn/core/renderer/methods-render.ts
var renderMethods = {
  render() {
    if (!this.needsRedraw)
      return;
    this.needsRedraw = false;
    this.dirtyRectsThisFrame.length = 0;
    this.debugCacheMissLog.length = 0;
    this.debugDirtySourceLog.length = 0;
    this._blurCacheMissesThisFrame = 0;
    if (this.allDirty || this.scrollY !== this.lastRenderedScrollY) {
      this.dirtyRectsThisFrame.push({
        x: 0,
        y: 0,
        w: this.cssWidth,
        h: this.cssHeight,
        source: this.allDirty ? "all_dirty" : "scroll"
      });
      if (!this.allDirty) {
        this.clearSceneBlurCache();
        this._scrollSettlePending = true;
      }
    }
    this.perfMonitor.canvasCssW = this.cssWidth;
    this.perfMonitor.canvasCssH = this.cssHeight;
    this.perfMonitor.canvasDevW = this.canvas.width;
    this.perfMonitor.canvasDevH = this.canvas.height;
    this.perfMonitor.dpr = this.dpr;
    this.perfMonitor.deviceDpr = typeof window !== "undefined" ? window.devicePixelRatio || 1 : 1;
    this.perfMonitor.frameStart();
    this.debugPefBboxes.length = 0;
    this.debugBlurRegions.length = 0;
    this.debugShadowBboxes.length = 0;
    this.debugDirtyMarkers.length = 0;
    this.debugCullRects.length = 0;
    this.debugPefPasses.length = 0;
    this.debugPlainRects.length = 0;
    if (!this.wallpaperReady && !this.backgroundColor) {
      this.perfMonitor.frameEnd();
      return;
    }
    const gl = this.gl;
    this.resizeFBOs(this.canvas.width, this.canvas.height);
    for (const cfg of this.buttonConfigs) {
      if (this.fgDirtyIds.has(cfg.id)) {
        this.rasterizeForeground(cfg);
      }
    }
    this.renderBackground();
    this.perfMonitor.incDrawCall();
    if (this.buttonConfigs.length === 0) {
      this.bindFBO(null);
      this.drawCopy(this.fboATex);
      this.perfMonitor.incDrawCall();
      this.perfMonitor.frameEnd();
      return;
    }
    const sceneBlurEl = this.buttonConfigs.find((e) => (e.sceneBlurRadius ?? 0) >= 0.5);
    if (sceneBlurEl) {
      const r = sceneBlurEl.sceneBlurRadius * this.dpr;
      const blurred = this.blurTexture(this.fboATex, r);
      this.bindFBO(this.fboA);
      this.drawCopy(blurred);
      this.perfMonitor.incBlurPass();
      this.perfMonitor.incDrawCall(2);
    }
    gl.enable(gl.BLEND);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
    const isolate = this.quickToggles.isolateBackdrop;
    if (isolate && this.bgOnlyFbo && this.bgOnlyTex) {
      this.bindFBO(this.bgOnlyFbo);
      this.gl.viewport(0, 0, this.fboW, this.fboH);
      this.drawCopy(this.fboATex);
      this.gl.enable(this.gl.BLEND);
      this.gl.blendFunc(this.gl.SRC_ALPHA, this.gl.ONE_MINUS_SRC_ALPHA);
    }
    const scrollY = this.scrollY;
    const CULL_MARGIN = 120;
    const cullMarginFor = (el) => Math.max(CULL_MARGIN, el.rect.h);
    const effRect = (el) => {
      const y = el.scroll ? el.rect.y - scrollY : el.rect.y;
      return { x: el.rect.x, y, w: el.rect.w, h: el.rect.h };
    };
    let curFbo = this.fboA;
    let curTex = this.fboATex;
    let otherFbo = this.fboB;
    let otherTex = this.fboBTex;
    for (const el of this.buttonConfigs) {
      if (el.renderOnTop)
        continue;
      const y = el.scroll ? el.rect.y - scrollY : el.rect.y;
      const margin = cullMarginFor(el);
      const culled = y + el.rect.h < -margin || y > this.cssHeight + margin;
      if (this.showCullDebug) {
        this.debugCullRects.push({
          id: el.id,
          x: el.rect.x,
          y,
          w: el.rect.w,
          h: el.rect.h,
          margin,
          culled,
          scroll: !!el.scroll,
          viewportH: this.cssHeight,
          pass: "main"
        });
      }
      if (culled)
        continue;
      const r = effRect(el);
      const st = this.buttonStates.get(el.id);
      const dirty = this.allDirty || this.dirtyElementIds.has(el.id);
      this.perfMonitor.incTotal();
      if (dirty)
        this.perfMonitor.incDirty();
      if (this.renderNonGlassElement(el, r, st, curFbo)) {
        if (this.showDirtyMarkers) {
          this.debugDirtyMarkers.push({ x: r.x, y: r.y, w: r.w, h: r.h, dirty });
        }
        if (dirty)
          this.dirtyRectsThisFrame.push({
            ...inflatedOutputRect(el, r.x, r.y, r.w, r.h),
            source: `nonglass:${el.id}`
          });
        if (isolate && this.bgOnlyFbo) {
          this.renderNonGlassElement(el, r, st, this.bgOnlyFbo);
        }
        continue;
      }
      if (el.backdropFbo && el.scrimColor) {
        this.renderDialogBackdrop(el.scrimColor, el.brightness, el.contrast, el.saturation);
      }
      if (el.useContinuousSdf) {
        this.loadContinuousSdf(el.rect.w, el.rect.h, el.cornerRadius);
      }
      const result = this.renderGlassElement(el, st, curFbo, curTex, otherFbo, otherTex, r);
      curFbo = result.curFbo;
      curTex = result.curTex;
      otherFbo = result.otherFbo;
      otherTex = result.otherTex;
      if (this.showDirtyMarkers) {
        this.debugDirtyMarkers.push({ x: r.x, y: r.y, w: r.w, h: r.h, dirty: !this._dbgLastGlassCacheHit });
      }
      if (el.isBottomTabContainer && this.tabsBackdropFbo && this.tabsBackdropTex) {
        this.bindFBO(this.tabsBackdropFbo);
        this.gl.clearColor(0, 0, 0, 0);
        this.gl.clear(this.gl.COLOR_BUFFER_BIT);
        this.drawCopy(curTex);
        this.bindFBO(curFbo);
        this.gl.enable(this.gl.BLEND);
        this.gl.blendFunc(this.gl.SRC_ALPHA, this.gl.ONE_MINUS_SRC_ALPHA);
      }
    }
    for (const el of this.buttonConfigs) {
      if (!el.renderOnTop)
        continue;
      const y = el.scroll ? el.rect.y - scrollY : el.rect.y;
      const margin = cullMarginFor(el);
      const culled = y + el.rect.h < -margin || y > this.cssHeight + margin;
      if (this.showCullDebug) {
        this.debugCullRects.push({
          id: el.id,
          x: el.rect.x,
          y,
          w: el.rect.w,
          h: el.rect.h,
          margin,
          culled,
          scroll: !!el.scroll,
          viewportH: this.cssHeight,
          pass: "onTop"
        });
      }
      if (culled)
        continue;
      const r = effRect(el);
      const st = this.buttonStates.get(el.id);
      const dirty = this.allDirty || this.dirtyElementIds.has(el.id);
      this.perfMonitor.incTotal();
      if (dirty)
        this.perfMonitor.incDirty();
      if (this.renderNonGlassElement(el, r, st, curFbo)) {
        if (this.showDirtyMarkers) {
          this.debugDirtyMarkers.push({ x: r.x, y: r.y, w: r.w, h: r.h, dirty });
        }
        if (dirty)
          this.dirtyRectsThisFrame.push({
            ...inflatedOutputRect(el, r.x, r.y, r.w, r.h),
            source: `nonglass:${el.id}`
          });
        if (isolate && this.bgOnlyFbo) {
          this.renderNonGlassElement(el, r, st, this.bgOnlyFbo);
        }
        continue;
      }
      const result = this.renderGlassElement(el, st, curFbo, curTex, otherFbo, otherTex, r);
      curFbo = result.curFbo;
      curTex = result.curTex;
      otherFbo = result.otherFbo;
      otherTex = result.otherTex;
      if (this.showDirtyMarkers) {
        this.debugDirtyMarkers.push({ x: r.x, y: r.y, w: r.w, h: r.h, dirty: !this._dbgLastGlassCacheHit });
      }
    }
    this.bindFBO(null);
    this.drawCopy(curTex);
    this.perfMonitor.incDrawCall();
    if (this._pendingEdgeScan) {
      this._debugFlushPendingEdgeScan();
    }
    this.dirtyElementIds.clear();
    this.allDirty = false;
    if (this._blurSettleBudgetBoost != null) {
      this.blurCacheMissesPerFrame = this._blurSettleBudgetBoost;
      this._blurSettleBudgetBoost = null;
    }
    if (this._scrollSettlePending) {
      this._scrollSettlePending = false;
      this._blurSettleBudgetBoost = this.blurCacheMissesPerFrame;
      this.blurCacheMissesPerFrame = 1e9;
      for (const e of this.elFboCache.values())
        e.valid = false;
      this.requestRender();
    }
    this.lastRenderedScrollY = this.scrollY;
    if (this.pendingExtraRenders > 0) {
      this.pendingExtraRenders--;
      for (const el of this.buttonConfigs) {
        if (el.isBottomTabIndicator) {
          this.markGroupDirty(el.isBottomTabIndicator.groupId);
        }
      }
      this.requestRender();
    }
    this.perfMonitor.frameEnd();
  }
};

// src/cdn/core/renderer/methods-render-background.ts
var backgroundMethods = {
  setSdfUniforms(u, aPosLoc, r, cornerRadius) {
    const gl = this.gl;
    gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
    gl.enableVertexAttribArray(aPosLoc);
    gl.vertexAttribPointer(aPosLoc, 2, gl.FLOAT, false, 0, 0);
    gl.uniform2f(u["uCanvasSize"], this.canvas.width, this.canvas.height);
    gl.uniform2f(u["uOffset"], r.x * this.dpr, r.y * this.dpr);
    gl.uniform2f(u["uSize"], r.w * this.dpr, r.h * this.dpr);
    gl.uniform4f(u["uCornerRadii"], cornerRadius * this.dpr, cornerRadius * this.dpr, cornerRadius * this.dpr, cornerRadius * this.dpr);
  },
  renderBackground() {
    const gl = this.gl;
    this.bindFBO(this.fboA);
    gl.disable(gl.BLEND);
    if (this.backgroundColor) {
      const [r, g, b] = this.backgroundColor;
      this.drawSolidFill(r, g, b, 1);
    } else {
      gl.useProgram(this.wallpaperProgram);
      gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
      gl.enableVertexAttribArray(this.aPosLocWp);
      gl.vertexAttribPointer(this.aPosLocWp, 2, gl.FLOAT, false, 0, 0);
      gl.activeTexture(gl.TEXTURE0);
      gl.bindTexture(gl.TEXTURE_2D, this.wallpaperTexture);
      gl.uniform1i(this.uWp["uBackdrop"], 0);
      gl.uniform2f(this.uWp["uCanvasSize"], this.canvas.width, this.canvas.height);
      gl.uniform2f(this.uWp["uWallpaperSize"], this.wallpaperSize[0], this.wallpaperSize[1]);
      gl.drawArrays(gl.TRIANGLES, 0, 6);
      if (this.wallpaperBlurFbo) {
        gl.bindFramebuffer(gl.FRAMEBUFFER, this.wallpaperBlurFbo);
        gl.viewport(0, 0, this.fboW, this.fboH);
        gl.disable(gl.SCISSOR_TEST);
        gl.drawArrays(gl.TRIANGLES, 0, 6);
      }
    }
  },
  renderDialogBackdrop(scrim, brightness, contrast, saturation) {
    const key = `${scrim.join(",")}|${brightness},${contrast},${saturation}`;
    if (this.dialogBackdropKey === key)
      return;
    this.dialogBackdropKey = key;
    const gl = this.gl;
    this.bindFBO(this.dialogBackdropFbo);
    gl.disable(gl.BLEND);
    if (this.backgroundColor) {
      const [r, g, b] = this.backgroundColor;
      this.drawSolidFill(r, g, b, 1);
    } else {
      gl.useProgram(this.wallpaperProgram);
      gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
      gl.enableVertexAttribArray(this.aPosLocWp);
      gl.vertexAttribPointer(this.aPosLocWp, 2, gl.FLOAT, false, 0, 0);
      gl.activeTexture(gl.TEXTURE0);
      gl.bindTexture(gl.TEXTURE_2D, this.wallpaperTexture);
      gl.uniform1i(this.uWp["uBackdrop"], 0);
      gl.uniform2f(this.uWp["uCanvasSize"], this.canvas.width, this.canvas.height);
      gl.uniform2f(this.uWp["uWallpaperSize"], this.wallpaperSize[0], this.wallpaperSize[1]);
      gl.drawArrays(gl.TRIANGLES, 0, 6);
    }
    if (scrim[3] > 0.001) {
      gl.enable(gl.BLEND);
      gl.blendFuncSeparate(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA, gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
      this.drawSolidFill(scrim[0], scrim[1], scrim[2], scrim[3]);
      gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
    }
    this.bindFBO(this.blurFboA);
    this.drawColorControls(this.dialogBackdropTex, brightness, contrast, saturation);
    this.bindFBO(this.dialogBackdropFbo);
    this.drawCopy(this.blurFboATex);
  }
};

// src/cdn/core/renderer/methods-render-nonglass.ts
var nonGlassMethods = {
  renderNonGlassElement(el, r, st, curFbo) {
    let r2 = r;
    if (el.enterProgress != null) {
      const raw = el.enterProgress;
      const derived = raw < 0 ? (1 - Math.exp(-Math.abs(raw))) * -1 : raw <= 1 ? raw : 1 + (1 - Math.exp(-(raw - 1)));
      const ty = -48 * DP * (1 - derived);
      const stretch = el.enterStretchFactor != null && derived > 1 ? el.enterStretchFactor * (derived - 1) * 32 * DP : 0;
      r2 = { x: r.x, y: r.y + ty + stretch, w: r.w, h: r.h };
    }
    if (el.kind === "plain-rect" && el.plainRect) {
      return this.renderPlainRectElement(el, r, r2, curFbo);
    }
    if (el.kind === "progressive-blur" && el.progressiveBlur) {
      return this.renderProgressiveBlurElement(el, r2, curFbo);
    }
    if (el.kind === "text") {
      return this.renderTextElement(el, r2, st, curFbo);
    }
    return false;
  }
};

// src/cdn/core/renderer/methods-render-diagnose.ts
function diagnosePlainRect(skipped, skipReason, finalAlpha, w, h, blendEnabled) {
  if (skipped)
    return { verdict: "SKIPPED", detail: skipReason ?? "unknown" };
  if (!isFinite(finalAlpha) || finalAlpha <= 0) {
    return { verdict: "INVISIBLE", detail: `finalAlpha=${finalAlpha} (colorA*enterA)` };
  }
  if (w <= 0 || h <= 0) {
    return { verdict: "DEGENERATE", detail: `rect ${w.toFixed(1)}x${h.toFixed(1)} ≤ 0` };
  }
  if (!blendEnabled) {
    return { verdict: "NO_OP", detail: "BLEND disabled by prior element" };
  }
  return { verdict: "OK", detail: `finalAlpha=${finalAlpha.toFixed(3)}` };
}

// src/cdn/core/renderer/methods-render-nonglass-plain-rect.ts
var nonGlassPlainRectMethods = {
  renderPlainRectElement(el, r, r2, curFbo) {
    const gl = this.gl;
    const baseC = el.isToggleTrack ? null : el.plainRect.color;
    if (baseC && baseC[3] <= 0) {
      if (this.showPlainRectDebug && curFbo !== this.bgOnlyFbo) {
        const col = el.plainRect.color;
        const sp0 = el.enterSafeProgress != null ? Math.max(0, Math.min(1, el.enterSafeProgress)) : el.enterProgress != null ? Math.max(0, Math.min(1, el.enterProgress)) : 1;
        const ea = el.enterProgress != null ? easeIn(sp0) : 1;
        const fa = col[3] * ea;
        const blendOn = this.gl.isEnabled(this.gl.BLEND);
        const reason = `color alpha=${col[3]} ≤ 0`;
        const dg = diagnosePlainRect(true, reason, fa, r2.w, r2.h, blendOn);
        this.debugPlainRects.push({
          id: el.id,
          x: r2.x,
          y: r2.y,
          w: r2.w,
          h: r2.h,
          origH: el.rect.h,
          colorR: col[0],
          colorG: col[1],
          colorB: col[2],
          colorA: col[3],
          enterProgress: el.enterProgress ?? null,
          enterSafeProgress: el.enterSafeProgress ?? null,
          enterA: ea,
          finalAlpha: fa,
          skipped: true,
          skipReason: reason,
          drawn: false,
          blendEnabled: blendOn,
          curFboIsA: curFbo === this.fboA,
          diagnosis: dg.verdict,
          diagnosisDetail: dg.detail
        });
      }
      return true;
    }
    this.bindFBO(curFbo);
    let clipEnabled = false;
    if (el.clipRect) {
      const cx0 = Math.max(0, Math.round(r2.x * this.dpr));
      const cy0 = Math.max(0, Math.round((this.cssHeight - (r2.y + r2.h)) * this.dpr));
      const cw = Math.min(this.fboW - cx0, Math.round(r2.w * this.dpr));
      const ch = Math.min(this.fboH - cy0, Math.round(r2.h * this.dpr));
      const clip = this.intersectClipScissor(el, cx0, cy0, cw, ch);
      gl.enable(gl.SCISSOR_TEST);
      gl.scissor(clip.x, clip.y, clip.w, clip.h);
      clipEnabled = true;
    }
    let c;
    if (el.isToggleTrack) {
      const tg = this.toggleStates.get(el.isToggleTrack.groupId);
      const f = tg ? tg.fraction : 0;
      const off = el.isToggleTrack.offColor;
      const on = el.isToggleTrack.onColor;
      c = [
        off[0] + (on[0] - off[0]) * f,
        off[1] + (on[1] - off[1]) * f,
        off[2] + (on[2] - off[2]) * f,
        off[3] + (on[3] - off[3]) * f
      ];
    } else {
      c = el.plainRect.color;
    }
    let fillRect = r2;
    if (el.isSliderFill) {
      const sf = this.toggleStates.get(el.isSliderFill.groupId);
      const fraction = sf ? sf.fraction : 0;
      const fillW = Math.max(el.isSliderFill.minW, el.isSliderFill.trackW * fraction);
      fillRect = { x: r.x, y: r.y, w: fillW, h: r.h };
    }
    gl.useProgram(this.plainRectProgram);
    this.setSdfUniforms(this.uPr, this.aPosLocPr, fillRect, el.cornerRadius);
    gl.blendFuncSeparate(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA, gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
    const enterA = el.enterProgress != null ? (() => {
      const sp = el.enterSafeProgress != null ? Math.max(0, Math.min(1, el.enterSafeProgress)) : Math.max(0, Math.min(1, el.enterProgress));
      return easeIn(sp);
    })() : 1;
    gl.uniform4f(this.uPr["uColor"], c[0], c[1], c[2], c[3] * enterA);
    gl.uniform1f(this.uPr["uCornerStyle"], this.cornerStyle);
    if (el.useContinuousSdf) {
      this.loadContinuousSdf(r2.w, r2.h, el.cornerRadius);
    }
    if (el.useContinuousSdf && this.continuousSdfTexture) {
      gl.activeTexture(gl.TEXTURE2);
      gl.bindTexture(gl.TEXTURE_2D, this.continuousSdfTexture);
      gl.uniform1i(this.uPr["uContinuousSdf"], 2);
      gl.uniform1f(this.uPr["uUseContinuousSdf"], 1);
      gl.uniform2f(this.uPr["uContinuousSdfTexSize"], this.continuousSdfTexSize[0], this.continuousSdfTexSize[1]);
      gl.uniform2f(this.uPr["uContinuousSdfElementSize"], r2.w * this.dpr, r2.h * this.dpr);
    } else {
      gl.uniform1f(this.uPr["uUseContinuousSdf"], 0);
    }
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    if (clipEnabled)
      gl.disable(gl.SCISSOR_TEST);
    this.perfMonitor.incNonGlass();
    this.perfMonitor.incDrawCall();
    if (this.showPlainRectDebug && curFbo !== this.bgOnlyFbo) {
      const fa = c[3] * enterA;
      const blendOn = this.gl.isEnabled(this.gl.BLEND);
      const dg = diagnosePlainRect(false, null, fa, fillRect.w, fillRect.h, blendOn);
      this.debugPlainRects.push({
        id: el.id,
        x: fillRect.x,
        y: fillRect.y,
        w: fillRect.w,
        h: fillRect.h,
        origH: el.rect.h,
        colorR: c[0],
        colorG: c[1],
        colorB: c[2],
        colorA: c[3],
        enterProgress: el.enterProgress ?? null,
        enterSafeProgress: el.enterSafeProgress ?? null,
        enterA,
        finalAlpha: fa,
        skipped: false,
        skipReason: null,
        drawn: true,
        blendEnabled: blendOn,
        curFboIsA: curFbo === this.fboA,
        diagnosis: dg.verdict,
        diagnosisDetail: dg.detail
      });
    }
    return true;
  }
};

// src/cdn/core/renderer/methods-render-nonglass-text.ts
var nonGlassTextMethods = {
  renderTextElement(el, r2, st, curFbo) {
    const gl = this.gl;
    this.bindFBO(curFbo);
    let drawRect = r2;
    let fgScaleX = 1;
    let fgScaleY = 1;
    if (el.isBottomTabContent) {
      const tg = this.toggleStates.get(el.isBottomTabContent.groupId);
      if (tg) {
        const containerW = el.isBottomTabContent.containerWidth ?? el.rect.w * 4;
        const containerScale = 1 + 16 * DP / containerW * tg.pressProgress;
        fgScaleX = containerScale;
        fgScaleY = containerScale;
        const scrollAdjust = el.scroll ? this.scrollY : 0;
        const pivotX = el.isBottomTabContent.containerCenterX ?? el.rect.x + el.rect.w / 2;
        const pivotY = (el.isBottomTabContent.containerCenterY ?? el.rect.y + el.rect.h / 2) - scrollAdjust;
        const tabCenterX = r2.x + el.rect.w / 2;
        const tabCenterY = r2.y + el.rect.h / 2;
        const cx = pivotX + (tabCenterX - pivotX) * containerScale + tg.panelOffset;
        const cy = pivotY + (tabCenterY - pivotY) * containerScale;
        const sw = el.rect.w * fgScaleX;
        const sh = el.rect.h * fgScaleY;
        drawRect = { x: cx - sw / 2, y: cy - sh / 2, w: sw, h: sh };
      }
    }
    const pText = st?.pressProgress ?? 0;
    let clipEnabled = false;
    if (el.clipRect) {
      const cx0 = Math.max(0, Math.round(drawRect.x * this.dpr));
      const cy0 = Math.max(0, Math.round((this.cssHeight - (drawRect.y + drawRect.h)) * this.dpr));
      const cw = Math.min(this.fboW - cx0, Math.round(drawRect.w * this.dpr));
      const ch = Math.min(this.fboH - cy0, Math.round(drawRect.h * this.dpr));
      const clip = this.intersectClipScissor(el, cx0, cy0, cw, ch);
      gl.enable(gl.SCISSOR_TEST);
      gl.scissor(clip.x, clip.y, clip.w, clip.h);
      clipEnabled = true;
    }
    if (el.isInteractive && pText > 0.001) {
      const pressTint = el.pressTintColor;
      gl.useProgram(this.tintProgram);
      gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
      gl.enableVertexAttribArray(this.aPosLocTn);
      gl.vertexAttribPointer(this.aPosLocTn, 2, gl.FLOAT, false, 0, 0);
      if (pressTint) {
        gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
      } else {
        gl.blendFunc(gl.SRC_ALPHA, gl.ONE);
      }
      gl.uniform2f(this.uTn["uCanvasSize"], this.canvas.width, this.canvas.height);
      gl.uniform2f(this.uTn["uOffset"], drawRect.x * this.dpr, drawRect.y * this.dpr);
      gl.uniform2f(this.uTn["uSize"], drawRect.w * this.dpr, drawRect.h * this.dpr);
      gl.uniform4f(this.uTn["uCornerRadii"], 0, 0, 0, 0);
      gl.uniform2f(this.uTn["uOriginalSize"], drawRect.w * this.dpr, drawRect.h * this.dpr);
      gl.uniform1f(this.uTn["uOriginalCornerRadius"], 0);
      gl.uniform2f(this.uTn["uLayerScale"], 1, 1);
      if (pressTint) {
        gl.uniform4f(this.uTn["uColor"], pressTint[0], pressTint[1], pressTint[2], 0.1 * pText);
      } else {
        gl.uniform4f(this.uTn["uColor"], 1, 1, 1, 0.1 * pText);
      }
      gl.drawArrays(gl.TRIANGLES, 0, 6);
      gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
    }
    const fgTex = this.fgTextures.get(el.id);
    if (fgTex) {
      gl.useProgram(this.foregroundProgram);
      gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
      gl.enableVertexAttribArray(this.aPosLocFg);
      gl.vertexAttribPointer(this.aPosLocFg, 2, gl.FLOAT, false, 0, 0);
      gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
      gl.activeTexture(gl.TEXTURE0);
      gl.bindTexture(gl.TEXTURE_2D, fgTex);
      gl.uniform1i(this.uFg["uTexture"], 0);
      gl.uniform2f(this.uFg["uCanvasSize"], this.canvas.width, this.canvas.height);
      gl.uniform2f(this.uFg["uOffset"], drawRect.x * this.dpr, drawRect.y * this.dpr);
      gl.uniform2f(this.uFg["uSize"], drawRect.w * this.dpr, drawRect.h * this.dpr);
      gl.uniform4f(this.uFg["uCornerRadii"], el.cornerRadius * this.dpr, el.cornerRadius * this.dpr, el.cornerRadius * this.dpr, el.cornerRadius * this.dpr);
      gl.uniform2f(this.uFg["uOriginalSize"], el.rect.w * this.dpr, el.rect.h * this.dpr);
      gl.uniform1f(this.uFg["uOriginalCornerRadius"], el.cornerRadius * this.dpr);
      gl.uniform2f(this.uFg["uLayerScale"], fgScaleX, fgScaleY);
      gl.uniform1f(this.uFg["uCornerStyle"], this.cornerStyle);
      gl.uniform1f(this.uFg["uUseContinuousSdf"], 0);
      gl.uniform1f(this.uFg["uAlpha"], el.enterProgress != null ? (() => {
        const sp = el.enterSafeProgress != null ? Math.max(0, Math.min(1, el.enterSafeProgress)) : Math.max(0, Math.min(1, el.enterProgress));
        return easeIn(sp);
      })() : 1);
      gl.drawArrays(gl.TRIANGLES, 0, 6);
      gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
    }
    this.perfMonitor.incNonGlass();
    this.perfMonitor.incDrawCall();
    if (clipEnabled)
      gl.disable(gl.SCISSOR_TEST);
    return true;
  }
};

// src/cdn/core/renderer/methods-render-nonglass-progressive-blur.ts
var nonGlassProgressiveBlurMethods = {
  renderProgressiveBlurElement(el, r2, curFbo) {
    const gl = this.gl;
    this.bindFBO(curFbo);
    gl.useProgram(this.progressiveBlurProgram);
    this.setSdfUniforms(this.uPb, this.aPosLocPb, r2, el.cornerRadius);
    gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, this.wallpaperTexture);
    gl.uniform1i(this.uPb["uBackdrop"], 0);
    gl.uniform2f(this.uPb["uWallpaperSize"], this.wallpaperSize[0], this.wallpaperSize[1]);
    gl.uniform1f(this.uPb["uBlurRadius"], el.progressiveBlur.blurRadius * this.dpr);
    const tc = el.progressiveBlur.tintColor;
    gl.uniform4f(this.uPb["uTintColor"], tc[0], tc[1], tc[2], tc[3]);
    gl.uniform1f(this.uPb["uTintIntensity"], el.progressiveBlur.tintIntensity);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    this.perfMonitor.incNonGlass();
    this.perfMonitor.incDrawCall();
    return true;
  }
};

// src/cdn/core/renderer/methods-render-glass-element-pass-context.ts
function createElementPassContext(el) {
  return {
    elRefractionHeight: el.refractionHeight,
    elRefractionAmount: el.refractionAmount,
    elBlurRadius: el.blurRadius,
    elHighlightAlpha: el.highlight ? el.highlight.alpha : 0,
    elSurfaceAlpha: el.surfaceColor[3],
    elContentScaleX: 1,
    elContentScaleY: 1,
    useToggleBackdrop: 0,
    useSolidBackdrop: 0,
    solidR: 1,
    solidG: 1,
    solidB: 1,
    solidA: 1,
    trackColorR: 0,
    trackColorG: 0,
    trackColorB: 0,
    trackColorA: 0,
    trackCenterX: 0,
    trackCenterY: 0,
    trackHalfW: 0,
    trackHalfH: 0,
    trackCornerRadius: 0,
    useIndicatorBackdrop: 0,
    containerRectX: 0,
    containerRectY: 0,
    containerHalfW: 0,
    containerHalfH: 0,
    containerCornerRadius: 0,
    indicatorAccentR: 0,
    indicatorAccentG: 0,
    indicatorAccentB: 0,
    indicatorAccentA: 0
  };
}

// src/cdn/core/renderer/methods-render-glass-element-pass-toggle.ts
function applyToggleKnobBackdrop(renderer, state, ctx) {
  const { el, sx, sy, sw, sh, togglePressProgress } = state;
  if (!el.isToggleKnob)
    return;
  const progress = togglePressProgress;
  ctx.elRefractionHeight = el.refractionHeight * progress;
  ctx.elRefractionAmount = el.refractionAmount * progress;
  ctx.elBlurRadius = 8 * (1 - progress);
  ctx.elHighlightAlpha = (el.highlight?.alpha ?? 0) * progress;
  ctx.elSurfaceAlpha = 0;
  const isSlider = el.isToggleKnob.velocityDivisor === 10;
  const xEnd = isSlider ? 1 : 0.75;
  const yEnd = isSlider ? 1 : 0.75;
  ctx.elContentScaleX = 2 / 3 + (xEnd - 2 / 3) * progress;
  ctx.elContentScaleY = 0 + (yEnd - 0) * progress;
  if (el.isToggleKnob.trackColorOff && el.isToggleKnob.trackColorOn && el.isToggleKnob.trackW && el.isToggleKnob.trackH) {
    const tg = renderer.toggleStates.get(el.isToggleKnob.groupId);
    const fraction = tg ? tg.fraction : 0;
    const off = el.isToggleKnob.trackColorOff;
    const on = el.isToggleKnob.trackColorOn;
    ctx.trackColorR = off[0] + (on[0] - off[0]) * fraction;
    ctx.trackColorG = off[1] + (on[1] - off[1]) * fraction;
    ctx.trackColorB = off[2] + (on[2] - off[2]) * fraction;
    ctx.trackColorA = off[3] + (on[3] - off[3]) * fraction;
    const knobCenterX = (sx + sw / 2) * renderer.dpr;
    const knobCenterY = (sy + sh / 2) * renderer.dpr;
    const trackOrigX = el.isToggleKnob.trackOriginalX ?? el.rect.x;
    const trackOrigY_raw = el.isToggleKnob.trackOriginalY ?? el.rect.y;
    const trackOrigY = el.scroll ? trackOrigY_raw - renderer.scrollY : trackOrigY_raw;
    const trackOrigCenterX = (trackOrigX + el.isToggleKnob.trackW / 2) * renderer.dpr;
    const trackOrigCenterY = (trackOrigY + el.isToggleKnob.trackH / 2) * renderer.dpr;
    const trackScaleX = 2 / 3 + (xEnd - 2 / 3) * progress;
    const trackScaleY = 0 + (yEnd - 0) * progress;
    ctx.trackCenterX = knobCenterX + (trackOrigCenterX - knobCenterX) * trackScaleX;
    ctx.trackCenterY = knobCenterY + (trackOrigCenterY - knobCenterY) * trackScaleY;
    const trackW = el.isToggleKnob.trackW * renderer.dpr;
    const trackH = el.isToggleKnob.trackH * renderer.dpr;
    ctx.trackHalfW = trackW * trackScaleX * 0.5;
    ctx.trackHalfH = trackH * trackScaleY * 0.5;
    ctx.trackCornerRadius = trackH * 0.5 * Math.min(trackScaleX, trackScaleY);
    ctx.useToggleBackdrop = 1;
    if (el.isToggleKnob.solidBackdropColor) {
      const sd = el.isToggleKnob.solidBackdropColor;
      ctx.solidR = sd[0];
      ctx.solidG = sd[1];
      ctx.solidB = sd[2];
      ctx.solidA = sd[3];
      ctx.useSolidBackdrop = 1;
    }
    ctx.elContentScaleX = 1;
    ctx.elContentScaleY = 1;
  }
}

// src/cdn/core/renderer/methods-render-glass-element-pass-indicator.ts
function applyIndicatorBackdrop(renderer, state, ctx) {
  const gl = renderer.gl;
  const { el, sx, sy, sw, sh, togglePressProgress } = state;
  if (!el.isBottomTabIndicator) {
    gl.uniform1f(renderer.uEl["uIndicatorPressProgress"], 0);
    gl.uniform1f(renderer.uEl["uIndicatorPanelOffset"], 0);
    gl.uniform1f(renderer.uEl["uDpr"], renderer.dpr);
    gl.uniform2f(renderer.uEl["uContainerCenter"], 0, 0);
    gl.uniform1f(renderer.uEl["uContainerScale"], 1);
    gl.uniform1f(renderer.uEl["uTabContentCount"], 0);
    gl.uniform2f(renderer.uEl["uInnerStrokeMaskOffset"], 1, 1);
    gl.uniform2f(renderer.uEl["uInnerStrokeMaskSize"], 1, 1);
    return;
  }
  const progress = togglePressProgress;
  ctx.elRefractionHeight = el.refractionHeight * progress;
  ctx.elRefractionAmount = el.refractionAmount * progress;
  ctx.elBlurRadius = 0;
  ctx.elHighlightAlpha = (el.highlight?.alpha ?? 0) * progress;
  const scrollAdjust = el.scroll ? renderer.scrollY : 0;
  if (el.isBottomTabIndicator.accentColor && el.isBottomTabIndicator.containerRect) {
    const ac = el.isBottomTabIndicator.accentColor;
    const cr = el.isBottomTabIndicator.containerRect;
    ctx.indicatorAccentR = ac[0];
    ctx.indicatorAccentG = ac[1];
    ctx.indicatorAccentB = ac[2];
    ctx.indicatorAccentA = 1;
    ctx.containerRectX = (cr.x + cr.w / 2) * renderer.dpr;
    ctx.containerRectY = (cr.y + cr.h / 2 - scrollAdjust) * renderer.dpr;
    ctx.containerHalfW = cr.w / 2 * renderer.dpr;
    ctx.containerHalfH = cr.h / 2 * renderer.dpr;
    ctx.containerCornerRadius = cr.h / 2 * renderer.dpr;
    ctx.useIndicatorBackdrop = 1;
  }
  const tg = renderer.toggleStates.get(el.isBottomTabIndicator.groupId);
  gl.uniform1f(renderer.uEl["uIndicatorPressProgress"], tg ? tg.pressProgress : 0);
  gl.uniform1f(renderer.uEl["uIndicatorPanelOffset"], tg ? tg.panelOffset * renderer.dpr : 0);
  gl.uniform1f(renderer.uEl["uDpr"], renderer.dpr);
  const ccx = el.isBottomTabIndicator.containerCenterX ?? 0;
  const ccy = el.isBottomTabIndicator.containerCenterY ?? 0;
  const cw = el.isBottomTabIndicator.containerWidth ?? el.rect.w;
  const cScale = tg ? 1 + 16 * DP / cw * tg.pressProgress : 1;
  gl.uniform2f(renderer.uEl["uContainerCenter"], ccx * renderer.dpr, (ccy - scrollAdjust) * renderer.dpr);
  gl.uniform1f(renderer.uEl["uContainerScale"], cScale);
  const ids = el.isBottomTabIndicator.tabContentIds ?? [];
  const rects = el.isBottomTabIndicator.tabContentRects ?? [];
  const n = Math.min(ids.length, rects.length, 8);
  let boundCount = 0;
  for (let i = 0;i < 8; i++) {
    if (i < n) {
      const tex = renderer.fgTextures.get(ids[i]);
      if (tex) {
        gl.activeTexture(gl.TEXTURE3 + boundCount);
        gl.bindTexture(gl.TEXTURE_2D, tex);
        gl.uniform1i(renderer.uEl[`uTabContentTex${boundCount}`], 3 + boundCount);
        const r = rects[i];
        gl.uniform4f(renderer.uEl[`uTabContentRects[${boundCount}]`], (r.x + r.w / 2) * renderer.dpr, (r.y + r.h / 2 - scrollAdjust) * renderer.dpr, r.w / 2 * renderer.dpr, r.h / 2 * renderer.dpr);
        boundCount++;
      }
    }
  }
  for (let i = boundCount;i < 8; i++) {
    gl.uniform4f(renderer.uEl[`uTabContentRects[${i}]`], 0, 0, 0, 0);
  }
  gl.uniform1f(renderer.uEl["uTabContentCount"], boundCount);
  if (renderer.tabsBackdropTex) {
    gl.activeTexture(gl.TEXTURE11);
    gl.bindTexture(gl.TEXTURE_2D, renderer.tabsBackdropTex);
    gl.uniform1i(renderer.uEl["uTabsGlassLayer"], 11);
  }
  generateInnerStrokeMask(renderer, ctx);
}
function generateInnerStrokeMask(renderer, ctx) {
  const gl = renderer.gl;
  const innerW = 2 * ctx.containerHalfW;
  const innerH = 2 * ctx.containerHalfH;
  const innerR = ctx.containerCornerRadius;
  const widthPx = Math.min(0.5 * renderer.dpr, Math.min(innerW, innerH) * 0.5);
  const strokeWidthDevice = Math.max(1, Math.ceil(widthPx) * 2);
  const blurPx = Math.max(0, 0.25 * renderer.dpr);
  const strokeMargin = Math.ceil(strokeWidthDevice) + 4;
  const maskW = Math.max(1, Math.ceil(innerW + 2 * strokeMargin));
  const maskH = Math.max(1, Math.ceil(innerH + 2 * strokeMargin));
  const deviceDpr = window.devicePixelRatio || 1;
  const SS = Math.min(2, Math.max(1, Math.floor(deviceDpr / renderer.dpr)));
  const canvasW = maskW * SS;
  const canvasH = maskH * SS;
  const maskKey = [
    "inner-rr",
    innerW.toFixed(3),
    innerH.toFixed(3),
    innerR.toFixed(3),
    strokeWidthDevice,
    blurPx.toFixed(3),
    strokeMargin,
    maskW,
    maskH,
    `ss${SS}`
  ].join(":");
  let mask = renderer.strokeMaskCache.get(maskKey);
  if (!mask) {
    const canvas = document.createElement("canvas");
    canvas.width = canvasW;
    canvas.height = canvasH;
    const ctx2d = canvas.getContext("2d", { alpha: true });
    if (!ctx2d)
      throw new Error("2D canvas not supported");
    const tex = gl.createTexture();
    if (!tex)
      throw new Error("WebGL texture allocation failed");
    mask = { tex, canvas, ctx: ctx2d, w: maskW, h: maskH, ready: false };
    renderer.strokeMaskCache.set(maskKey, mask);
    if (renderer.strokeMaskCache.size > 32) {
      const oldestKey = renderer.strokeMaskCache.keys().next().value;
      if (oldestKey && oldestKey !== maskKey) {
        const oldest = renderer.strokeMaskCache.get(oldestKey);
        if (oldest)
          gl.deleteTexture(oldest.tex);
        renderer.strokeMaskCache.delete(oldestKey);
      }
    }
  }
  if (!mask.ready) {
    const smCtx = mask.ctx;
    smCtx.clearRect(0, 0, canvasW, canvasH);
    smCtx.save();
    smCtx.scale(SS, SS);
    smCtx.translate(strokeMargin, strokeMargin);
    const r = Math.min(innerR, innerW / 2, innerH / 2);
    const path = new Path2D;
    path.moveTo(r, 0);
    path.lineTo(innerW - r, 0);
    path.arcTo(innerW, 0, innerW, r, r);
    path.lineTo(innerW, innerH - r);
    path.arcTo(innerW, innerH, innerW - r, innerH, r);
    path.lineTo(r, innerH);
    path.arcTo(0, innerH, 0, innerH - r, r);
    path.lineTo(0, r);
    path.arcTo(0, 0, r, 0, r);
    path.closePath();
    smCtx.clip(path);
    smCtx.lineWidth = strokeWidthDevice;
    smCtx.strokeStyle = "rgba(255,255,255,1)";
    smCtx.lineJoin = "round";
    smCtx.lineCap = "round";
    smCtx.filter = blurPx > 0.01 ? `blur(${blurPx}px)` : "none";
    smCtx.stroke(path);
    smCtx.filter = "none";
    smCtx.restore();
    gl.bindTexture(gl.TEXTURE_2D, mask.tex);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, mask.canvas);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    mask.ready = true;
  }
  gl.activeTexture(gl.TEXTURE12);
  gl.bindTexture(gl.TEXTURE_2D, mask.tex);
  gl.uniform1i(renderer.uEl["uInnerStrokeMask"], 12);
  gl.uniform2f(renderer.uEl["uInnerStrokeMaskOffset"], strokeMargin, strokeMargin);
  gl.uniform2f(renderer.uEl["uInnerStrokeMaskSize"], mask.w, mask.h);
}

// src/cdn/core/renderer/methods-render-glass-element-pass.ts
var glassElementPassMethods = {
  renderGlassElementPass(state, curTex, backdropBbox) {
    const gl = this.gl;
    const { el, sx, sy, sw, sh, radii, togglePressProgress, layerScale } = state;
    gl.useProgram(this.elementProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
    gl.enableVertexAttribArray(this.aPosLocEl);
    gl.vertexAttribPointer(this.aPosLocEl, 2, gl.FLOAT, false, 0, 0);
    gl.blendFuncSeparate(gl.ONE, gl.ONE_MINUS_SRC_ALPHA, gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, curTex);
    gl.uniform1i(this.uEl["uBackdrop"], 0);
    if (this.wallpaperTexture) {
      gl.activeTexture(gl.TEXTURE1);
      gl.bindTexture(gl.TEXTURE_2D, this.wallpaperTexture);
      gl.uniform1i(this.uEl["uWallpaperSampler"], 1);
    }
    gl.uniform2f(this.uEl["uCanvasSize"], this.canvas.width, this.canvas.height);
    gl.uniform2f(this.uEl["uWallpaperSize"], this.wallpaperSize[0], this.wallpaperSize[1]);
    gl.uniform2f(this.uEl["uElementOffset"], sx * this.dpr, sy * this.dpr);
    gl.uniform2f(this.uEl["uElementSize"], sw * this.dpr, sh * this.dpr);
    if (backdropBbox) {
      const cw = this.canvas.width;
      const ch = this.canvas.height;
      const u0 = backdropBbox.x / cw;
      const u1 = (backdropBbox.x + backdropBbox.w) / cw;
      const v1 = 1 - backdropBbox.y / ch;
      const v0 = 1 - (backdropBbox.y + backdropBbox.h) / ch;
      gl.uniform4f(this.uEl["uBackdropBbox"], u0, v0, u1 - u0, v1 - v0);
    } else {
      gl.uniform4f(this.uEl["uBackdropBbox"], 0, 0, 1, 1);
    }
    gl.uniform4f(this.uEl["uCornerRadii"], radii[0] * this.dpr, radii[1] * this.dpr, radii[2] * this.dpr, radii[3] * this.dpr);
    gl.uniform2f(this.uEl["uOriginalSize"], state.origW * this.dpr, state.origH * this.dpr);
    gl.uniform1f(this.uEl["uOriginalCornerRadius"], state.origCornerRadius * this.dpr);
    gl.uniform2f(this.uEl["uLayerScale"], state.layerScaleX, state.layerScaleY);
    gl.uniform1f(this.uEl["uElementRotation"], el.elementRotation ?? 0);
    gl.uniform1f(this.uEl["uUsePerElementFbo"], state.usePerElementFbo ? 1 : 0);
    if (state.usePerElementFbo) {
      gl.uniform2f(this.uEl["uSceneRectOffset"], state.sceneRectOffsetX, state.sceneRectOffsetY);
      gl.uniform2f(this.uEl["uElFboSize"], state.elFboW, state.elFboH);
    }
    const ctx = createElementPassContext(el);
    applyToggleKnobBackdrop(this, state, ctx);
    applyIndicatorBackdrop(this, state, ctx);
    gl.uniform1f(this.uEl["uUseToggleBackdrop"], ctx.useToggleBackdrop);
    gl.uniform1f(this.uEl["uUseSolidBackdrop"], ctx.useSolidBackdrop);
    gl.uniform4f(this.uEl["uSolidBackdropColor"], ctx.solidR, ctx.solidG, ctx.solidB, ctx.solidA);
    gl.uniform4f(this.uEl["uTrackColor"], ctx.trackColorR, ctx.trackColorG, ctx.trackColorB, ctx.trackColorA);
    gl.uniform4f(this.uEl["uTrackRect"], ctx.trackCenterX, ctx.trackCenterY, ctx.trackHalfW, ctx.trackHalfH);
    gl.uniform1f(this.uEl["uTrackCornerRadius"], ctx.trackCornerRadius);
    gl.uniform1f(this.uEl["uIndicatorBackdrop"], ctx.useIndicatorBackdrop);
    gl.uniform4f(this.uEl["uContainerRect"], ctx.containerRectX, ctx.containerRectY, ctx.containerHalfW, ctx.containerHalfH);
    gl.uniform1f(this.uEl["uContainerCornerRadius"], ctx.containerCornerRadius);
    gl.uniform4f(this.uEl["uIndicatorAccent"], ctx.indicatorAccentR, ctx.indicatorAccentG, ctx.indicatorAccentB, ctx.indicatorAccentA);
    gl.uniform1f(this.uEl["uInsetPx"], 4 * this.dpr);
    const qsRefractionH = this.quickToggles.refraction ? ctx.elRefractionHeight : 0;
    const qsRefractionA = this.quickToggles.refraction ? ctx.elRefractionAmount : 0;
    gl.uniform1f(this.uEl["uRefractionHeight"], qsRefractionH * this.dpr);
    gl.uniform1f(this.uEl["uRefractionAmount"], qsRefractionA * this.dpr);
    gl.uniform1f(this.uEl["uDepthEffect"], el.depthEffect ? 1 : 0);
    gl.uniform1f(this.uEl["uChromaticAberration"], el.chromaticAberration && this.quickToggles.chromatic ? 1 : 0);
    const useSampleWallpaper = el.sampleWallpaper || state.independent;
    const inlineBlurRadius = shouldUseSeparableBlur(el, state) ? 0 : ctx.elBlurRadius;
    gl.uniform1f(this.uEl["uBlurRadius"], inlineBlurRadius * layerScale * this.dpr);
    gl.uniform1f(this.uEl["uSaturation"], el.saturation);
    gl.uniform1f(this.uEl["uBrightness"], el.brightness);
    gl.uniform1f(this.uEl["uContrast"], el.contrast);
    gl.uniform1f(this.uEl["uContentScaleX"], ctx.elContentScaleX);
    gl.uniform1f(this.uEl["uContentScaleY"], ctx.elContentScaleY);
    gl.uniform4f(this.uEl["uTintColor"], el.tintColor[0], el.tintColor[1], el.tintColor[2], el.tintColor[3]);
    gl.uniform4f(this.uEl["uSurfaceColor"], el.surfaceColor[0], el.surfaceColor[1], el.surfaceColor[2], ctx.elSurfaceAlpha);
    if (el.highlight) {
      gl.uniform3f(this.uEl["uHighlightColor"], el.highlight.color[0], el.highlight.color[1], el.highlight.color[2]);
      gl.uniform1f(this.uEl["uHighlightAngle"], el.highlight.angle);
      gl.uniform1f(this.uEl["uHighlightFalloff"], el.highlight.falloff);
      gl.uniform1f(this.uEl["uHighlightAlpha"], ctx.elHighlightAlpha);
      gl.uniform1f(this.uEl["uHighlightMode"], el.highlight.mode);
      const elMinDimPx = Math.min(state.origW, state.origH) * this.dpr;
      const elWidthPx = Math.min(el.highlight.widthDp * this.dpr, elMinDimPx * 0.5);
      const elBlurPx = (el.highlight.blurRadiusDp ?? el.highlight.widthDp / 2) * this.dpr;
      const elStrokeWidth = el.highlight.aa !== false ? Math.ceil(elWidthPx) * 2 : Math.max(1, elWidthPx) * 2;
      gl.uniform1f(this.uEl["uHighlightStrokeWidth"], elStrokeWidth);
      gl.uniform1f(this.uEl["uHighlightBlur"], elBlurPx);
    } else {
      gl.uniform1f(this.uEl["uHighlightAlpha"], 0);
      gl.uniform1f(this.uEl["uHighlightMode"], 0);
      gl.uniform1f(this.uEl["uHighlightStrokeWidth"], 0);
      gl.uniform1f(this.uEl["uHighlightBlur"], 0);
    }
    const sdfSource = el.isSdfTexture?.textureSource ?? "clock";
    const sdfTex = sdfSource === "text" ? this.textSdfTexture : this.sdfTexture;
    const sdfTexSize = sdfSource === "text" ? this.textSdfTextureSize : this.sdfTextureSize;
    if (el.isSdfTexture && sdfTex) {
      gl.activeTexture(gl.TEXTURE2);
      gl.bindTexture(gl.TEXTURE_2D, sdfTex);
      gl.uniform1i(this.uEl["uSdfTexSampler"], 2);
      gl.uniform1f(this.uEl["uUseSdfTexture"], 1);
      gl.uniform2f(this.uEl["uSdfTexSize"], sdfTexSize[0], sdfTexSize[1]);
      gl.uniform1f(this.uEl["uSdfLightAngle"], el.isSdfTexture.lightAngle);
      gl.uniform1f(this.uEl["uRefractionHeight"], (this.quickToggles.refraction ? el.isSdfTexture.refractionHeight : 0) * this.dpr);
      gl.uniform1f(this.uEl["uSdfHighlightScale"], el.isSdfTexture.highlightScale ?? 1.5);
      gl.uniform1f(this.uEl["uSdfBevelEnabled"], el.isSdfTexture.bevelEnabled ?? true ? 1 : 0);
      gl.uniform1f(this.uEl["uSdfGlassTintHue"], el.isSdfTexture.glassTintHue ?? 0);
      gl.uniform1f(this.uEl["uSdfGlassTintEnabled"], el.isSdfTexture.glassTintEnabled ?? false ? 1 : 0);
      gl.uniform1f(this.uEl["uSdfGlassTintMix"], el.isSdfTexture.glassTintMix ?? 0);
      gl.uniform1f(this.uEl["uSdfGlassTintStrength"], el.isSdfTexture.glassTintStrength ?? 0.85);
      gl.uniform1f(this.uEl["uSdfEdgeMatteEnabled"], el.isSdfTexture.edgeMatteEnabled ?? false ? 1 : 0);
      gl.uniform1f(this.uEl["uSdfEdgeMatteTargets"], el.isSdfTexture.edgeMatteTargets ?? 7);
      const bevelP = el.isSdfTexture.edgeMatteBevelParams ?? [1, 0];
      gl.uniform2f(this.uEl["uSdfEdgeMatteBevelParams"], bevelP[0], bevelP[1]);
      const tintP = el.isSdfTexture.edgeMatteTintParams ?? [1, 0];
      gl.uniform2f(this.uEl["uSdfEdgeMatteTintParams"], tintP[0], tintP[1]);
      const baseP = el.isSdfTexture.edgeMatteBaseParams ?? [1, 0];
      gl.uniform2f(this.uEl["uSdfEdgeMatteBaseParams"], baseP[0], baseP[1]);
      const brightenP = el.isSdfTexture.edgeMatteBrightenParams ?? [1, 0];
      gl.uniform2f(this.uEl["uSdfEdgeMatteBrightenParams"], brightenP[0], brightenP[1]);
      gl.uniform1f(this.uEl["uSdfEdgeMatteBevelStrength"], el.isSdfTexture.edgeMatteBevelStrength ?? 1);
      gl.uniform1f(this.uEl["uSdfEdgeMatteTintStrength"], el.isSdfTexture.edgeMatteTintStrength ?? 1);
      gl.uniform1f(this.uEl["uSdfEdgeMatteBaseStrength"], el.isSdfTexture.edgeMatteBaseStrength ?? 1);
      gl.uniform1f(this.uEl["uSdfEdgeMatteBrightenStrength"], el.isSdfTexture.edgeMatteBrightenStrength ?? 1);
      gl.uniform1f(this.uEl["uSdfDebugMode"], el.isSdfTexture.debugMode ? 1 : 0);
      gl.uniform1f(this.uEl["uSdfAaMin"], el.isSdfTexture.aaMin ?? 0.5);
    } else {
      gl.uniform1f(this.uEl["uUseSdfTexture"], 0);
      gl.uniform1f(this.uEl["uSdfHighlightScale"], 1.5);
      gl.uniform1f(this.uEl["uSdfBevelEnabled"], 1);
      gl.uniform1f(this.uEl["uSdfGlassTintHue"], 0);
      gl.uniform1f(this.uEl["uSdfGlassTintEnabled"], 0);
      gl.uniform1f(this.uEl["uSdfGlassTintMix"], 0);
      gl.uniform1f(this.uEl["uSdfGlassTintStrength"], 0.85);
      gl.uniform1f(this.uEl["uSdfEdgeMatteEnabled"], 0);
      gl.uniform1f(this.uEl["uSdfEdgeMatteTargets"], 7);
      gl.uniform2f(this.uEl["uSdfEdgeMatteBevelParams"], 1, 0);
      gl.uniform2f(this.uEl["uSdfEdgeMatteTintParams"], 1, 0);
      gl.uniform2f(this.uEl["uSdfEdgeMatteBaseParams"], 1, 0);
      gl.uniform2f(this.uEl["uSdfEdgeMatteBrightenParams"], 1, 0);
      gl.uniform1f(this.uEl["uSdfEdgeMatteBevelStrength"], 1);
      gl.uniform1f(this.uEl["uSdfEdgeMatteTintStrength"], 1);
      gl.uniform1f(this.uEl["uSdfEdgeMatteBaseStrength"], 1);
      gl.uniform1f(this.uEl["uSdfEdgeMatteBrightenStrength"], 1);
      gl.uniform1f(this.uEl["uSdfDebugMode"], 0);
      gl.uniform1f(this.uEl["uSdfAaMin"], 0.5);
      if (this.dummyTex) {
        gl.activeTexture(gl.TEXTURE2);
        gl.bindTexture(gl.TEXTURE_2D, this.dummyTex);
      }
    }
    if (el.useContinuousSdf && this.continuousSdfTexture) {
      gl.activeTexture(gl.TEXTURE2);
      gl.bindTexture(gl.TEXTURE_2D, this.continuousSdfTexture);
      gl.uniform1i(this.uEl["uContinuousSdf"], 2);
      gl.uniform1f(this.uEl["uUseContinuousSdf"], 1);
      gl.uniform2f(this.uEl["uContinuousSdfTexSize"], this.continuousSdfTexSize[0], this.continuousSdfTexSize[1]);
      gl.uniform2f(this.uEl["uContinuousSdfElementSize"], state.origW * this.dpr, state.origH * this.dpr);
    } else {
      gl.uniform1f(this.uEl["uUseContinuousSdf"], 0);
      if (this.dummyTex && !el.isSdfTexture) {
        gl.activeTexture(gl.TEXTURE2);
        gl.bindTexture(gl.TEXTURE_2D, this.dummyTex);
      }
    }
    gl.uniform1f(this.uEl["uNoContinuousSdfInRefraction"], el.useContinuousSdf && !this.noContinuousSdf ? 0 : 1);
    gl.uniform1f(this.uEl["uEnterAlpha"], state.enterAlpha);
    gl.uniform1f(this.uEl["uCornerStyle"], this.cornerStyle);
    if (el.isMagnifier) {
      gl.uniform1f(this.uEl["uUseMagnifier"], 1);
      gl.uniform1f(this.uEl["uMagnifierZoom"], el.isMagnifier.zoom);
      gl.uniform1f(this.uEl["uMagnifierOffsetY"], el.isMagnifier.sampleOffsetY * this.dpr);
    } else {
      gl.uniform1f(this.uEl["uUseMagnifier"], 0);
    }
    gl.uniform1f(this.uEl["uSkipColorControls"], el.backdropFbo && shouldUseSeparableBlur(el, state) ? 1 : 0);
    gl.uniform1f(this.uEl["uSampleWallpaper"], useSampleWallpaper ? 1 : 0);
    if (el.scrimColor) {
      gl.uniform4f(this.uEl["uScrimColor"], el.scrimColor[0], el.scrimColor[1], el.scrimColor[2], el.scrimColor[3]);
    } else {
      gl.uniform4f(this.uEl["uScrimColor"], 0, 0, 0, 0);
    }
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    state.elHighlightAlpha = ctx.elHighlightAlpha;
  }
};

// src/cdn/core/renderer/inner-shadow-mask.ts
function buildPath(w, h, radius, useG2) {
  if (useG2) {
    const dummyCanvas = new OffscreenCanvas(1, 1);
    const dummyCtx = dummyCanvas.getContext("2d");
    return continuousCurvatureRoundedRectPath(dummyCtx, w, h, radius);
  }
  const path = new Path2D;
  if (typeof path.roundRect === "function") {
    path.roundRect(0, 0, w, h, radius);
  } else {
    const r = Math.min(radius, w / 2, h / 2);
    path.moveTo(r, 0);
    path.lineTo(w - r, 0);
    path.arcTo(w, 0, w, r, r);
    path.lineTo(w, h - r);
    path.arcTo(w, h, w - r, h, r);
    path.lineTo(r, h);
    path.arcTo(0, h, 0, h - r, r);
    path.lineTo(0, r);
    path.arcTo(0, 0, r, 0, r);
    path.closePath();
  }
  return path;
}
function createCanvas(w, h) {
  const canvas = new OffscreenCanvas(w, h);
  const ctx = canvas.getContext("2d", { alpha: true });
  return { canvas, ctx };
}
function generateInnerShadowMask(params) {
  const { w, h, radius, offsetX, offsetY, blurSigma, margin, useG2, supersample: SS } = params;
  const maskW = Math.max(1, Math.ceil(w + 2 * margin));
  const maskH = Math.max(1, Math.ceil(h + 2 * margin));
  const canvasW = maskW * SS;
  const canvasH = maskH * SS;
  const { canvas: tempCanvas, ctx: tCtx } = createCanvas(canvasW, canvasH);
  const { canvas: outputCanvas, ctx: oCtx } = createCanvas(canvasW, canvasH);
  tCtx.save();
  tCtx.scale(SS, SS);
  tCtx.translate(margin, margin);
  const path = buildPath(w, h, radius, useG2);
  tCtx.clip(path);
  tCtx.globalCompositeOperation = "source-over";
  tCtx.fillStyle = "white";
  tCtx.fill(path);
  tCtx.globalCompositeOperation = "destination-out";
  tCtx.save();
  tCtx.translate(offsetX, offsetY);
  tCtx.fill(path);
  tCtx.restore();
  tCtx.globalCompositeOperation = "source-over";
  tCtx.restore();
  if (blurSigma > 0.01) {
    oCtx.filter = `blur(${blurSigma * SS}px)`;
  } else {
    oCtx.filter = "none";
  }
  oCtx.drawImage(tempCanvas, 0, 0);
  oCtx.filter = "none";
  return { canvas: outputCanvas, maskW, maskH, margin };
}

// src/cdn/core/renderer/inner-shadow-cache.ts
var MAX_CACHE_SIZE = 32;
function buildMaskKey(shadowIndex, params) {
  return [
    "is",
    shadowIndex,
    params.useG2 ? "g2" : "rr",
    params.w.toFixed(3),
    params.h.toFixed(3),
    params.radius.toFixed(3),
    params.offsetX.toFixed(3),
    params.offsetY.toFixed(3),
    params.blurSigma.toFixed(3),
    params.margin,
    Math.ceil(params.w + 2 * params.margin),
    Math.ceil(params.h + 2 * params.margin),
    `ss${params.supersample}`
  ].join(":");
}
function getOrCreateMaskEntry(cache, gl, key, maskW, maskH) {
  let entry = cache.get(key);
  if (entry)
    return entry;
  const tex = gl.createTexture();
  if (!tex)
    throw new Error("WebGL texture allocation failed");
  entry = { tex, w: maskW, h: maskH, ready: false };
  cache.set(key, entry);
  if (cache.size > MAX_CACHE_SIZE) {
    const oldestKey = cache.keys().next().value;
    if (oldestKey && oldestKey !== key) {
      const oldest = cache.get(oldestKey);
      if (oldest)
        gl.deleteTexture(oldest.tex);
      cache.delete(oldestKey);
    }
  }
  return entry;
}
function uploadMaskTexture(gl, entry, result) {
  gl.bindTexture(gl.TEXTURE_2D, entry.tex);
  gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
  gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, result.canvas);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
  entry.ready = true;
}
function destroyCache(gl, cache) {
  for (const entry of cache.values()) {
    gl.deleteTexture(entry.tex);
  }
  cache.clear();
}

// src/cdn/core/renderer/methods-render-glass-post-passes-inner-shadow.ts
function renderGlassInnerShadowPass(renderer, state) {
  const gl = renderer.gl;
  const { el, sx, sy, sw, sh, radii, togglePressProgress } = state;
  if (!el.innerShadow || !renderer.quickToggles.innershadow)
    return;
  const origSizeX = state.origW * renderer.dpr;
  const origSizeY = state.origH * renderer.dpr;
  const origRadius = state.origCornerRadius * renderer.dpr;
  const layerScaleX = state.layerScaleX;
  const layerScaleY = state.layerScaleY;
  drawInnerShadowPass(renderer, state, el.innerShadow, 0);
  function drawInnerShadowPass(r, st, shadowCfg, shadowIndex) {
    const progress = st.el.isToggleKnob || st.el.isBottomTabIndicator ? togglePressProgress : 1;
    const shadowAlpha = shadowCfg.alpha * progress * st.enterAlpha;
    const shadowRadius = shadowCfg.radius * progress;
    const shadowOffsetX = shadowCfg.offsetX * progress;
    const shadowOffsetY = shadowCfg.offsetY * progress;
    if (shadowAlpha <= 0.001 || shadowRadius <= 0.5)
      return;
    const blurSigma = shadowRadius * r.dpr;
    const margin = Math.ceil(blurSigma * 3) + 2;
    const maskW = Math.max(1, Math.ceil(origSizeX + 2 * margin));
    const maskH = Math.max(1, Math.ceil(origSizeY + 2 * margin));
    const deviceDpr = window.devicePixelRatio || 1;
    const SS = Math.min(2, Math.max(1, Math.floor(deviceDpr / r.dpr)));
    const useG2 = !!st.el.useContinuousSdf;
    const offsetXDp = shadowOffsetX * r.dpr;
    const offsetYDp = shadowOffsetY * r.dpr;
    const maskParams = {
      w: origSizeX,
      h: origSizeY,
      radius: origRadius,
      offsetX: offsetXDp,
      offsetY: offsetYDp,
      blurSigma,
      margin,
      useG2,
      supersample: SS
    };
    const key = buildMaskKey(shadowIndex, maskParams);
    const entry = getOrCreateMaskEntry(r.innerShadowMaskCache, gl, key, maskW, maskH);
    if (!entry.ready) {
      const result = generateInnerShadowMask(maskParams);
      uploadMaskTexture(gl, entry, result);
    }
    gl.enable(gl.BLEND);
    gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
    gl.useProgram(r.innerShadowMaskCompositeProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, r.quadBuffer);
    gl.enableVertexAttribArray(r.aPosLocIs);
    gl.vertexAttribPointer(r.aPosLocIs, 2, gl.FLOAT, false, 0, 0);
    gl.uniform2f(r.uIs["uCanvasSize"], r.canvas.width, r.canvas.height);
    gl.uniform2f(r.uIs["uOffset"], sx * r.dpr, sy * r.dpr);
    gl.uniform2f(r.uIs["uSize"], sw * r.dpr, sh * r.dpr);
    gl.uniform4f(r.uIs["uCornerRadii"], radii[0] * r.dpr, radii[1] * r.dpr, radii[2] * r.dpr, radii[3] * r.dpr);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, entry.tex);
    gl.uniform1i(r.uIs["uInnerShadowMask"], 0);
    gl.uniform2f(r.uIs["uMaskOffset"], margin, margin);
    gl.uniform2f(r.uIs["uMaskSize"], entry.w, entry.h);
    const color = shadowCfg.color ?? [0, 0, 0];
    gl.uniform3f(r.uIs["uInnerShadowColor"], color[0], color[1], color[2]);
    gl.uniform1f(r.uIs["uInnerShadowAlpha"], shadowAlpha);
    gl.uniform2f(r.uIs["uOriginalSize"], origSizeX, origSizeY);
    gl.uniform1f(r.uIs["uOriginalCornerRadius"], origRadius);
    gl.uniform2f(r.uIs["uLayerScale"], layerScaleX, layerScaleY);
    gl.uniform1f(r.uIs["uElementRotation"], st.elementRotation);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
  }
}

// src/cdn/core/renderer/methods-render-glass-post-passes-glow.ts
function renderGlassGlowAndOverlays(renderer, state) {
  const gl = renderer.gl;
  const { el, st, isButton, p, sx, sy, sw, sh, radii, togglePressProgress } = state;
  const origSizeX = state.origW * renderer.dpr;
  const origSizeY = state.origH * renderer.dpr;
  const origRadius = state.origCornerRadius * renderer.dpr;
  const layerScaleX = state.layerScaleX;
  const layerScaleY = state.layerScaleY;
  const bindTintContinuousSdf = () => {
    if (el.useContinuousSdf && renderer.continuousSdfTexture) {
      gl.activeTexture(gl.TEXTURE2);
      gl.bindTexture(gl.TEXTURE_2D, renderer.continuousSdfTexture);
      gl.uniform1i(renderer.uTn["uContinuousSdf"], 2);
      gl.uniform1f(renderer.uTn["uUseContinuousSdf"], 1);
      gl.uniform2f(renderer.uTn["uContinuousSdfTexSize"], renderer.continuousSdfTexSize[0], renderer.continuousSdfTexSize[1]);
      gl.uniform2f(renderer.uTn["uContinuousSdfElementSize"], state.origW * renderer.dpr, state.origH * renderer.dpr);
    } else {
      gl.uniform1f(renderer.uTn["uUseContinuousSdf"], 0);
    }
  };
  const isContainer = !!el.isBottomTabContainer;
  const glowP = isButton ? p : isContainer ? togglePressProgress : 0;
  if (isButton && el.isInteractive && st && p > 0.001 || isContainer && togglePressProgress > 0.001) {
    gl.useProgram(renderer.tintProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, renderer.quadBuffer);
    gl.enableVertexAttribArray(renderer.aPosLocTn);
    gl.vertexAttribPointer(renderer.aPosLocTn, 2, gl.FLOAT, false, 0, 0);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE);
    gl.uniform2f(renderer.uTn["uCanvasSize"], renderer.canvas.width, renderer.canvas.height);
    gl.uniform2f(renderer.uTn["uOffset"], sx * renderer.dpr, sy * renderer.dpr);
    gl.uniform2f(renderer.uTn["uSize"], sw * renderer.dpr, sh * renderer.dpr);
    gl.uniform4f(renderer.uTn["uCornerRadii"], radii[0] * renderer.dpr, radii[1] * renderer.dpr, radii[2] * renderer.dpr, radii[3] * renderer.dpr);
    gl.uniform2f(renderer.uTn["uOriginalSize"], origSizeX, origSizeY);
    gl.uniform1f(renderer.uTn["uOriginalCornerRadius"], origRadius);
    gl.uniform2f(renderer.uTn["uLayerScale"], layerScaleX, layerScaleY);
    gl.uniform1f(renderer.uTn["uElementRotation"], state.elementRotation);
    gl.uniform1f(renderer.uTn["uCornerStyle"], renderer.cornerStyle);
    bindTintContinuousSdf();
    gl.uniform4f(renderer.uTn["uColor"], 1, 1, 1, 0.08 * glowP);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    gl.useProgram(renderer.highlightProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, renderer.quadBuffer);
    gl.enableVertexAttribArray(renderer.aPosLocHl);
    gl.vertexAttribPointer(renderer.aPosLocHl, 2, gl.FLOAT, false, 0, 0);
    gl.blendFunc(gl.ONE, gl.ONE);
    gl.uniform2f(renderer.uHl["uCanvasSize"], renderer.canvas.width, renderer.canvas.height);
    gl.uniform2f(renderer.uHl["uOffset"], sx * renderer.dpr, sy * renderer.dpr);
    gl.uniform2f(renderer.uHl["uSize"], sw * renderer.dpr, sh * renderer.dpr);
    gl.uniform4f(renderer.uHl["uCornerRadii"], radii[0] * renderer.dpr, radii[1] * renderer.dpr, radii[2] * renderer.dpr, radii[3] * renderer.dpr);
    gl.uniform2f(renderer.uHl["uOriginalSize"], origSizeX, origSizeY);
    gl.uniform1f(renderer.uHl["uOriginalCornerRadius"], origRadius);
    gl.uniform2f(renderer.uHl["uLayerScale"], layerScaleX, layerScaleY);
    gl.uniform1f(renderer.uHl["uElementRotation"], state.elementRotation);
    gl.uniform1f(renderer.uHl["uCornerStyle"], renderer.cornerStyle);
    gl.uniform4f(renderer.uHl["uColor"], 1, 1, 1, 0.15 * glowP);
    const minDim = Math.min(sw, sh) * renderer.dpr;
    gl.uniform1f(renderer.uHl["uRadius"], minDim * 1.5);
    let px, py;
    if (isContainer) {
      const tg = renderer.toggleStates.get(el.isBottomTabContainer.groupId);
      const tabsCount = el.isBottomTabContainer.tabsCount ?? 4;
      const tabW = el.rect.w / tabsCount;
      const fraction = tg ? tg.fraction : 0;
      const indCenterX = (fraction + 0.5) * tabW;
      const scaleToLocal = sw / el.rect.w;
      px = Math.max(0, Math.min(sw, indCenterX * scaleToLocal)) * renderer.dpr;
      py = sh / 2 * renderer.dpr;
    } else {
      px = Math.max(0, Math.min(sw, st.dragX * state.layerScaleX)) * renderer.dpr;
      py = Math.max(0, Math.min(sh, st.dragY * state.layerScaleY)) * renderer.dpr;
    }
    gl.uniform2f(renderer.uHl["uPosition"], px, py);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
  }
  if (el.isToggleKnob && togglePressProgress < 0.999) {
    const whiteAlpha = 1 * (1 - togglePressProgress);
    gl.useProgram(renderer.tintProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, renderer.quadBuffer);
    gl.enableVertexAttribArray(renderer.aPosLocTn);
    gl.vertexAttribPointer(renderer.aPosLocTn, 2, gl.FLOAT, false, 0, 0);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
    gl.uniform2f(renderer.uTn["uCanvasSize"], renderer.canvas.width, renderer.canvas.height);
    gl.uniform2f(renderer.uTn["uOffset"], sx * renderer.dpr, sy * renderer.dpr);
    gl.uniform2f(renderer.uTn["uSize"], sw * renderer.dpr, sh * renderer.dpr);
    gl.uniform4f(renderer.uTn["uCornerRadii"], radii[0] * renderer.dpr, radii[1] * renderer.dpr, radii[2] * renderer.dpr, radii[3] * renderer.dpr);
    gl.uniform2f(renderer.uTn["uOriginalSize"], origSizeX, origSizeY);
    gl.uniform1f(renderer.uTn["uOriginalCornerRadius"], origRadius);
    gl.uniform2f(renderer.uTn["uLayerScale"], layerScaleX, layerScaleY);
    gl.uniform1f(renderer.uTn["uElementRotation"], state.elementRotation);
    gl.uniform1f(renderer.uTn["uCornerStyle"], renderer.cornerStyle);
    bindTintContinuousSdf();
    gl.uniform4f(renderer.uTn["uColor"], 1, 1, 1, whiteAlpha);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
  }
  if (el.isBottomTabIndicator && el.isBottomTabIndicator.dimColor) {
    const dc = el.isBottomTabIndicator.dimColor;
    const prog = togglePressProgress;
    gl.useProgram(renderer.tintProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, renderer.quadBuffer);
    gl.enableVertexAttribArray(renderer.aPosLocTn);
    gl.vertexAttribPointer(renderer.aPosLocTn, 2, gl.FLOAT, false, 0, 0);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
    gl.uniform2f(renderer.uTn["uCanvasSize"], renderer.canvas.width, renderer.canvas.height);
    gl.uniform2f(renderer.uTn["uOffset"], sx * renderer.dpr, sy * renderer.dpr);
    gl.uniform2f(renderer.uTn["uSize"], sw * renderer.dpr, sh * renderer.dpr);
    gl.uniform4f(renderer.uTn["uCornerRadii"], radii[0] * renderer.dpr, radii[1] * renderer.dpr, radii[2] * renderer.dpr, radii[3] * renderer.dpr);
    gl.uniform2f(renderer.uTn["uOriginalSize"], origSizeX, origSizeY);
    gl.uniform1f(renderer.uTn["uOriginalCornerRadius"], origRadius);
    gl.uniform2f(renderer.uTn["uLayerScale"], layerScaleX, layerScaleY);
    gl.uniform1f(renderer.uTn["uElementRotation"], state.elementRotation);
    gl.uniform1f(renderer.uTn["uCornerStyle"], renderer.cornerStyle);
    bindTintContinuousSdf();
    gl.uniform4f(renderer.uTn["uColor"], dc[0], dc[1], dc[2], 0.1 * (1 - prog));
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    gl.uniform4f(renderer.uTn["uColor"], 0, 0, 0, 0.03 * prog);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
  }
}

// src/cdn/core/renderer/methods-render-glass-post-passes-rim-highlight.ts
function renderGlassRimHighlight(renderer, state) {
  const gl = renderer.gl;
  const { el, sx, sy, sw, sh, radii, togglePressProgress, elHighlightAlpha } = state;
  if (!el.highlight || el.highlight.alpha <= 0.001 || !renderer.quickToggles.highlight)
    return;
  const origSizeX = state.origW * renderer.dpr;
  const origSizeY = state.origH * renderer.dpr;
  const origRadius = state.origCornerRadius * renderer.dpr;
  const layerScaleX = state.layerScaleX;
  const layerScaleY = state.layerScaleY;
  const rimAlpha = el.isToggleKnob || el.isBottomTabIndicator ? elHighlightAlpha : el.highlight.alpha;
  const paintAlpha = el.highlight.mode === 1 ? 0.38 : 1;
  const finalAlpha = rimAlpha * state.enterAlpha * paintAlpha;
  if (finalAlpha <= 0.001)
    return;
  const widthPx = Math.min(el.highlight.widthDp * renderer.dpr, Math.min(origSizeX, origSizeY) * 0.5);
  const strokeWidthDevice = el.highlight.aa !== false ? Math.max(1, Math.ceil(widthPx) * 2) : Math.max(1, Math.round(widthPx) * 2);
  const blurPx = Math.max(0, (el.highlight.blurRadiusDp ?? el.highlight.widthDp / 2) * renderer.dpr);
  const strokeMargin = Math.ceil(strokeWidthDevice) + 4;
  const maskW = Math.max(1, Math.ceil(origSizeX + 2 * strokeMargin));
  const maskH = Math.max(1, Math.ceil(origSizeY + 2 * strokeMargin));
  const deviceDpr = window.devicePixelRatio || 1;
  const SS = Math.min(2, Math.max(1, Math.floor(deviceDpr / renderer.dpr)));
  const canvasW = maskW * SS;
  const canvasH = maskH * SS;
  const useG2 = !!el.useContinuousSdf;
  const maskKey = [
    useG2 ? "g2" : "rr",
    origSizeX.toFixed(3),
    origSizeY.toFixed(3),
    origRadius.toFixed(3),
    strokeWidthDevice,
    blurPx.toFixed(3),
    strokeMargin,
    maskW,
    maskH,
    `ss${SS}`
  ].join(":");
  let mask = renderer.strokeMaskCache.get(maskKey);
  if (!mask) {
    const canvas = document.createElement("canvas");
    canvas.width = canvasW;
    canvas.height = canvasH;
    const ctx2d = canvas.getContext("2d", { alpha: true });
    if (!ctx2d)
      throw new Error("2D canvas not supported");
    const tex = gl.createTexture();
    if (!tex)
      throw new Error("WebGL texture allocation failed");
    mask = { tex, canvas, ctx: ctx2d, w: maskW, h: maskH, ready: false };
    renderer.strokeMaskCache.set(maskKey, mask);
    if (renderer.strokeMaskCache.size > 32) {
      const oldestKey = renderer.strokeMaskCache.keys().next().value;
      if (oldestKey && oldestKey !== maskKey) {
        const oldest = renderer.strokeMaskCache.get(oldestKey);
        if (oldest)
          gl.deleteTexture(oldest.tex);
        renderer.strokeMaskCache.delete(oldestKey);
      }
    }
  }
  if (!mask.ready) {
    const smCtx = mask.ctx;
    smCtx.clearRect(0, 0, canvasW, canvasH);
    smCtx.save();
    smCtx.scale(SS, SS);
    smCtx.translate(strokeMargin, strokeMargin);
    let path;
    if (useG2) {
      path = continuousCurvatureRoundedRectPath(smCtx, origSizeX, origSizeY, origRadius);
    } else {
      path = new Path2D;
      const r = Math.min(origRadius, origSizeX / 2, origSizeY / 2);
      path.moveTo(r, 0);
      path.lineTo(origSizeX - r, 0);
      path.arcTo(origSizeX, 0, origSizeX, r, r);
      path.lineTo(origSizeX, origSizeY - r);
      path.arcTo(origSizeX, origSizeY, origSizeX - r, origSizeY, r);
      path.lineTo(r, origSizeY);
      path.arcTo(0, origSizeY, 0, origSizeY - r, r);
      path.lineTo(0, r);
      path.arcTo(0, 0, r, 0, r);
      path.closePath();
    }
    smCtx.clip(path);
    smCtx.lineWidth = strokeWidthDevice;
    smCtx.strokeStyle = "rgba(255,255,255,1)";
    smCtx.lineJoin = "round";
    smCtx.lineCap = "round";
    smCtx.filter = blurPx > 0.01 ? `blur(${blurPx}px)` : "none";
    smCtx.stroke(path);
    smCtx.filter = "none";
    smCtx.restore();
    gl.bindTexture(gl.TEXTURE_2D, mask.tex);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, mask.canvas);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    mask.ready = true;
  }
  gl.enable(gl.BLEND);
  if (el.highlight.mode === 1) {
    gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
  } else {
    gl.blendFunc(gl.ONE, gl.ONE);
  }
  gl.useProgram(renderer.strokeMaskCompositeProgram);
  gl.bindBuffer(gl.ARRAY_BUFFER, renderer.quadBuffer);
  gl.enableVertexAttribArray(renderer.aPosLocSm);
  gl.vertexAttribPointer(renderer.aPosLocSm, 2, gl.FLOAT, false, 0, 0);
  gl.uniform2f(renderer.uSm["uCanvasSize"], renderer.canvas.width, renderer.canvas.height);
  gl.uniform2f(renderer.uSm["uOffset"], sx * renderer.dpr, sy * renderer.dpr);
  gl.uniform2f(renderer.uSm["uSize"], sw * renderer.dpr, sh * renderer.dpr);
  gl.uniform4f(renderer.uSm["uCornerRadii"], radii[0] * renderer.dpr, radii[1] * renderer.dpr, radii[2] * renderer.dpr, radii[3] * renderer.dpr);
  gl.activeTexture(gl.TEXTURE0);
  gl.bindTexture(gl.TEXTURE_2D, mask.tex);
  gl.uniform1i(renderer.uSm["uStrokeMask"], 0);
  gl.uniform2f(renderer.uSm["uMaskOffset"], strokeMargin, strokeMargin);
  gl.uniform2f(renderer.uSm["uMaskSize"], mask.w, mask.h);
  gl.uniform4f(renderer.uSm["uHighlightColor"], el.highlight.color[0], el.highlight.color[1], el.highlight.color[2], 1);
  gl.uniform1f(renderer.uSm["uHighlightAngle"], el.useGravityAngle ? renderer.gravityAngle : el.highlight.angle);
  gl.uniform1f(renderer.uSm["uHighlightFalloff"], el.highlight.falloff);
  gl.uniform1f(renderer.uSm["uHighlightAlpha"], finalAlpha);
  gl.uniform1f(renderer.uSm["uHighlightMode"], el.highlight.mode);
  gl.uniform2f(renderer.uSm["uOriginalSize"], origSizeX, origSizeY);
  gl.uniform1f(renderer.uSm["uOriginalCornerRadius"], origRadius);
  gl.uniform2f(renderer.uSm["uLayerScale"], layerScaleX, layerScaleY);
  gl.uniform1f(renderer.uSm["uElementRotation"], state.elementRotation);
  gl.drawArrays(gl.TRIANGLES, 0, 6);
  gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
}

// src/cdn/core/renderer/methods-render-glass-post-passes.ts
var glassPostPassMethods = {
  renderGlassPostPasses(state) {
    const gl = this.gl;
    const { el, st, isButton, p, sx, sy, sw, sh, radii } = state;
    const origSizeX = state.origW * this.dpr;
    const origSizeY = state.origH * this.dpr;
    const origRadius = state.origCornerRadius * this.dpr;
    const layerScaleX = state.layerScaleX;
    const layerScaleY = state.layerScaleY;
    renderGlassInnerShadowPass(this, state);
    renderGlassGlowAndOverlays(this, state);
    if (isButton && (el.label || el.icon)) {
      const fgTex = this.fgTextures.get(el.id);
      if (fgTex) {
        gl.useProgram(this.foregroundProgram);
        gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
        gl.enableVertexAttribArray(this.aPosLocFg);
        gl.vertexAttribPointer(this.aPosLocFg, 2, gl.FLOAT, false, 0, 0);
        gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
        gl.activeTexture(gl.TEXTURE0);
        gl.bindTexture(gl.TEXTURE_2D, fgTex);
        gl.uniform1i(this.uFg["uTexture"], 0);
        gl.uniform2f(this.uFg["uCanvasSize"], this.canvas.width, this.canvas.height);
        gl.uniform2f(this.uFg["uOffset"], sx * this.dpr, sy * this.dpr);
        gl.uniform2f(this.uFg["uSize"], sw * this.dpr, sh * this.dpr);
        gl.uniform4f(this.uFg["uCornerRadii"], radii[0] * this.dpr, radii[1] * this.dpr, radii[2] * this.dpr, radii[3] * this.dpr);
        gl.uniform2f(this.uFg["uOriginalSize"], origSizeX, origSizeY);
        gl.uniform1f(this.uFg["uOriginalCornerRadius"], origRadius);
        gl.uniform2f(this.uFg["uLayerScale"], layerScaleX, layerScaleY);
        gl.uniform1f(this.uFg["uCornerStyle"], this.cornerStyle);
        if (el.useContinuousSdf && this.continuousSdfTexture) {
          gl.activeTexture(gl.TEXTURE2);
          gl.bindTexture(gl.TEXTURE_2D, this.continuousSdfTexture);
          gl.uniform1i(this.uFg["uContinuousSdf"], 2);
          gl.uniform1f(this.uFg["uUseContinuousSdf"], 1);
          gl.uniform2f(this.uFg["uContinuousSdfTexSize"], this.continuousSdfTexSize[0], this.continuousSdfTexSize[1]);
          gl.uniform2f(this.uFg["uContinuousSdfElementSize"], state.origW * this.dpr, state.origH * this.dpr);
        } else {
          gl.uniform1f(this.uFg["uUseContinuousSdf"], 0);
        }
        gl.uniform1f(this.uFg["uAlpha"], 1 - 0.15 * p);
        gl.drawArrays(gl.TRIANGLES, 0, 6);
        gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
      }
    }
    renderGlassRimHighlight(this, state);
  }
};

// src/cdn/core/renderer/methods-dirty.ts
var dirtyTrackingMethods = {
  markElementDirty(id) {
    this.dirtyElementIds.add(id);
    const entry = this.elFboCache.get(id);
    if (entry)
      entry.valid = false;
    if (this.showDirtyMarkers) {
      const stack = new Error().stack ?? "";
      const lines = stack.split(`
`);
      let source = "unknown";
      for (let i = 2;i < lines.length; i++) {
        const ln = lines[i].trim();
        if (!ln)
          continue;
        if (ln.includes("markElementDirty") || ln.includes("markGroupDirty") || ln.includes("markAllDirty"))
          continue;
        const m = ln.match(/at\s+(\S+)\s+\(/);
        source = m ? m[1] : ln.slice(0, 60);
        break;
      }
      this.debugDirtySourceLog.push({ id, source });
    }
  },
  markAllDirty() {
    this.allDirty = true;
    this.dirtyElementIds.clear();
    for (const entry of this.elFboCache.values())
      entry.valid = false;
  },
  markGroupDirty(groupId) {
    for (const el of this.buttonConfigs) {
      if (el.isToggleKnob?.groupId === groupId || el.isToggleTrack?.groupId === groupId || el.isSliderFill?.groupId === groupId || el.isBottomTabContainer?.groupId === groupId || el.isBottomTabContent?.groupId === groupId || el.isBottomTabIndicator?.groupId === groupId) {
        this.markElementDirty(el.id);
      }
    }
  },
  markGravityDirty() {
    for (const el of this.buttonConfigs) {
      if (el.useGravityAngle)
        this.markElementDirty(el.id);
    }
  },
  hasDirtyElements() {
    return this.allDirty || this.dirtyElementIds.size > 0;
  },
  deleteElFboCacheEntry(id) {
    const entry = this.elFboCache.get(id);
    if (!entry)
      return;
    const gl = this.gl;
    gl.deleteFramebuffer(entry.fb);
    gl.deleteTexture(entry.tex);
    this.elFboCache.delete(id);
  }
};

// src/cdn/core/renderer/methods-debug.ts
function analyzeEdgeScan(scan) {
  const { pixels, dpr } = scan;
  const N = pixels.length;
  if (N < 4) {
    return {
      edgeIdx: 0,
      edgeOffsetCss: 0,
      transitionHalfW: 0,
      rgbInside: 0,
      rgbOutside: 0,
      minRgbInTransition: 0,
      blackFringeDetected: false,
      hasNearBlackPx: false,
      canvasOpaque: true,
      verdict: "Scan too short (element not found or off-screen)."
    };
  }
  const lum = new Float32Array(N);
  let opaqueCount = 0;
  for (let i = 0;i < N; i++) {
    const p = pixels[i];
    lum[i] = 0.299 * p.r + 0.587 * p.g + 0.114 * p.b;
    if (p.a >= 250)
      opaqueCount++;
  }
  const canvasOpaque = opaqueCount > N * 0.9;
  let maxGrad = 0;
  let edgeIdx = Math.floor(N / 2);
  for (let i = 2;i < N - 2; i++) {
    const g = Math.abs(lum[i + 1] - lum[i - 1]);
    if (g > maxGrad) {
      maxGrad = g;
      edgeIdx = i;
    }
  }
  const edgeOffsetCss = pixels[edgeIdx].offset;
  const transitionHalfW = Math.max(3, Math.floor(N / 8));
  const zoneStart = Math.max(0, edgeIdx - transitionHalfW);
  const zoneEnd = Math.min(N - 1, edgeIdx + transitionHalfW);
  const insideStart = Math.max(0, zoneStart - 3);
  const insideEnd = Math.max(insideStart, zoneStart - 1);
  let rgbInside = 0, insideCount = 0;
  for (let i = insideStart;i <= insideEnd; i++) {
    rgbInside += lum[i];
    insideCount++;
  }
  rgbInside = insideCount > 0 ? rgbInside / insideCount : lum[0];
  const outsideStart = Math.min(N - 1, zoneEnd + 1);
  const outsideEnd = Math.min(N - 1, zoneEnd + 3);
  let rgbOutside = 0, outsideCount = 0;
  for (let i = outsideStart;i <= outsideEnd; i++) {
    rgbOutside += lum[i];
    outsideCount++;
  }
  rgbOutside = outsideCount > 0 ? rgbOutside / outsideCount : lum[N - 1];
  let minRgbInTransition = 255;
  let hasNearBlackPx = false;
  for (let i = zoneStart;i <= zoneEnd; i++) {
    const l = lum[i];
    if (l < minRgbInTransition)
      minRgbInTransition = l;
    if (l < 30)
      hasNearBlackPx = true;
  }
  const threshold = 25;
  const blackFringeDetected = maxGrad > 10 && minRgbInTransition < Math.min(rgbInside, rgbOutside) - threshold && minRgbInTransition < 100;
  let verdict;
  if (blackFringeDetected && hasNearBlackPx) {
    verdict = `⚠ BLACK FRINGE: RGB dips to ${minRgbInTransition.toFixed(0)} at edge (inside=${rgbInside.toFixed(0)}, outside=${rgbOutside.toFixed(0)}). Near-black pixels in transition zone → premult-alpha leak or refraction reads outside FBO.`;
  } else if (blackFringeDetected) {
    verdict = `⚠ DARK EDGE: RGB dips to ${minRgbInTransition.toFixed(0)} at edge (inside=${rgbInside.toFixed(0)}, outside=${rgbOutside.toFixed(0)}). Edge is darker than both sides.`;
  } else if (hasNearBlackPx && maxGrad > 10) {
    verdict = `⚠ NEAR-BLACK PX at edge: min RGB ${minRgbInTransition.toFixed(0)} (inside=${rgbInside.toFixed(0)}, outside=${rgbOutside.toFixed(0)}). Investigate.`;
  } else if (maxGrad <= 10) {
    verdict = `~ Flat scan (no sharp edge detected). Max gradient ${maxGrad.toFixed(1)}. Element may be off-screen or uniformly colored.`;
  } else {
    verdict = `✓ Clean edge. Transition RGB ${minRgbInTransition.toFixed(0)} is between inside ${rgbInside.toFixed(0)} and outside ${rgbOutside.toFixed(0)}. No black fringe.`;
  }
  return {
    edgeIdx,
    edgeOffsetCss,
    transitionHalfW,
    rgbInside,
    rgbOutside,
    minRgbInTransition,
    blackFringeDetected,
    hasNearBlackPx,
    canvasOpaque,
    verdict
  };
}
var debugMethods = {
  debugReadEdgeScanline(halfRangeCss = 20) {
    this._pendingEdgeScan = { halfRangeCss };
    this.requestRender();
  },
  debugCycleEdgeScanTarget() {
    const candidates = this.buttonConfigs.filter((e) => e.useContinuousSdf && e.rect.w > 0 && e.rect.h > 0);
    if (candidates.length === 0)
      return 0;
    this._edgeScanTargetIdx = (this._edgeScanTargetIdx + 1) % candidates.length;
    this._pendingEdgeScan = { halfRangeCss: 20 };
    this.requestRender();
    return this._edgeScanTargetIdx;
  },
  debugClearEdgeScan() {
    this._pendingEdgeScan = null;
    this._edgeScanResult = null;
    this._edgeScanCounter++;
  },
  _debugFlushPendingEdgeScan() {
    const pending = this._pendingEdgeScan;
    if (!pending)
      return;
    this._pendingEdgeScan = null;
    const candidates = this.buttonConfigs.filter((e) => e.useContinuousSdf && e.rect.w > 0 && e.rect.h > 0).map((e) => {
      const minDim = Math.min(e.rect.w, e.rect.h);
      const isCapsule = e.cornerRadius >= minDim / 2 - 0.5;
      return { el: e, isCapsule };
    }).sort((a, b) => Number(b.isCapsule) - Number(a.isCapsule));
    if (candidates.length === 0) {
      this._edgeScanCounter++;
      this._edgeScanResult = {
        scanId: this._edgeScanCounter,
        elementId: "(none)",
        targetIdx: 0,
        targetCount: 0,
        isCapsule: false,
        rect: { x: 0, y: 0, w: 0, h: 0 },
        cornerRadius: 0,
        dpr: this.dpr || 1,
        cornerCenter: { x: 0, y: 0 },
        cornerPoint45: { x: 0, y: 0 },
        patchCssX: 0,
        patchCssY: 0,
        patchDevSize: 0,
        halfRange: pending.halfRangeCss,
        patch: new Uint8Array(0),
        pixels: [],
        sdfProfile: null,
        sdfTexSize: 0,
        analysis: {
          edgeIdx: 0,
          edgeOffsetCss: 0,
          transitionHalfW: 0,
          rgbInside: 0,
          rgbOutside: 0,
          minRgbInTransition: 0,
          blackFringeDetected: false,
          hasNearBlackPx: false,
          canvasOpaque: true,
          verdict: "No useContinuousSdf element found on screen."
        }
      };
      return;
    }
    const targetIdx = this._edgeScanTargetIdx % candidates.length;
    const picked = candidates[targetIdx];
    const el = picked.el;
    const { rect, cornerRadius: r } = el;
    const dpr = this.dpr || 1;
    const gl = this.gl;
    const halfRangeCss = pending.halfRangeCss;
    const sqrt2 = Math.SQRT2;
    const cornerCx = rect.x + rect.w - r;
    const cornerCy = rect.y + r;
    const p45x = cornerCx + r / sqrt2;
    const p45y = cornerCy - r / sqrt2;
    const patchCssX = p45x - halfRangeCss;
    const patchCssY = p45y - halfRangeCss;
    const patchCssSize = halfRangeCss * 2;
    const patchDevSize = Math.max(1, Math.round(patchCssSize * dpr));
    const patchDevX = Math.round(patchCssX * dpr);
    const patchDevYTop = Math.round(patchCssY * dpr);
    const clampedW = Math.min(patchDevSize, this.canvas.width - patchDevX);
    const clampedH = Math.min(patchDevSize, this.canvas.height - patchDevYTop);
    if (clampedW <= 0 || clampedH <= 0)
      return;
    const readY = this.canvas.height - (patchDevYTop + clampedH);
    const clampedReadY = Math.max(0, Math.min(this.canvas.height - clampedH, readY));
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    const buf = new Uint8Array(clampedW * clampedH * 4);
    gl.readPixels(patchDevX, clampedReadY, clampedW, clampedH, gl.RGBA, gl.UNSIGNED_BYTE, buf);
    const patch = new Uint8Array(clampedW * clampedH * 4);
    for (let row = 0;row < clampedH; row++) {
      const srcRow = clampedH - 1 - row;
      patch.set(buf.subarray(srcRow * clampedW * 4, (srcRow + 1) * clampedW * 4), row * clampedW * 4);
    }
    const diagN = Math.min(clampedW, clampedH);
    const pixels = [];
    for (let i = 0;i < diagN; i++) {
      const col = clampedW - 1 - i;
      const row = i;
      const idx = (row * clampedW + col) * 4;
      const offset = (diagN / 2 - i) / dpr;
      pixels.push({
        offset,
        r: patch[idx],
        g: patch[idx + 1],
        b: patch[idx + 2],
        a: patch[idx + 3]
      });
    }
    this._edgeScanCounter++;
    let sdfProfile = null;
    let sdfTexSize = 0;
    const maskEntries = getMaskCacheEntries();
    const elW = rect.w;
    const elH = rect.h;
    const elR = Math.round(r);
    const matchedEntry = maskEntries.find((e) => {
      const parts = e.key.split(",");
      return Math.round(parseFloat(parts[0])) === Math.round(elW) && Math.round(parseFloat(parts[1])) === Math.round(elH) && Math.round(parseFloat(parts[2])) === elR;
    });
    if (matchedEntry) {
      sdfTexSize = matchedEntry.texSize;
      const texData = matchedEntry.tex;
      const ts = matchedEntry.texSize;
      const elementSizeX = elW * dpr;
      const elementSizeY = elH * dpr;
      const maxDim = Math.max(elementSizeX, elementSizeY);
      const margin = 4;
      const scale = (ts - 2 * margin) / maxDim;
      const elementCenterX = rect.x + rect.w / 2;
      const elementCenterY = rect.y + rect.h / 2;
      sdfProfile = [];
      for (let i = 0;i < diagN; i++) {
        const col = clampedW - 1 - i;
        const row = i;
        const patchCanvasX = patchCssX + col / dpr;
        const patchCanvasY = patchCssY + row / dpr;
        const centeredOrigX = (patchCanvasX - elementCenterX) * dpr;
        const centeredOrigY = (patchCanvasY - elementCenterY) * dpr;
        const texX = ts / 2 + centeredOrigX * scale;
        const texY = ts / 2 + centeredOrigY * scale;
        const u = texX / ts;
        const v = texY / ts;
        const fx = texX;
        const fy = texY;
        const ix = Math.floor(fx);
        const iy = Math.floor(fy);
        const fracX = fx - ix;
        const fracY = fy - iy;
        const clamp = (v2) => Math.max(0, Math.min(ts - 1, v2));
        const i00 = (clamp(iy) * ts + clamp(ix)) * 4;
        const i10 = (clamp(iy) * ts + clamp(ix + 1)) * 4;
        const i01 = (clamp(iy + 1) * ts + clamp(ix)) * 4;
        const i11 = (clamp(iy + 1) * ts + clamp(ix + 1)) * 4;
        const w00 = (1 - fracX) * (1 - fracY);
        const w10 = fracX * (1 - fracY);
        const w01 = (1 - fracX) * fracY;
        const w11 = fracX * fracY;
        const rVal = texData[i00] * w00 + texData[i10] * w10 + texData[i01] * w01 + texData[i11] * w11;
        const gVal = texData[i00 + 1] * w00 + texData[i10 + 1] * w10 + texData[i01 + 1] * w01 + texData[i11 + 1] * w11;
        const offset = (diagN / 2 - i) / dpr;
        sdfProfile.push({ r: rVal, g: gVal, offset });
      }
    }
    const base = {
      scanId: this._edgeScanCounter,
      elementId: el.id,
      targetIdx,
      targetCount: candidates.length,
      isCapsule: picked.isCapsule,
      rect: { x: rect.x, y: rect.y, w: rect.w, h: rect.h },
      cornerRadius: r,
      dpr,
      cornerCenter: { x: cornerCx, y: cornerCy },
      cornerPoint45: { x: p45x, y: p45y },
      patchCssX,
      patchCssY,
      patchDevSize: clampedW,
      halfRange: halfRangeCss,
      patch,
      pixels,
      sdfProfile,
      sdfTexSize
    };
    this._edgeScanResult = { ...base, analysis: analyzeEdgeScan(base) };
  }
};

// src/cdn/core/renderer/methods-uniforms.ts
var uniformMethods = {
  cacheUniforms() {
    const gl = this.gl;
    const elNames = [
      "uBackdrop",
      "uWallpaperSampler",
      "uTabsBackdropSampler",
      "uCanvasSize",
      "uWallpaperSize",
      "uElementOffset",
      "uElementSize",
      "uBackdropBbox",
      "uCornerRadii",
      "uRefractionHeight",
      "uRefractionAmount",
      "uDepthEffect",
      "uChromaticAberration",
      "uBlurRadius",
      "uSaturation",
      "uBrightness",
      "uContrast",
      "uTintColor",
      "uSurfaceColor",
      "uHighlightColor",
      "uHighlightAngle",
      "uHighlightFalloff",
      "uHighlightAlpha",
      "uHighlightMode",
      "uHighlightStrokeWidth",
      "uHighlightBlur",
      "uContentScaleX",
      "uContentScaleY",
      "uUseToggleBackdrop",
      "uUseSolidBackdrop",
      "uSolidBackdropColor",
      "uTrackColor",
      "uTrackRect",
      "uTrackCornerRadius",
      "uOriginalSize",
      "uOriginalCornerRadius",
      "uLayerScale",
      "uIndicatorBackdrop",
      "uContainerRect",
      "uContainerCornerRadius",
      "uIndicatorAccent",
      "uInsetPx",
      "uIndicatorPressProgress",
      "uIndicatorPanelOffset",
      "uDpr",
      "uContainerCenter",
      "uContainerScale",
      "uTabContentTex0",
      "uTabContentTex1",
      "uTabContentTex2",
      "uTabContentTex3",
      "uTabContentTex4",
      "uTabContentTex5",
      "uTabContentTex6",
      "uTabContentTex7",
      "uTabContentRects[0]",
      "uTabContentRects[1]",
      "uTabContentRects[2]",
      "uTabContentRects[3]",
      "uTabContentRects[4]",
      "uTabContentRects[5]",
      "uTabContentRects[6]",
      "uTabContentRects[7]",
      "uTabContentCount",
      "uTabsGlassLayer",
      "uSdfTexSampler",
      "uUseSdfTexture",
      "uSdfTexSize",
      "uSdfLightAngle",
      "uEnterAlpha",
      "uSdfHighlightScale",
      "uSdfBevelEnabled",
      "uSdfGlassTintHue",
      "uSdfGlassTintEnabled",
      "uSdfGlassTintMix",
      "uSdfGlassTintStrength",
      "uSdfEdgeMatteEnabled",
      "uSdfEdgeMatteTargets",
      "uSdfEdgeMatteBevelParams",
      "uSdfEdgeMatteTintParams",
      "uSdfEdgeMatteBaseParams",
      "uSdfEdgeMatteBrightenParams",
      "uSdfEdgeMatteBevelStrength",
      "uSdfEdgeMatteTintStrength",
      "uSdfEdgeMatteBaseStrength",
      "uSdfEdgeMatteBrightenStrength",
      "uSdfDebugMode",
      "uSdfAaMin",
      "uUsePerElementFbo",
      "uSceneRectOffset",
      "uElFboSize",
      "uBackdropRect",
      "uCornerStyle",
      "uSkipColorControls",
      "uUseMagnifier",
      "uMagnifierZoom",
      "uMagnifierOffsetY",
      "uElementRotation",
      "uContinuousSdf",
      "uUseContinuousSdf",
      "uContinuousSdfTexSize",
      "uContinuousSdfElementSize",
      "uNoContinuousSdfInRefraction",
      "uInnerStrokeMask",
      "uInnerStrokeMaskOffset",
      "uInnerStrokeMaskSize"
    ];
    for (const n of elNames)
      this.uEl[n] = gl.getUniformLocation(this.elementProgram, n);
    const shNames = [
      "uCanvasSize",
      "uElementOffset",
      "uElementSize",
      "uCornerRadii",
      "uShadowRadius",
      "uShadowOffset",
      "uShadowColor",
      "uOriginalSize",
      "uOriginalCornerRadius",
      "uLayerScale",
      "uElementRotation",
      "uCornerStyle"
    ];
    for (const n of shNames)
      this.uSh[n] = gl.getUniformLocation(this.shadowProgram, n);
    const wpNames = ["uBackdrop", "uCanvasSize", "uWallpaperSize"];
    for (const n of wpNames)
      this.uWp[n] = gl.getUniformLocation(this.wallpaperProgram, n);
    const fgNames = [
      "uTexture",
      "uCanvasSize",
      "uOffset",
      "uSize",
      "uCornerRadii",
      "uAlpha",
      "uOriginalSize",
      "uOriginalCornerRadius",
      "uLayerScale",
      "uCornerStyle",
      "uUseContinuousSdf",
      "uContinuousSdf",
      "uContinuousSdfTexSize",
      "uContinuousSdfElementSize"
    ];
    for (const n of fgNames)
      this.uFg[n] = gl.getUniformLocation(this.foregroundProgram, n);
    const hlNames = [
      "uCanvasSize",
      "uOffset",
      "uSize",
      "uCornerRadii",
      "uColor",
      "uRadius",
      "uPosition",
      "uOriginalSize",
      "uOriginalCornerRadius",
      "uLayerScale",
      "uElementRotation",
      "uCornerStyle"
    ];
    for (const n of hlNames)
      this.uHl[n] = gl.getUniformLocation(this.highlightProgram, n);
    const tnNames = [
      "uCanvasSize",
      "uOffset",
      "uSize",
      "uCornerRadii",
      "uColor",
      "uOriginalSize",
      "uOriginalCornerRadius",
      "uLayerScale",
      "uElementRotation",
      "uCornerStyle"
    ];
    for (const n of tnNames)
      this.uTn[n] = gl.getUniformLocation(this.tintProgram, n);
    const rmNames = [
      "uCanvasSize",
      "uOffset",
      "uSize",
      "uCornerRadii",
      "uHighlightColor",
      "uHighlightAngle",
      "uHighlightFalloff",
      "uHighlightAlpha",
      "uHighlightMode",
      "uHighlightStrokeWidth",
      "uHighlightBlur",
      "uOriginalSize",
      "uOriginalCornerRadius",
      "uLayerScale",
      "uElementRotation",
      "uCornerStyle",
      "uUseContinuousSdf",
      "uContinuousSdf",
      "uContinuousSdfTexSize",
      "uContinuousSdfElementSize"
    ];
    for (const n of rmNames)
      this.uRm[n] = gl.getUniformLocation(this.rimHighlightProgram, n);
    const hsNames = [
      "uCanvasSize",
      "uOffset",
      "uSize",
      "uCornerRadii",
      "uHighlightStrokeWidth",
      "uOriginalSize",
      "uOriginalCornerRadius",
      "uLayerScale",
      "uElementRotation",
      "uCornerStyle",
      "uUseContinuousSdf",
      "uContinuousSdf",
      "uContinuousSdfTexSize",
      "uContinuousSdfElementSize"
    ];
    for (const n of hsNames)
      this.uHs[n] = gl.getUniformLocation(this.highlightStrokeProgram, n);
    const hcNames = [
      "uCanvasSize",
      "uOffset",
      "uSize",
      "uCornerRadii",
      "uBlurredMask",
      "uMaskTexSize",
      "uHighlightColor",
      "uHighlightAngle",
      "uHighlightFalloff",
      "uHighlightAlpha",
      "uHighlightMode",
      "uOriginalSize",
      "uOriginalCornerRadius",
      "uLayerScale",
      "uElementRotation",
      "uCornerStyle",
      "uUseContinuousSdf",
      "uContinuousSdf",
      "uContinuousSdfTexSize",
      "uContinuousSdfElementSize"
    ];
    for (const n of hcNames)
      this.uHc[n] = gl.getUniformLocation(this.highlightCompositeProgram, n);
    const smNames = [
      "uCanvasSize",
      "uOffset",
      "uSize",
      "uCornerRadii",
      "uStrokeMask",
      "uMaskOffset",
      "uMaskSize",
      "uHighlightColor",
      "uHighlightAngle",
      "uHighlightFalloff",
      "uHighlightAlpha",
      "uHighlightMode",
      "uOriginalSize",
      "uOriginalCornerRadius",
      "uLayerScale",
      "uElementRotation"
    ];
    for (const n of smNames)
      this.uSm[n] = gl.getUniformLocation(this.strokeMaskCompositeProgram, n);
    const isNames = [
      "uCanvasSize",
      "uOffset",
      "uSize",
      "uCornerRadii",
      "uInnerShadowMask",
      "uMaskOffset",
      "uMaskSize",
      "uInnerShadowColor",
      "uInnerShadowAlpha",
      "uOriginalSize",
      "uOriginalCornerRadius",
      "uLayerScale",
      "uElementRotation"
    ];
    for (const n of isNames)
      this.uIs[n] = gl.getUniformLocation(this.innerShadowMaskCompositeProgram, n);
    const prNames = [
      "uCanvasSize",
      "uOffset",
      "uSize",
      "uCornerRadii",
      "uColor",
      "uCornerStyle",
      "uUseContinuousSdf",
      "uContinuousSdf",
      "uContinuousSdfTexSize",
      "uContinuousSdfElementSize"
    ];
    for (const n of prNames)
      this.uPr[n] = gl.getUniformLocation(this.plainRectProgram, n);
    const pbNames = [
      "uBackdrop",
      "uCanvasSize",
      "uWallpaperSize",
      "uOffset",
      "uSize",
      "uBlurRadius",
      "uTintColor",
      "uTintIntensity"
    ];
    for (const n of pbNames)
      this.uPb[n] = gl.getUniformLocation(this.progressiveBlurProgram, n);
    const cpNames = ["uTexture", "uCanvasSize"];
    for (const n of cpNames)
      this.uCp[n] = gl.getUniformLocation(this.copyProgram, n);
    const sfNames = ["uColor"];
    for (const n of sfNames)
      this.uSf[n] = gl.getUniformLocation(this.solidFillProgram, n);
    const ccNames = ["uTexture", "uTexSize", "uBrightness", "uContrast", "uSaturation"];
    for (const n of ccNames)
      this.uCc[n] = gl.getUniformLocation(this.colorControlsProgram, n);
    const stNames = ["uTexture", "uCanvasSize", "uTintColor"];
    for (const n of stNames)
      this.uSt[n] = gl.getUniformLocation(this.sceneTintProgram, n);
    const efNames = ["uTexture", "uCanvasSize", "uElementCenter", "uElementSize", "uRotation", "uSrcSize"];
    for (const n of efNames)
      this.uEf[n] = gl.getUniformLocation(this.elFboCompositeProgram, n);
    const ecNames = ["uTexture", "uSrcOffset", "uSrcSize", "uDstSize"];
    for (const n of ecNames)
      this.uEc[n] = gl.getUniformLocation(this.elFboCropProgram, n);
  }
};

// src/cdn/core/renderer/methods-blur.ts
function compileBlurPair(gl, tapCount, genShader, errLabel) {
  const mk = (dir) => {
    const fs = compileShader(gl, gl.FRAGMENT_SHADER, genShader(tapCount, dir));
    const vs = compileShader(gl, gl.VERTEX_SHADER, VERTEX_SHADER);
    const p = gl.createProgram();
    gl.attachShader(p, vs);
    gl.attachShader(p, fs);
    gl.bindAttribLocation(p, 0, "aPos");
    gl.linkProgram(p);
    gl.deleteShader(vs);
    gl.deleteShader(fs);
    if (!gl.getProgramParameter(p, gl.LINK_STATUS)) {
      const log = gl.getProgramInfoLog(p);
      gl.deleteProgram(p);
      throw new Error(errLabel + " (taps=" + tapCount + "," + dir + "): " + log);
    }
    return p;
  };
  const hProg = mk("horizontal");
  const vProg = mk("vertical");
  const uH = {
    uTexture: gl.getUniformLocation(hProg, "uTexture"),
    uTexSize: gl.getUniformLocation(hProg, "uTexSize"),
    uRadius: gl.getUniformLocation(hProg, "uRadius")
  };
  const uV = {
    uTexture: gl.getUniformLocation(vProg, "uTexture"),
    uTexSize: gl.getUniformLocation(vProg, "uTexSize"),
    uRadius: gl.getUniformLocation(vProg, "uRadius")
  };
  return { hProg, vProg, uH, uV, aPosH: 0, aPosV: 0 };
}
var blurMethods = {
  ensureBlurPrograms(tapCount) {
    if (this.blurPrograms.has(tapCount))
      return;
    this.blurPrograms.set(tapCount, compileBlurPair(this.gl, tapCount, generateSeparableBlurShader, "Blur program link error"));
  },
  pickDsBlurLevel(radius) {
    if (!this.dynamicBlurDownsample || this.dsBlurLevels.length === 0) {
      return {
        ds: this.effectiveBlurDownsample || 1,
        fboA: this.dsBlurFboA,
        texA: this.dsBlurFboATex,
        fboB: this.dsBlurFboB,
        texB: this.dsBlurFboBTex,
        w: this.dsBlurFboW || this.fboW,
        h: this.dsBlurFboH || this.fboH
      };
    }
    const levels = this.dsBlurLevels;
    const r = Math.max(0.5, radius);
    const maxDs = levels[levels.length - 1].ds;
    let usedDs = 1;
    if (r >= 6) {
      const exp = Math.floor(Math.log2(r / 6));
      usedDs = Math.pow(2, exp);
    }
    if (usedDs > maxDs)
      usedDs = maxDs;
    if (usedDs < 1)
      usedDs = 1;
    for (let i = levels.length - 1;i >= 0; i--) {
      if (levels[i].ds <= usedDs)
        return levels[i];
    }
    return levels[0];
  },
  runBlurPasses(srcTex, dstFboA, dstTexA, dstFboB, dstTexB, w, h, radius, tapCount, glassMode) {
    const gl = this.gl;
    const tProg0 = performance.now();
    if (glassMode) {
      this.ensureBlurPrograms(tapCount);
    } else {
      this.ensureHighlightBlurPrograms(tapCount);
    }
    const entry = (glassMode ? this.blurPrograms : this.highlightBlurPrograms).get(tapCount);
    const tProg1 = performance.now();
    const tState0 = performance.now();
    const savedFb = gl.getParameter(gl.FRAMEBUFFER_BINDING);
    const savedScissor = gl.isEnabled(gl.SCISSOR_TEST);
    const savedBox = gl.getParameter(gl.SCISSOR_BOX);
    gl.disable(gl.SCISSOR_TEST);
    gl.disable(gl.BLEND);
    const tState1 = performance.now();
    const tH0 = performance.now();
    gl.bindFramebuffer(gl.FRAMEBUFFER, dstFboA);
    gl.viewport(0, 0, w, h);
    gl.useProgram(entry.hProg);
    gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
    gl.enableVertexAttribArray(entry.aPosH);
    gl.vertexAttribPointer(entry.aPosH, 2, gl.FLOAT, false, 0, 0);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, srcTex);
    gl.uniform1i(entry.uH["uTexture"], 0);
    gl.uniform2f(entry.uH["uTexSize"], w, h);
    gl.uniform1f(entry.uH["uRadius"], radius);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    const tH1 = performance.now();
    const tV0 = performance.now();
    gl.bindFramebuffer(gl.FRAMEBUFFER, dstFboB);
    gl.viewport(0, 0, w, h);
    gl.useProgram(entry.vProg);
    gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
    gl.enableVertexAttribArray(entry.aPosV);
    gl.vertexAttribPointer(entry.aPosV, 2, gl.FLOAT, false, 0, 0);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, dstTexA);
    gl.uniform1i(entry.uV["uTexture"], 0);
    gl.uniform2f(entry.uV["uTexSize"], w, h);
    gl.uniform1f(entry.uV["uRadius"], radius);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    const tV1 = performance.now();
    const tR0 = performance.now();
    gl.bindFramebuffer(gl.FRAMEBUFFER, savedFb);
    gl.viewport(0, 0, this.fboW, this.fboH);
    if (savedScissor) {
      gl.enable(gl.SCISSOR_TEST);
      gl.scissor(savedBox[0], savedBox[1], savedBox[2], savedBox[3]);
    }
    const tR1 = performance.now();
    if (this.lastBlurStats) {
      this.lastBlurStats.progMs = tProg1 - tProg0;
      this.lastBlurStats.stateMs = tState1 - tState0 + (tR1 - tR0);
      this.lastBlurStats.drawMs = tH1 - tH0 + (tV1 - tV0);
    }
    return dstTexB;
  },
  blurTexture(srcTex, radius, bbox) {
    if (bbox) {
      this.ensureElementFBO(bbox.w, bbox.h);
      return this.cropAndBlurBackdrop(srcTex, bbox.x, bbox.y, bbox.w, bbox.h, radius);
    }
    if (this.useKawaseBlur)
      return this.kawaseBlurTexture(srcTex, radius);
    const lvl = this.pickDsBlurLevel(radius);
    const ds = lvl.ds;
    const dsRadius = ds > 1 ? radius / ds : radius;
    if (dsRadius < 0.5) {
      this.lastBlurStats = { type: "gauss", passes: 0, taps: 0, maxSample: 0, w: lvl.w, h: lvl.h, progMs: 0, stateMs: 0, drawMs: 0 };
      return srcTex;
    }
    let taps = computeBlur1DTapCount(dsRadius);
    taps = Math.min(taps, Math.max(1, this.blurTapCap | 0));
    this.lastBlurStats = { type: "gauss", passes: 2, taps, maxSample: 3 * dsRadius, w: lvl.w, h: lvl.h, progMs: 0, stateMs: 0, drawMs: 0 };
    return this.runBlurPasses(srcTex, lvl.fboA, lvl.texA, lvl.fboB, lvl.texB, lvl.w, lvl.h, dsRadius, taps, true);
  },
  ensureKawaseProgram() {
    if (this.kawasePrograms)
      return;
    const gl = this.gl;
    const fs = compileShader(gl, gl.FRAGMENT_SHADER, generateKawaseBlurShader());
    const vs = compileShader(gl, gl.VERTEX_SHADER, VERTEX_SHADER);
    const p = gl.createProgram();
    gl.attachShader(p, vs);
    gl.attachShader(p, fs);
    gl.bindAttribLocation(p, 0, "aPos");
    gl.linkProgram(p);
    gl.deleteShader(vs);
    gl.deleteShader(fs);
    if (!gl.getProgramParameter(p, gl.LINK_STATUS)) {
      const log = gl.getProgramInfoLog(p);
      gl.deleteProgram(p);
      throw new Error("Kawase program link error: " + log);
    }
    this.kawasePrograms = {
      prog: p,
      uTexture: gl.getUniformLocation(p, "uTexture"),
      uTexSize: gl.getUniformLocation(p, "uTexSize"),
      uRadius: gl.getUniformLocation(p, "uRadius"),
      uIteration: gl.getUniformLocation(p, "uIteration"),
      uTotalIters: gl.getUniformLocation(p, "uTotalIters"),
      aPos: 0
    };
  },
  kawaseBlurTexture(srcTex, radius) {
    const lvl = this.pickDsBlurLevel(radius);
    const ds = lvl.ds;
    const dsRadius = ds > 1 ? radius / ds : radius;
    if (dsRadius < 0.5) {
      this.lastBlurStats = { type: "kawase", passes: 0, taps: 0, maxSample: 0, w: lvl.w, h: lvl.h, progMs: 0, stateMs: 0, drawMs: 0 };
      return srcTex;
    }
    const iters = kawaseIterationsForRadius(dsRadius, this.kawaseQuality);
    const dMax = dsRadius * Math.sqrt(6 * iters / ((iters + 1) * (2 * iters + 1)));
    this.lastBlurStats = { type: "kawase", passes: iters, taps: 4 * iters, maxSample: dMax * Math.SQRT2, w: lvl.w, h: lvl.h, progMs: 0, stateMs: 0, drawMs: 0 };
    const tProg0 = performance.now();
    this.ensureKawaseProgram();
    const kp = this.kawasePrograms;
    const tProg1 = performance.now();
    const tState0 = performance.now();
    const gl = this.gl;
    const { w, h } = lvl;
    const savedFb = gl.getParameter(gl.FRAMEBUFFER_BINDING);
    const savedScissor = gl.isEnabled(gl.SCISSOR_TEST);
    const savedBox = gl.getParameter(gl.SCISSOR_BOX);
    gl.disable(gl.SCISSOR_TEST);
    gl.disable(gl.BLEND);
    const tState1 = performance.now();
    const tDraw0 = performance.now();
    let curSrc = srcTex;
    for (let i = 0;i < iters; i++) {
      const writeFboA = i % 2 === 0;
      const dstFbo = writeFboA ? lvl.fboA : lvl.fboB;
      gl.bindFramebuffer(gl.FRAMEBUFFER, dstFbo);
      gl.viewport(0, 0, w, h);
      gl.useProgram(kp.prog);
      gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
      gl.enableVertexAttribArray(kp.aPos);
      gl.vertexAttribPointer(kp.aPos, 2, gl.FLOAT, false, 0, 0);
      gl.activeTexture(gl.TEXTURE0);
      gl.bindTexture(gl.TEXTURE_2D, curSrc);
      gl.uniform1i(kp.uTexture, 0);
      gl.uniform2f(kp.uTexSize, w, h);
      gl.uniform1f(kp.uRadius, dsRadius);
      gl.uniform1f(kp.uIteration, i);
      gl.uniform1f(kp.uTotalIters, iters);
      gl.drawArrays(gl.TRIANGLES, 0, 6);
      curSrc = writeFboA ? lvl.texA : lvl.texB;
    }
    const tDraw1 = performance.now();
    const tR0 = performance.now();
    gl.bindFramebuffer(gl.FRAMEBUFFER, savedFb);
    gl.viewport(0, 0, this.fboW, this.fboH);
    if (savedScissor) {
      gl.enable(gl.SCISSOR_TEST);
      gl.scissor(savedBox[0], savedBox[1], savedBox[2], savedBox[3]);
    }
    const tR1 = performance.now();
    this.lastBlurStats.progMs = tProg1 - tProg0;
    this.lastBlurStats.stateMs = tState1 - tState0 + (tR1 - tR0);
    this.lastBlurStats.drawMs = tDraw1 - tDraw0;
    const lastWroteA = (iters - 1) % 2 === 0;
    return lastWroteA ? lvl.texA : lvl.texB;
  },
  ensureHighlightBlurPrograms(tapCount) {
    if (this.highlightBlurPrograms.has(tapCount))
      return;
    this.highlightBlurPrograms.set(tapCount, compileBlurPair(this.gl, tapCount, generateHighlightBlurShader, "Highlight blur program link error"));
  },
  blurHighlightMask(srcTex, sigmaPx) {
    const lvl = this.pickDsBlurLevel(sigmaPx);
    const ds = lvl.ds;
    const dsSigma = ds > 1 ? sigmaPx / ds : sigmaPx;
    if (dsSigma < 0.01)
      return srcTex;
    let taps = computeHighlightBlurTapCount(dsSigma);
    taps = Math.min(taps, Math.max(3, this.blurTapCap | 0));
    return this.runBlurPasses(srcTex, lvl.fboA, lvl.texA, lvl.fboB, lvl.texB, lvl.w, lvl.h, dsSigma, taps, false);
  }
};

// src/cdn/core/renderer/methods-dispose.ts
var disposeMethods = {
  dispose() {
    if (this.rafId !== null)
      cancelAnimationFrame(this.rafId);
    this.rafId = null;
    if (this.animRafId !== null)
      cancelAnimationFrame(this.animRafId);
    this.animRafId = null;
    const gl = this.gl;
    if (this.wallpaperTexture)
      gl.deleteTexture(this.wallpaperTexture);
    for (const tex of this.fgTextures.values())
      gl.deleteTexture(tex);
    this.fgTextures.clear();
    for (const entry of this.strokeMaskCache.values())
      gl.deleteTexture(entry.tex);
    this.strokeMaskCache.clear();
    destroyCache(gl, this.innerShadowMaskCache);
    if (this.fboA)
      gl.deleteFramebuffer(this.fboA);
    if (this.fboATex)
      gl.deleteTexture(this.fboATex);
    if (this.fboB)
      gl.deleteFramebuffer(this.fboB);
    if (this.fboBTex)
      gl.deleteTexture(this.fboBTex);
    this.fboA = this.fboB = null;
    this.fboATex = this.fboBTex = null;
    if (this.tabsBackdropFbo)
      gl.deleteFramebuffer(this.tabsBackdropFbo);
    if (this.tabsBackdropTex)
      gl.deleteTexture(this.tabsBackdropTex);
    this.tabsBackdropFbo = null;
    this.tabsBackdropTex = null;
    if (this.wallpaperBlurFbo)
      gl.deleteFramebuffer(this.wallpaperBlurFbo);
    if (this.wallpaperBlurTex)
      gl.deleteTexture(this.wallpaperBlurTex);
    if (this.blurFboA)
      gl.deleteFramebuffer(this.blurFboA);
    if (this.blurFboATex)
      gl.deleteTexture(this.blurFboATex);
    if (this.blurFboB)
      gl.deleteFramebuffer(this.blurFboB);
    if (this.blurFboBTex)
      gl.deleteTexture(this.blurFboBTex);
    if (this.dsBlurFboA)
      gl.deleteFramebuffer(this.dsBlurFboA);
    if (this.dsBlurFboATex)
      gl.deleteTexture(this.dsBlurFboATex);
    if (this.dsBlurFboB)
      gl.deleteFramebuffer(this.dsBlurFboB);
    if (this.dsBlurFboBTex)
      gl.deleteTexture(this.dsBlurFboBTex);
    for (const lvl of this.dsBlurLevels) {
      gl.deleteFramebuffer(lvl.fboA);
      gl.deleteTexture(lvl.texA);
      gl.deleteFramebuffer(lvl.fboB);
      gl.deleteTexture(lvl.texB);
    }
    this.dsBlurLevels = [];
    this.wallpaperBlurFbo = this.blurFboA = this.blurFboB = this.dsBlurFboA = this.dsBlurFboB = null;
    this.wallpaperBlurTex = this.blurFboATex = this.blurFboBTex = this.dsBlurFboATex = this.dsBlurFboBTex = null;
    if (this.highlightMaskFbo)
      gl.deleteFramebuffer(this.highlightMaskFbo);
    if (this.highlightMaskTex)
      gl.deleteTexture(this.highlightMaskTex);
    this.highlightMaskFbo = null;
    this.highlightMaskTex = null;
    if (this.dialogBackdropFbo)
      gl.deleteFramebuffer(this.dialogBackdropFbo);
    if (this.dialogBackdropTex)
      gl.deleteTexture(this.dialogBackdropTex);
    this.dialogBackdropFbo = null;
    this.dialogBackdropTex = null;
    this.dialogBackdropKey = null;
    if (this.bgOnlyFbo)
      gl.deleteFramebuffer(this.bgOnlyFbo);
    if (this.bgOnlyTex)
      gl.deleteTexture(this.bgOnlyTex);
    this.bgOnlyFbo = null;
    this.bgOnlyTex = null;
    if (this.elFbo)
      gl.deleteFramebuffer(this.elFbo);
    if (this.elFboTex)
      gl.deleteTexture(this.elFboTex);
    this.elFbo = null;
    this.elFboTex = null;
    this.elFboW = this.elFboH = 0;
    if (this.backdropCropFbo)
      gl.deleteFramebuffer(this.backdropCropFbo);
    if (this.backdropCropTex)
      gl.deleteTexture(this.backdropCropTex);
    this.backdropCropFbo = null;
    this.backdropCropTex = null;
    if (this.elBlurFboA)
      gl.deleteFramebuffer(this.elBlurFboA);
    if (this.elBlurFboATex)
      gl.deleteTexture(this.elBlurFboATex);
    if (this.elBlurFboB)
      gl.deleteFramebuffer(this.elBlurFboB);
    if (this.elBlurFboBTex)
      gl.deleteTexture(this.elBlurFboBTex);
    this.elBlurFboA = this.elBlurFboB = null;
    this.elBlurFboATex = this.elBlurFboBTex = null;
    for (const e of this.elFboCache.values()) {
      gl.deleteFramebuffer(e.fb);
      gl.deleteTexture(e.tex);
    }
    this.elFboCache.clear();
    for (const { hProg, vProg } of this.blurPrograms.values()) {
      gl.deleteProgram(hProg);
      gl.deleteProgram(vProg);
    }
    this.blurPrograms.clear();
    for (const { hProg, vProg } of this.highlightBlurPrograms.values()) {
      gl.deleteProgram(hProg);
      gl.deleteProgram(vProg);
    }
    this.highlightBlurPrograms.clear();
    if (this.kawasePrograms) {
      gl.deleteProgram(this.kawasePrograms.prog);
      this.kawasePrograms = null;
    }
    for (const entry of this.backdropBlurCache.values()) {
      gl.deleteTexture(entry.tex);
      gl.deleteFramebuffer(entry.fb);
    }
    this.backdropBlurCache.clear();
    for (const p of this.backdropBlurCacheFboPool) {
      gl.deleteTexture(p.tex);
      gl.deleteFramebuffer(p.fb);
    }
    this.backdropBlurCacheFboPool.length = 0;
    if (this.cacheCopyReadFbo) {
      gl.deleteFramebuffer(this.cacheCopyReadFbo);
      this.cacheCopyReadFbo = null;
    }
    if (this.sdfTexture)
      gl.deleteTexture(this.sdfTexture);
    this.sdfTexture = null;
    if (this.textSdfTexture)
      gl.deleteTexture(this.textSdfTexture);
    this.textSdfTexture = null;
    for (const { tex } of this.continuousSdfPool.values())
      gl.deleteTexture(tex);
    this.continuousSdfPool.clear();
    this.continuousSdfTexture = null;
    this.continuousSdfKey = null;
    this._debugUploadedSdfTexMap.clear();
    gl.deleteProgram(this.elementProgram);
    gl.deleteProgram(this.shadowProgram);
    gl.deleteProgram(this.wallpaperProgram);
    gl.deleteProgram(this.foregroundProgram);
    gl.deleteProgram(this.highlightProgram);
    gl.deleteProgram(this.tintProgram);
    gl.deleteProgram(this.rimHighlightProgram);
    gl.deleteProgram(this.highlightStrokeProgram);
    gl.deleteProgram(this.highlightCompositeProgram);
    gl.deleteProgram(this.strokeMaskCompositeProgram);
    gl.deleteProgram(this.innerShadowMaskCompositeProgram);
    gl.deleteProgram(this.plainRectProgram);
    gl.deleteProgram(this.progressiveBlurProgram);
    gl.deleteProgram(this.copyProgram);
    gl.deleteProgram(this.solidFillProgram);
    gl.deleteProgram(this.colorControlsProgram);
    gl.deleteProgram(this.sceneTintProgram);
    gl.deleteProgram(this.elFboCompositeProgram);
    gl.deleteProgram(this.elFboCropProgram);
    gl.deleteBuffer(this.quadBuffer);
  }
};

// src/cdn/core/renderer/index.ts
"use client";

class LiquidGlassRenderer {
  gl;
  elementProgram;
  shadowProgram;
  wallpaperProgram;
  foregroundProgram;
  highlightProgram;
  tintProgram;
  rimHighlightProgram;
  highlightStrokeProgram;
  highlightCompositeProgram;
  strokeMaskCompositeProgram;
  innerShadowMaskCompositeProgram;
  plainRectProgram;
  progressiveBlurProgram;
  copyProgram;
  solidFillProgram;
  colorControlsProgram;
  sceneTintProgram;
  elFboCompositeProgram;
  elFboCropProgram;
  quadBuffer;
  wallpaperTexture = null;
  wallpaperReady = false;
  wallpaperSize = [1, 1];
  canvas;
  dpr = 0;
  buttonConfigs = [];
  buttonStates = new Map;
  toggleStates = new Map;
  scrollY = 0;
  scrollVelocity = 0;
  contentHeight = 0;
  cssWidth = 0;
  cssHeight = 0;
  wheelTarget = null;
  backgroundColor = null;
  needsRedraw = true;
  dirtyElementIds = new Set;
  allDirty = true;
  showDirtyMarkers = false;
  debugDirtyMarkers = [];
  _dbgLastGlassCacheHit = false;
  dirtyRectsThisFrame = [];
  lastRenderedScrollY = 0;
  _scrollSettlePending = false;
  _blurSettleBudgetBoost = null;
  debugCacheMissLog = [];
  debugDirtySourceLog = [];
  fboA = null;
  fboATex = null;
  fboB = null;
  fboBTex = null;
  fboW = 0;
  fboH = 0;
  tabsBackdropFbo = null;
  tabsBackdropTex = null;
  tabsBackdropDirty = true;
  wallpaperBlurFbo = null;
  wallpaperBlurTex = null;
  blurFboA = null;
  blurFboATex = null;
  blurFboB = null;
  blurFboBTex = null;
  dsBlurFboA = null;
  dsBlurFboATex = null;
  dsBlurFboB = null;
  dsBlurFboBTex = null;
  highlightMaskFbo = null;
  highlightMaskTex = null;
  dialogBackdropFbo = null;
  dialogBackdropTex = null;
  dialogBackdropKey = null;
  bgOnlyFbo = null;
  bgOnlyTex = null;
  blurPrograms = new Map;
  highlightBlurPrograms = new Map;
  kawasePrograms = null;
  useKawaseBlur = true;
  useBlurCache = true;
  kawaseQuality = 1;
  gravityAngle = 45 * Math.PI / 180;
  blurTapCap = 9;
  blurDownsample = 4;
  dsBlurFboW = 0;
  dsBlurFboH = 0;
  effectiveBlurDownsample = 4;
  dynamicBlurDownsample = false;
  dsBlurLevels = [];
  cornerStyle = 1;
  capsuleSdfQuality = 0.5;
  noContinuousSdf = true;
  directBackdropSample = true;
  usePerElementFbo = false;
  quickToggles = {
    highlight: true,
    backdropBlur: true,
    chromatic: true,
    refraction: true,
    outerShadow: true,
    innershadow: true,
    perElementFbo: false,
    isolateBackdrop: false
  };
  isSoftwareRenderer = false;
  showPefBbox = false;
  debugPefBboxes = [];
  showBlurDebug = false;
  debugBlurRegions = [];
  lastBlurStats = null;
  backdropBlurCache = new Map;
  backdropBlurCacheFboPool = [];
  cacheCopyReadFbo = null;
  backdropBlurCacheMax = 64;
  _blurCacheMissesThisFrame = 0;
  blurCacheMissesPerFrame = 1;
  showBlurCacheCheckerboard = false;
  showBlurCachePreview = false;
  backdropBlurCacheSnapshots = [];
  showShadowBbox = false;
  debugShadowBboxes = [];
  debugSdfHoleTopLeftR = false;
  debugSdfHoleTopLeftG = false;
  showCullDebug = false;
  debugCullRects = [];
  showPefPassDebug = false;
  debugPefPasses = [];
  showPlainRectDebug = false;
  debugPlainRects = [];
  perfMonitor = new PerfMonitor;
  elFbo = null;
  elFboTex = null;
  elFboW = 0;
  elFboH = 0;
  elFboCache = new Map;
  wallpaperVersion = 0;
  backdropCropFbo = null;
  backdropCropTex = null;
  elBlurFboA = null;
  elBlurFboATex = null;
  elBlurFboB = null;
  elBlurFboBTex = null;
  sdfTexture = null;
  sdfTextureReady = false;
  sdfTextureSize = [1, 1];
  textSdfTexture = null;
  textSdfTextureReady = false;
  textSdfTextureSize = [1, 1];
  continuousSdfPool = new Map;
  continuousSdfTexture = null;
  continuousSdfTexSize = [128, 128];
  continuousSdfKey = null;
  dummyTex = null;
  _lastCapsuleGenMs = 0;
  _lastCapsuleUploadMs = 0;
  _lastCapsuleKey = "";
  _debugUploadedSdfTexMap = new Map;
  get _debugLastUploadedSdfTex() {
    const arr = Array.from(this._debugUploadedSdfTexMap.values());
    return arr.length ? arr[arr.length - 1].tex : null;
  }
  get _debugLastUploadedSdfKey() {
    const arr = Array.from(this._debugUploadedSdfTexMap.keys());
    return arr.length ? arr[arr.length - 1] : "";
  }
  get _debugLastUploadedSdfTexSize() {
    const arr = Array.from(this._debugUploadedSdfTexMap.values());
    return arr.length ? arr[arr.length - 1].texSize : 0;
  }
  _pendingEdgeScan = null;
  _edgeScanResult = null;
  _edgeScanCounter = 0;
  _edgeScanTargetIdx = 0;
  clearCapsuleSdfPool() {
    const gl = this.gl;
    for (const { tex } of this.continuousSdfPool.values())
      gl.deleteTexture(tex);
    this.continuousSdfPool.clear();
    this.continuousSdfTexture = null;
    this.continuousSdfKey = null;
    this._lastCapsuleGenMs = 0;
    this._lastCapsuleUploadMs = 0;
    this._lastCapsuleKey = "";
    this._debugUploadedSdfTexMap.clear();
  }
  clearStrokeMaskCache() {
    const gl = this.gl;
    const n = this.strokeMaskCache.size;
    for (const entry of this.strokeMaskCache.values())
      gl.deleteTexture(entry.tex);
    this.strokeMaskCache.clear();
    return n;
  }
  fgCanvas;
  fgCtx;
  fgTextures = new Map;
  fgDirtyIds = new Set;
  strokeMaskCache = new Map;
  innerShadowMaskCache = new Map;
  rafId = null;
  animRafId = null;
  pendingExtraRenders = 0;
  aPosLocEl;
  aPosLocSh;
  aPosLocWp;
  aPosLocFg;
  aPosLocHl;
  aPosLocTn;
  aPosLocRm;
  aPosLocHs;
  aPosLocHc;
  aPosLocSm;
  aPosLocIs;
  aPosLocPr;
  aPosLocPb;
  aPosLocCp;
  aPosLocSf;
  aPosLocCc;
  aPosLocSt;
  aPosLocEf;
  aPosLocEc;
  uEl = {};
  uSh = {};
  uWp = {};
  uFg = {};
  uHl = {};
  uTn = {};
  uRm = {};
  uHs = {};
  uHc = {};
  uSm = {};
  uIs = {};
  uPr = {};
  uPb = {};
  uCp = {};
  uSf = {};
  uCc = {};
  uSt = {};
  uEf = {};
  uEc = {};
  static TAB_PRESSED_SCALE = 78 / 56;
  constructor(canvas) {
    this.canvas = canvas;
    const gl = canvas.getContext("webgl", {
      premultipliedAlpha: false,
      alpha: false,
      antialias: false,
      preserveDrawingBuffer: false,
      powerPreference: "low-power"
    });
    if (!gl)
      throw new Error("WebGL not supported");
    this.gl = gl;
    this.elementProgram = createProgram(gl, VERTEX_SHADER, ELEMENT_FRAGMENT_SHADER);
    this.shadowProgram = createProgram(gl, VERTEX_SHADER, SHADOW_FRAGMENT_SHADER);
    this.wallpaperProgram = createProgram(gl, VERTEX_SHADER, WALLPAPER_FRAGMENT_SHADER);
    this.foregroundProgram = createProgram(gl, VERTEX_SHADER, FOREGROUND_FRAGMENT_SHADER);
    this.highlightProgram = createProgram(gl, VERTEX_SHADER, HIGHLIGHT_FRAGMENT_SHADER);
    this.tintProgram = createProgram(gl, VERTEX_SHADER, TINT_FRAGMENT_SHADER);
    this.rimHighlightProgram = createProgram(gl, VERTEX_SHADER, RIM_HIGHLIGHT_FRAGMENT_SHADER);
    this.highlightStrokeProgram = createProgram(gl, VERTEX_SHADER, HIGHLIGHT_STROKE_FRAGMENT_SHADER);
    this.highlightCompositeProgram = createProgram(gl, VERTEX_SHADER, HIGHLIGHT_COMPOSITE_FRAGMENT_SHADER);
    this.strokeMaskCompositeProgram = createProgram(gl, VERTEX_SHADER, STROKE_MASK_COMPOSITE_FRAGMENT_SHADER);
    this.innerShadowMaskCompositeProgram = createProgram(gl, VERTEX_SHADER, INNER_SHADOW_MASK_COMPOSITE_FRAGMENT_SHADER);
    this.plainRectProgram = createProgram(gl, VERTEX_SHADER, PLAIN_RECT_FRAGMENT_SHADER);
    this.progressiveBlurProgram = createProgram(gl, VERTEX_SHADER, PROGRESSIVE_BLUR_FRAGMENT_SHADER);
    this.copyProgram = createProgram(gl, VERTEX_SHADER, COPY_FRAGMENT_SHADER);
    this.solidFillProgram = createProgram(gl, VERTEX_SHADER, SOLID_FILL_FRAGMENT_SHADER);
    this.colorControlsProgram = createProgram(gl, VERTEX_SHADER, COLOR_CONTROLS_FRAGMENT_SHADER);
    this.sceneTintProgram = createProgram(gl, VERTEX_SHADER, SCENE_TINT_FRAGMENT_SHADER);
    this.elFboCompositeProgram = createProgram(gl, VERTEX_SHADER, EL_FBO_COMPOSITE_FRAGMENT_SHADER);
    this.elFboCropProgram = createProgram(gl, VERTEX_SHADER, EL_FBO_CROP_FRAGMENT_SHADER);
    this.quadBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, -1, 1, 1, -1, 1, 1]), gl.STATIC_DRAW);
    this.aPosLocEl = gl.getAttribLocation(this.elementProgram, "aPos");
    this.aPosLocSh = gl.getAttribLocation(this.shadowProgram, "aPos");
    this.aPosLocWp = gl.getAttribLocation(this.wallpaperProgram, "aPos");
    this.aPosLocFg = gl.getAttribLocation(this.foregroundProgram, "aPos");
    this.aPosLocHl = gl.getAttribLocation(this.highlightProgram, "aPos");
    this.aPosLocTn = gl.getAttribLocation(this.tintProgram, "aPos");
    this.aPosLocRm = gl.getAttribLocation(this.rimHighlightProgram, "aPos");
    this.aPosLocHs = gl.getAttribLocation(this.highlightStrokeProgram, "aPos");
    this.aPosLocHc = gl.getAttribLocation(this.highlightCompositeProgram, "aPos");
    this.aPosLocSm = gl.getAttribLocation(this.strokeMaskCompositeProgram, "aPos");
    this.aPosLocIs = gl.getAttribLocation(this.innerShadowMaskCompositeProgram, "aPos");
    this.aPosLocPr = gl.getAttribLocation(this.plainRectProgram, "aPos");
    this.aPosLocPb = gl.getAttribLocation(this.progressiveBlurProgram, "aPos");
    this.aPosLocCp = gl.getAttribLocation(this.copyProgram, "aPos");
    this.aPosLocSf = gl.getAttribLocation(this.solidFillProgram, "aPos");
    this.aPosLocCc = gl.getAttribLocation(this.colorControlsProgram, "aPos");
    this.aPosLocSt = gl.getAttribLocation(this.sceneTintProgram, "aPos");
    this.aPosLocEf = gl.getAttribLocation(this.elFboCompositeProgram, "aPos");
    this.aPosLocEc = gl.getAttribLocation(this.elFboCropProgram, "aPos");
    this.fgCanvas = typeof document !== "undefined" ? document.createElement("canvas") : null;
    const fgCtx = this.fgCanvas?.getContext("2d", { alpha: true });
    if (!fgCtx)
      throw new Error("2D canvas not supported");
    this.fgCtx = fgCtx;
    this.dummyTex = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, this.dummyTex);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, 1, 1, 0, gl.RGBA, gl.UNSIGNED_BYTE, new Uint8Array([0, 0, 0, 0]));
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    this.cacheUniforms();
    this.perfMonitor.attachGl(gl);
    this.detectSoftwareRenderer();
    this.perfMonitor.isSoftwareRenderer = this.isSoftwareRenderer;
  }
  detectSoftwareRenderer() {
    const gl = this.gl;
    try {
      const dbgExt = gl.getExtension("WEBGL_debug_renderer_info");
      const rendererStr = dbgExt ? String(gl.getParameter(dbgExt.UNMASKED_RENDERER_WEBGL) || "") : String(gl.getParameter(gl.RENDERER) || "");
      const r = rendererStr.toLowerCase();
      this.isSoftwareRenderer = r.includes("swiftshader") || r.includes("llvmpipe") || r.includes("softpipe") || r.includes("swrast") || r.includes("software") || r.includes("basic render") || r.includes("mesa software") || r.includes("apple software");
    } catch {}
  }
  anyDebugOverlayOn() {
    return this.showPefBbox || this.showBlurDebug || this.showShadowBbox || this.showCullDebug || this.showPlainRectDebug || this.showPefPassDebug || this.showDirtyMarkers;
  }
}
Object.assign(LiquidGlassRenderer.prototype, fboMethods, wallpaperMethods, scrollMethods, toggleMethods, tabsMethods, elementMethods, animationMethods, rasterMethods, renderMethods, backgroundMethods, nonGlassMethods, nonGlassPlainRectMethods, nonGlassTextMethods, nonGlassProgressiveBlurMethods, glassRenderMethods, glassElementPassMethods, glassPostPassMethods, dirtyTrackingMethods, debugMethods, uniformMethods, blurMethods, disposeMethods);

// src/cdn/host.ts
function localPos(e, canvas) {
  const rect = canvas.getBoundingClientRect();
  const scaleX = canvas.clientWidth > 0 ? rect.width / canvas.clientWidth : 1;
  const scaleY = canvas.clientHeight > 0 ? rect.height / canvas.clientHeight : 1;
  return {
    x: (e.clientX - rect.left) / (scaleX || 1),
    y: (e.clientY - rect.top) / (scaleY || 1)
  };
}
function computeReleaseVelocity(samples) {
  if (samples.length < 2)
    return 0;
  const now = samples[samples.length - 1].t;
  const cutoff = now - 100;
  let oldest = samples[samples.length - 1];
  for (let i = samples.length - 1;i >= 0; i--) {
    if (samples[i].t < cutoff)
      break;
    oldest = samples[i];
  }
  const dt = (now - oldest.t) / 1000;
  if (dt < 0.001)
    return 0;
  const dy = samples[samples.length - 1].y - oldest.y;
  return -dy / dt;
}
function computeReleaseVelocity2D(samples) {
  if (samples.length < 2)
    return { x: 0, y: 0 };
  const last = samples[samples.length - 1];
  const now = last.t;
  const cutoff = now - 100;
  let oldest = last;
  for (let i = samples.length - 1;i >= 0; i--) {
    if (samples[i].t < cutoff)
      break;
    oldest = samples[i];
  }
  const dt = (now - oldest.t) / 1000;
  if (dt < 0.001)
    return { x: 0, y: 0 };
  return { x: (last.x - oldest.x) / dt, y: (last.y - oldest.y) / dt };
}

class LiquidGlass {
  renderer;
  canvas;
  opts;
  elements = [];
  interactions = {};
  gestures = new Map;
  prevPinch = null;
  ro = null;
  destroyed = false;
  constructor(opts) {
    this.opts = opts;
    this.canvas = opts.canvas;
    this.renderer = new LiquidGlassRenderer(opts.canvas);
    if (opts.backgroundColor)
      this.renderer.setBackgroundColor(opts.backgroundColor);
    if (opts.dpr != null) {
      const deviceDpr = typeof window !== "undefined" ? window.devicePixelRatio || 1 : 1;
      this.renderer.dpr = opts.dpr > 0 ? Math.max(0.5, Math.min(deviceDpr, opts.dpr)) : Math.max(1, deviceDpr);
    }
    if (opts.blurTapCap != null)
      this.renderer.blurTapCap = Math.max(1, Math.min(33, opts.blurTapCap | 0));
    if (opts.blurDownsample != null)
      this.renderer.blurDownsample = Math.max(1, Math.min(8, opts.blurDownsample));
    if (opts.cornerStyle != null)
      this.renderer.cornerStyle = opts.cornerStyle;
    if (opts.useKawaseBlur != null)
      this.renderer.useKawaseBlur = opts.useKawaseBlur;
    if (opts.useBlurCache != null)
      this.renderer.useBlurCache = opts.useBlurCache;
    if (opts.kawaseQuality != null)
      this.renderer.kawaseQuality = Math.max(0, Math.min(1, opts.kawaseQuality));
    const ready = opts.backgroundColor ? Promise.resolve() : this.renderer.loadWallpaper(opts.wallpaper ?? "").catch((e) => {
      opts.onError?.(e);
    });
    ready.then(() => {
      if (this.destroyed)
        return;
      requestAnimationFrame(() => this.opts.onReady?.());
    });
    this.canvas.style.display = "block";
    this.canvas.style.touchAction = "none";
    this.canvas.style.cursor = "pointer";
    this.resize();
    if (typeof ResizeObserver !== "undefined") {
      this.ro = new ResizeObserver(() => this.resize());
      this.ro.observe(this.canvas);
    }
    this.onPointerDown = this.onPointerDown.bind(this);
    this.onPointerMove = this.onPointerMove.bind(this);
    this.onPointerUp = this.onPointerUp.bind(this);
    this.onWheel = this.onWheel.bind(this);
    this.canvas.addEventListener("pointerdown", this.onPointerDown);
    this.canvas.addEventListener("pointermove", this.onPointerMove);
    this.canvas.addEventListener("pointerup", this.onPointerUp);
    this.canvas.addEventListener("pointerleave", this.onPointerUp);
    this.canvas.addEventListener("pointercancel", this.onPointerUp);
    this.canvas.addEventListener("wheel", this.onWheel, { passive: false });
  }
  setElements(elements) {
    this.elements = elements;
    this.renderer.setElements(elements);
  }
  setInteractions(interactions) {
    this.interactions = interactions;
  }
  setContentHeight(h) {
    this.renderer.setContentHeight(h);
  }
  scrollTo(y) {
    this.renderer.setScrollY(y);
  }
  async setWallpaper(src) {
    this.renderer.setBackgroundColor(null);
    await this.renderer.loadWallpaper(src);
  }
  requestRender() {
    this.renderer.requestRender();
  }
  getRenderer() {
    return this.renderer;
  }
  destroy() {
    if (this.destroyed)
      return;
    this.destroyed = true;
    this.ro?.disconnect();
    this.ro = null;
    this.canvas.removeEventListener("pointerdown", this.onPointerDown);
    this.canvas.removeEventListener("pointermove", this.onPointerMove);
    this.canvas.removeEventListener("pointerup", this.onPointerUp);
    this.canvas.removeEventListener("pointerleave", this.onPointerUp);
    this.canvas.removeEventListener("pointercancel", this.onPointerUp);
    this.canvas.removeEventListener("wheel", this.onWheel);
    this.renderer.dispose();
  }
  resize() {
    if (this.destroyed)
      return;
    const w = this.canvas.clientWidth || this.canvas.parentElement?.clientWidth || 0;
    const h = this.canvas.clientHeight || this.canvas.parentElement?.clientHeight || 0;
    if (w <= 0 || h <= 0)
      return;
    this.renderer.resize(w, h);
  }
  onWheel(e) {
    e.preventDefault();
    const delta = e.deltaY !== 0 ? e.deltaY : e.deltaX;
    const cur = this.renderer.getScrollY();
    this.renderer.setScrollY(cur + delta);
  }
  onPointerDown(e) {
    if (this.destroyed)
      return;
    const { x, y } = localPos(e, this.canvas);
    const scrollY = this.renderer.getScrollY();
    let hit = null;
    for (let i = this.elements.length - 1;i >= 0; i--) {
      const el = this.elements[i];
      if (el.clipRect) {
        const cr = el.clipRect;
        if (x < cr.x || x > cr.x + cr.w || y < cr.y || y > cr.y + cr.h)
          continue;
      }
      const hr = el.hitRect ?? el.rect;
      const visibleHY = el.scroll ? hr.y - scrollY : hr.y;
      let testX = x, testY = y;
      const elRot = el.elementRotation ?? 0;
      const elSx = el.elementScaleX ?? 1;
      const elSy = el.elementScaleY ?? 1;
      if (Math.abs(elRot) > 0.001 || Math.abs(elSx - 1) > 0.001 || Math.abs(elSy - 1) > 0.001) {
        const cx = hr.x + hr.w * 0.5;
        const cy = (el.scroll ? hr.y - scrollY : hr.y) + hr.h * 0.5;
        const dx = x - cx;
        const dy = y - cy;
        const cos = Math.cos(-elRot);
        const sin = Math.sin(-elRot);
        let rx = dx * cos - dy * sin;
        let ry = dx * sin + dy * cos;
        if (Math.abs(elSx) > 0.001)
          rx /= elSx;
        if (Math.abs(elSy) > 0.001)
          ry /= elSy;
        testX = cx + rx;
        testY = cy + ry;
      }
      if (testX >= hr.x && testX <= hr.x + hr.w && testY >= visibleHY && testY <= visibleHY + hr.h) {
        const hasInteraction = !!this.interactions[el.id];
        if (!hasInteraction && !el.isInteractive)
          continue;
        hit = el;
        break;
      }
    }
    if (hit) {
      const hitId = hit.id;
      const existingEntry = Array.from(this.gestures.entries()).find(([, g]) => g.pressedId === hitId && g.mode !== "transform");
      if (existingEntry && this.interactions[hitId]?.onTransform) {
        const [partnerPid, partnerGs] = existingEntry;
        if (hit.isInteractive && (hit.kind === "button" || hit.kind === "text")) {
          this.renderer.setPressed(hitId, false);
        }
        const p1 = { x: partnerGs.x, y: partnerGs.y };
        const dx = x - p1.x;
        const dy = y - p1.y;
        this.prevPinch = {
          dist: Math.hypot(dx, dy),
          angle: Math.atan2(dy, dx),
          cx: (p1.x + x) / 2,
          cy: (p1.y + y) / 2
        };
        partnerGs.mode = "transform";
        partnerGs.transformPartner = e.pointerId;
        this.gestures.set(e.pointerId, {
          pressedId: hitId,
          startX: x,
          startY: y,
          startClientY: e.clientY,
          startScrollY: this.renderer.getScrollY(),
          dragStarted: false,
          mode: "transform",
          hasDrag: !!this.interactions[hitId]?.onDrag,
          velocitySamples: [{ t: performance.now(), x: e.clientX, y: e.clientY }],
          x,
          y,
          transformPartner: partnerPid
        });
        this.capture(e.pointerId);
        return;
      }
    }
    const hasDrag = !!(hit && this.interactions[hit.id]?.onDrag);
    this.gestures.set(e.pointerId, {
      pressedId: hit ? hit.id : null,
      startX: x,
      startY: y,
      startClientY: e.clientY,
      startScrollY: this.renderer.getScrollY(),
      dragStarted: false,
      mode: "pending",
      hasDrag,
      velocitySamples: [{ t: performance.now(), x: e.clientX, y: e.clientY }],
      x,
      y,
      transformPartner: null
    });
    if (hit && hit.isInteractive) {
      const hasDrag0 = !!this.interactions[hit.id]?.onDrag;
      if (hit.kind === "button" || hit.kind === "text" || hit.kind === "glass-shape" && !hasDrag0 && !!this.interactions[hit.id]?.onTap) {
        this.renderer.setPressed(hit.id, true, { x, y });
      }
    }
    this.capture(e.pointerId);
  }
  onPointerMove(e) {
    if (this.destroyed)
      return;
    const { x, y } = localPos(e, this.canvas);
    const gs = this.gestures.get(e.pointerId);
    if (!gs)
      return;
    gs.x = x;
    gs.y = y;
    if (gs.mode === "transform") {
      const partnerPid = gs.transformPartner;
      if (partnerPid == null)
        return;
      const partner = this.gestures.get(partnerPid);
      if (!partner)
        return;
      const id = gs.pressedId;
      if (!id)
        return;
      const dx2 = partner.x - gs.x;
      const dy2 = partner.y - gs.y;
      const dist = Math.hypot(dx2, dy2);
      const angle = Math.atan2(dy2, dx2);
      const cx = (gs.x + partner.x) / 2;
      const cy = (gs.y + partner.y) / 2;
      const prev = this.prevPinch;
      if (prev && prev.dist > 0.001) {
        const gestureZoom = dist / prev.dist;
        let gestureRotate = angle - prev.angle;
        if (gestureRotate > Math.PI)
          gestureRotate -= 2 * Math.PI;
        if (gestureRotate < -Math.PI)
          gestureRotate += 2 * Math.PI;
        const pan = { x: cx - prev.cx, y: cy - prev.cy };
        this.interactions[id]?.onTransform?.(pan, gestureZoom, gestureRotate);
      }
      this.prevPinch = { dist, angle, cx, cy };
      return;
    }
    gs.velocitySamples.push({ t: performance.now(), x: e.clientX, y: e.clientY });
    if (gs.velocitySamples.length > 20)
      gs.velocitySamples.shift();
    const dx = x - gs.startX;
    const dy = y - gs.startY;
    const absDx = Math.abs(dx);
    const absDy = Math.abs(dy);
    if (gs.mode === "pending") {
      const MOVE_THRESHOLD = 4;
      const id0 = gs.pressedId;
      if (id0) {
        const el0 = this.elements.find((b) => b.id === id0);
        if (el0?.kind === "button" && el0.isInteractive) {
          this.renderer.setDragPosition(id0, { x, y });
        }
      }
      if (absDx < MOVE_THRESHOLD && absDy < MOVE_THRESHOLD)
        return;
      const id = gs.pressedId;
      const hitEl = id ? this.elements.find((b) => b.id === id) : null;
      const isButton = hitEl?.kind === "button" && hitEl?.isInteractive;
      const hasDrag = !!hitEl && !!this.interactions[id]?.onDrag;
      const isShapeButton = !hasDrag && hitEl?.kind === "glass-shape" && hitEl?.isInteractive && !!this.interactions[id]?.onTap;
      if (hasDrag) {
        gs.mode = "drag";
        gs.dragStarted = true;
        this.interactions[id]?.onDragStart?.({ x: gs.startX, y: gs.startY });
      } else if (isButton || isShapeButton) {
        this.renderer.setDragPosition(id, { x, y });
      } else {
        const SCROLL_TAKEOVER_THRESHOLD = 14;
        const verticalDominant = absDy > absDx + 2 && absDy >= SCROLL_TAKEOVER_THRESHOLD;
        if (verticalDominant) {
          const otherScrolling = Array.from(this.gestures.entries()).some(([pid, g]) => pid !== e.pointerId && g.mode === "scroll");
          if (otherScrolling)
            return;
          if (id) {
            const el = this.elements.find((b) => b.id === id);
            if (el?.isInteractive && el.kind === "text") {
              this.renderer.setPressed(id, false);
            }
          }
          gs.mode = "scroll";
          const scrollDelta = e.clientY - gs.startClientY;
          this.renderer.setScrollY(gs.startScrollY - scrollDelta);
          return;
        }
      }
    }
    if (gs.mode === "scroll") {
      const scrollDelta = e.clientY - gs.startClientY;
      this.renderer.setScrollY(gs.startScrollY - scrollDelta);
      return;
    }
    if (gs.mode === "drag") {
      const id = gs.pressedId;
      if (!id)
        return;
      const el = this.elements.find((b) => b.id === id);
      if (!el)
        return;
      if (el.kind === "button" && el.isInteractive) {
        this.renderer.setDragPosition(id, { x, y });
      }
      this.interactions[id]?.onDrag?.({ x, y }, { x: dx, y: dy });
    }
  }
  onPointerUp(e) {
    if (this.destroyed)
      return;
    const gs = this.gestures.get(e.pointerId);
    if (!gs) {
      if (this.canvas.hasPointerCapture(e.pointerId))
        this.release(e.pointerId);
      return;
    }
    const mode = gs.mode;
    const id = gs.pressedId;
    if (mode === "transform") {
      const partnerPid = gs.transformPartner;
      this.gestures.delete(e.pointerId);
      this.prevPinch = null;
      if (partnerPid != null) {
        const partner = this.gestures.get(partnerPid);
        if (partner) {
          partner.transformPartner = null;
          partner.mode = "drag";
          partner.dragStarted = true;
          partner.startX = partner.x;
          partner.startY = partner.y;
          if (partner.pressedId) {
            this.interactions[partner.pressedId]?.onDragStart?.({ x: partner.x, y: partner.y });
          }
        }
      }
      if (this.canvas.hasPointerCapture(e.pointerId))
        this.release(e.pointerId);
      return;
    }
    if (id) {
      const el = this.elements.find((b) => b.id === id);
      if (el?.isInteractive) {
        const hasDrag1 = !!this.interactions[id]?.onDrag;
        if (el.kind === "button" || el.kind === "text" || el.kind === "glass-shape" && !hasDrag1 && !!this.interactions[id]?.onTap) {
          this.renderer.setPressed(id, false);
        }
      }
    }
    if (mode === "scroll") {
      const v = computeReleaseVelocity(gs.velocitySamples);
      if (Math.abs(v) > 50)
        this.renderer.setScrollVelocity(v);
    }
    if (id) {
      const { x, y } = localPos(e, this.canvas);
      if (gs.dragStarted) {
        const { x: vx, y: vy } = computeReleaseVelocity2D(gs.velocitySamples);
        this.interactions[id]?.onDragEnd?.({ x, y }, { x: vx, y: vy });
      } else if (mode === "pending" || mode === "drag") {
        this.interactions[id]?.onTap?.({ x, y });
      }
    }
    this.gestures.delete(e.pointerId);
    if (this.canvas.hasPointerCapture(e.pointerId))
      this.release(e.pointerId);
  }
  capture(pointerId) {
    try {
      this.canvas.setPointerCapture(pointerId);
    } catch {}
  }
  release(pointerId) {
    try {
      this.canvas.releasePointerCapture(pointerId);
    } catch {}
  }
}
// src/cdn/constants.ts
var DP2 = 1;
var FONT_FAMILY = '-apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
var BUTTON_HEIGHT = 48 * DP2;
var BUTTON_HORIZONTAL_PADDING = 16 * DP2;
var TEXT_FONT_SIZE_PX = 15 * DP2;
var TITLE_FONT_SIZE_PX = 28 * DP2;
var GLASS_PARAMS = {
  refractionHeight: 12 * DP2,
  refractionAmount: -24 * DP2,
  depthEffect: false,
  chromaticAberration: false,
  blurRadius: 2 * DP2,
  saturation: 1.5,
  brightness: 0,
  contrast: 1
};
var DEFAULT_HIGHLIGHT = {
  mode: 0,
  color: [1, 1, 1],
  angle: 45 * Math.PI / 180,
  falloff: 1,
  alpha: 0.5,
  widthDp: 0.5
};
var DEFAULT_SHADOW = {
  radius: 24 * DP2,
  alpha: 0.1,
  offsetX: 0,
  offsetY: 24 / 6 * DP2,
  color: [0, 0, 0]
};
function lerp(a, b, t) {
  return a + (b - a) * t;
}

// src/cdn/elements.ts
function measureTextWidth(text, fontSizePx, fontWeight = 400) {
  try {
    const c = document.createElement("canvas");
    const ctx = c.getContext("2d");
    if (!ctx)
      return text.length * fontSizePx * 0.55;
    ctx.font = `${fontWeight} ${fontSizePx}px -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif`;
    return ctx.measureText(text).width;
  } catch {
    return text.length * fontSizePx * 0.55;
  }
}
function makeButton(id, rect, spec, scroll = true) {
  return {
    id,
    kind: "button",
    rect,
    ...GLASS_PARAMS,
    cornerRadius: spec.cornerRadius ?? rect.h / 2,
    saturation: spec.saturation ?? GLASS_PARAMS.saturation,
    brightness: spec.brightness ?? GLASS_PARAMS.brightness,
    contrast: spec.contrast ?? GLASS_PARAMS.contrast,
    tintColor: spec.tintColor ?? [0, 0, 0, 0],
    surfaceColor: spec.surfaceColor ?? [0, 0, 0, 0],
    highlight: { ...DEFAULT_HIGHLIGHT },
    outerShadow: { ...DEFAULT_SHADOW },
    label: spec.label,
    labelColor: spec.labelColor ?? [0, 0, 0, 1],
    labelFontSizePx: spec.labelFontSizePx,
    showChevron: false,
    isInteractive: true,
    pressTintColor: spec.pressTintColor,
    scroll,
    independentBackdrop: false,
    directBackdropSample: true
  };
}
function makeText(id, rect, text, opts = {}, scroll = true) {
  return {
    id,
    kind: "text",
    rect,
    cornerRadius: 0,
    refractionHeight: 0,
    refractionAmount: 0,
    depthEffect: false,
    chromaticAberration: false,
    blurRadius: 0,
    saturation: 1,
    brightness: 0,
    contrast: 1,
    tintColor: [0, 0, 0, 0],
    surfaceColor: [0, 0, 0, 0],
    highlight: null,
    outerShadow: null,
    label: "",
    labelColor: [0, 0, 0, 1],
    showChevron: false,
    isInteractive: false,
    pressTintColor: opts.pressTintColor,
    scroll,
    text: {
      content: text,
      color: opts.color ?? [0, 0, 0, 1],
      fontSizePx: opts.fontSizePx ?? TEXT_FONT_SIZE_PX,
      fontWeight: opts.fontWeight ?? 400,
      align: opts.align ?? "left",
      wrap: opts.wrap ?? false,
      paddingPx: opts.paddingPx ?? 16,
      valign: opts.valign,
      maxLines: opts.maxLines,
      halo: opts.halo ?? "auto",
      icon: opts.icon ? {
        path: opts.icon.icon,
        size: opts.icon.size,
        layoutSize: opts.icon.layoutSize,
        color: opts.icon.color,
        viewport: opts.icon.viewport
      } : undefined
    }
  };
}
function makePlainRect(id, rect, color, cornerRadius = 0, scroll = true) {
  return {
    id,
    kind: "plain-rect",
    rect,
    cornerRadius,
    refractionHeight: 0,
    refractionAmount: 0,
    depthEffect: false,
    chromaticAberration: false,
    blurRadius: 0,
    saturation: 1,
    brightness: 0,
    contrast: 1,
    tintColor: [0, 0, 0, 0],
    surfaceColor: [0, 0, 0, 0],
    highlight: null,
    outerShadow: null,
    label: "",
    labelColor: [0, 0, 0, 1],
    showChevron: false,
    isInteractive: false,
    scroll,
    plainRect: { color }
  };
}
function makeGlassShape(id, rect, opts = {}, scroll = true) {
  return {
    id,
    kind: "glass-shape",
    rect,
    cornerRadius: opts.cornerRadius ?? rect.h / 2,
    refractionHeight: opts.refractionHeight ?? 12,
    refractionAmount: opts.refractionAmount ?? -24,
    depthEffect: opts.depthEffect ?? false,
    chromaticAberration: opts.chromaticAberration ?? false,
    blurRadius: opts.blurRadius ?? 2,
    saturation: opts.saturation ?? 1.5,
    brightness: opts.brightness ?? 0,
    contrast: opts.contrast ?? 1,
    tintColor: [0, 0, 0, 0],
    surfaceColor: opts.surfaceColor ?? [0, 0, 0, 0],
    highlight: opts.highlight !== undefined ? opts.highlight : { ...DEFAULT_HIGHLIGHT },
    outerShadow: opts.outerShadow !== undefined ? opts.outerShadow : { ...DEFAULT_SHADOW },
    label: "",
    labelColor: [0, 0, 0, 1],
    showChevron: false,
    isInteractive: opts.isInteractive ?? false,
    scroll,
    innerShadow: opts.innerShadow ?? null,
    independentBackdrop: true
  };
}
// src/cdn/drag.ts
var dragStates = new Map;
var draggingGroups = new Set;
function fracFromPos(px, trackX, dragW) {
  return Math.max(0, Math.min(1, (px - trackX) / dragW));
}
function makeSliderInteractions(opts) {
  const { groupId, trackX, dragW, renderer, onValueChange, onLiveValue, snap, didDragThreshold = 3 } = opts;
  if (!dragStates.has(groupId))
    dragStates.set(groupId, { fraction: 0, x: 0, didDrag: false });
  const ds = dragStates.get(groupId);
  const applySnap = (f) => snap ? snap(f) : f;
  return {
    onTap: (pos) => {
      const r = renderer();
      if (!r)
        return;
      const f = applySnap(fracFromPos(pos.x, trackX, dragW));
      r.setToggleTarget(groupId, f);
      onValueChange(f);
    },
    onDragStart: (pos) => {
      const r = renderer();
      if (!r)
        return;
      ds.fraction = r.getToggleFraction(groupId);
      ds.x = pos.x;
      ds.didDrag = false;
      r.beginToggleDrag(groupId, ds.fraction);
    },
    onDrag: (pos) => {
      const r = renderer();
      if (!r)
        return;
      if (Math.abs(pos.x - ds.x) > didDragThreshold)
        ds.didDrag = true;
      r.dragToggle(groupId, ds.fraction, pos.x, ds.x, dragW);
      const f = r.getToggleFraction(groupId);
      if (onLiveValue)
        onLiveValue(f);
    },
    onDragEnd: () => {
      const r = renderer();
      if (!r)
        return;
      const rawF = r.endSliderDrag(groupId);
      const snappedF = applySnap(rawF);
      r.setToggleTarget(groupId, snappedF);
      onValueChange(snappedF);
    }
  };
}
function makeTabDragInteractions(groupId, tabWidth, tabsCount, renderer, onSelect) {
  if (!dragStates.has(groupId))
    dragStates.set(groupId, { fraction: 0, x: 0, didDrag: false });
  const ds = dragStates.get(groupId);
  return {
    onDragStart: (pos) => {
      const r = renderer();
      if (!r)
        return;
      draggingGroups.add(groupId);
      ds.fraction = r.getTabTarget(groupId);
      ds.x = pos.x;
      ds.didDrag = false;
      r.beginTabDrag(groupId, ds.fraction, tabsCount);
    },
    onDrag: (pos) => {
      const r = renderer();
      if (!r)
        return;
      if (Math.abs(pos.x - ds.x) > 3)
        ds.didDrag = true;
      r.dragTab(groupId, ds.fraction, pos.x, ds.x, tabWidth, tabsCount);
    },
    onDragEnd: () => {
      const r = renderer();
      if (!r)
        return;
      const finalIndex = r.endTabDrag(groupId, tabsCount);
      if (ds.didDrag)
        onSelect(finalIndex);
      draggingGroups.delete(groupId);
    }
  };
}
function makeToggleInteractions(opts) {
  const { groupId, trackX, dragW, renderer, onValueChange, onLiveValue, didDragThreshold = 3 } = opts;
  if (!dragStates.has(groupId))
    dragStates.set(groupId, { fraction: 0, x: 0, didDrag: false });
  const ds = dragStates.get(groupId);
  const getValue = opts.getValue ?? ((r) => r.getToggleTarget(groupId));
  return {
    onTap: () => {
      const r = renderer();
      if (!r)
        return;
      const next = getValue(r) >= 0.5 ? 0 : 1;
      r.setToggleTarget(groupId, next);
      onValueChange(next);
      if (opts.onToggle)
        opts.onToggle(next);
    },
    onDragStart: (pos) => {
      const r = renderer();
      if (!r)
        return;
      ds.fraction = getValue(r);
      ds.x = pos.x;
      ds.didDrag = false;
      r.beginToggleDrag(groupId, ds.fraction);
    },
    onDrag: (pos) => {
      const r = renderer();
      if (!r)
        return;
      if (Math.abs(pos.x - ds.x) > didDragThreshold)
        ds.didDrag = true;
      r.dragToggle(groupId, ds.fraction, pos.x, ds.x, dragW);
      const f = r.getToggleFraction(groupId);
      if (onLiveValue)
        onLiveValue(f);
    },
    onDragEnd: () => {
      const r = renderer();
      if (!r)
        return;
      const rawF = r.endToggleDrag(groupId);
      onValueChange(rawF >= 0.5 ? 1 : 0);
    }
  };
}

// src/cdn/controls.ts
var DEFAULT_ACCENT = [52 / 255, 199 / 255, 89 / 255, 1];
var DEFAULT_TRACK_OFF = [120 / 255, 120 / 255, 120 / 255, 0.2];
var KNOB_HIGHLIGHT = {
  mode: 1,
  color: [1, 1, 1],
  angle: Math.PI / 4,
  falloff: 1,
  alpha: 1,
  widthDp: 0.5 / 1.5,
  blurRadiusDp: 0.25 / 1.5
};
function makeKnobMaterial(knobH, refractionHeight, refractionAmount) {
  return {
    cornerRadius: knobH / 2,
    refractionHeight,
    refractionAmount,
    blurRadius: 8,
    saturation: 1,
    surfaceColor: [0, 0, 0, 0],
    chromaticAberration: true,
    highlight: KNOB_HIGHLIGHT,
    outerShadow: { radius: 4, alpha: 0.05, offsetX: 0, offsetY: 4 / 6, color: [0, 0, 0] },
    innerShadow: { radius: 4, alpha: 0.3, offsetX: 0, offsetY: 4 }
  };
}
var usedIds = new Set;
function uniqueId(base) {
  if (!usedIds.has(base)) {
    usedIds.add(base);
    return base;
  }
  let n = 2;
  while (usedIds.has(`${base}-${n}`))
    n++;
  const id = `${base}-${n}`;
  usedIds.add(id);
  return id;
}
function makeToggle(glass, spec) {
  const id = spec.id ?? uniqueId("toggle");
  const trackW = spec.width ?? 64;
  const trackH = spec.height ?? 28;
  const knobW = spec.knobWidth ?? 40;
  const knobH = spec.knobHeight ?? 24;
  const accent = spec.accent ?? DEFAULT_ACCENT;
  const offColor = spec.offColor ?? DEFAULT_TRACK_OFF;
  const knobX = spec.x + 2;
  const knobY = spec.y + (trackH - knobH) / 2;
  const dragW = Math.max(1, trackW - knobW - 4);
  const renderer = () => glass.getRenderer();
  const track = makePlainRect(`${id}-track`, { x: spec.x, y: spec.y, w: trackW, h: trackH }, offColor, trackH / 2);
  track.isToggleTrack = { groupId: id, offColor, onColor: accent };
  const knob = makeGlassShape(`${id}-knob`, { x: knobX, y: knobY, w: knobW, h: knobH }, makeKnobMaterial(knobH, 5, -10));
  knob.isToggleKnob = {
    groupId: id,
    dragWidth: dragW,
    velocityDivisor: 50,
    trackColorOff: offColor,
    trackColorOn: accent,
    trackW,
    trackH,
    trackOriginalX: spec.x,
    trackOriginalY: spec.y,
    solidBackdropColor: spec.solidBackdropColor
  };
  const interaction = makeToggleInteractions({
    groupId: id,
    trackX: spec.x,
    dragW,
    renderer,
    onValueChange: spec.onValueChange,
    onLiveValue: spec.onLiveValue
  });
  const control = {
    groupId: id,
    hitIds: [`${id}-track`, `${id}-knob`],
    elements: [track, knob],
    interactions: { [`${id}-track`]: interaction, [`${id}-knob`]: interaction },
    setValue: (fraction) => renderer().setToggleTarget(id, Math.max(0, Math.min(1, fraction))),
    getValue: () => renderer().getToggleTarget(id),
    getLiveValue: () => renderer().getToggleFraction(id)
  };
  if (spec.value)
    control.setValue(1);
  return control;
}
function makeSlider(glass, spec) {
  const id = spec.id ?? uniqueId("slider");
  const trackW = spec.width ?? 200;
  const trackH = spec.trackHeight ?? 6;
  const knobW = spec.knobWidth ?? 40;
  const knobH = spec.knobHeight ?? 24;
  const hitH = spec.hitHeight ?? 48;
  const accent = spec.accent ?? DEFAULT_ACCENT;
  const trackColor = spec.trackColor ?? DEFAULT_TRACK_OFF;
  const initial = Math.max(0, Math.min(1, spec.value ?? 0));
  const dragW = Math.max(1, trackW - knobW / 2);
  const knobX = spec.x - knobW / 4;
  const knobY = spec.y + (trackH - knobH) / 2;
  const renderer = () => glass.getRenderer();
  const track = makePlainRect(`${id}-track`, { x: spec.x, y: spec.y, w: trackW, h: trackH }, trackColor, trackH / 2);
  track.hitRect = {
    x: spec.x,
    y: spec.y + (trackH - hitH) / 2,
    w: trackW,
    h: hitH
  };
  const fill = makePlainRect(`${id}-fill`, { x: spec.x, y: spec.y, w: Math.max(trackH, initial * trackW), h: trackH }, accent, trackH / 2);
  fill.isSliderFill = {
    groupId: id,
    trackX: spec.x,
    trackW,
    knobW,
    minW: 0
  };
  const knob = makeGlassShape(`${id}-knob`, { x: knobX, y: knobY, w: knobW, h: knobH }, makeKnobMaterial(knobH, 10, -14));
  knob.isToggleKnob = { groupId: id, dragWidth: dragW, velocityDivisor: 10 };
  knob.independentBackdrop = false;
  knob.hitRect = {
    x: knobX,
    y: knobY + (knobH - hitH) / 2,
    w: knobW,
    h: hitH
  };
  const interaction = makeSliderInteractions({
    groupId: id,
    trackX: spec.x,
    dragW,
    renderer,
    onValueChange: spec.onValueChange,
    onLiveValue: spec.onLiveValue,
    snap: spec.snap
  });
  const control = {
    groupId: id,
    hitIds: [`${id}-track`, `${id}-knob`],
    elements: [track, fill, knob],
    interactions: { [`${id}-track`]: interaction, [`${id}-knob`]: interaction },
    setValue: (fraction) => renderer().setToggleTarget(id, Math.max(0, Math.min(1, fraction))),
    getValue: () => renderer().getToggleTarget(id),
    getLiveValue: () => renderer().getToggleFraction(id)
  };
  if (initial > 0)
    control.setValue(initial);
  return control;
}
// src/cdn/ui/layout.ts
var registry = new Map;
function registerWidget(kind, impl) {
  registry.set(kind, impl);
}
function widgetImpl(kind) {
  const impl = registry.get(kind);
  if (!impl)
    throw new Error(`[LG] unknown widget kind: "${kind}"`);
  return impl;
}
function measureNode(node, availW, ctx) {
  switch (node.kind) {
    case "vstack": {
      const o = stackOpts(node);
      const innerW = availW - 2 * o.padding;
      let h = 0;
      let flexCount = 0;
      let fixedCount = 0;
      const sizes = [];
      for (const c of node.children ?? []) {
        const m = measureNode(c, Math.max(0, innerW), ctx);
        sizes.push(m);
        if (m.flex)
          flexCount += m.flex;
        else {
          h += m.h;
          fixedCount++;
        }
      }
      if (fixedCount > 0)
        h += o.spacing * (fixedCount - 1 + (flexCount > 0 ? 1 : 0));
      const w = Math.max(...sizes.map((s) => s.w), 0);
      return { w: w + 2 * o.padding, h: h + 2 * o.padding, flex: node.flex ? 1 : undefined };
    }
    case "hstack": {
      const o = stackOpts(node);
      const sizes = (node.children ?? []).map((c) => measureNode(c, availW, ctx));
      let w = 0;
      let flexCount = 0;
      let fixedCount = 0;
      for (const m of sizes) {
        if (m.flex)
          flexCount += m.flex;
        else {
          w += m.w;
          fixedCount++;
        }
      }
      if (fixedCount > 0)
        w += o.spacing * (fixedCount - 1 + (flexCount > 0 ? 1 : 0));
      const h = Math.max(...sizes.map((s) => s.h), 0);
      return { w: w + 2 * o.padding, h: h + 2 * o.padding, flex: node.flex ? 1 : undefined };
    }
    case "zstack": {
      const o = stackOpts(node);
      const sizes = (node.children ?? []).map((c) => measureNode(c, Math.max(0, availW - 2 * o.padding), ctx));
      return {
        w: Math.max(...sizes.map((s) => s.w), 0) + 2 * o.padding,
        h: Math.max(...sizes.map((s) => s.h), 0) + 2 * o.padding,
        flex: node.flex ? 1 : undefined
      };
    }
    case "spacer": {
      const minH = num(node.minHeight, 0);
      const minW = num(node.minWidth, 0);
      return { w: minW, h: minH, flex: num(node.flex, 1) };
    }
    case "position": {
      const child = node.children?.[0];
      if (!child)
        return { w: 0, h: 0 };
      const m = measureNode(child, availW, ctx);
      return { w: num(node.w, m.w), h: num(node.h, m.h) };
    }
    default: {
      const impl = widgetImpl(node.kind);
      const m = impl.measure(node, availW, ctx);
      return node.flex ? { ...m, flex: num(node.flex, 1) } : m;
    }
  }
}
function stackOpts(node) {
  return {
    spacing: num(node.spacing, 0),
    padding: num(node.padding, 0),
    align: node.align ?? "center"
  };
}
function num(v, d) {
  return typeof v === "number" && Number.isFinite(v) ? v : d;
}
function buildNode(node, frame, ctx, path) {
  switch (node.kind) {
    case "vstack": {
      const o = stackOpts(node);
      const inner = { x: frame.x + o.padding, y: frame.y + o.padding, w: Math.max(0, frame.w - 2 * o.padding) };
      const children = node.children ?? [];
      const sizes = children.map((c) => measureNode(c, inner.w, ctx));
      const totalFlex = sizes.reduce((s, m) => s + (m.flex ?? 0), 0);
      const fixedH = sizes.reduce((s, m) => s + (m.flex ? 0 : m.h), 0);
      const gaps = children.length > 1 ? o.spacing * (children.length - 1) : 0;
      const freeH = Math.max(0, frame.h - 2 * o.padding - fixedH - gaps);
      let y = inner.y;
      let idx = 0;
      for (const c of children) {
        const m = sizes[idx];
        const ch = m.flex ? totalFlex > 0 ? m.flex / totalFlex * freeH : 0 : m.h;
        const cw = Math.min(m.w, inner.w);
        let cx = inner.x;
        if (o.align === "center")
          cx = inner.x + (inner.w - cw) / 2;
        else if (o.align === "trailing")
          cx = inner.x + (inner.w - cw);
        const cPath = `${path}/${c.key ?? `${c.kind}@${idx}`}`;
        const fillW = m.w >= inner.w - 0.5;
        buildNode(c, { x: cx, y, w: fillW ? inner.w : cw, h: ch }, ctx, cPath);
        if (idx < children.length - 1)
          y += ch + o.spacing;
        else
          y += ch;
        idx++;
      }
      return;
    }
    case "hstack": {
      const o = stackOpts(node);
      const inner = { x: frame.x + o.padding, y: frame.y + o.padding, w: Math.max(0, frame.w - 2 * o.padding) };
      const children = node.children ?? [];
      const sizes = children.map((c) => {
        const m = measureNode(c, inner.w, ctx);
        return c.fill === true && !m.flex ? { ...m, flex: 1 } : m;
      });
      const totalFlex = sizes.reduce((s, m) => s + (m.flex ?? 0), 0);
      const fixedW = sizes.reduce((s, m) => s + (m.flex ? 0 : m.w), 0);
      const gaps = children.length > 1 ? o.spacing * (children.length - 1) : 0;
      const freeW = Math.max(0, inner.w - fixedW - gaps);
      let x = inner.x;
      let idx = 0;
      for (const c of children) {
        const m = sizes[idx];
        const cw = m.flex ? totalFlex > 0 ? m.flex / totalFlex * freeW : 0 : m.w;
        const chh = m.h;
        let cy = inner.y;
        if (o.align === "center")
          cy = frame.y + (frame.h - chh) / 2;
        else if (o.align === "bottom")
          cy = frame.y + frame.h - chh - o.padding;
        else if (o.align === "top")
          cy = inner.y;
        const cPath = `${path}/${c.key ?? `${c.kind}@${idx}`}`;
        const fillH = m.h >= frame.h - 2 * o.padding - 0.5;
        buildNode(c, { x, y: cy, w: cw, h: fillH ? frame.h - 2 * o.padding : chh }, ctx, cPath);
        if (idx < children.length - 1)
          x += cw + o.spacing;
        else
          x += cw;
        idx++;
      }
      return;
    }
    case "zstack": {
      const o = stackOpts(node);
      const children = node.children ?? [];
      let idx = 0;
      for (const c of children) {
        const m = measureNode(c, Math.max(0, frame.w - 2 * o.padding), ctx);
        const cPath = `${path}/${c.key ?? `${c.kind}@${idx}`}`;
        const cw = m.w >= frame.w - 2 * o.padding - 0.5 ? frame.w - 2 * o.padding : m.w;
        const ch = m.h >= frame.h - 2 * o.padding - 0.5 ? frame.h - 2 * o.padding : m.h;
        let cx = frame.x + o.padding;
        let cy = frame.y + o.padding;
        if (o.align === "center") {
          cx = frame.x + (frame.w - cw) / 2;
          cy = frame.y + (frame.h - ch) / 2;
        }
        buildNode(c, { x: cx, y: cy, w: cw, h: ch }, ctx, cPath);
        idx++;
      }
      return;
    }
    case "spacer":
      return;
    case "position": {
      const child = node.children?.[0];
      if (!child)
        return;
      const cPath = `${path}/${child.key ?? child.kind}`;
      const m = measureNode(child, frame.w, ctx);
      buildNode(child, { x: frame.x + num(node.dx, 0), y: frame.y + num(node.dy, 0), w: num(node.w, m.w), h: num(node.h, m.h) }, ctx, cPath);
      return;
    }
    default: {
      const impl = widgetImpl(node.kind);
      impl.build(node, frame, ctx, path);
    }
  }
}
function wrappedLines(text, fontPx, maxW, fontWeight = 400) {
  if (!text)
    return 1;
  const words = text.split(/\s+/);
  let cur = "";
  let lines = 0;
  for (const word of words) {
    const test = cur ? `${cur} ${word}` : word;
    if (measureTextWidth(test, fontPx, fontWeight) <= maxW || !cur)
      cur = test;
    else {
      lines++;
      cur = word;
    }
  }
  if (cur)
    lines++;
  return Math.max(1, lines);
}
function wrappedHeight(text, fontPx, maxW, fontWeight = 400) {
  return wrappedLines(text, fontPx, maxW, fontWeight) * fontPx * 1.35;
}

// src/cdn/ui/types.ts
var LIGHT_PALETTE = {
  textPrimary: [0.06, 0.06, 0.08, 1],
  textSecondary: [0.06, 0.06, 0.08, 0.6],
  accent: [52 / 255, 199 / 255, 89 / 255, 1],
  trackOff: [120 / 255, 120 / 255, 120 / 255, 0.2],
  cardBg: [1, 1, 1, 1],
  cardTitle: [0.06, 0.06, 0.08, 1],
  scrim: [41 / 255, 41 / 255, 58 / 255, 0.23],
  dialogContainer: [250 / 255, 250 / 255, 250 / 255, 0.6],
  dialogContent: [0.06, 0.06, 0.08, 1],
  dialogAccent: [0, 136 / 255, 1, 1],
  tabsContainer: [250 / 255, 250 / 255, 250 / 255, 0.4],
  tabsAccent: [0, 136 / 255, 1],
  tabsContent: [0, 0, 0, 1],
  ccTileSurface: [0, 0, 0, 0.05],
  ccTileAccent: [0, 136 / 255, 1, 1]
};
var DARK_PALETTE = {
  textPrimary: [1, 1, 1, 0.95],
  textSecondary: [1, 1, 1, 0.6],
  accent: [52 / 255, 199 / 255, 89 / 255, 1],
  trackOff: [1, 1, 1, 0.16],
  cardBg: [28 / 255, 28 / 255, 30 / 255, 1],
  cardTitle: [1, 1, 1, 0.92],
  scrim: [18 / 255, 18 / 255, 18 / 255, 0.56],
  dialogContainer: [18 / 255, 18 / 255, 18 / 255, 0.4],
  dialogContent: [1, 1, 1, 0.92],
  dialogAccent: [0, 145 / 255, 1, 1],
  tabsContainer: [18 / 255, 18 / 255, 18 / 255, 0.4],
  tabsAccent: [0, 145 / 255, 1],
  tabsContent: [1, 1, 1, 1],
  ccTileSurface: [1, 1, 1, 0.08],
  ccTileAccent: [0, 145 / 255, 1, 1]
};
var ICONS = {
  flight: {
    path: "M240 216q0 -9 6 -17t16 -8q9 0 16 8t7 17v112l93 39V164q0 -9 6 -17t16 -8q10 0 17 8t7 17v228l56 23q12 5 12 19t-12 19l-56 23v228q0 10 -7 17.5t-17 7.5q-10 0 -16 -7.5t-6 -17.5V605l-93 39v112q0 9 -7 17t-16 8q-10 0 -16 -8t-6 -17V623l-69 -29q-12 -5 -12 -19t12 -19l69 -29V216zm62 -24l46 19v83l-46 -19v-83z",
    viewport: 960
  },
  wifi: {
    path: "M12 21l-1.4 -2.9q-0.1 -0.2 0 -0.4t0.4 -0.2h2q0.3 0 0.4 0.2t0 0.4L12 21zM5.5 17.5q-0.2 0 -0.4 -0.2t-0.2 -0.4t0.2 -0.4q3 -3 6.9 -3t6.9 3q0.2 0.2 0.2 0.4t-0.2 0.4l-1 1q-0.1 0.1 -0.3 0.1t-0.3 -0.1q-2.3 -2.1 -5.6 -2.1t-5.6 2.1q-0.1 0.1 -0.3 0.1t-0.3 -0.1l-1 -1zM2 14q-0.2 0 -0.4 -0.2t-0.2 -0.4t0.2 -0.4q4.2 -4 10 -4t10 4q0.2 0.2 0.2 0.4t-0.2 0.4l-1 1q-0.1 0.1 -0.3 0.1t-0.3 -0.1q-3.5 -3.3 -8.4 -3.3t-8.4 3.3q-0.1 0.1 -0.3 0.1t-0.3 -0.1l-1 -1zM8 6.5q0 0.2 -0.1 0.4t-0.4 0.2t-0.4 -0.2L5.5 6.3q-0.2 -0.1 -0.2 -0.4t0.2 -0.4q3.1 -1.9 6.5 -1.9t6.5 1.9q0.2 0.1 0.2 0.4t-0.2 0.4l-1.6 0.6q-0.2 0.2 -0.4 0.2t-0.4 -0.2q-2 -1.1 -4.5 -1.1t-4.5 1.1z",
    viewport: 24
  },
  bluetooth: {
    path: "M12 22l-5 -5l2 -2l2 2V7l-2 2l-2 -2l5 -5l5 5l-2 2l-2 -2v10l2 -2l2 2L12 22zm0 -11.2L14.5 8.3L12 5.8L9.5 8.3L12 10.8z",
    viewport: 24
  },
  moon: {
    path: "M12 3q-0.5 0 -0.5 0.5t0.5 0.5q3.7 0 6.3 2.7T21 13q0 3.7 -2.7 6.3T12 22q-3.7 0 -6.3 -2.7T3 13q0 -0.5 0.5 -0.5t0.5 0.5q0 2.9 2.1 4.9T11 19.9q0.4 -1.1 0.4 -2.2q0 -1.5 -0.8 -2.7T8.6 13.3q-1.1 -0.7 -2.4 -0.7q-0.3 0 -0.7 0q0.6 -2.3 2.5 -3.7T12 7.5q3 0 5.1 2.1t2.1 5.1q0 1.4 -0.5 2.7q1.2 -1.6 1.2 -4.4q0 -3.9 -2.8 -6.7T12 3z",
    viewport: 24
  },
  lock: {
    path: "M12 2q-2.5 0 -4.25 1.75T6 8v3H5q-0.8 0 -1.4 0.6T3 13v8q0 0.8 0.6 1.4T5 23h14q0.8 0 1.4 -0.6T21 21v-8q0 -0.8 -0.6 -1.4T19 11h-1V8q0 -2.5 -1.75 -4.25T12 2zm0 2q1.7 0 2.85 1.15T16 8v3H8V8q0 -1.7 1.15 -2.85T12 4zm0 10q0.8 0 1.4 0.6T14 16t-0.6 1.4T12 18t-1.4 -0.6T10 16t0.6 -1.4T12 14z",
    viewport: 24
  },
  play: {
    path: "M8 5v14l11 -7L8 5z",
    viewport: 24
  },
  volume: {
    path: "M4 9v6h4l5 5V4L8 9H4zm12.5 3q0 -1.9 -1 -3.4l-1.4 1.4q0.6 0.9 0.6 2t-0.6 2l1.4 1.4q1 -1.5 1 -3.4zM16 3.8v2q2.9 0.9 2.9 4.2t-2.9 4.2v2q3.9 -1 3.9 -6.2t-3.9 -6.2z",
    viewport: 24
  },
  sun: {
    path: "M12 7q-2.5 0 -4.25 1.75T6 13t1.75 4.25T12 19t4.25 -1.75T18 13t-1.75 -4.25T12 7zm0 2q1.7 0 2.85 1.15T16 13t-1.15 2.85T12 17t-2.85 -1.15T8 13t1.15 -2.85T12 9zm-1 -7h2v3h-2V2zm0 19h2v3h-2v-3zM2 11h3v2H2v-2zm17 0h3v2h-3v-2zM4.2 5.6l1.4 -1.4l2.1 2.1L6.3 7.7L4.2 5.6zm12.1 12.1l1.4 -1.4l2.1 2.1l-1.4 1.4l-2.1 -2.1zM4.2 20.4l2.1 -2.1l1.4 1.4l-2.1 2.1l-1.4 -1.4zM16.3 6.3l2.1 -2.1l1.4 1.4l-2.1 2.1l-1.4 -1.4z",
    viewport: 24
  }
};

// src/cdn/ui/widgets.ts
var num2 = (v, d) => typeof v === "number" && Number.isFinite(v) ? v : d;
var str = (v, d) => typeof v === "string" ? v : d;
var fun = (v) => typeof v === "function" ? v : undefined;
var col = (v, d) => Array.isArray(v) && v.length === 4 ? v : d;
var FONTS = {
  title: { size: 26, weight: 700 },
  title2: { size: 20, weight: 600 },
  headline: { size: 17, weight: 600 },
  body: { size: 15, weight: 400 },
  subheadline: { size: 13, weight: 400 },
  caption: { size: 12, weight: 400 }
};
function resolveIcon(spec) {
  if (!spec)
    return;
  const builtin = ICONS[spec.icon];
  if (builtin) {
    return { ...spec, icon: builtin.path, viewport: spec.viewport ?? builtin.viewport };
  }
  return { ...spec, viewport: spec.viewport ?? 24 };
}
registerWidget("text", {
  measure(node, availW, ctx) {
    const font = FONTS[str(node.font, "body")] ?? FONTS.body;
    const size = num2(node.fontSize, font.size);
    const weight = num2(node.fontWeight, font.weight);
    const content = str(node.children?.[0] ?? node.text, "");
    const wrap = node.wrap === true;
    const pad = num2(node.padding, 0);
    const w = wrap || node.fill ? availW : Math.min(availW, measureTextWidth(content, size, weight) + 2 * pad);
    const h = num2(node.height, wrap ? wrappedHeight(content, size, availW - 2 * pad, weight) : Math.max(20, size * 1.35));
    return { w: w + (node.fill ? 0 : 2 * pad), h };
  },
  build(node, frame, ctx, path) {
    const font = FONTS[str(node.font, "body")] ?? FONTS.body;
    const color = col(node.color, ctx.palette.textPrimary);
    const el = makeText(path, {
      x: frame.x,
      y: frame.y + (frame.h - Math.max(20, num2(node.fontSize, font.size) * 1.35)) / 2,
      w: frame.w,
      h: Math.max(20, num2(node.fontSize, font.size) * 1.35)
    }, str(node.children?.[0] ?? node.text, ""), {
      color,
      fontSizePx: num2(node.fontSize, font.size),
      fontWeight: num2(node.fontWeight, font.weight),
      align: node.align ?? "left",
      wrap: node.wrap === true,
      paddingPx: 0,
      halo: node.halo ?? "auto",
      icon: resolveIcon(node.icon)
    });
    if (node.press) {
      el.isInteractive = true;
      el.pressTintColor = col(node.press, color);
      ctx.interactions[path] = { onTap: fun(node.onTap) ?? (() => {}) };
    } else if (fun(node.onTap)) {
      el.isInteractive = true;
      el.pressTintColor = color;
      ctx.interactions[path] = { onTap: fun(node.onTap) };
    }
    ctx.elements.push(el);
  }
});
registerWidget("button", {
  measure(node, availW) {
    const label = str(node.children?.[0] ?? node.label, "");
    const h = num2(node.height, 48);
    const pad = num2(node.horizontalPadding, 16);
    const w = node.fill || num2(node.width, 0) > 0 ? num2(node.width, availW) : Math.min(availW, Math.ceil(measureTextWidth(label, 15)) + 2 * pad);
    return { w, h };
  },
  build(node, frame, ctx, path) {
    const label = str(node.children?.[0] ?? node.label, "");
    const variant = str(node.variant, "plain");
    const tint = col(node.tint, [0, 0, 0, 0]);
    const surface = col(node.surface, [0, 0, 0, 0]);
    const labelColor = variant === "tinted" ? col(node.labelColor, [1, 1, 1, 1]) : col(node.labelColor, [0, 0, 0, 1]);
    const el = makeButton(path, { x: frame.x, y: frame.y, w: frame.w, h: frame.h }, {
      label,
      tintColor: variant === "tinted" ? tint : [0, 0, 0, 0],
      surfaceColor: variant === "surface" ? col(node.surface, [1, 1, 1, 0.3]) : surface,
      labelColor,
      labelFontSizePx: num2(node.fontSize, 15),
      pressTintColor: col(node.pressTintColor, undefined)
    });
    ctx.elements.push(el);
    ctx.interactions[path] = {
      onTap: fun(node.onTap) ?? (() => {})
    };
  }
});
registerWidget("toggle", {
  measure(node) {
    return { w: num2(node.width, 64), h: num2(node.height, 28) };
  },
  build(node, frame, ctx, path) {
    const control = makeToggle(ctx.glass, {
      id: path,
      x: frame.x,
      y: frame.y,
      width: frame.w,
      height: frame.h,
      value: node.value === true,
      accent: col(node.accent, ctx.palette.accent),
      offColor: col(node.trackOff, ctx.palette.trackOff),
      solidBackdropColor: ctx.cardBg ?? undefined,
      onValueChange: fun(node.onChange) ?? (() => {})
    });
    ctx.elements.push(...control.elements);
    Object.assign(ctx.interactions, control.interactions);
  }
});
registerWidget("slider", {
  measure(node, availW) {
    return { w: num2(node.width, availW), h: 48 };
  },
  build(node, frame, ctx, path) {
    const control = makeSlider(ctx.glass, {
      id: path,
      x: frame.x,
      y: frame.y + (frame.h - 24) / 2,
      width: frame.w,
      value: num2(node.value, 0),
      accent: col(node.accent, ctx.palette.accent),
      trackColor: col(node.trackOff, ctx.palette.trackOff),
      snap: fun(node.snap),
      onValueChange: fun(node.onChange) ?? (() => {}),
      onLiveValue: fun(node.onLiveValue)
    });
    ctx.elements.push(...control.elements);
    Object.assign(ctx.interactions, control.interactions);
  }
});
registerWidget("toggleRow", {
  measure(node, availW) {
    return { w: availW, h: num2(node.height, 48) };
  },
  build(node, frame, ctx, path) {
    const label = str(node.children?.[0] ?? node.label, "");
    const rowH = frame.h;
    const TOGGLE_W = 64;
    const TOGGLE_H = 28;
    const pad = 16;
    const trackX = frame.x + frame.w - TOGGLE_W - pad;
    const trackY = frame.y + (rowH - TOGGLE_H) / 2;
    const toggle = makeToggle(ctx.glass, {
      id: path,
      x: trackX,
      y: trackY,
      value: node.value === true,
      accent: col(node.accent, ctx.palette.accent),
      offColor: col(node.trackOff, ctx.palette.trackOff),
      solidBackdropColor: ctx.cardBg ?? undefined,
      onValueChange: (v) => (fun(node.onChange) ?? (() => {}))(v)
    });
    const labelEl = makeText(`${path}/label`, { x: frame.x + pad, y: frame.y, w: frame.w - TOGGLE_W - 3 * pad, h: rowH }, label, {
      color: col(node.labelColor, ctx.palette.textPrimary),
      fontSizePx: 15,
      align: "left",
      paddingPx: 0,
      halo: "auto",
      pressTintColor: col(node.labelColor, ctx.palette.textPrimary)
    });
    labelEl.isInteractive = true;
    ctx.elements.push(labelEl, ...toggle.elements);
    ctx.interactions[`${path}/label`] = {
      onTap: () => {
        const next = node.value === true ? 0 : 1;
        (fun(node.onChange) ?? (() => {}))(next);
      }
    };
    Object.assign(ctx.interactions, toggle.interactions);
  }
});
registerWidget("sliderRow", {
  measure(node, availW) {
    return { w: availW, h: 76 };
  },
  build(node, frame, ctx, path) {
    const label = str(node.children?.[0] ?? node.label, "");
    const pad = 16;
    const showValue = node.showValue !== false;
    const live = showValue ? fun(node.onLiveValue) ?? ((f) => {
      const el = ctx.elements.find((e) => e.id === `${path}/value`);
      if (el?.text)
        el.text.content = `${Math.round(f * 100)}%`;
    }) : undefined;
    const labelEl = makeText(`${path}/label`, { x: frame.x + pad, y: frame.y + 8, w: frame.w - 2 * pad - (showValue ? 44 : 0), h: 22 }, label, { color: ctx.palette.textPrimary, fontSizePx: 15, align: "left", paddingPx: 0, halo: "auto" });
    ctx.elements.push(labelEl);
    if (showValue) {
      const valueEl = makeText(`${path}/value`, { x: frame.x + frame.w - pad - 44, y: frame.y + 8, w: 44, h: 22 }, `${Math.round(num2(node.value, 0) * 100)}%`, {
        color: ctx.palette.textSecondary,
        fontSizePx: 15,
        align: "right",
        paddingPx: 0,
        halo: "auto",
        fontWeight: 600
      });
      ctx.elements.push(valueEl);
    }
    const slider = makeSlider(ctx.glass, {
      id: path,
      x: frame.x + pad,
      y: frame.y + 44,
      width: frame.w - 2 * pad,
      value: num2(node.value, 0),
      accent: col(node.accent, ctx.palette.accent),
      trackColor: col(node.trackOff, ctx.palette.trackOff),
      snap: fun(node.snap),
      onValueChange: (f) => {
        const el = ctx.elements.find((e) => e.id === `${path}/value`);
        if (el?.text)
          el.text.content = `${Math.round(f * 100)}%`;
        (fun(node.onChange) ?? (() => {}))(f);
      },
      onLiveValue: live
    });
    ctx.elements.push(...slider.elements);
    Object.assign(ctx.interactions, slider.interactions);
  }
});
registerWidget("buttonRow", {
  measure(node, availW) {
    return { w: availW, h: 48 };
  },
  build(node, frame, ctx, path) {
    const label = str(node.children?.[0] ?? node.label, "");
    const color = col(node.color, [0, 136 / 255, 1, 1]);
    const el = makeText(path, { x: frame.x + 16, y: frame.y, w: frame.w - 32, h: frame.h }, label, { color, fontSizePx: 15, fontWeight: 500, align: "left", paddingPx: 0, halo: "auto", pressTintColor: ctx.palette.textPrimary });
    el.isInteractive = true;
    ctx.elements.push(el);
    ctx.interactions[path] = { onTap: fun(node.onTap) ?? (() => {}) };
  }
});
registerWidget("card", {
  measure(node, availW, ctx) {
    const pad = num2(node.padding, 16);
    const children = node.children ?? [];
    const titleH = node.title ? 24 + 12 : 0;
    let h = pad + titleH;
    let i = 0;
    for (const c of children) {
      const m = measureNode(c, availW - 2 * pad, ctx);
      h += m.h;
      if (i < children.length - 1)
        h += num2(node.spacing, 0);
      i++;
    }
    h += pad;
    return { w: availW, h, flex: node.fill ? 1 : undefined };
  },
  build(node, frame, ctx, path) {
    const pad = num2(node.padding, 16);
    const radius = num2(node.cornerRadius, 32);
    const cardColor = col(node.background, ctx.palette.cardBg);
    const card = makePlainRect(path, { x: frame.x, y: frame.y, w: frame.w, h: frame.h }, cardColor, radius);
    ctx.elements.push(card);
    const prevCardBg = ctx.cardBg;
    ctx.cardBg = cardColor;
    let y = frame.y + pad;
    if (node.title) {
      ctx.elements.push(makeText(`${path}/title`, { x: frame.x + pad, y, w: frame.w - 2 * pad, h: 24 }, str(node.title, ""), { color: ctx.palette.cardTitle, fontSizePx: 14, fontWeight: 600, align: "left", paddingPx: 0, halo: "none" }));
      y += 24 + 12;
    }
    const children = node.children ?? [];
    const spacing = num2(node.spacing, 0);
    const innerW = frame.w - 2 * pad;
    let i = 0;
    for (const c of children) {
      const m = measureNode(c, innerW, ctx);
      const cPath = `${path}/${c.key ?? `${c.kind}@${i}`}`;
      buildNode(c, { x: frame.x + pad, y, w: innerW, h: m.h }, ctx, cPath);
      y += m.h + spacing;
      i++;
    }
    ctx.cardBg = prevCardBg;
  }
});
registerWidget("dialog", {
  measure() {
    return { w: 0, h: 0 };
  },
  build(node, frame, ctx, path) {
    if (node.visible !== true)
      return;
    const { W, H } = ctx.stage;
    const pad = num2(node.margin, 40);
    const cardW = W - 2 * pad;
    const cardH = num2(node.height, 276);
    const cardX = pad;
    const cardY = (H - cardH) / 2;
    const p = ctx.palette;
    const scrim = makePlainRect(`${path}/scrim`, { x: 0, y: 0, w: W, h: H }, p.scrim, 0);
    scrim.scroll = false;
    ctx.elements.push(scrim);
    const card = makeGlassShape(`${path}/card`, { x: cardX, y: cardY, w: cardW, h: cardH }, {
      cornerRadius: 48,
      refractionHeight: 24,
      refractionAmount: -48,
      blurRadius: 16,
      saturation: 1.5,
      surfaceColor: p.dialogContainer,
      highlight: { ...DEFAULT_HIGHLIGHT, mode: 2, color: [1, 1, 1], alpha: 0.38, widthDp: 0.5 },
      depthEffect: true
    }, false);
    card.useSeparableBlur = true;
    card.independentBackdrop = false;
    ctx.elements.push(card);
    ctx.elements.push(makeText(`${path}/title`, { x: cardX + 28, y: cardY + 24, w: cardW - 56, h: 36 }, str(node.title, ""), { color: p.dialogContent, fontSizePx: 24, fontWeight: 500, align: "left", paddingPx: 0, halo: "none" }, false));
    ctx.elements.push(makeText(`${path}/body`, { x: cardX + 24, y: cardY + 80, w: cardW - 48, h: 100 }, str(node.message, ""), {
      color: [p.dialogContent[0], p.dialogContent[1], p.dialogContent[2], 0.68],
      fontSizePx: 15,
      align: "left",
      wrap: true,
      valign: "top",
      maxLines: 5,
      paddingPx: 0,
      halo: "none"
    }, false));
    const btnH = 48;
    const btnW = (cardW - 2 * 24 - 16) / 2;
    const btnY = cardY + cardH - 24 - btnH;
    const cancel = makeButton(`${path}/cancel`, { x: cardX + 24, y: btnY, w: btnW, h: btnH }, {
      label: "",
      tintColor: [0, 0, 0, 0],
      surfaceColor: [p.dialogContainer[0], p.dialogContainer[1], p.dialogContainer[2], 0.2],
      labelColor: p.dialogContent,
      saturation: 1,
      brightness: 0,
      contrast: 1
    }, false);
    cancel.refractionHeight = 0;
    cancel.refractionAmount = 0;
    cancel.blurRadius = 0;
    cancel.highlight = null;
    cancel.outerShadow = null;
    ctx.elements.push(cancel);
    ctx.elements.push(makeText(`${path}/cancel-label`, { x: cardX + 24, y: btnY, w: btnW, h: btnH }, str(node.cancelLabel, "Cancel"), { color: p.dialogContent, fontSizePx: 16, align: "center", paddingPx: 0, halo: "none" }, false));
    const okay = makeButton(`${path}/okay`, { x: cardX + 24 + btnW + 16, y: btnY, w: btnW, h: btnH }, {
      label: "",
      tintColor: [0, 0, 0, 0],
      surfaceColor: col(node.okayColor, p.dialogAccent),
      labelColor: [1, 1, 1, 1],
      saturation: 1,
      brightness: 0,
      contrast: 1
    }, false);
    okay.refractionHeight = 0;
    okay.refractionAmount = 0;
    okay.blurRadius = 0;
    okay.highlight = null;
    okay.outerShadow = null;
    ctx.elements.push(okay);
    ctx.elements.push(makeText(`${path}/okay-label`, { x: cardX + 24 + btnW + 16, y: btnY, w: btnW, h: btnH }, str(node.okayLabel, "Okay"), { color: [1, 1, 1, 1], fontSizePx: 16, align: "center", paddingPx: 0, halo: "none" }, false));
    ctx.interactions[`${path}/scrim`] = {
      onTap: () => (fun(node.onCancel) ?? (() => {}))()
    };
    ctx.interactions[`${path}/cancel`] = {
      onTap: () => (fun(node.onCancel) ?? (() => {}))()
    };
    ctx.interactions[`${path}/cancel-label`] = ctx.interactions[`${path}/cancel`];
    ctx.interactions[`${path}/okay`] = {
      onTap: () => (fun(node.onOkay) ?? (() => {}))()
    };
    ctx.interactions[`${path}/okay-label`] = ctx.interactions[`${path}/okay`];
  }
});
registerWidget("tabBar", {
  measure(node, availW) {
    if (node.fixed)
      return { w: 0, h: 0 };
    return { w: num2(node.width, availW), h: 64 };
  },
  build(node, frame, ctx, path) {
    const tabs = Array.isArray(node.tabs) ? node.tabs : ["Tab 1", "Tab 2", "Tab 3"];
    const count = tabs.length;
    const p = ctx.palette;
    const CONTAINER_H = 64;
    const GLASS_H = 56;
    const GLASS_PAD = 4;
    const fixed = node.fixed === true;
    const fr = fixed ? { x: 0, y: ctx.stage.H - CONTAINER_H, w: ctx.stage.W } : frame;
    const scroll = !fixed;
    const tabW = (fr.w - 2 * GLASS_PAD) / count;
    const glassX = fr.x + GLASS_PAD;
    const glassY = fr.y + GLASS_PAD;
    const container = makeGlassShape(`${path}/container`, { x: fr.x, y: fr.y, w: fr.w, h: CONTAINER_H }, {
      cornerRadius: CONTAINER_H / 2,
      refractionHeight: 24,
      refractionAmount: -24,
      blurRadius: 8,
      saturation: 1.5,
      surfaceColor: p.tabsContainer,
      highlight: { ...DEFAULT_HIGHLIGHT, alpha: 0.5 },
      depthEffect: true
    }, scroll);
    container.isBottomTabContainer = { groupId: path, tabsCount: count };
    container.independentBackdrop = false;
    ctx.elements.push(container);
    const drag = makeTabDragInteractions(path, tabW, count, ctx.renderer, (i) => (fun(node.onSelect) ?? (() => {}))(i));
    for (let i = 0;i < count; i++) {
      const spec = tabs[i];
      const label = typeof spec === "string" ? spec : spec?.label ?? `Tab ${i + 1}`;
      const iconName = typeof spec === "string" ? undefined : spec?.icon;
      const icon = iconName ? resolveIcon({ icon: iconName, size: 24, layoutSize: 28, color: p.tabsContent }) : undefined;
      const el = makeText(`${path}/tab@${i}`, { x: glassX + tabW * i, y: glassY, w: tabW, h: GLASS_H }, label, {
        color: p.tabsContent,
        fontSizePx: 12,
        align: "center",
        paddingPx: 0,
        halo: "auto",
        icon
      }, scroll);
      el.isBottomTabContent = {
        groupId: path,
        containerCenterX: fr.x + fr.w / 2,
        containerCenterY: fr.y + CONTAINER_H / 2,
        containerWidth: fr.w
      };
      ctx.elements.push(el);
      ctx.interactions[`${path}/tab@${i}`] = {
        onTap: () => {
          const r2 = ctx.renderer();
          r2?.setTabSelected(path, i, count);
          (fun(node.onSelect) ?? (() => {}))(i);
        },
        onDragStart: drag.onDragStart,
        onDrag: drag.onDrag,
        onDragEnd: drag.onDragEnd
      };
    }
    ctx.interactions[`${path}/container`] = drag;
    const indicator = makeGlassShape(`${path}/indicator`, { x: glassX, y: glassY, w: tabW, h: GLASS_H }, {
      cornerRadius: GLASS_H / 2,
      refractionHeight: 10,
      refractionAmount: -14,
      blurRadius: 0,
      saturation: 1,
      surfaceColor: [0, 0, 0, 0],
      highlight: { ...DEFAULT_HIGHLIGHT, alpha: 0.5 },
      outerShadow: { ...DEFAULT_SHADOW },
      innerShadow: { radius: 8, alpha: 0.3, offsetX: 0, offsetY: 8 },
      chromaticAberration: true
    }, scroll);
    indicator.independentBackdrop = false;
    indicator.isBottomTabIndicator = {
      groupId: path,
      dragWidth: tabW,
      dimColor: p.tabsContent,
      accentColor: [...p.tabsAccent],
      containerRect: { x: glassX - GLASS_PAD, y: glassY, w: fr.w, h: GLASS_H },
      containerCenterX: fr.x + fr.w / 2,
      containerCenterY: fr.y + CONTAINER_H / 2,
      containerWidth: fr.w,
      tabContentIds: Array.from({ length: count }, (_, i) => `${path}/tab@${i}`),
      tabContentRects: Array.from({ length: count }, (_, i) => ({
        x: glassX + tabW * i,
        y: glassY,
        w: tabW,
        h: GLASS_H
      }))
    };
    ctx.elements.push(indicator);
    const r = ctx.renderer();
    r?.setTabSelected(path, Math.max(0, Math.min(count - 1, num2(node.selected, 0))), count);
  }
});
var CC_ITEM = 68;
var CC_GAP = 16;
var CC_INNER = 56;
registerWidget("controlCenter", {
  measure(node, availW) {
    const tiles = node.tiles ?? [];
    const gridW = Math.max(availW, 4 * CC_ITEM + 3 * CC_GAP);
    let rows = 0;
    let spanCursor = 0;
    for (const t of tiles) {
      const span = num2(t.span, 1);
      if (spanCursor + span > 4) {
        rows++;
        spanCursor = 0;
      }
      spanCursor += span;
    }
    if (spanCursor > 0)
      rows++;
    const gridH = rows > 0 ? rows * CC_ITEM + (rows - 1) * CC_GAP : 0;
    return { w: gridW, h: gridH + 2 * CC_GAP };
  },
  build(node, frame, ctx, path) {
    const tiles = node.tiles ?? [];
    const p = ctx.palette;
    if (node.dim) {
      const dim = makePlainRect(`${path}/dim`, { x: 0, y: 0, w: ctx.stage.W, h: ctx.stage.H }, [0, 0, 0, 0.4], 0);
      dim.scroll = false;
      dim.sceneBlurRadius = 4;
      ctx.elements.push(dim);
    }
    const spanW = (s) => s * CC_ITEM + (s - 1) * CC_GAP;
    const spanH = (s) => s * CC_ITEM + (s - 1) * CC_GAP;
    let rowIdx = 0;
    let colSpan = 0;
    let i = 0;
    for (const t of tiles) {
      const span = Math.max(1, Math.min(4, num2(t.span, 1)));
      const vSpan = t.tall ? 2 : 1;
      if (colSpan + span > 4) {
        rowIdx++;
        colSpan = 0;
      }
      const x = frame.x + spanW(colSpan);
      const y = frame.y + spanH(rowIdx);
      const w = spanW(span);
      const h = spanH(vSpan);
      const tile = makeGlassShape(`${path}/tile@${i}`, { x, y, w, h }, {
        cornerRadius: CC_ITEM / 2,
        refractionHeight: 24,
        refractionAmount: -48,
        blurRadius: 0,
        saturation: 1.5,
        surfaceColor: p.ccTileSurface,
        highlight: { ...DEFAULT_HIGHLIGHT, falloff: 2 },
        outerShadow: null,
        depthEffect: true
      });
      tile.isInteractive = true;
      ctx.elements.push(tile);
      if (t.icon) {
        const icon = resolveIcon({
          icon: t.icon,
          size: span >= 2 ? 24 : 24,
          layoutSize: 28,
          color: [1, 1, 1, 1]
        });
        const iconEl = makeText(`${path}/tile@${i}/icon`, { x: x + (w - 28) / 2, y: y + (h - 28) / 2 - (span >= 2 ? 10 : 0), w: 28, h: 28 }, "", { icon, paddingPx: 0, halo: "none" });
        iconEl.enterProgress = 1;
        ctx.elements.push(iconEl);
      }
      if (t.active) {
        ctx.elements.push(makePlainRect(`${path}/tile@${i}/active`, {
          x: x + w - CC_GAP - CC_INNER,
          y: y + h - CC_GAP - CC_INNER,
          w: CC_INNER,
          h: CC_INNER
        }, p.ccTileAccent, CC_INNER / 2));
      }
      if (t.onTap) {
        ctx.interactions[`${path}/tile@${i}`] = { onTap: t.onTap };
        tile.isInteractive = true;
      }
      colSpan += span;
      i++;
    }
  }
});
registerWidget("clock", {
  measure(node) {
    const w = num2(node.width, 300);
    return { w, h: w * (515 / 1599) };
  },
  build(node, frame, ctx, path) {
    const off = node.offset ?? { x: 0, y: 0 };
    const el = makeGlassShape(path, {
      x: frame.x + num2(off.x, 0),
      y: frame.y + num2(off.y, 0),
      w: frame.w,
      h: frame.h
    }, {
      cornerRadius: 0,
      refractionHeight: 0,
      refractionAmount: 0,
      blurRadius: 2,
      saturation: 1.5,
      brightness: -0.1,
      contrast: 0.75,
      surfaceColor: [1, 1, 1, 0.25],
      highlight: null,
      outerShadow: null
    });
    el.isSdfTexture = { refractionHeight: 48, lightAngle: 45 };
    el.independentBackdrop = true;
    ctx.elements.push(el);
    if (node.draggable) {
      const start = { x: 0, y: 0 };
      const onChange = fun(node.onOffsetChange);
      ctx.interactions[path] = {
        onDragStart: () => {
          start.x = num2(off.x, 0);
          start.y = num2(off.y, 0);
        },
        onDrag: (_pos, delta) => {
          onChange?.({ x: start.x + delta.x, y: start.y + delta.y });
        }
      };
    }
  }
});
registerWidget("magnifier", {
  measure() {
    return { w: 128, h: 96 };
  },
  build(node, frame, ctx, path) {
    const off = node.offset ?? { x: 0, y: 0 };
    const x = frame.x + num2(off.x, 0);
    const y = frame.y + num2(off.y, 0);
    const el = makeGlassShape(path, { x, y, w: frame.w, h: frame.h }, {
      cornerRadius: frame.h / 2,
      refractionHeight: 8,
      refractionAmount: -24,
      blurRadius: 0,
      saturation: 1,
      surfaceColor: [0, 0, 0, 0],
      highlight: { ...DEFAULT_HIGHLIGHT },
      outerShadow: { ...DEFAULT_SHADOW },
      innerShadow: { radius: 16, alpha: 0.15, offsetX: 0, offsetY: 16 },
      depthEffect: true,
      chromaticAberration: true
    });
    el.isMagnifier = { zoom: 1.5, sampleOffsetY: 80 };
    el.independentBackdrop = false;
    ctx.elements.push(el);
    const cursor = makePlainRect(`${path}/cursor`, { x: x + frame.w / 2 - 2, y: y + frame.h / 2 + 4, w: 4, h: 24 }, ctx.palette.accent, 2);
    cursor.hitRect = { x: x - 22, y: y - 22, w: frame.w + 44, h: frame.h + 44 };
    ctx.elements.push(cursor);
    const start = { x: 0, y: 0 };
    const onChange = fun(node.onOffsetChange);
    const handler = {
      onDragStart: () => {
        start.x = num2(off.x, 0);
        start.y = num2(off.y, 0);
      },
      onDrag: (_pos, delta) => {
        onChange?.({ x: start.x + delta.x, y: start.y + delta.y });
      }
    };
    ctx.interactions[path] = handler;
    ctx.interactions[`${path}/cursor`] = handler;
  }
});
registerWidget("glassPane", {
  measure(node, availW) {
    return { w: num2(node.width, availW), h: num2(node.height, 200) };
  },
  build(node, frame, ctx, path) {
    const off = node.offset ?? { x: 0, y: 0 };
    const el = makeGlassShape(path, {
      x: frame.x + num2(off.x, 0),
      y: frame.y + num2(off.y, 0),
      w: frame.w,
      h: frame.h
    }, {
      cornerRadius: num2(node.cornerRadius, frame.h / 2),
      refractionHeight: num2(node.refractionHeight, 12),
      refractionAmount: num2(node.refractionAmount, -24),
      blurRadius: num2(node.blurRadius, 2),
      saturation: num2(node.saturation, 1.5),
      surfaceColor: col(node.surfaceColor, [0, 0, 0, 0]),
      highlight: { ...DEFAULT_HIGHLIGHT },
      outerShadow: { ...DEFAULT_SHADOW },
      depthEffect: true,
      chromaticAberration: true,
      innerShadow: fun(node.innerShadow)
    });
    if (typeof node.rotation === "number")
      el.elementRotation = node.rotation;
    if (typeof node.scale === "number") {
      el.elementScaleX = node.scale;
      el.elementScaleY = node.scale;
    }
    ctx.elements.push(el);
    if (node.draggable) {
      const start = { x: 0, y: 0 };
      const onDrag = fun(node.onDrag);
      ctx.interactions[path] = {
        onDragStart: () => {
          start.x = num2(off.x, 0);
          start.y = num2(off.y, 0);
        },
        onDrag: (_pos, delta) => onDrag?.({ x: start.x + delta.x, y: start.y + delta.y }, 0),
        onTransform: (pan, zoom, rotate) => {
          fun(node.onTransform)?.(pan, zoom, rotate);
        }
      };
    }
  }
});
registerWidget("scrim", {
  measure() {
    return { w: 0, h: 0 };
  },
  build(node, _frame, ctx, path) {
    const el = makePlainRect(path, { x: 0, y: 0, w: ctx.stage.W, h: ctx.stage.H }, col(node.color, [0, 0, 0, 0.3]), 0);
    el.scroll = false;
    if (typeof node.blur === "number")
      el.sceneBlurRadius = node.blur;
    ctx.elements.push(el);
    if (fun(node.onTap)) {
      el.isInteractive = true;
      ctx.interactions[path] = { onTap: () => fun(node.onTap)() };
    }
  }
});
registerWidget("icon", {
  measure(node) {
    const s = num2(node.size, 24);
    return { w: s, h: s };
  },
  build(node, frame, ctx, path) {
    const icon = resolveIcon({
      icon: str(node.children?.[0] ?? node.name, ""),
      size: num2(node.size, 24),
      color: col(node.color, ctx.palette.textPrimary)
    });
    if (!icon)
      return;
    const el = makeText(path, frame, "", {
      icon,
      paddingPx: 0,
      halo: "none"
    });
    ctx.elements.push(el);
    if (fun(node.onTap)) {
      el.isInteractive = true;
      el.hitRect = { x: frame.x - 12, y: frame.y - 12, w: frame.w + 24, h: frame.h + 24 };
      ctx.interactions[path] = { onTap: () => fun(node.onTap)() };
    }
  }
});

// src/cdn/ui/scene.ts
function createScene(glass, opts) {
  const stage = {
    W: opts.width,
    H: opts.height ?? glass.canvas?.clientHeight ?? 760
  };
  const palette = { ...opts.theme === "dark" ? DARK_PALETTE : LIGHT_PALETTE, ...opts.palette };
  let render = null;
  let rafHandle = null;
  let disposed = false;
  let lastHeight = 0;
  const schedule = () => {
    if (disposed || rafHandle != null)
      return;
    rafHandle = requestAnimationFrame(() => {
      rafHandle = null;
      rebuild();
    });
  };
  const wrap = (target) => new Proxy(target, {
    set(t, prop, value) {
      if (t[prop] !== value) {
        t[prop] = value;
        schedule();
      }
      return true;
    },
    deleteProperty(t, prop) {
      delete t[prop];
      schedule();
      return true;
    }
  });
  const state = wrap({ ...opts.state ?? {} });
  const ctx = {
    glass,
    renderer: () => glass.getRenderer(),
    stage,
    palette,
    cardBg: null,
    elements: [],
    interactions: {}
  };
  function rebuild() {
    if (disposed || !render)
      return;
    const r = glass.getRenderer();
    if (!r)
      return;
    if (opts.height == null && glass.canvas?.clientHeight) {
      stage.H = glass.canvas.clientHeight;
    }
    ctx.elements = [];
    ctx.interactions = {};
    const root = render(state);
    let rootH = 0;
    if (root) {
      const m = measureNode(root, stage.W, ctx);
      rootH = m.h;
      buildNode(root, { x: 0, y: 0, w: stage.W, h: m.h }, ctx, "lg");
    }
    glass.setElements(ctx.elements);
    glass.setInteractions(ctx.interactions);
    lastHeight = rootH;
    glass.setContentHeight(Math.max(rootH, stage.H));
  }
  if (opts.clockSdfUrl) {
    const r = glass.getRenderer();
    r?.loadSdfTexture(opts.clockSdfUrl).catch(() => {});
  }
  return {
    state,
    setBody(fn) {
      render = fn;
      rebuild();
    },
    refresh() {
      schedule();
    },
    contentHeight() {
      return lastHeight;
    },
    destroy() {
      disposed = true;
      if (rafHandle != null)
        cancelAnimationFrame(rafHandle);
      rafHandle = null;
      render = null;
    }
  };
}

// src/cdn/ui/index.ts
var node = (kind, props, children) => {
  const n = { kind, ...props };
  if (children)
    n.children = children;
  return n;
};
function VStack(opts, children) {
  return node("vstack", opts, children);
}
function HStack(opts, children) {
  return node("hstack", opts, children);
}
function ZStack(opts, children) {
  return node("zstack", opts, children);
}
function Spacer(opts = {}) {
  return node("spacer", opts);
}
function Position(opts, child) {
  return node("position", opts, [child]);
}
function Text(label, opts = {}) {
  return node("text", { text: label, ...opts });
}
function Icon(name, opts = {}) {
  return node("icon", { name, ...opts });
}
function Button(label, opts = {}) {
  return node("button", { label, ...opts });
}
function Toggle(opts) {
  return node("toggle", opts);
}
function Slider(opts) {
  return node("slider", opts);
}
function ToggleRow(label, opts = {}) {
  return node("toggleRow", { label, ...opts });
}
function SliderRow(label, opts = {}) {
  return node("sliderRow", { label, ...opts });
}
function ButtonRow(label, opts = {}) {
  return node("buttonRow", { label, ...opts });
}
function Card(opts, children) {
  return node("card", opts, children);
}
function Dialog(opts) {
  return node("dialog", opts);
}
function TabBar(opts) {
  return node("tabBar", opts);
}
function ControlCenter(opts) {
  return node("controlCenter", opts);
}
function Clock(opts) {
  return node("clock", opts);
}
function Magnifier(opts) {
  return node("magnifier", opts);
}
function GlassPane(opts) {
  return node("glassPane", opts);
}
function Scrim(opts = {}) {
  return node("scrim", opts);
}
var create = createScene;
var LG = {
  create,
  VStack,
  HStack,
  ZStack,
  Spacer,
  Position,
  Text,
  Icon,
  Button,
  Toggle,
  Slider,
  ToggleRow,
  SliderRow,
  ButtonRow,
  Card,
  Dialog,
  TabBar,
  ControlCenter,
  Clock,
  Magnifier,
  GlassPane,
  Scrim
};

// src/cdn/index.ts
var VERSION = "0.3.2";
export {
  measureTextWidth,
  makeToggleInteractions,
  makeToggle,
  makeText,
  makeTabDragInteractions,
  makeSliderInteractions,
  makeSlider,
  makePlainRect,
  makeGlassShape,
  makeButton,
  lerp,
  create as createScene,
  ZStack,
  VStack,
  VERSION,
  ToggleRow,
  Toggle,
  Text,
  TabBar,
  TITLE_FONT_SIZE_PX,
  TEXT_FONT_SIZE_PX,
  Spacer,
  SliderRow,
  Slider,
  Scrim,
  Position,
  Magnifier,
  LiquidGlassRenderer,
  LiquidGlass,
  LIGHT_PALETTE,
  LG,
  Icon,
  ICONS,
  HStack,
  GlassPane,
  GLASS_PARAMS,
  FONT_FAMILY,
  Dialog,
  DEFAULT_TRACK_OFF,
  DEFAULT_SHADOW,
  DEFAULT_HIGHLIGHT,
  DEFAULT_ACCENT,
  DARK_PALETTE,
  ControlCenter,
  Clock,
  Card,
  ButtonRow,
  Button,
  BUTTON_HORIZONTAL_PADDING,
  BUTTON_HEIGHT
};
