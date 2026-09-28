<?php
/**
 * IP 归属地查询（米人 API）+ 本地缓存
 * ------------------------------------------------------------
 * 接口：GET https://api.mir6.com/api/ip_json?ip={ip}
 * 限流 15 次/秒 → 同 IP 结果落库缓存，避免重复外呼。
 */
declare(strict_types=1);

define('IP_API_ENDPOINT', 'https://api.mir6.com/api/ip_json');
define('IP_CACHE_TTL', 604800);   // 7 天

/**
 * 清理第三方返回的地名：去掉国家/地区代码后缀（「中国[CN]」→「中国」）与多余空白。
 */
function ip_clean_name(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    $s = preg_replace('/\[[A-Za-z]{2,3}\]/u', '', $s);
    return trim((string)$s);
}

/**
 * 归一化归属地字段。
 *
 * 关键在于**结构化字段优先**：米人接口原本就分别返回 country / province / city，
 * 但接口同时给了一个拼好的 location（形如「中国[CN] 江苏省 南京市」）。
 * 早先的实现直接用那个拼接串，字段边界就此丢失，统计时只能靠正则去猜省和市 ——
 * 于是出现「城市里是四川省」「省份里是中国[CN]」这类错位。
 * 现在一律由结构字段重拼 location，第三方拼好的串只作最后兜底。
 */
function ip_normalize(array $d): array
{
    if ($d === array()) { return $d; }

    $co = ip_clean_name(isset($d['country'])  ? (string)$d['country']  : '');
    $pr = ip_clean_name(isset($d['province']) ? (string)$d['province'] : '');
    $ci = ip_clean_name(isset($d['city'])     ? (string)$d['city']     : '');

    /* 结构字段全空时才退回文本解析（备用源部分字段缺失的场景） */
    if ($co === '' && $pr === '' && $ci === '') {
        $p = location_parts(isset($d['location']) ? (string)$d['location'] : '');
        if ($p[0] !== '' && $p[0] !== '其他') { $co = $p[0]; }
        $pr = $p[1];
        $ci = $p[2];
    }

    $d['country']  = $co;
    $d['province'] = $pr;
    $d['city']     = $ci;

    /* 去重：直辖市常常省、市同名（北京市/北京市），拼串里只留一份 */
    $segs = array();
    foreach (array($co, $pr, $ci) as $v) {
        if ($v !== '' && !in_array($v, $segs, true)) { $segs[] = $v; }
    }
    $d['location'] = implode(' ', $segs);

    if (empty($d['isp']) && !empty($d['ispName']) && is_string($d['ispName'])) { $d['isp'] = $d['ispName']; }
    if (empty($d['isp']) && !empty($d['operator']) && is_string($d['operator'])) { $d['isp'] = $d['operator']; }
    return $d;
}

/**
 * 从查询结果中取出结构化归属地：array(国家, 省份, 城市)。
 * 三个字段各自独立，调用方不再需要对拼接串做二次解析。
 */
function ip_fields(array $info): array
{
    $get = function ($k) use ($info) {
        return isset($info[$k]) && is_string($info[$k]) ? trim($info[$k]) : '';
    };
    return array($get('country'), $get('province'), $get('city'));
}

/**
 * 回填历史访问记录的归属地（把旧的 location 拼接串拆成三个独立列）。
 * 优先用 ip_cache 里同一条目的结构字段；匹配不到再退回改进后的文本解析。
 * 返回处理的条数。幂等：只处理 country 仍为空的行。
 */
function visits_backfill(int $limit = 2000): int
{
    if (!function_exists('table_exists') || !table_exists('user_visits')) { return 0; }
    if (!column_exists('user_visits', 'country')) { return 0; }

    $locs = db_all("SELECT DISTINCT location FROM user_visits WHERE country = '' AND location <> '' LIMIT $limit");
    if (empty($locs)) { return 0; }

    $hasCache = table_exists('ip_cache');
    $n = 0;
    foreach ($locs as $r) {
        $loc = (string)$r['location'];
        $co = ''; $pr = ''; $ci = '';

        if ($hasCache) {
            $p = db_one("SELECT payload FROM ip_cache WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.location')) = ? LIMIT 1", array($loc));
            if ($p !== null) {
                $d = json_decode((string)$p['payload'], true);
                if (is_array($d)) {
                    $f = ip_fields(ip_normalize($d));
                    $co = $f[0]; $pr = $f[1]; $ci = $f[2];
                }
            }
        }
        if ($co === '' && $pr === '' && $ci === '') {
            $f = location_parts($loc);
            if ($f[0] !== '其他') { $co = $f[0]; }
            $pr = $f[1]; $ci = $f[2];
        }

        db_exec('UPDATE user_visits SET country = ?, province = ?, city = ? WHERE location = ? AND country = ?',
            array(mb_substr($co, 0, 32, 'UTF-8'), mb_substr($pr, 0, 32, 'UTF-8'), mb_substr($ci, 0, 32, 'UTF-8'), $loc, ''));
        $n++;
    }
    return $n;
}

/** 备用归属地源（主源无结果时尝试），失败返回空数组 */
function ip_api_fetch_backup(string $ip): array
{
    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?lang=zh-CN';
    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
        ));
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array('timeout' => 4)));
        $raw = @file_get_contents($url, false, $ctx);
    }
    if (!is_string($raw) || $raw === '') { return array(); }
    $j = json_decode($raw, true);
    if (!is_array($j) || (isset($j['status']) && $j['status'] !== 'success')) { return array(); }
    return array(
        'country'  => isset($j['country']) ? (string)$j['country'] : '',
        'province' => isset($j['regionName']) ? (string)$j['regionName'] : '',
        'city'     => isset($j['city']) ? (string)$j['city'] : '',
        'isp'      => isset($j['isp']) ? (string)$j['isp'] : '',
    );
}

/**
 * 查询 IP 归属地信息（返回 data 数组；失败返回空数组）
 */
function ip_lookup(string $ip, bool $useCache = true): array
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false && strpos($ip, '.') === false) {
        return array();
    }
    $h = ip_hash($ip);

    if ($useCache) {
        $row = db_one('SELECT payload, created_at FROM ip_cache WHERE ip_hash = ? LIMIT 1', array($h));
        if ($row !== null) {
            $age = time() - strtotime((string)$row['created_at'] . ' UTC');
            if ($age < IP_CACHE_TTL) {
                $d = json_decode((string)$row['payload'], true);
                return is_array($d) ? ip_normalize($d) : array();
            }
        }
    }

    $data = ip_normalize(ip_api_fetch($ip));
    if (empty($data['location'])) {
        /* 主源没给出归属地 → 试备用源 */
        $bak = ip_normalize(ip_api_fetch_backup($ip));
        if (!empty($bak['location'])) { $data = $bak; }
    }
    if (!empty($data)) {
        $sql = 'INSERT INTO ip_cache (ip_hash, ip_masked, payload, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE payload = VALUES(payload), created_at = VALUES(created_at)';
        db_exec($sql, array($h, mask_ip($ip), json_encode($data, JSON_UNESCAPED_UNICODE)));
    }
    return $data;
}

function ip_api_fetch(string $ip): array
{
    $url = IP_API_ENDPOINT . '?ip=' . urlencode($ip);
    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array('timeout' => 4)));
        $raw = @file_get_contents($url, false, $ctx);
    }
    if (!is_string($raw) || $raw === '') { return array(); }
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['data']) || !is_array($j['data'])) { return array(); }
    return $j['data'];
}
