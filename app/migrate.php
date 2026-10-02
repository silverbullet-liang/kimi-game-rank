<?php
/**
 * 数据库自动迁移
 * ------------------------------------------------------------
 * 基于 settings.schema_version 逐版本升级，幂等安全；
 * 每次请求（bootstrap 内）自动检查，首次访问即完成升级。
 */
declare(strict_types=1);

define('SCHEMA_VERSION', 18);

/**
 * 表的全部列名（按表名缓存）。
 * 不用 SHOW COLUMNS ... LIKE：列名里的下划线在 LIKE 中是通配符，
 * 转义又会踩到 NO_BACKSLASH_ESCAPES 之类的服务端设置——两种写法都可能判错。
 * 直接取回全部列名再做精确比较，最稳。
 */
function table_columns(string $table, bool $refresh = false): array
{
    static $cache = array();
    $t = str_replace('`', '', $table);
    if ($refresh) { unset($cache[$t]); }
    if (isset($cache[$t])) { return $cache[$t]; }

    $cols = array();
    try {
        foreach (db_all('SHOW COLUMNS FROM `' . $t . '`') as $row) {
            $vals = array_values((array)$row);
            if (!empty($vals)) { $cols[(string)$vals[0]] = true; }
        }
    } catch (Throwable $e) { /* 表不存在等，视为无列 */ }
    return $cache[$t] = $cols;
}

function column_exists(string $table, string $column, bool $refresh = false): bool
{
    $cols = table_columns($table, $refresh);
    return isset($cols[$column]);
}

