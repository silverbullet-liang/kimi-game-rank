<?php
/**
 * 关键文件完整性（v3.51.0）
 * ------------------------------------------------------------
 * 目的：文件被改动时（主机注入、扩展落地后门、包上传不全、被替换为内存马）能立刻发现。
 * 做法：
 *   · 清单记录每个关键文件的 sha256 + md5 + 字节数，清单本身用 HMAC 签名；
 *   · 签名密钥取 config 的 secrets.cron_key —— 只改文件不改清单会被发现，
 *     改清单但不知密钥同样会被发现；
 *   · 运行期低频抽样校验（默认 10 分钟一次），异常只记录与告警，绝不阻断站点；
 *   · 清单在 app/data/ 下，该目录由 .htaccess 直接 404，不可外部读取。
 *
 * 生成 / 校验：php tools/integrity.php sign | check
 */
declare(strict_types=1);

/** 参与校验的文件：入口、前端产物、安全与路由核心。改这些才需要重新签名。 */
function integrity_files(): array
{
    return array(
        'index.php',
        '.htaccess',
        'assets/js/app.js',
        'assets/js/monitor.js',
        'assets/css/app.css',
        'app/bootstrap.php',
        'app/helpers.php',
        'app/auth.php',
        'app/db.php',
        'app/seo.php',
        'app/integrity.php',
        'app/upload_guard.php',
        'app/captcha.php',
        'app/moderation.php',
        'api/auth.php',
        'api/works.php',
        'api/comments.php',
        'api/lobby.php',
        'api/media.php',
        'api/admin.php',
        'api/account.php',
        'app/email.php',
        'app/email_code.php',
        'app/works_cat.php',
    );
}

function integrity_sign_key(): string
{
    $k = (string)cfg('secrets.cron_key', '');
    return $k !== '' ? $k : 'kimgr-integrity';
}

function integrity_path(): string
{
    return APP_ROOT . '/app/data/integrity.json';
}

/** 现算所有文件指纹（供签名与校验共用） */
function integrity_body(): array
{
    $out = array();
    foreach (integrity_files() as $rel) {
        $p = APP_ROOT . '/' . $rel;
        if (!is_file($p)) {
            $out[$rel] = array('sha256' => '', 'md5' => '', 'size' => -1);
            continue;
        }
        $out[$rel] = array(
            'sha256' => (string)@hash_file('sha256', $p),
            'md5'    => (string)@hash_file('md5', $p),
            'size'   => (int)@filesize($p),
        );
    }
    return $out;
}

function integrity_sign(array $body): string
{
    return hash_hmac('sha256', json_encode($body, JSON_UNESCAPED_SLASHES), integrity_sign_key());
}

/** 重新生成并落盘（发版时执行；运行期不要调用） */
function integrity_sign_now(): array
{
    $body = integrity_body();
    $doc  = array(
        'version' => defined('APP_VERSION') ? APP_VERSION : '',
        'at'      => gmdate('Y-m-d H:i:s'),
        'algo'    => 'sha256+md5',
        'files'   => $body,
        'sig'     => integrity_sign($body),
    );
    @file_put_contents(
        integrity_path(),
        json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    return $doc;
}

/** 读清单（带进程内缓存） */
function integrity_manifest(): array
{
    static $doc = null;
    if ($doc !== null) { return $doc; }
    $p = integrity_path();
    if (!is_file($p)) { return $doc = array(); }
    $raw = (string)@file_get_contents($p);
    $j   = json_decode($raw, true);
    return $doc = is_array($j) ? $j : array();
}

/**
 * 校验：清单签名 → 逐文件 sha256 + md5。
 * 返回 ok / sig_ok / bad（被改）/ missing（丢失）/ extra（清单里有但当前不该有）/ at / version
 */
function integrity_check(): array
{
    $man = integrity_manifest();
    $res = array(
        'ok' => false, 'sig_ok' => false, 'bad' => array(), 'missing' => array(),
        'at' => isset($man['at']) ? (string)$man['at'] : '',
        'version' => isset($man['version']) ? (string)$man['version'] : '',
        'count' => 0,
    );
    if (empty($man['files']) || !is_array($man['files'])) {
        $res['reason'] = 'no-manifest';
        return $res;
    }

    $res['sig_ok'] = isset($man['sig']) && hash_equals(integrity_sign($man['files']), (string)$man['sig']);
    $res['count']  = count($man['files']);

    foreach ($man['files'] as $rel => $want) {
        $p = APP_ROOT . '/' . $rel;
        if (!is_file($p)) { $res['missing'][] = $rel; continue; }
        $sha = (string)@hash_file('sha256', $p);
        $md5 = (string)@hash_file('md5', $p);
        if ($sha !== (string)($want['sha256'] ?? '') || $md5 !== (string)($want['md5'] ?? '')) {
            $res['bad'][] = $rel;
        }
    }

    $res['ok'] = $res['sig_ok'] && empty($res['bad']) && empty($res['missing']);
    return $res;
}

/** 低频抽样校验：默认 600 秒一次；结果写日志与设置项，供控制面板展示 */
function integrity_tick(int $intervalSec = 600)
{
    $stamp = APP_ROOT . '/storage/integrity.last';
    $now   = time();
    $last  = is_file($stamp) ? (int)@filemtime($stamp) : 0;
    if ($last > 0 && ($now - $last) < $intervalSec) { return; }
    @touch($stamp);

    try {
        $r = integrity_check();
        $brief = array(
            'ok' => $r['ok'], 'sig_ok' => $r['sig_ok'], 'at' => gmdate('Y-m-d H:i:s'),
            'bad' => array_slice($r['bad'], 0, 20), 'missing' => array_slice($r['missing'], 0, 20),
            'count' => $r['count'],
        );
        setting_set('integrity.state', json_encode($brief, JSON_UNESCAPED_UNICODE));
        if (!$r['ok']) {
            app_log('integrity: mismatch bad=' . implode(',', array_slice($r['bad'], 0, 10))
                . ' missing=' . implode(',', array_slice($r['missing'], 0, 10))
                . ' sig=' . ($r['sig_ok'] ? 'ok' : 'BAD'));
        }
    } catch (Throwable $e) {
        app_log('integrity tick failed: ' . $e->getMessage());
    }
}
