package com.kimigame.deploy;

import java.math.BigInteger;

/**
 * X25519：由 32 字节私钥推出 32 字节公钥（站点互通要用的那把）。
 *
 * 为什么自己实现：站点互通用的是 Curve25519 的 ECDH 部分，
 * Android 上要拿到同等能力得依赖第三方库；这里只需要「私钥 → 公钥」这一步，
 * 用 BigInteger 写一遍 Montgomery ladder 即可，零依赖。
 * 慢（每次几百次大数运算）但密钥只在配对时生成几次，与性能无关。
 *
 * 自带 RFC 7748 测试向量（main）：构建脚本打包前先跑它，算错就不出包。
 */
final class X25519 {

    private static final BigInteger P = BigInteger.valueOf(2).pow(255).subtract(BigInteger.valueOf(19));
    private static final BigInteger A24 = BigInteger.valueOf(121665);
    private static final BigInteger ZERO = BigInteger.ZERO;
    private static final BigInteger ONE = BigInteger.ONE;

    private X25519() { }

    /** 私钥 → 公钥（基点 9） */
    static byte[] base(byte[] scalar) {
        byte[] u = new byte[32];
        u[0] = 9;
        return scalarMult(scalar, u);
    }

    /** X25519 标量乘：k * u */
    static byte[] scalarMult(byte[] scalar, byte[] point) {
        byte[] k = clamp(scalar);
        BigInteger x1 = decodeU(point);
        BigInteger x2 = ONE, z2 = ZERO, x3 = x1, z3 = ONE;
        int swap = 0;

        for (int t = 254; t >= 0; t--) {
            int kt = (k[t >> 3] >> (t & 7)) & 1;
            swap ^= kt;
            if (swap == 1) {
                BigInteger tx = x2; x2 = x3; x3 = tx;
                BigInteger tz = z2; z2 = z3; z3 = tz;
            }
            swap = kt;

            BigInteger a = x2.add(z2).mod(P);
            BigInteger aa = a.multiply(a).mod(P);
            BigInteger b = x2.subtract(z2).mod(P);
            BigInteger bb = b.multiply(b).mod(P);
            BigInteger e = aa.subtract(bb).mod(P);
            BigInteger cc = x3.add(z3).mod(P);
            BigInteger d = x3.subtract(z3).mod(P);
            BigInteger da = d.multiply(a).mod(P);
            BigInteger cb = cc.multiply(b).mod(P);

            BigInteger t1 = da.add(cb).mod(P);
            x3 = t1.multiply(t1).mod(P);
            BigInteger t2 = da.subtract(cb).mod(P);
            z3 = x1.multiply(t2.multiply(t2).mod(P)).mod(P);
            x2 = aa.multiply(bb).mod(P);
            z2 = e.multiply(aa.add(A24.multiply(e).mod(P)).mod(P)).mod(P);
        }

        if (swap == 1) {
            BigInteger tx = x2; x2 = x3; x3 = tx;
            BigInteger tz = z2; z2 = z3; z3 = tz;
        }

        BigInteger out = x2.multiply(z2.modPow(P.subtract(BigInteger.valueOf(2)), P)).mod(P);
        return encodeU(out);
    }

    /** 钳位：低 3 位清零、最高位清零、次高位置 1（RFC 7748） */
    private static byte[] clamp(byte[] in) {
        byte[] k = new byte[32];
        System.arraycopy(in, 0, k, 0, Math.min(32, in.length));
        k[0] &= (byte) 248;
        k[31] &= (byte) 127;
        k[31] |= (byte) 64;
        return k;
    }

    /** 小端解码，最高位忽略 */
    private static BigInteger decodeU(byte[] u) {
        byte[] b = new byte[32];
        System.arraycopy(u, 0, b, 0, Math.min(32, u.length));
        b[31] &= (byte) 127;
        byte[] be = new byte[32];
        for (int i = 0; i < 32; i++) { be[i] = b[31 - i]; }
        return new BigInteger(1, be);
    }

    /** 小端编码 */
    private static byte[] encodeU(BigInteger v) {
        byte[] be = v.mod(P).toByteArray();          // 大端、可能带符号位
        byte[] out = new byte[32];
        for (int i = 0; i < 32; i++) {
            int idx = be.length - 1 - i;
            out[i] = (idx >= 0 && idx < be.length) ? be[idx] : 0;
        }
        return out;
    }

    /* ============================ 自检（构建前跑） ============================ */

    private static byte[] unhex(String s) {
        byte[] out = new byte[s.length() / 2];
        for (int i = 0; i < out.length; i++) {
            out[i] = (byte) Integer.parseInt(s.substring(i * 2, i * 2 + 2), 16);
        }
        return out;
    }

    private static String hex(byte[] b) {
        StringBuilder sb = new StringBuilder();
        for (int i = 0; i < b.length; i++) { sb.append(String.format("%02x", b[i])); }
        return sb.toString();
    }

    public static void main(String[] args) {
        int bad = 0;

        // RFC 7748 §5.2 标量乘向量
        String got1 = hex(scalarMult(
                unhex("a546e36bf0527c9d3b16154b82465edd62144c0ac1fc5a18506a2244ba449ac4"),
                unhex("e6db6867583030db3594c1a424b15f7c726624ec26b3353b10a903a6d0ab1c4c")));
        String want1 = "c3da55379de9c6908e94ea4df28d084f32eccf03491c71f754b4075577a28552";
        System.out.println((got1.equals(want1) ? "ok   " : "FAIL ") + "标量乘向量");
        if (!got1.equals(want1)) { bad++; }

        // RFC 7748 §6.1 双方公钥与共享密钥
        String aliceSk = "77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a";
        String alicePk = "8520f0098930a754748b7ddcb43ef75a0dbf3a0d26381af4eba4a98eaa9b4e6a";
        String bobSk = "5dab087e624a8a4b79e17f8b83800ee66f3bb1292618b6fd1c2f8b27ff88e0eb";
        String bobPk = "de9edb7d7b7dc1b4d35b61c2ece435373f8343c85b78674dadfc7e146f882b4f";
        String shared = "4a5d9d5ba4ce2de1728e3bf480350f25e07e21c947d19e3376f09b3c1e161742";

        boolean okA = hex(base(unhex(aliceSk))).equals(alicePk);
        boolean okB = hex(base(unhex(bobSk))).equals(bobPk);
        boolean okS = hex(scalarMult(unhex(aliceSk), unhex(bobPk))).equals(shared)
                && hex(scalarMult(unhex(bobSk), unhex(alicePk))).equals(shared);
        System.out.println((okA ? "ok   " : "FAIL ") + "Alice 公钥");
        System.out.println((okB ? "ok   " : "FAIL ") + "Bob 公钥");
        System.out.println((okS ? "ok   " : "FAIL ") + "双方共享密钥一致");
        if (!okA || !okB || !okS) { bad++; }

        System.out.println(bad == 0 ? "X25519 自检通过" : "X25519 自检失败");
        System.exit(bad == 0 ? 0 : 1);
    }
}
