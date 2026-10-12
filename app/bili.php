<?php
/**
 * B站视频：解析（米人 API）、取帧（官方 storyboard）、抽帧审核。
 * ------------------------------------------------------------
 * · 米人 api.mir6.com/api/bzjiexi：给 BV 号/链接，返回视频信息与 mp4 直链。
 *   直链带防盗链（要求请求带 Referer —— 浏览器从本站发起即自带本站 Referer，
 *   实测可直接播放）且有时效（约 1 天），因此**永不入库**：只在播放前实时解析
 *   一次交给前端直连播放，本站不代理视频流量。
 * · 官方 storyboard（x/player/videoshot）：给 aid + cid 返回一张雪碧图（含上百帧）
 *   与其网格尺寸，用于「随机抽帧」送图片审核 —— 无需 ffmpeg、不下载整段视频。
 * · 入库只存 BV 号（messages.media_url），展示信息（标题/封面/时长/作者）存 meta。
 */
declare(strict_types=1);

define('BILI_MIREN_EP', 'https://api.mir6.com/api/bzjiexi');
define('BILI_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
define('BILI_REFERER', 'https://www.bilibili.com/');

/** 从任意输入（完整链接 / BV 号 / b23.tv 短链）提取 BV 号；失败返回空串 */
function bili_bv_of(string $input): string
{
    $s = trim($input);
    if ($s === '') { return ''; }
    if (preg_match('/[Bb][Vv]([0-9A-Za-z]{10})/', $s, $m)) { return 'BV' . $m[1]; }
    if (preg_match('#^https?://#i', $s) && (stripos($s, 'b23.tv') !== false || stripos($s, 'bilibili.com') !== false)) {
        $final = bili_follow($s);
        if ($final !== '' && preg_match('/[Bb][Vv]([0-9A-Za-z]{10})/', $final, $m2)) { return 'BV' . $m2[1]; }
    }
    return '';
}

/** 跟随重定向取最终地址（b23.tv 短链 → 标准页） */
function bili_follow(string $url): string
{
    if (!function_exists('curl_init')) { return ''; }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_NOBODY         => true,          // HEAD
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => BILI_UA,
    ));
    curl_exec($ch);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return $final;
}

/** 简单 GET（可选 Referer），返回 array(code, body) */
function bili_get(string $url, bool $withReferer = true, int $timeout = 15): array
{
    if (!function_exists('curl_init')) { return array('code' => 0, 'body' => ''); }
    $h = array('User-Agent: ' . BILI_UA, 'Accept: application/json, text/plain, */*');
    if ($withReferer) { $h[] = 'Referer: ' . BILI_REFERER; }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => is_string($body) ? $body : '');
}

/** 米人解析：返回归一信息（含 video_url 直链，仅供即时播放）；失败返回 null */
function bili_miren(string $bv)
{
    if (!preg_match('/^BV[0-9A-Za-z]{10}$/', $bv)) { return null; }
    $link = 'https://www.bilibili.com/video/' . $bv . '/';
    $r = bili_get(BILI_MIREN_EP . '?url=' . rawurlencode($link) . '&type=json', true, 20);
    if ($r['code'] < 200 || $r['code'] >= 300 || $r['body'] === '') { return null; }
    $j = json_decode($r['body'], true);
    if (!is_array($j) || (int)($j['code'] ?? 0) !== 200) { return null; }
    $d0 = (isset($j['data'][0]) && is_array($j['data'][0])) ? $j['data'][0] : array();
    return array(
        'bv'         => $bv,
        'title'      => mb_substr(trim((string)($j['title'] ?? $d0['title'] ?? '')), 0, 120, 'UTF-8'),
        'cover'      => preg_replace('#^http:#i', 'https:', trim((string)($j['imgurl'] ?? ''))),
        'duration'   => (int)($d0['duration'] ?? 0),
        'duration_f' => (string)($d0['durationFormat'] ?? ''),
        'author'     => mb_substr(trim((string)($j['user']['name'] ?? '')), 0, 60, 'UTF-8'),
        'video_url'  => (string)($d0['video_url'] ?? ''),
    );
}

/** 官方视频信息（aid + cid），用于取 storyboard */
function bili_official(string $bv)
{
    $r = bili_get('https://api.bilibili.com/x/web-interface/view?bvid=' . rawurlencode($bv), true, 15);
    if ($r['code'] < 200 || $r['code'] >= 300) { return null; }
    $j = json_decode($r['body'], true);
    if (!is_array($j) || (int)($j['code'] ?? -1) !== 0 || empty($j['data']) || !is_array($j['data'])) { return null; }
    return array('aid' => (int)($j['data']['aid'] ?? 0), 'cid' => (int)($j['data']['cid'] ?? 0));
}

