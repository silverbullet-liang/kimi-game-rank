<?php
/**
 * 文件完整性自检（用完即删）
 * 访问 /selfcheck.php —— 列出所有应存在的文件，缺哪个一目了然。
 */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

$expect = array(
    '根目录' => array('index.php', 'install.php', 'cron.php', '.htaccess', 'README.md'),
    'config/' => array('config.php', 'config.sample.php', 'api_keys.php', 'scoring.php'),
    'app/' => array('bootstrap.php', 'db.php', 'helpers.php', 'crypto.php', 'scoring.php', 'auth.php', 'feed_client.php', 'zhipu.php', 'ip_lookup.php', 'autoinstall.php', 'migrate.php'),
    'api/' => array('auth.php', 'works.php', 'comments.php', 'lobby.php', 'ai.php', 'feedback.php', 'profile.php', 'admin.php', 'dashboard.php', 'site.php', 'start.php', 'img.php', 'media.php'),
    'assets/' => array('css/app.css'),
    'assets/js/' => array('app.js'),
    'assets/js/src/' => array('app.js', 'core.js', 'md.js', 'router.js', 'theme.js', 'transitions.js'),
    'assets/js/src/pages/' => array('rank.js', 'detail.js', 'lobby.js', 'mine.js', 'login.js', 'panel.js', 'doc.js', 'feedback.js'),
    'assets/vendor/' => array('liquid-glass@0.3.2.js'),
    'assets/docs/' => array('社区公约.md', '用户协议.md', '隐私政策.md', '功能说明.md', 'AI 使用说明.md', '评分标准.md', '入榜规则.md', '更新日志.md'),
    'sql/' => array('schema.sql', 'schema_admin.sql'),
    'assets/emoji/' => array('index.json'),
);

$broken = array();   // 文件 => 原因
$total = 0;
$have = 0;
$rows = '';
foreach ($expect as $dir => $files) {
    $cells = '';
    foreach ($files as $f) {
        $total++;
        $path = __DIR__ . '/' . ($dir === '根目录' ? '' : $dir) . $f;
        $rel  = ($dir === '根目录' ? '' : $dir) . $f;
        /* 必须把三种故障分开报：缺失 / 被误传成目录 / 不可读。
           只查 file_exists 会漏掉后两种——它们在前端都只表现为「请求失败」，
           但在服务器上是完全不同的修法（重传文件 / 删除同名目录 / 改权限）。
           故障文件即使存在，也不能算「找到」。 */
        if (!file_exists($path))            { $why = '缺失'; }
        elseif (is_dir($path))              { $why = '被传成了目录，需删除后重传文件'; }
        elseif (!is_readable($path))        { $why = '不可读，权限应设为 644'; }
        else                                { $why = ''; }

        if ($why === '') { $have++; } else { $broken[$rel] = $why; }
        $cells .= '<span style="display:inline-block;margin:3px 6px 3px 0;padding:3px 9px;border-radius:7px;font-size:12px;'
                . ($why === '' ? 'background:#e8f5e9;color:#1a7f37' : 'background:#fff0f0;color:#c62a2f;font-weight:700')
                . '">' . ($why === '' ? '✓' : '✗') . ' ' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $rows .= '<tr><td style="vertical-align:top;padding:10px 12px;font-weight:600;white-space:nowrap;border-bottom:1px solid #eee">'
           . htmlspecialchars($dir, ENT_QUOTES, 'UTF-8') . '</td><td style="padding:10px 12px;border-bottom:1px solid #eee">' . $cells . '</td></tr>';
}

/* 运行环境 */
$env = array();
$env['PHP 版本'] = PHP_VERSION;
$env['mbstring'] = function_exists('mb_strlen') ? '✓' : '✗ 缺失';
$env['pdo_mysql'] = extension_loaded('pdo_mysql') ? '✓' : '✗ 缺失';
$env['curl'] = extension_loaded('curl') ? '✓' : '✗ 缺失';
$env['openssl'] = extension_loaded('openssl') ? '✓' : '✗ 缺失';
$env['storage 可写'] = is_writable(__DIR__ . '/storage') ? '✓' : '✗ 不可写';
$env['config 可写'] = is_writable(__DIR__ . '/config/config.php') ? '✓' : '✗ 不可写（自动初始化需要）';
$env['已初始化'] = file_exists(__DIR__ . '/storage/installed.lock') ? '✓' : '未完成（刷新首页会自动初始化）';

$envRows = '';
foreach ($env as $k => $v) {
    $bad = strpos((string)$v, '✗') !== false;
    $envRows .= '<tr><td style="padding:7px 12px;border-bottom:1px solid #eee">' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</td>'
              . '<td style="padding:7px 12px;border-bottom:1px solid #eee;color:' . ($bad ? '#c62a2f' : '#1a7f37') . ';font-weight:600">'
              . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td></tr>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>文件自检 · Kimi游戏榜</title>
<style>
body{margin:0;background:#f6f7f9;color:#1f2329;font:14px/1.6 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:860px;margin:24px auto;padding:0 14px}
.card{background:#fff;border:1px solid #e3e5e8;border-radius:12px;padding:20px;margin-bottom:16px}
h1{font-size:19px;margin:0 0 4px}
h2{font-size:15px;margin:0 0 12px}
.big{font-size:26px;font-weight:800}
table{width:100%;border-collapse:collapse;font-size:13px}
.notice{padding:11px 14px;border-radius:10px;margin:12px 0;font-size:13px}
.ok{background:#e8f5e9;color:#1a7f37}
.bad{background:#fff0f0;color:#c62a2f;font-weight:600}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>文件完整性自检</h1>
    <p style="color:#8a8f99;margin:0 0 12px">应存在 <?= $total ?> 个文件，实际正常 <?= $have ?> 个。</p>
    <div class="big" style="color:<?= count($broken) ? '#c62a2f' : '#1a7f37' ?>">
      <?= count($broken) ? '异常 ' . count($broken) . ' 个文件' : '✓ 文件完整' ?>
    </div>
    <?php if ($broken): ?>
      <div class="notice bad">
        异常清单：<?= htmlspecialchars(implode('、', array_map(function ($k, $v) { return $k . '（' . $v . '）'; }, array_keys($broken), $broken)), ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div class="notice ok">修复方式：用 FTP 打开对应目录，把异常项<b>整个删除</b>（它可能是文件，也可能是被误传成的同名目录），再从压缩包里重新上传该文件，权限 644。</div>
    <?php else: ?>
      <div class="notice ok">文件齐全。若按钮仍无响应，请刷新首页并把页面顶部红色提示截图反馈。</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>运行环境</h2>
    <table><?= $envRows ?></table>
  </div>

  <div class="card">
    <h2>文件明细</h2>
    <table><?= $rows ?></table>
  </div>
</div>
</body>
</html>
