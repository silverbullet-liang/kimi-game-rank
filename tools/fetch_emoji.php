<?php
/**
 * 表情包抓取器（服务端自动获取并本地化，解决防盗链）
 * ------------------------------------------------------------
 * 触发：cron.php?key={cron_key}&task=emoji
 * 逻辑：拉取远端表情目录 → 下载图片到 assets/emoji/{pack}/ → 生成 index.json
 * 说明：图片保存在本站，引用时不存在防盗链问题。
 */
declare(strict_types=1);

define('EMOJI_SOURCE_CATALOG', 'https://cdn.jsdelivr.net/gh/hellodigua/dsh-emoji@main/src/catalog.generated.ts');
define('EMOJI_SOURCE_BASE', 'https://cdn.jsdelivr.net/gh/hellodigua/dsh-emoji@main/assets/emoji/bilibili/');
define('EMOJI_DIR', APP_ROOT . '/assets/emoji');

function emoji_http_get(string $url, int $timeout = 20): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; KimiRankEmojiFetcher/1.0)',
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $code !== 200) { throw new RuntimeException('下载失败: ' . $url); }
    return $body;
}

/** 从 catalog.generated.ts 中解析表情数组 */
function emoji_parse_catalog(string $ts): array
{
    $pos = strpos($ts, 'export const EMOJIS');
    if ($pos === false) { throw new RuntimeException('目录格式已变更'); }
    $start = strpos($ts, '[', $pos);
    if ($start === false) { throw new RuntimeException('目录解析失败'); }

    $depth = 0; $inStr = false; $esc = false; $end = -1;
    $len = strlen($ts);
    for ($i = $start; $i < $len; $i++) {
        $c = $ts[$i];
        if ($inStr) {
            if ($esc) { $esc = false; }
            elseif ($c === '\\') { $esc = true; }
            elseif ($c === '"') { $inStr = false; }
            continue;
        }
        if ($c === '"') { $inStr = true; }
        elseif ($c === '[') { $depth++; }
        elseif ($c === ']') {
            $depth--;
            if ($depth === 0) { $end = $i + 1; break; }
        }
    }
    if ($end < 0) { throw new RuntimeException('目录结构不完整'); }
    $arr = json_decode(substr($ts, $start, $end - $start), true);
    if (!is_array($arr)) { throw new RuntimeException('目录 JSON 解析失败'); }
    return $arr;
}

/** 主流程：抓取并本地化 */
function fetch_emoji_pack(): array
{
    $ts = emoji_http_get(EMOJI_SOURCE_CATALOG);
    $list = emoji_parse_catalog($ts);

    $dir = EMOJI_DIR . '/bilibili';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('表情目录不可写：assets/emoji/bilibili');
    }

    $items = array();
    $ok = 0; $skip = 0; $fail = 0;
    foreach ($list as $e) {
        $file = isset($e['file']) ? (string)$e['file'] : '';
        $name = isset($e['name']) ? (string)$e['name'] : '';
        if ($file === '' || $name === '') { continue; }
        $target = $dir . '/' . basename($file);
        if (is_file($target) && filesize($target) > 0) {
            $skip++;
        } else {
            try {
                $bin = emoji_http_get(EMOJI_SOURCE_BASE . rawurlencode($file));
                if (strlen($bin) > 512 * 1024) { $fail++; continue; }
                file_put_contents($target, $bin);
                $ok++;
            } catch (Throwable $ex) {
                $fail++;
                app_log('emoji fetch fail: ' . $file . ' ' . $ex->getMessage());
                continue;
            }
            usleep(120000);   // 节流
        }
        $items[] = array(
            'code'     => $name,
            'file'     => 'bilibili/' . basename($file),
            'keywords' => isset($e['keywords']) && is_array($e['keywords']) ? array_slice($e['keywords'], 0, 6) : array(),
        );
    }

    $index = array('packs' => array(array(
        'id' => 'bilibili', 'name' => 'B站表情', 'items' => $items,
    )));
    file_put_contents(EMOJI_DIR . '/index.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return array('downloaded' => $ok, 'skipped' => $skip, 'failed' => $fail, 'indexed' => count($items));
}
