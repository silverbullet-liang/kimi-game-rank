<?php
/**
 * 自动初始化：首次访问时自动建表、生成密钥、预置管理员，全程无需人工输入。
 * 幂等：重复调用安全；完成后写 storage/installed.lock。
 */
declare(strict_types=1);

function auto_install()
{
    $lock = APP_ROOT . '/storage/installed.lock';
    if (file_exists($lock)) { return; }

    /* 连接自检：主库 */
    try {
        $main = db();
        $hasUsers = db_val('SHOW TABLES LIKE "users"');
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (strpos($m, '1045') !== false || stripos($m, 'Access denied') !== false) {
            throw new RuntimeException('MySQL 拒绝访问（1045）：请到主机面板「MySQL Databases」核对 MySQL User Name 与密码（即 vPanel 密码），并确保 config/config.php 中 db.user / db.pass 与之一致。原始信息：' . $m);
        }
        if (strpos($m, '1049') !== false || stripos($m, 'Unknown database') !== false) {
            throw new RuntimeException('数据库不存在（1049）：请先在主机面板创建主库。原始信息：' . $m);
        }
        throw new RuntimeException('数据库连接失败：' . $m);
    }

    if (!$hasUsers) {
        $main->exec(file_get_contents(APP_ROOT . '/sql/schema.sql'));
    }

    /* 管理员独立库 */
    try {
        $adm = db_admin();
        $hasCred = db_admin_one('SHOW TABLES LIKE "admin_credentials"');
        if (!$hasCred) {
            $adm->exec(file_get_contents(APP_ROOT . '/sql/schema_admin.sql'));
        }
        ensure_admin_row($adm);
    } catch (Throwable $e) {
        throw new RuntimeException('管理员库初始化失败：' . $e->getMessage() . '（请在面板确认已创建独立的管理员数据库）');
    }

    ensure_secrets();
    @file_put_contents($lock, gmdate('c') . ' auto-installed');
}

/** 预置管理员：库里只存「凭据的加盐慢哈希」，不存明文 */
function ensure_admin_row(PDO $adm)
{
    $raw = (string)cfg('admin.secret_raw', '');
    if ($raw === '') { throw new RuntimeException('config.admin.secret_raw 未配置'); }

    /* 确保慢哈希列存在（老库升级时可能还没有）。
       不用 `SHOW COLUMNS ... LIKE ?`：原生预处理下 SHOW 不接受占位符。 */
    try {
        $cols = array();
        foreach ($adm->query('SHOW COLUMNS FROM `admin_credentials`')->fetchAll(PDO::FETCH_NUM) as $r) {
            $cols[(string)$r[0]] = true;
        }
        if (!isset($cols['secret_hash'])) {
            $adm->exec('ALTER TABLE `admin_credentials` ADD COLUMN `secret_hash` VARCHAR(255) NULL');
        }
    } catch (Throwable $e) {
        throw new RuntimeException('admin_credentials 结构检查失败：' . $e->getMessage());
    }

    $hash = password_hash(admin_credential_of($raw), PASSWORD_DEFAULT);

    $st = $adm->prepare(
        "INSERT INTO admin_credentials (username, secret_plaintext, secret_hash, created_at)
         VALUES (?, '', ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE secret_plaintext = '', secret_hash = VALUES(secret_hash)"
    );
    $st->execute(array('admin', $hash));
}

/** 生成并回写密钥（已有则跳过）；同时刷新运行时配置 */
function ensure_secrets()
{
    $cur = $GLOBALS['APP_CONFIG'];
    if (!empty($cur['secrets']['rc4_key']) && !empty($cur['secrets']['aes_key'])) { return; }

    /* ------------------------------------------------------------
     * 密钥持久化策略（顺序至关重要）
     * 1) storage/secrets.json —— storage 目录必定可写（站点能跑就说明可写），
     *    且普通文件读取不受 OPcache 影响；
     * 2) 若文件读写均不可用 → 用「固定种子派生」兜底。
     * 切勿每次请求随机生成：密钥一变，所有登录态令牌立刻失效，
     * 前端收到 401 会重载页面，形成请求风暴（表现为「网络连接不稳定」）。
     * ------------------------------------------------------------ */
    $file = APP_ROOT . '/storage/secrets.json';
    $saved = array();

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($j) && !empty($j['rc4_key']) && !empty($j['aes_key'])) { $saved = $j; }
    }

    if (empty($saved)) {
        $rand = function ($len) {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $out = '';
            for ($i = 0; $i < $len; $i++) { $out .= $chars[random_int(0, strlen($chars) - 1)]; }
            return $out;
        };
        $adminRaw = (string)cfg('admin.secret_raw', '');
        $cand = array(
            'rc4_key'  => hash('sha256', $adminRaw) . md5('1970101') . hash('sha256', 'jgybhjhjh:jji') . $rand(512),
            'aes_key'  => $rand(100),
            'cron_key' => $rand(64),
        );
        if (!is_dir(APP_ROOT . '/storage')) { @mkdir(APP_ROOT . '/storage', 0775, true); }
        $written = @file_put_contents($file, json_encode($cand, JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($written !== false) {
            @chmod($file, 0600);
            $saved = $cand;
            app_log('secrets generated → storage/secrets.json');
        } else {
            /* 文件不可写：确定性派生（跨请求完全一致，绝不用随机值） */
            $seed = ($adminRaw !== '' ? $adminRaw : 'kimi-game-rank-default-seed');
            $saved = array(
                'rc4_key'  => hash('sha256', $seed . '|rc4') . md5('1970101') . hash('sha256', 'jgybhjhjh:jji') . hash('sha256', $seed . '|rc4-long'),
                'aes_key'  => hash('sha256', $seed . '|aes'),
                'cron_key' => hash('sha256', $seed . '|cron'),
            );
            app_log('secrets derived from seed (storage not writable)');
        }
    }

    $cur['secrets'] = array(
        'rc4_key'  => (string)$saved['rc4_key'],
        'aes_key'  => (string)$saved['aes_key'],
        'cron_key' => isset($saved['cron_key']) ? (string)$saved['cron_key'] : '',
    );
    $GLOBALS['APP_CONFIG'] = $cur;
}