function table_exists(string $table): bool
{
    try {
        /* 不用 `SHOW TABLES LIKE ?`：原生预处理下 SHOW 不接受占位符。
           改为取回全部表名精确比对（不做缓存，避免同请求内新建表被漏判）。 */
        $t = str_replace('`', '', $table);
        foreach (db_all('SHOW TABLES') as $row) {
            $vals = array_values((array)$row);
            if (!empty($vals) && (string)$vals[0] === $t) { return true; }
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

function migration_lock_path(): string
{
    return APP_ROOT . '/storage/migrated_' . SCHEMA_VERSION . '.lock';
}

/**
 * 结构体检：与 schema_version 和迁移锁都无关。
 * ------------------------------------------------------------
 * 专门修补一种残局——「版本号已写成 9/10、锁文件也落了，但 ALTER 其实失败了」。
 * 此时 run_migrations() 会被永久短路上，缺的列再也补不回来，
 * 而接口一旦用到那列就是必崩的 500。
 *
 * 因此这里不看版本号，只看真实结构：缺列就补。
 * 每 10 分钟最多跑一次，正常情况下的代价可以忽略。
 */
function ensure_schema(bool $force = false)
{
    $stamp = APP_ROOT . '/storage/schema_checked.txt';
    if (!$force && is_file($stamp) && (time() - (int)@file_get_contents($stamp)) < 600) { return; }
    @file_put_contents($stamp, (string)time(), LOCK_EX);

    /* 各版本迁移曾经新增过的关键列，全部在此兜底。
       判据只看「列在不在」，与 schema_version 一刀两断。 */
    $need = array(
        'users' => array(
            'role' => "ENUM('user','admin','subadmin') NOT NULL DEFAULT 'user'",
            'uid8' => "CHAR(8) NULL COMMENT '8位可逆UID'",
            'ban_until' => 'DATETIME NULL',
        ),
        'messages' => array(
            'msg_type'    => "VARCHAR(12) NOT NULL DEFAULT 'text'",
            'media_url'   => "VARCHAR(255) NOT NULL DEFAULT ''",
            'is_recalled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ),
        'comments' => array(
            'content_norm' => "CHAR(64) NULL COMMENT '内容规整指纹'",
            'is_deleted'   => 'TINYINT(1) NOT NULL DEFAULT 0',
            'deleted_by'   => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_blocked'   => 'TINYINT(1) NOT NULL DEFAULT 0',
            'target_type'  => "ENUM('work','discipline','discipline_list') NOT NULL DEFAULT 'work'",
        ),
        'feedback' => array(
            'content_norm' => "CHAR(64) NULL COMMENT '内容规整指纹'",
            'is_public'    => 'TINYINT(1) NOT NULL DEFAULT 1',
            'is_deleted'   => 'TINYINT(1) NOT NULL DEFAULT 0',
            'admin_reply'  => 'TEXT NULL',
            'replied_at'   => 'DATETIME NULL',
        ),
        'works' => array(
            'vote_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'peak_score' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ),
        'user_visits' => array(
            'country'  => "VARCHAR(32) NOT NULL DEFAULT ''",
            'province' => "VARCHAR(32) NOT NULL DEFAULT ''",
            'city'     => "VARCHAR(32) NOT NULL DEFAULT ''",
            'ip_hash'  => "CHAR(64) NOT NULL DEFAULT ''",
        ),
        'ai_usage' => array(
            'provider' => "VARCHAR(12) NOT NULL DEFAULT ''",
        ),
    );

    $fixed = array();
    foreach ($need as $t => $cols) {
        if (!table_exists($t)) { continue; }
        foreach ($cols as $c => $ddl) {
            if (column_exists($t, $c)) { continue; }
            try {
                db_exec('ALTER TABLE `' . $t . '` ADD COLUMN `' . $c . '` ' . $ddl);
                table_columns($t, true);   // 结构已变，立刻刷新缓存，否则后续判断仍按旧结构走
                $fixed[] = $t . '.' . $c;
            } catch (Throwable $e) {
                /* 共享主机上 ALTER 可能因锁等待失败，留着下次再试 */
                app_log('ensure_schema failed ' . $t . '.' . $c . ': ' . $e->getMessage());
                return;
            }
        }
    }
    /* 独立库：管理员凭据的慢哈希列 */
    try {
        if (db_admin_val('SHOW TABLES LIKE "admin_credentials"') !== null
            && !db_admin_col_exists('admin_credentials', 'secret_hash')) {
            db_admin_exec('ALTER TABLE `admin_credentials` ADD COLUMN `secret_hash` VARCHAR(255) NULL');
            $fixed[] = 'admin_credentials.secret_hash';
        }
    } catch (Throwable $e) {
        app_log('ensure_schema (admin db) failed: ' . $e->getMessage());
    }

    if ($fixed) { app_log('ensure_schema fixed: ' . implode(', ', $fixed)); }

    /* 指纹列刚补上时历史行还没有指纹，限时回填，剩余的下次继续。
       用 refresh 读取，确保看到的是上面 ALTER 之后的结构。 */
    foreach (array('comments', 'feedback') as $t) {
        if (!table_exists($t) || !column_exists($t, 'content_norm', true)) { continue; }
        $deadline = time() + 5;
        for ($i = 0; $i < 40 && time() < $deadline; $i++) {
            $rows = db_all(
                'SELECT id, content FROM `' . $t . "` WHERE content_norm IS NULL AND content <> '' LIMIT 300"
            );
            if (empty($rows)) { break; }
            foreach ($rows as $r) {
                db_exec(
                    'UPDATE `' . $t . '` SET content_norm = ? WHERE id = ?',
                    array(dup_content_norm((string)$r['content']), (int)$r['id'])
                );
            }
        }
        $idx = ($t === 'comments') ? 'idx_cm_user_norm' : 'idx_fb_user_norm';
        try {
            db_exec('ALTER TABLE `' . $t . '` ADD INDEX `' . $idx . '` (`user_id`, `content_norm`)');
        } catch (Throwable $e) { /* 索引已存在 */ }
    }
}

function run_migrations(bool $force = false)
{
    /* 结构体检必须在锁短路之前：锁只代表「迁移跑过」，
       不代表「结构真的建成了」。强制模式下连 10 分钟限流也一并绕过。 */
    ensure_schema($force);

    /* 文件锁短路：迁移完成标记落在文件系统上。
       若仅依赖 settings 表，一旦该表异常就会「每次请求都重跑全部建表/改表检查」，
       在共享主机上足以拖慢到前端超时（表现为「网络连接不稳定」）。 */
    if (!$force && is_file(migration_lock_path())) { return; }

    $cur = (int)setting_get('schema_version', '1');
    if ($cur >= SCHEMA_VERSION && !$force) {
        @file_put_contents(migration_lock_path(), gmdate('c') . ' (skip)');
        return;
    }

    /* ---------- v2：世界对话支持图片消息 ---------- */
    if ($cur < 2) {
        if (table_exists('messages')) {
            if (!column_exists('messages', 'msg_type')) {
                db_exec("ALTER TABLE `messages` ADD COLUMN `msg_type` VARCHAR(12) NOT NULL DEFAULT 'text' AFTER `content`");
            }
            if (!column_exists('messages', 'media_url')) {
                db_exec("ALTER TABLE `messages` ADD COLUMN `media_url` VARCHAR(255) NOT NULL DEFAULT '' AFTER `msg_type`");
            }
        }
        setting_set('schema_version', '2');
        app_log('schema migrated to v2（messages.msg_type / media_url）');
    }

    /* ---------- v3：管理员用户行（供评论/对话落库）+ 副管理员表 ---------- */
    if ($cur < 3) {
        try {
            $row = db_one('SELECT id FROM users WHERE username_norm = ? LIMIT 1', array('admin'));
            if ($row === null) {
                $ts = now_utc();
                $uid = db_insert(
                    'INSERT INTO users (username, username_norm, password_hash, salt, registered_at, settings, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    array('admin', 'admin', password_chain(rand_hex(16), $ts, 'admin'), password_make_salt(), $ts,
                          json_encode(default_user_settings()), $ts)
                );
                setting_set('admin_user_id', (string)$uid);
                app_log('created admin user row id=' . $uid);
            } else {
                setting_set('admin_user_id', (string)(int)$row['id']);
            }
        } catch (Throwable $e) {
            app_log('migrate v3 admin row failed: ' . $e->getMessage());
        }
        try {
            db_admin()->exec('CREATE TABLE IF NOT EXISTS sub_admins (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(64) NOT NULL,
                username_norm VARCHAR(64) NOT NULL,
                secret_plaintext VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY uk_sub_norm (username_norm)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } catch (Throwable $e) {
            app_log('migrate v3 sub_admins failed: ' . $e->getMessage());
        }
        setting_set('schema_version', '3');
        app_log('schema migrated to v3（admin 用户行 / sub_admins）');
    }

    /* ---------- v4：users.role（徽章/权限）+ sub_admins.user_id ---------- */
    if ($cur < 4) {
        try {
            if (!db_col_exists('users', 'role')) {
                db_exec("ALTER TABLE users ADD COLUMN role ENUM('user','admin','subadmin') NOT NULL DEFAULT 'user' AFTER username_norm");
            }
            if (admin_uid() > 0) { db_exec("UPDATE users SET role = 'admin' WHERE id = ?", array(admin_uid())); }
        } catch (Throwable $e) {
            app_log('migrate v4 users.role failed: ' . $e->getMessage());
        }
        try {
            /* 幂等：确保表存在（v3 建表可能失败） */
            db_admin_exec('CREATE TABLE IF NOT EXISTS sub_admins (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(64) NOT NULL,
                username_norm VARCHAR(64) NOT NULL,
                user_id INT UNSIGNED NOT NULL DEFAULT 0,
                secret_plaintext VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY uk_sub_norm (username_norm)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            if (!db_admin_col_exists('sub_admins', 'user_id')) {
                db_admin_exec('ALTER TABLE sub_admins ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER username_norm');
            }
            if (!db_col_exists('users', 'role')) {
                db_exec("ALTER TABLE users ADD COLUMN role ENUM('user','admin','subadmin') NOT NULL DEFAULT 'user' AFTER username_norm");
            }
        } catch (Throwable $e) {
            app_log('migrate v4 sub_admins/user.role failed: ' . $e->getMessage());
        }
        setting_set('schema_version', '4');
        app_log('schema migrated to v4（role 列 / sub_admins.user_id）');
    }

    /* ---------- v5：8 位可逆 UID（uid8） ---------- */
    if ($cur < 5) {
        try {
            if (!db_col_exists('users', 'uid8')) {
                db_exec('ALTER TABLE users ADD COLUMN uid8 CHAR(8) NULL COMMENT \'8位可逆UID\' AFTER role');
            }
            if (!db_index_exists('users', 'uk_uid8')) {
                db_exec('ALTER TABLE users ADD UNIQUE KEY uk_uid8 (uid8)');
            }
            /* 回填：管理员 → 88888888；副管理员 → 豹子号池；普通用户 → 仿射计算 */
            $admins = db_all("SELECT id, role, uid8 FROM users WHERE role = 'admin' ORDER BY id ASC");
            foreach ($admins as $a) {
                if ((string)$a['uid8'] !== UID_ADMIN) {
                    db_exec('UPDATE users SET uid8 = ? WHERE id = ?', array(UID_ADMIN, (int)$a['id']));
                }
            }
            $subs = db_all("SELECT id, username, uid8 FROM users WHERE role = 'subadmin' ORDER BY id ASC");
            $pool = uid_babao_pool();
            foreach ($subs as $i => $sb) {
                $want = isset($pool[$i]) ? $pool[$i] : '';
                if ($want !== '' && (string)$sb['uid8'] !== $want && !uid_taken($want, (int)$sb['id'])) {
                    db_exec('UPDATE users SET uid8 = ? WHERE id = ?', array($want, (int)$sb['id']));
                } elseif ((string)$sb['uid8'] === '') {
                    db_exec('UPDATE users SET uid8 = ? WHERE id = ?', array(uid_assign((int)$sb['id'], 'subadmin'), (int)$sb['id']));
                }
            }
            $plain = db_all("SELECT id, uid8 FROM users WHERE role = 'user' OR role IS NULL ORDER BY id ASC");
            foreach ($plain as $u) {
                if ((string)$u['uid8'] !== '') { continue; }
                db_exec('UPDATE users SET uid8 = ? WHERE id = ?', array(uid_assign((int)$u['id'], 'user'), (int)$u['id']));
            }
        } catch (Throwable $e) {
            app_log('migrate v5 uid8 failed: ' . $e->getMessage());
        }
        setting_set('schema_version', '5');
        app_log('schema migrated to v5（8 位可逆 UID）');
    }

    /* ---------- v6：表结构自愈（修复建表 SQL 部分执行被静默中断导致的缺表） ---------- */
    if ($cur < 6) {
        try {
            $schema = json_decode((string)file_get_contents(APP_ROOT . '/app/schema.json'), true);
            if (is_array($schema)) {
                $created = array(); $failed = array();

                foreach ((array)($schema['main'] ?? array()) as $name => $ddl) {
                    try {
                        if (db_val('SHOW TABLES LIKE "' . $name . '"') === null) {
                            db_exec((string)$ddl);
                            $created[] = $name;
                        }
                    } catch (Throwable $e) { $failed[] = $name . ': ' . $e->getMessage(); }
                }
                foreach ((array)($schema['admin'] ?? array()) as $name => $ddl) {
                    try {
                        if (db_admin_val('SHOW TABLES LIKE "' . $name . '"') === null) {
                            db_admin_exec((string)$ddl);
                            $created[] = 'admin.' . $name;
                        }
                    } catch (Throwable $e) { $failed[] = 'admin.' . $name . ': ' . $e->getMessage(); }
                }

                /* 关键列兜底（v4/v5 若中途失败，这里补齐） */
                try {
                    if (!db_col_exists('users', 'role')) {
                        db_exec("ALTER TABLE users ADD COLUMN role ENUM('user','admin','subadmin') NOT NULL DEFAULT 'user' AFTER username_norm");
                    }
                    if (!db_col_exists('users', 'uid8')) {
                        db_exec("ALTER TABLE users ADD COLUMN uid8 CHAR(8) NULL COMMENT '8位可逆UID' AFTER role");
                    }
                    foreach (array('msg_type' => "VARCHAR(12) NOT NULL DEFAULT 'text'",
                                   'media_url' => "VARCHAR(255) NOT NULL DEFAULT ''",
                                   'is_recalled' => 'TINYINT(1) NOT NULL DEFAULT 0') as $col => $def) {
                        if (!db_col_exists('messages', $col)) {
                            db_exec('ALTER TABLE messages ADD COLUMN ' . $col . ' ' . $def);
                        }
                    }
                } catch (Throwable $e) { $failed[] = 'columns: ' . $e->getMessage(); }

                if ($created) { app_log('v6 self-heal created tables: ' . implode(',', $created)); }
                if ($failed)  { app_log('v6 self-heal failures: ' . implode(' | ', $failed)); }
                else          { app_log('schema migrated to v6（表结构自愈完成）'); }
            }
            setting_set('schema_version', '6');

        } catch (Throwable $e) {
            app_log('migrate v6 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v7：每日签到表（AI 额度加成） ---------- */
    if ($cur < 7) {
        try {
            if (!table_exists('checkins')) {
                db_exec("CREATE TABLE IF NOT EXISTS `checkins` ( `user_id` INT UNSIGNED NOT NULL, `checkin_date` DATE NOT NULL, `streak_after` INT UNSIGNED NOT NULL DEFAULT 1, `created_at` DATETIME NOT NULL, PRIMARY KEY (`user_id`, `checkin_date`), KEY `idx_checkins_month` (`user_id`, `checkin_date`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日签到'");
                app_log('v7 created table: checkins');
            }
            app_log('schema migrated to v7');
            setting_set('schema_version', '7');

        } catch (Throwable $e) {
            app_log('migrate v7 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v8：反馈表的回复列 ----------
       此前代码只写了 UPDATE feedback SET admin_reply / replied_at，
       但这两列从未被创建过，导致管理员回复反馈必定报错（表现为「无法回复」）。 */
    if ($cur < 8) {
        try {
            if (!table_exists('feedback')) {
                db_exec("CREATE TABLE IF NOT EXISTS `feedback` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT UNSIGNED NOT NULL,
                    `content` TEXT NOT NULL,
                    `is_public` TINYINT(1) NOT NULL DEFAULT 1,
                    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
                    `admin_reply` TEXT NULL,
                    `replied_at` DATETIME NULL,
                    `created_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_fb_user` (`user_id`),
                    KEY `idx_fb_time` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户反馈'");
                app_log('v8 created table: feedback');
            } else {
                /* 先补基础列，再加回复列（回复列要用 AFTER 引用它们） */
                if (!column_exists('feedback', 'is_public')) {
                    db_exec("ALTER TABLE `feedback` ADD COLUMN `is_public` TINYINT(1) NOT NULL DEFAULT 1");
                    app_log('v8 added feedback.is_public');
                }
                if (!column_exists('feedback', 'is_deleted')) {
                    db_exec("ALTER TABLE `feedback` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0");
                    app_log('v8 added feedback.is_deleted');
                }
                if (!column_exists('feedback', 'admin_reply')) {
                    db_exec("ALTER TABLE `feedback` ADD COLUMN `admin_reply` TEXT NULL AFTER `is_deleted`");
                    app_log('v8 added feedback.admin_reply');
                }
                if (!column_exists('feedback', 'replied_at')) {
                    db_exec("ALTER TABLE `feedback` ADD COLUMN `replied_at` DATETIME NULL AFTER `admin_reply`");
                    app_log('v8 added feedback.replied_at');
                }
            }
            app_log('schema migrated to v8（feedback 回复列就绪）');
            setting_set('schema_version', '8');

        } catch (Throwable $e) {
            app_log('migrate v8 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v9：防重复指纹列 ----------
       评论与反馈改为「同一用户不得重复发相同内容」（不限时间窗口），
       需要按内容指纹比对。规整口径与用户名唯一性完全一致，
       因此存的是 norm_username() 后的 sha256。 */
    if ($cur < 9) {
        try {
            foreach (array('comments', 'feedback') as $t) {
                if (!table_exists($t)) { continue; }
                if (!column_exists($t, 'content_norm')) {
                    db_exec("ALTER TABLE `" . $t . "` ADD COLUMN `content_norm` CHAR(64) NULL COMMENT '内容规整指纹'");
                    app_log('v9 added ' . $t . '.content_norm');
                }
                /* 回填历史内容：分批处理，避免共享主机单次请求超时 */
                $guard = 0;
                while ($guard < 40) {
                    $guard++;
                    $rows = db_all("SELECT id, content FROM `" . $t . "` WHERE content_norm IS NULL AND content <> '' LIMIT 300");
                    if (empty($rows)) { break; }
                    foreach ($rows as $r) {
                        db_exec("UPDATE `" . $t . "` SET content_norm = ? WHERE id = ?",
                            array(dup_content_norm((string)$r['content']), (int)$r['id']));
                    }
                }
            }
            /* 索引：加速比对（同人 + 同指纹） */
            $idxMap = array('comments' => 'idx_cm_user_norm', 'feedback' => 'idx_fb_user_norm');
            foreach ($idxMap as $t => $idx) {
                if (!table_exists($t)) { continue; }
                try {
                    db_exec("ALTER TABLE `" . $t . "` ADD INDEX `" . $idx . "` (`user_id`, `content_norm`)");
                    app_log('v9 added index ' . $idx);
                } catch (Throwable $e) { /* 索引已存在则忽略 */ }
            }
            app_log('schema migrated to v9（防重复指纹就绪）');
            setting_set('schema_version', '9');

        } catch (Throwable $e) {
            app_log('migrate v9 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v11：管理员凭据改存加盐慢哈希 ----------
       此前 admin_credentials 直接保存密钥原文，库一旦被拖走密钥即暴露。
       现在：凭据（原文的 sha256，64 位 hex）经加盐慢哈希后存储，并清掉明文列。
       登录方式不变——输入的仍是那串 64 位 hex，属于无感升级。

       顺序至关重要：先把哈希写进去、确认写成功，再清明文。
       反过来的话，一旦中途出错就把管理员永久锁在门外。 */
    if ($cur < 11) {
        try {
            if (db_admin_val('SHOW TABLES LIKE "admin_credentials"') !== null) {
                if (!db_admin_col_exists('admin_credentials', 'secret_hash')) {
                    db_admin_exec('ALTER TABLE `admin_credentials` ADD COLUMN `secret_hash` VARCHAR(255) NULL COMMENT \'凭据的加盐慢哈希\'');
                    app_log('v11 added admin_credentials.secret_hash');
                }

                /* 回填：原文 → 凭据 → 加盐慢哈希 */
                $rows = db_admin_all(
                    'SELECT id, secret_plaintext FROM admin_credentials
                     WHERE (secret_hash IS NULL OR secret_hash = \'\') AND secret_plaintext <> \'\''
                );
                foreach ($rows as $r) {
                    $cred = admin_credential_of((string)$r['secret_plaintext']);
                    db_admin_exec(
                        'UPDATE admin_credentials SET secret_hash = ? WHERE id = ?',
                        array(password_hash($cred, PASSWORD_DEFAULT), (int)$r['id'])
                    );
                }

                /* 确认哈希确实落库，才敢清明文 */
                $done = (int)db_admin_val(
                    'SELECT COUNT(*) FROM admin_credentials WHERE secret_hash IS NOT NULL AND secret_hash <> \'\''
                );
                if ($done > 0) {
                    db_admin_exec("UPDATE admin_credentials SET secret_plaintext = '' WHERE secret_hash IS NOT NULL AND secret_hash <> ''");
                    app_log('v11 admin plaintext cleared（凭据已转为慢哈希）');
                } else {
                    app_log('v11 skipped: hash not written, plaintext kept');
                }
            }
            app_log('schema migrated to v11（管理员凭据慢哈希）');
            setting_set('schema_version', '11');
        } catch (Throwable $e) {
            app_log('migrate v11 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v10：榜单排序物化 + 索引 ----------
       此前「高手榜 / 人气榜」用相关子查询算出排序值再排序：
         SELECT ..., (SELECT MAX(total_score) FROM ...) AS peak ORDER BY peak DESC
       每行都要执行一次子查询，而且计算列无法建索引，作品一多就明显变慢。
       这里把两个排序值物化成真实列，写入时维护，排序便能走索引。 */
    if ($cur < 10) {
        try {
            if (table_exists('works')) {
                if (!column_exists('works', 'vote_count')) {
                    db_exec("ALTER TABLE `works` ADD COLUMN `vote_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '点赞数（冗余，便于排序）'");
                    app_log('v10 added works.vote_count');
                }
                if (!column_exists('works', 'peak_score')) {
                    db_exec("ALTER TABLE `works` ADD COLUMN `peak_score` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '历史最高总分（冗余，便于排序）'");
                    app_log('v10 added works.peak_score');
                }

                /* 回填历史数据 */
                if (table_exists('work_votes')) {
                    db_exec("UPDATE `works` w SET w.vote_count = (SELECT COUNT(*) FROM `work_votes` v WHERE v.work_id = w.id)");
                }
                if (table_exists('work_score_history')) {
                    db_exec("UPDATE `works` w SET w.peak_score = (SELECT COALESCE(MAX(h.total_score), 0) FROM `work_score_history` h WHERE h.work_id = w.id)");
                }
                db_exec("UPDATE `works` SET peak_score = total_score WHERE peak_score = 0 AND total_score > 0");
                app_log('v10 backfilled vote_count / peak_score');

                /* 榜单排序用索引：WHERE is_hidden = 0 + ORDER BY 排序键 */
                $idx = array(
                    'idx_w_total' => '(`is_hidden`, `total_score`)',
                    'idx_w_heat'  => '(`is_hidden`, `heat_score`)',
                    'idx_w_peak'  => '(`is_hidden`, `peak_score`)',
                    'idx_w_vote'  => '(`is_hidden`, `vote_count`)',
                );
                foreach ($idx as $name => $cols) {
                    try {
                        db_exec("ALTER TABLE `works` ADD INDEX `" . $name . "` " . $cols);
                        app_log('v10 added index ' . $name);
                    } catch (Throwable $e) { /* 已存在则忽略 */ }
                }
            }

            /* 关联表的索引：子查询与列表查询都用得上 */
            $rel = array(
                'work_score_history' => array('idx_wsh_work' => '(`work_id`)'),
                'work_votes'         => array('idx_wv_work' => '(`work_id`)'),
                'comments'           => array('idx_cm_work' => '(`work_id`, `is_deleted`)'),
                'ai_messages'        => array('idx_ai_user' => '(`user_id`, `id`)'),
            );
            foreach ($rel as $t => $idxs) {
                if (!table_exists($t)) { continue; }
                foreach ($idxs as $name => $cols) {
                    try {
                        db_exec("ALTER TABLE `" . $t . "` ADD INDEX `" . $name . "` " . $cols);
                        app_log('v10 added index ' . $name);
                    } catch (Throwable $e) { /* 已存在则忽略 */ }
                }
            }
            app_log('schema migrated to v10（榜单排序物化与索引就绪）');
            setting_set('schema_version', '10');

        } catch (Throwable $e) {
            app_log('migrate v10 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v12：OpenRouter 免费模型 + 两级配额 ----------
       三张新表，全部 CREATE TABLE IF NOT EXISTS，天然幂等：
         or_models      —— 上游免费文本模型缓存（列表接口结果）
         or_state       —— 单行状态：每日刷新日期、上游额度快照、全站本地计数
         ai_daily_quota —— 每人每日调用次数（原子自增，跨天自动归零） */
    if ($cur < 12) {
        try {
            db_exec("CREATE TABLE IF NOT EXISTS `or_models` (
                `model_id`       VARCHAR(160) NOT NULL,
                `name`           VARCHAR(160) NOT NULL DEFAULT '',
                `context_length` INT UNSIGNED NOT NULL DEFAULT 0,
                `params`         TEXT NULL COMMENT 'supported_parameters JSON',
                `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
                `fetched_at`     DATETIME NOT NULL,
                PRIMARY KEY (`model_id`),
                KEY `idx_orm_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OpenRouter 免费文本模型'");

            db_exec("CREATE TABLE IF NOT EXISTS `or_state` (
                `id`             TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `refresh_date`   CHAR(10) NOT NULL DEFAULT '' COMMENT 'UTC 日期 Y-m-d',
                `api_used`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上游已用次数',
                `api_limit`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上游每日上限',
                `api_checked_at` DATETIME NULL,
                `site_day`       CHAR(10) NOT NULL DEFAULT '' COMMENT '本地计数所属 UTC 日',
                `site_used`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本地全站已用次数',
                `updated_at`     DATETIME NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OpenRouter 状态与全站配额'");

            db_exec("CREATE TABLE IF NOT EXISTS `ai_daily_quota` (
                `user_id`  INT UNSIGNED NOT NULL,
                `day`      DATE NOT NULL,
                `used`     INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`user_id`, `day`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每人每日 AI 调用次数'");

            app_log('schema migrated to v12（OpenRouter 模型与配额表就绪）');
            setting_set('schema_version', '12');
        } catch (Throwable $e) {
            app_log('migrate v12 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v13：AI 问答缓存 ----------
       无上下文的独立提问命中缓存时直接沿用，省一次模型调用。 */
    if ($cur < 13) {
        try {
            db_exec("CREATE TABLE IF NOT EXISTS `ai_answer_cache` (
                `q_norm`     CHAR(64) NOT NULL COMMENT '提问规整指纹',
                `question`   VARCHAR(500) NOT NULL DEFAULT '',
                `answer`     MEDIUMTEXT NOT NULL,
                `model`      VARCHAR(160) NOT NULL DEFAULT '',
                `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`q_norm`),
                KEY `idx_cache_hits` (`hits`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 问答缓存'");
            app_log('schema migrated to v13（AI 问答缓存就绪）');
            setting_set('schema_version', '13');
        } catch (Throwable $e) {
            app_log('migrate v13 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v14：访问归属地结构化 + 评论屏蔽状态 ----------
       归属地改为「国家 / 省份 / 城市」三个独立列存储与统计，历史记录按缓存回填；
       评论新增 is_blocked（屏蔽后折叠），与 is_deleted（删除入回收站）区分开。 */
    if ($cur < 14) {
        try {
            if (table_exists('user_visits')) {
                foreach (array(
                    'country'  => "VARCHAR(32) NOT NULL DEFAULT ''",
                    'province' => "VARCHAR(32) NOT NULL DEFAULT ''",
                    'city'     => "VARCHAR(32) NOT NULL DEFAULT ''",
                ) as $c => $ddl) {
                    if (!column_exists('user_visits', $c)) {
                        db_exec('ALTER TABLE `user_visits` ADD COLUMN `' . $c . '` ' . $ddl);
                    }
                }
                table_columns('user_visits', true);
            }
            if (table_exists('comments') && !column_exists('comments', 'is_blocked')) {
                db_exec("ALTER TABLE `comments` ADD COLUMN `is_blocked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_deleted`");
                table_columns('comments', true);
            }
            $filled = function_exists('visits_backfill') ? visits_backfill() : 0;
            app_log('schema migrated to v14（访问归属地结构化，回填 ' . $filled . ' 条）');
            setting_set('schema_version', '14');
        } catch (Throwable $e) {
            app_log('migrate v14 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v15：AI 用量按通道分账 ----------
       同一张 ai_usage 原先把「本站模型（智谱）」与「模型网关（免费模型）」混在一起，
       控制面板因此无法分开统计。新增 provider 列：glm / gateway / ''（本列之前的旧记录）。 */
    if ($cur < 15) {
        try {
            if (table_exists('ai_usage') && !column_exists('ai_usage', 'provider')) {
                db_exec("ALTER TABLE `ai_usage` ADD COLUMN `provider` VARCHAR(12) NOT NULL DEFAULT '' COMMENT 'glm=本站模型 gateway=模型网关 空=早期未区分' AFTER `user_id`");
                table_columns('ai_usage', true);
            }
            app_log('schema migrated to v15（AI 用量按通道分账）');
            setting_set('schema_version', '15');
        } catch (Throwable $e) {
            app_log('migrate v15 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v16：AI 重审的标注 ----------
       被审核拦下后，用户可点「AI 重审」，模型给出 true / middle / false 三档。
       判 middle（可能有恶意）时仍然放行，但要在消息旁标注提醒 ——
       这个结论必须落在行上，只放缓存会在过期后无声丢失。 */
    if ($cur < 16) {
        try {
            foreach (array('messages', 'comments') as $t) {
                if (table_exists($t) && !column_exists($t, 'review_flag')) {
                    db_exec("ALTER TABLE `" . $t . "` ADD COLUMN `review_flag` VARCHAR(12) NOT NULL DEFAULT '' COMMENT 'AI 重审标注：空=正常 middle=可能有恶意'");
                    table_columns($t, true);
                }
            }
            setting_set('schema_version', '16');
            app_log('schema migrated to v16（AI 重审标注）');
        } catch (Throwable $e) {
            app_log('migrate v16 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v17：违纪通报 ----------
       管理员可对用户一键通报（可写多条理由）并封停账号与访问 IP；
       被通报者访问站点时跳转到违纪界面，界面下方列出理由，并可像作品一样评论。
       IP 只存 sha256(盐+IP) 指纹用于匹配，不保留明文。 */
    if ($cur < 17) {
        try {
            db_exec("CREATE TABLE IF NOT EXISTS `discipline_reports` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`     INT UNSIGNED NOT NULL COMMENT '被通报用户',
                `username`    VARCHAR(64) NOT NULL DEFAULT '' COMMENT '用户名快照',
                `reasons`     TEXT NOT NULL COMMENT '通报理由（JSON 数组，可多条）',
                `note`        TEXT NULL COMMENT '补充说明',
                `banned`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否同时封停账号',
                `ban_days`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '封禁天数，0=永久',
                `ban_until`   DATETIME NULL COMMENT '解封时间，NULL=永久',
                `purged`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已清理其内容',
                `ip_banned`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否封禁访问 IP',
                `by_uid`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作管理员',
                `views`       INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`  DATETIME NOT NULL,
                PRIMARY KEY (`id`), KEY `idx_user` (`user_id`), KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='违纪通报'");

            db_exec("CREATE TABLE IF NOT EXISTS `banned_ips` (
                `ip_hash`    CHAR(64) NOT NULL COMMENT 'sha256(盐+IP)，不可逆指纹',
                `ip_masked`  VARCHAR(64) NOT NULL DEFAULT '' COMMENT '脱敏展示',
                `report_id`  INT UNSIGNED NULL,
                `user_id`    INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`ip_hash`), KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='被封禁的 IP 指纹'");

            if (table_exists('user_visits') && !column_exists('user_visits', 'ip_hash')) {
                db_exec("ALTER TABLE `user_visits` ADD COLUMN `ip_hash` CHAR(64) NOT NULL DEFAULT '' COMMENT 'sha256(盐+IP)，用于封禁匹配'");
                table_columns('user_visits', true);
            }
            if (table_exists('comments') && !column_exists('comments', 'target_type')) {
                db_exec("ALTER TABLE `comments` ADD COLUMN `target_type` ENUM('work','discipline') NOT NULL DEFAULT 'work' COMMENT '评论目标类型'");
                table_columns('comments', true);
            }
            /* 封禁支持设置天数：到期自动解封，NULL 表示永久 */
            if (table_exists('users') && !column_exists('users', 'ban_until')) {
                db_exec("ALTER TABLE `users` ADD COLUMN `ban_until` DATETIME NULL COMMENT '解封时间，NULL 且 is_banned=1 表示永久'");
                table_columns('users', true);
            }
            foreach (array(
                'ban_days'  => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'ban_until' => 'DATETIME NULL',
                'purged'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            ) as $c => $ddl) {
                if (table_exists('discipline_reports') && !column_exists('discipline_reports', $c)) {
                    db_exec('ALTER TABLE `discipline_reports` ADD COLUMN `' . $c . '` ' . $ddl);
                    table_columns('discipline_reports', true);
                }
            }
            setting_set('schema_version', '17');
            app_log('schema migrated to v17（违纪通报）');
        } catch (Throwable $e) {
            app_log('migrate v17 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v18：违纪通报的累计与列表评论区 ----------
       通报列表需要一处统一的评论区（挂在「违纪通报」这个目录上），
       因此把 target_type 扩展出 discipline_list。 */
    if ($cur < 18) {
        try {
            if (table_exists('comments') && column_exists('comments', 'target_type')) {
                db_exec("ALTER TABLE `comments` MODIFY COLUMN `target_type`
                         ENUM('work','discipline','discipline_list') NOT NULL DEFAULT 'work'
                         COMMENT '评论目标类型：work 作品 discipline 单条通报 discipline_list 通报列表'");
                table_columns('comments', true);
            }
            setting_set('schema_version', '18');
            app_log('schema migrated to v18（违纪通报列表评论区）');
        } catch (Throwable $e) {
            app_log('migrate v18 failed: ' . $e->getMessage());
        }
    }

    /* 只有结构确认完整才写版本号、落锁：
       否则锁会把「半成品」永久固定下来，此后所有请求都被短路，再也修不回来。 */
    $ok = true;
    foreach (array('comments', 'feedback') as $t) {
        if (table_exists($t) && !column_exists($t, 'content_norm')) { $ok = false; }
    }
    if (table_exists('comments') && !column_exists('comments', 'is_blocked')) { $ok = false; }
    foreach (array('country', 'province', 'city') as $c) {
        if (table_exists('user_visits') && !column_exists('user_visits', $c)) { $ok = false; }
    }
    foreach (array('or_models', 'or_state', 'ai_daily_quota', 'ai_answer_cache',
                   'discipline_reports', 'banned_ips') as $t) {
        if (!table_exists($t)) { $ok = false; }
    }
    if (table_exists('user_visits') && !column_exists('user_visits', 'ip_hash')) { $ok = false; }
    if (table_exists('users') && !column_exists('users', 'ban_until')) { $ok = false; }
    if (table_exists('discipline_reports') && !column_exists('discipline_reports', 'ban_until')) { $ok = false; }
    if (table_exists('comments') && !column_exists('comments', 'target_type')) { $ok = false; }
    if (table_exists('ai_usage') && !column_exists('ai_usage', 'provider')) { $ok = false; }
    if ($ok) {
        setting_set('schema_version', (string)SCHEMA_VERSION);
        @file_put_contents(migration_lock_path(), gmdate('c'));
    } else {
        app_log('migrations finished but schema incomplete — lock withheld');
    }
}

/**
 * 数据库状态报告（只读）。
 * 供控制面板展示，也用于「一键更新」前后的对照。
 */
function db_report(): array
{
    $need = array(
        'users'    => array('role', 'uid8'),
        'messages' => array('msg_type', 'media_url', 'is_recalled', 'review_flag'),
        'comments' => array('content_norm', 'is_deleted', 'deleted_by', 'is_blocked', 'review_flag'),
        'feedback' => array('content_norm', 'is_public', 'is_deleted', 'admin_reply', 'replied_at'),
        'works'    => array('vote_count', 'peak_score'),
        'user_visits' => array('country', 'province', 'city'),
        'or_models'      => array('model_id', 'name', 'context_length', 'is_active', 'fetched_at'),
        'or_state'       => array('refresh_date', 'api_used', 'api_limit', 'site_day', 'site_used'),
        'ai_daily_quota' => array('user_id', 'day', 'used'),
        'ai_answer_cache'=> array('q_norm', 'question', 'answer', 'hits', 'updated_at'),
        'ai_usage'       => array('user_id', 'provider', 'total_tokens', 'created_at'),
    );

    $missing = array();
    $counted = 0;
    foreach ($need as $t => $cols) {
        if (!table_exists($t)) { $missing[] = $t . '（整张表缺失）'; continue; }
        table_columns($t, true);     // 刷新一次，后面走缓存
        foreach ($cols as $c) {
            $counted++;
            if (!column_exists($t, $c)) { $missing[] = $t . '.' . $c; }
        }
    }

    /* 独立库：管理员凭据 */
    try {
        if (db_admin_val('SHOW TABLES LIKE "admin_credentials"') !== null) {
            $counted++;
            if (!db_admin_col_exists('admin_credentials', 'secret_hash')) {
                $missing[] = 'admin_credentials.secret_hash';
            }
        }
    } catch (Throwable $e) { /* 独立库不可用则跳过 */ }

    $stampFile = APP_ROOT . '/storage/schema_checked.txt';
    return array(
        'current'   => (string)setting_get('schema_version', '1'),
        'expected'  => (string)SCHEMA_VERSION,
        'missing'   => $missing,
        'checked'   => $counted,
        'healthy'   => empty($missing),
        'locked'    => is_file(migration_lock_path()),
        'last_run'  => is_file($stampFile)
            ? date('Y-m-d H:i:s', (int)@file_get_contents($stampFile)) : '',
    );
}