/** 官方 storyboard（雪碧图 + 网格），用于随机抽帧 */
function bili_storyboard(int $aid, int $cid)
{
    if ($aid <= 0 || $cid <= 0) { return null; }
    $r = bili_get('https://api.bilibili.com/x/player/videoshot?aid=' . $aid . '&cid=' . $cid . '&index=1', true, 15);
    if ($r['code'] < 200 || $r['code'] >= 300) { return null; }
    $j = json_decode($r['body'], true);
    if (!is_array($j) || (int)($j['code'] ?? -1) !== 0 || empty($j['data']['image'])) { return null; }
    $d = $j['data'];
    $imgs = array();
    foreach ((array)$d['image'] as $u) {
        $u = (string)$u;
        $imgs[] = (strpos($u, '//') === 0) ? ('https:' . $u) : $u;
    }
    if (!$imgs) { return null; }
    return array(
        'images' => $imgs,
        'x_len'  => max(1, (int)($d['img_x_len'] ?? 10)),
        'y_len'  => max(1, (int)($d['img_y_len'] ?? 10)),
        'x_size' => max(1, (int)($d['img_x_size'] ?? 160)),
        'y_size' => max(1, (int)($d['img_y_size'] ?? 90)),
    );
}

/** 下载图片到临时文件（限 4MB），返回路径；失败返回空串 */
function bili_download(string $url, int $maxBytes = 4 * 1024 * 1024): string
{
    $r = bili_get($url, false, 20);              // storyboard 图公开可下（无防盗链）
    if ($r['code'] < 200 || $r['code'] >= 300 || $r['body'] === '') { return ''; }
    if (strlen($r['body']) > $maxBytes) { return ''; }
    $dir = APP_ROOT . '/storage/tmp';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $f = $dir . '/bili_' . bin2hex(random_bytes(6)) . '.jpg';
    return (@file_put_contents($f, $r['body']) !== false) ? $f : '';
}

/**
 * 随机抽帧 → 图片审核。
 * 返回 array(ok, reject, reason)：ok=能否给出结论；reject=是否违规。
 * ok=false 表示审核不可用（调用方应拒绝发送：视频风险高于图片）。
 */
function bili_audit(string $bv, int $want = 3): array
{
    if (!function_exists('image_audit_check')) { return array('ok' => false, 'reason' => 'no_audit'); }

    $off = bili_official($bv);
    if ($off === null) { return array('ok' => false, 'reason' => 'no_official'); }
    $sb = bili_storyboard($off['aid'], $off['cid']);
    if ($sb === null) { return array('ok' => false, 'reason' => 'no_shot'); }

    $sprite = bili_download($sb['images'][0]);
    if ($sprite === '') { return array('ok' => false, 'reason' => 'dl_failed'); }

    $frames = array();
    if (function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
        $src = @imagecreatefromjpeg($sprite);
        if ($src !== false) {
            $cells = $sb['x_len'] * $sb['y_len'];
            $want  = max(1, min(4, $want));
            $pick  = array(); $guard = 0;
            while (count($pick) < min($want, $cells) && $guard < 60) { $pick[random_int(0, $cells - 1)] = true; $guard++; }
            $dir = APP_ROOT . '/storage/tmp';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            foreach (array_keys($pick) as $ci) {
                $cx = $ci % $sb['x_len'];
                $cy = intdiv($ci, $sb['x_len']);
                $crop = @imagecreatetruecolor($sb['x_size'], $sb['y_size']);
                if ($crop === false) { continue; }
                @imagecopy($crop, $src, 0, 0, $cx * $sb['x_size'], $cy * $sb['y_size'], $sb['x_size'], $sb['y_size']);
                $f = $dir . '/bili_' . bin2hex(random_bytes(6)) . '.jpg';
                if (@imagejpeg($crop, $f, 82)) { $frames[] = $f; }
                imagedestroy($crop);
            }
            imagedestroy($src);
        }
    }
    /* 无 GD 或裁切失败：退回用整张雪碧图（拼图同样是一张图，能识别违规） */
    if (!$frames) { $frames[] = $sprite; }
    else { @unlink($sprite); }

    $checked = 0; $rejectReason = '';
    foreach ($frames as $f) {
        try { $v = image_audit_check($f); }
        catch (Throwable $e) { $v = array('ok' => true, 'via' => 'error', 'reason' => ''); }
        @unlink($f);
        $via = (string)($v['via'] ?? 'none');
        if (in_array($via, array('none', 'degraded', 'error'), true)) { continue; }   // 这一张没审成
        $checked++;
        if (empty($v['ok']) && $rejectReason === '') { $rejectReason = (string)($v['reason'] ?? '含违规内容'); }
    }
    if ($checked === 0)   { return array('ok' => false, 'reason' => 'all_unavailable'); }
    if ($rejectReason !== '') { return array('ok' => true, 'reject' => true, 'reason' => $rejectReason); }
    return array('ok' => true, 'reject' => false, 'reason' => '');
}
