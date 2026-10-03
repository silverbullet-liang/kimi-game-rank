<?php
/**
 * 站间互通加密（非对称）
 * ------------------------------------------------------------
 * · 曲线：Curve25519（X25519 + XSalsa20-Poly1305，libsodium crypto_box）
 * · 私钥：32 字节 = 64 个十六进制字符，填在本机 config 的 peers.private_key
 * · 公钥：由私钥推导，同为 64 位十六进制，可公开（写入对端站点列表）
 * · 传输：JSON → gzip 压缩 → 非对称加密（附完整性标签）→ base64
 *
 * 为什么用 sodium 而非 openssl：openssl 的 X25519 在共享主机上常不可用；
 * sodium 是 PHP 自带扩展（7.2+），其密钥恰好 32 字节，正对应「64 位私钥」。
 *
 * 认证即加密：A 用「A 私钥 + B 公钥」封装，B 用「B 私钥 + A 公钥」解封。
 * 任一方私钥或公钥不符，解封必失败 —— 这就是「两边私钥检测通过」的判定。
 */
declare(strict_types=1);

/** 64 位十六进制密钥是否合法 */
function peer_key_valid(string $hex): bool
{
    return (bool)preg_match('/^[0-9a-f]{64}$/', strtolower(trim($hex)));
}

/** 加密能力是否具备（sodium 缺失即整体不可用，fail-closed） */
function peer_crypto_ready(): bool
{
    return function_exists('sodium_crypto_box') && function_exists('sodium_crypto_box_open')
        && function_exists('sodium_crypto_box_keypair_from_secretkey_and_publickey');
}

/** 由 64 位私钥推导 64 位公钥；失败返回空串 */
function peer_public_from_private(string $skHex): string
{
    if (!peer_crypto_ready() || !peer_key_valid($skHex)) { return ''; }
    try {
        return bin2hex(sodium_crypto_scalarmult_base(hex2bin(strtolower(trim($skHex)))));
    } catch (Throwable $e) {
        return '';
    }
}

/** 组装 crypto_box 的 keypair（本机私钥 + 对端公钥），加密与解密同式 */
function peer_box_pair(string $mySkHex, string $peerPkHex)
{
    return sodium_crypto_box_keypair_from_secretkey_and_publickey(
        hex2bin(strtolower(trim($mySkHex))),
        hex2bin(strtolower(trim($peerPkHex)))
    );
}

/**
 * 打包：JSON → gzip → 非对称加密 → base64。
 * 返回可直接放进 HTTP body 的字符串；密钥或能力不符时抛异常。
 */
function peer_pack(array $payload, string $mySkHex, string $peerPkHex): string
{
    if (!peer_crypto_ready()) { throw new RuntimeException('主机未启用 sodium 扩展，站间加密不可用'); }
    if (!peer_key_valid($mySkHex) || !peer_key_valid($peerPkHex)) {
        throw new RuntimeException('密钥格式错误（本机私钥与对端公钥都须为 64 位十六进制）');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) { throw new RuntimeException('数据序列化失败'); }

    $plain = gzencode($json, 6);           // 先压缩
    if ($plain === false) { $plain = $json; }

    $nonce = random_bytes(SODIUM_CRYPTO_BOX_NONCEBYTES);
    $ct = sodium_crypto_box($plain, $nonce, peer_box_pair($mySkHex, $peerPkHex));
    return base64_encode($nonce . $ct);     // 随机数前置，解密时切回
}

/**
 * 解包：base64 → 非对称解密 → gunzip → JSON。
 * 任何一步失败都抛异常（fail-closed），绝不放行未经验证的数据。
 */
function peer_unpack(string $b64, string $mySkHex, string $peerPkHex): array
{
    if (!peer_crypto_ready()) { throw new RuntimeException('主机未启用 sodium 扩展，站间加密不可用'); }
    if (!peer_key_valid($mySkHex) || !peer_key_valid($peerPkHex)) {
        throw new RuntimeException('密钥格式错误');
    }
    $raw = base64_decode(trim($b64), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_BOX_NONCEBYTES) {
        throw new RuntimeException('密文格式错误');
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_BOX_NONCEBYTES);
    $ct = substr($raw, SODIUM_CRYPTO_BOX_NONCEBYTES);

    $plain = sodium_crypto_box_open($ct, $nonce, peer_box_pair($mySkHex, $peerPkHex));
    if ($plain === false) { throw new RuntimeException('解密失败：密钥不匹配或数据被篡改'); }

    $json = @gzdecode($plain);
    if ($json === false) { $json = $plain; }   // 容忍未压缩的兼容输入
    $data = json_decode($json, true);
    if (!is_array($data)) { throw new RuntimeException('数据解析失败'); }
    return $data;
}
