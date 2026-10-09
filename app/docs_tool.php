<?php
/**
 * 文档工具：让 AI 能查站内公开文档（assets/docs/*.md）
 * ------------------------------------------------------------
 * 与作品工具共用同一套标签协议与执行器，三种用法：
 *   {"action":"docs"}                     → 文档清单（名称 + 一句话说明）
 *   {"action":"docs","query":"封禁"}       → 全文检索，返回各文档命中段落
 *   {"action":"docs","name":"用户协议"}    → 读取该文档正文（超长截断）
 * 只读 assets/docs 下的 .md：白名单来自目录扫描，不接受任意路径，天然免疫目录穿越。
 */
declare(strict_types=1);

function docs_tool_dir(): string { return APP_ROOT . '/assets/docs'; }

/** 文档清单：文件名（去 .md）→ 绝对路径（静态缓存，一次请求内只扫一次目录） */
function docs_tool_files(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $out = array();
    foreach ((array)@scandir(docs_tool_dir()) as $f) {
        if (substr($f, -3) !== '.md') { continue; }
        $out[substr($f, 0, -3)] = docs_tool_dir() . '/' . $f;
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $cache = $out;
}

/** 文档导读：取首个正文行作一句话说明（跳过标题、引用、列表与表格行） */
function docs_tool_intro(string $path): string
{
    $fp = @fopen($path, 'r');
    if (!$fp) { return ''; }
    $intro = '';
    while (($line = fgets($fp)) !== false) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || $t[0] === '>' || $t[0] === '-' || $t[0] === '|' || $t[0] === '*') { continue; }
        $intro = $t;
        break;
    }
    fclose($fp);
    return mb_substr($intro, 0, 80, 'UTF-8');
}

/** 名称归一：去书名号与空白、忽略大小写，使「《用户协议》」「用户协议」都能对上 */
function docs_tool_norm(string $s): string
{
    $s = str_replace(array('《', '》', ' ', "\t", '　', "\n", "\r"), '', $s);
    return mb_strtolower(trim($s), 'UTF-8');
}

/** 按名称定位文档；精确匹配优先，其次唯一包含匹配 */
function docs_tool_find(string $name): array
{
    $n = docs_tool_norm($name);
    if ($n === '') { return array(); }
    $files = docs_tool_files();
    foreach ($files as $title => $path) {
        if (docs_tool_norm($title) === $n) { return array('name' => $title, 'path' => $path); }
    }
    $hit = array();
    foreach ($files as $title => $path) {
        if (mb_strpos(docs_tool_norm($title), $n) !== false) { $hit[] = array('name' => $title, 'path' => $path); }
    }
    return count($hit) === 1 ? $hit[0] : array();
}

/** 全文检索：按空行分段，命中关键词的段落连标题一起返回（每篇最多 $maxHits 段） */
function docs_tool_search(string $q, int $maxDocs = 3, int $maxHits = 3): array
{
    $keys = preg_split('/[\s,，、;；]+/u', trim($q));
    $keys = array_values(array_filter((array)$keys, function ($k) { return mb_strlen((string)$k, 'UTF-8') >= 1; }));
    if (!$keys) { return array(); }

    $out = array();
    foreach (docs_tool_files() as $title => $path) {
        $text = (string)@file_get_contents($path);
        if ($text === '') { continue; }
        $hits = array();
        foreach ((array)preg_split('/\n\s*\n/', $text) as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p, 'UTF-8') < 4) { continue; }
            foreach ($keys as $k) {
                if (mb_stripos($p, (string)$k) !== false) {
                    $hits[] = mb_substr($p, 0, 400, 'UTF-8');
                    break;
                }
            }
            if (count($hits) >= $maxHits) { break; }
        }
        if ($hits) { $out[] = array('name' => $title, 'excerpts' => $hits); }
        if (count($out) >= $maxDocs) { break; }
    }
    return $out;
}

/** 读取单篇正文（默认截断 6000 字，避免提示词无界膨胀） */
function docs_tool_read(string $name, int $maxChars = 6000): array
{
    $f = docs_tool_find($name);
    if (!$f) {
        return array('ok' => false, 'action' => 'docs', 'error' => '没有找到该文档',
            'hint' => '可用文档：' . implode('、', array_keys(docs_tool_files())) . '；也可用 {"action":"docs","query":"关键词"} 全文检索。');
    }
    $text = (string)@file_get_contents($f['path']);
    if ($text === '') { return array('ok' => false, 'action' => 'docs', 'error' => '文档读取失败'); }
    $full = mb_strlen($text, 'UTF-8');
    if ($full > $maxChars) { $text = mb_substr($text, 0, $maxChars, 'UTF-8') . "\n…（已截断，全文约 " . $full . " 字）"; }
    return array('ok' => true, 'action' => 'docs', 'mode' => 'read',
        'name' => (string)$f['name'], 'text' => $text, 'chars' => $full);
}

/** 执行入口（由 works_tool_execute 转发，保持工具注册表集中在一处） */
function docs_tool_execute(array $params): array
{
    $name  = isset($params['name']) ? trim((string)$params['name'])
           : (isset($params['doc']) ? trim((string)$params['doc'])
           : (isset($params['title']) ? trim((string)$params['title']) : ''));
    $query = isset($params['query']) ? trim((string)$params['query'])
           : (isset($params['q']) ? trim((string)$params['q']) : '');

    if ($name !== '') { return docs_tool_read($name); }

    if ($query !== '') {
        $hits = docs_tool_search($query);
        if (!$hits) {
            return array('ok' => false, 'action' => 'docs', 'error' => '文档里没有检索到相关内容',
                'hint' => '换个关键词；想看全部文档用 {"action":"docs"}。');
        }
        return array('ok' => true, 'action' => 'docs', 'mode' => 'search',
            'query' => $query, 'hits' => $hits, 'count' => count($hits));
    }

    $list = array();
    foreach (docs_tool_files() as $title => $path) {
        $list[] = array('name' => $title, 'intro' => docs_tool_intro($path));
    }
    return array('ok' => true, 'action' => 'docs', 'mode' => 'list', 'docs' => $list, 'count' => count($list));
}
