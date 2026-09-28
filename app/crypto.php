<?php
/**
 * 加密与口令链路
 * ------------------------------------------------------------
 * - NFC 归一化（intl 优先，缺失时用内置常见组合映射兜底）
 * - 口令链路：md5 → sha256(+注册UTC) → PBKDF2-HMAC-SHA256(随机盐, 10000)
 * - 登录态：payload(JSON) → RC4 → AES-256-CBC，下放前端
 * - 恒定时间比较一律 hash_equals
 */
declare(strict_types=1);

/* ============================================================
 * Unicode NFC 归一化
 * ============================================================ */
function nfc_normalize(string $s): string
{
    if (class_exists('Normalizer')) {
        $r = Normalizer::normalize($s, Normalizer::FORM_C);
        return $r === false ? $s : $r;
    }
    // 兜底：常见「基字符 + 组合记号」→ 预组合字符（覆盖拉丁重音等高频场景）
    static $map = null;
    if ($map === null) {
        $map = array(
            "a\xCC\x81" => "\xC3\xA1", "e\xCC\x81" => "\xC3\xA9", "i\xCC\x81" => "\xC3\xAD",
            "o\xCC\x81" => "\xC3\xB3", "u\xCC\x81" => "\xC3\xBA", "n\xCC\x83" => "\xC3\xB1",
            "A\xCC\x81" => "\xC3\x81", "E\xCC\x81" => "\xC3\x89", "C\xCC\xA7" => "\xC3\x87",
            "a\xCC\x80" => "\xC3\xA0", "e\xCC\x80" => "\xC3\xA8", "u\xCC\x88" => "\xC3\xBC",
            "o\xCC\x88" => "\xC3\xB6", "a\xCC\x88" => "\xC3\xA4", "s\xCC\x81" => "\xC5\x9B",
        );
    }
    $s = strtr($s, $map);
    // 合并残留的组合记号（\xCC\x80-\xCF\xBF 段）
    $r = preg_replace('/([\xCC-\xCF][\x80-\xBF])/u', '', $s);
    return $r === null ? $s : $r;
}

/* ============================================================
 * 口令链路（用户指定）
 *   stored = PBKDF2-SHA256( sha256( md5(password) . utcRegisteredAt ), salt, 10000 )
 * ============================================================ */
function password_chain(string $plain, string $utcRegisteredAt, string $salt, int $rounds = 10000): string
{
    $h1 = md5($plain);
    $h2 = hash('sha256', $h1 . $utcRegisteredAt);
    return hash_pbkdf2('sha256', $h2, $salt, $rounds, 64, false);
}

function password_make_salt(): string
{
    return bin2hex(random_bytes(16));   // 32 hex
}

/** 恒定时间校验 */
function password_verify_chain(string $plain, string $utcRegisteredAt, string $salt, string $stored): bool
{
    $calc = password_chain($plain, $utcRegisteredAt, $salt);
    return hash_equals($stored, $calc);
}

/* ============================================================
 * RC4
 * ============================================================ */
function rc4_crypt(string $key, string $data): string
{
    $keyLen = strlen($key);
    if ($keyLen === 0) { return $data; }
    $s = range(0, 255);
    $j = 0;
    for ($i = 0; $i < 256; $i++) {
        $j = ($j + $s[$i] + ord($key[$i % $keyLen])) & 0xFF;
        $t = $s[$i]; $s[$i] = $s[$j]; $s[$j] = $t;
    }
    $out = '';
    $i = $j = 0;
    $len = strlen($data);
    for ($n = 0; $n < $len; $n++) {
        $i = ($i + 1) & 0xFF;
        $j = ($j + $s[$i]) & 0xFF;
        $t = $s[$i]; $s[$i] = $s[$j]; $s[$j] = $t;
        $out .= chr(ord($data[$n]) ^ $s[($s[$i] + $s[$j]) & 0xFF]);
    }
    return $out;
}

/* ============================================================
 * AES-256-CBC
 * ============================================================ */
function aes_key_bytes(): string
{
    return hash('sha256', (string)cfg('secrets.aes_key', 'fallback'), true); // 32B
}

function aes_encrypt(string $plain): string
{
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', aes_key_bytes(), OPENSSL_RAW_DATA, $iv);
    if ($ct === false) { throw new RuntimeException('AES 加密失败'); }
    return base64_encode($iv . $ct);
}

function aes_decrypt(string $b64): string
{
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) < 17) { throw new RuntimeException('密文格式错误'); }
    $iv = substr($raw, 0, 16);
    $ct = substr($raw, 16);
    $pt = openssl_decrypt($ct, 'aes-256-cbc', aes_key_bytes(), OPENSSL_RAW_DATA, $iv);
    if ($pt === false) { throw new RuntimeException('AES 解密失败'); }
    return $pt;
}

/* ============================================================
 * 登录态令牌：payload → RC4 → AES → base64url
 * ============================================================ */
function token_encode(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $rc4  = rc4_crypt((string)cfg('secrets.rc4_key', ''), $json);
    $aes  = aes_encrypt($rc4);
    return rtrim(strtr($aes, '+/', '-_'), '=');
}

function token_decode(string $token): array
{
    $b64 = strtr($token, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) { $b64 .= str_repeat('=', 4 - $pad); }
    $rc4 = aes_decrypt($b64);
    $json = rc4_crypt((string)cfg('secrets.rc4_key', ''), $rc4);   // RC4 对称
    $data = json_decode($json, true);
    if (!is_array($data)) { throw new RuntimeException('令牌损坏'); }
    return $data;
}

/* ============================================================
 * 其它
 * ============================================================ */
function rand_hex(int $bytes = 16): string { return bin2hex(random_bytes($bytes)); }

/** 恒定时间随机失败延迟（抹平时序侧信道） */
function timing_delay()
{
    $range = cfg('security.fail_delay_us', array(60000, 180000));
    if (!is_array($range) || count($range) < 2) { $range = array(60000, 180000); }
    usleep(random_int((int)$range[0], (int)$range[1]));
}
