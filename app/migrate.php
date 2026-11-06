<?php
/**
 * 数据库自动迁移
 * ------------------------------------------------------------
 * 基于 settings.schema_version 逐版本升级，幂等安全；
 * 每次请求（bootstrap 内）自动检查，首次访问即完成升级。
 */
declare(strict_types=1);

define('SCHEMA_VERSION', 36);

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
            'link_checked_at' => 'DATETIME NULL',
            'feat' => 'TEXT NULL',
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
                `ban_days`    DECIMAL(7,2) NOT NULL DEFAULT 0 COMMENT '封禁天数（支持小数），0=永久',
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
                'ban_days'  => 'DECIMAL(7,2) NOT NULL DEFAULT 0',
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

    /* ---------- v19：封禁时长支持小数 ----------
       0.5 天、1.5 天这类「小时级冷静期」很常用，整数天粒度太粗。
       把 ban_days 从 INT 改为 DECIMAL(7,2)（0.01–99999.99 天），
       存量整数数据在类型转换中原值保持，不需要回填。 */
    if ($cur < 19) {
        try {
            if (table_exists('discipline_reports') && column_exists('discipline_reports', 'ban_days')) {
                db_exec("ALTER TABLE `discipline_reports` MODIFY `ban_days` DECIMAL(7,2) NOT NULL DEFAULT 0 COMMENT '封禁天数（支持小数），0=永久'");
                table_columns('discipline_reports', true);
            }
            setting_set('schema_version', '19');
            app_log('schema migrated to v19（封禁时长支持小数）');
        } catch (Throwable $e) {
            app_log('migrate v19 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v20：登录限速补账号维度 ----------
       原表只记 IP，换个代理就是新身份。加 user_norm 记被尝试的账号，
       让「同一账号被跨 IP 连续试探」也能累计到锁定阈值。
       存量行 user_norm 留空（空串不计入账号维度），不影响 IP 维度判定。 */
    if ($cur < 20) {
        try {
            if (table_exists('login_attempts') && !column_exists('login_attempts', 'user_norm')) {
                db_exec("ALTER TABLE `login_attempts`
                         ADD COLUMN `user_norm` VARCHAR(191) NOT NULL DEFAULT ''
                         COMMENT '被尝试的账号规整名' AFTER `ip_hash`,
                         ADD KEY `idx_norm_time` (`user_norm`, `created_at`)");
                table_columns('login_attempts', true);
            }
            setting_set('schema_version', '20');
            app_log('schema migrated to v20（登录限速账号维度）');
        } catch (Throwable $e) {
            app_log('migrate v20 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v21：站点互通（peers / sync_ids / sync_tombs）----------
       互通不碰任何内容表：跨站行标识走侧表 sync_ids（table_name+local_id → gid），
       真删走墓碑 sync_tombs。只新增三张空表，风险为零。 */
    if ($cur < 21) {
        try {
            db_exec("CREATE TABLE IF NOT EXISTS `peers` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(64) NOT NULL DEFAULT '',
                `base_url` VARCHAR(255) NOT NULL DEFAULT '',
                `pubkey` CHAR(64) NOT NULL DEFAULT '',
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `last_sync_at` DATETIME NULL,
                `last_status` VARCHAR(255) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`), UNIQUE KEY `uk_peer_url` (`base_url`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db_exec("CREATE TABLE IF NOT EXISTS `sync_ids` (
                `table_name` VARCHAR(32) NOT NULL,
                `local_id` VARCHAR(64) NOT NULL,
                `gid` CHAR(32) NOT NULL,
                `at` DATETIME NOT NULL,
                PRIMARY KEY (`table_name`,`local_id`), UNIQUE KEY `uk_gid` (`gid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db_exec("CREATE TABLE IF NOT EXISTS `sync_tombs` (
                `gid` CHAR(32) NOT NULL,
                `table_name` VARCHAR(32) NOT NULL,
                `at` DATETIME NOT NULL,
                PRIMARY KEY (`gid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            setting_set('schema_version', '21');
            app_log('schema migrated to v21（站点互通）');
        } catch (Throwable $e) {
            app_log('migrate v21 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v22：违纪通报的「反篡改」计数 ----------
       有人用油猴脚本把全屏封禁说明删掉来绕过限制。前端检测到之后会把「第几次」上报给后端：
       前三次只提醒，第四次起每次把封禁时间延长 0.05 天。 */
    if ($cur < 22) {
        try {
            if (table_exists('discipline_reports')) {
                if (!column_exists('discipline_reports', 'tamper_count')) {
                    db_exec("ALTER TABLE `discipline_reports` ADD COLUMN `tamper_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '检测到试图绕过封禁说明的次数'");
                    app_log('v22 added discipline_reports.tamper_count');
                }
                if (!column_exists('discipline_reports', 'tamper_at')) {
                    db_exec("ALTER TABLE `discipline_reports` ADD COLUMN `tamper_at` DATETIME NULL COMMENT '最近一次反篡改上报时间'");
                    app_log('v22 added discipline_reports.tamper_at');
                }
                table_columns('discipline_reports', true);
            }
            app_log('schema migrated to v22（违纪通报反篡改计数）');
            setting_set('schema_version', '22');
        } catch (Throwable $e) {
            app_log('migrate v22 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v23：全站对象编号 ----------
       评论 / 违纪通报 / 世界对话 / AI 对话 / 作品 / 反馈 / 公告 各分配两位类型码；
       编号 = 4 位年 + 2 位月 + 2 位日 + 2 位类型码 + 4 位随机数（共 14 位）。
       历史数据按其创建日期回填，回填完成后落唯一索引。 */
    if ($cur < 23) {
        try {
            $oidTables = oid_target_tables();
            foreach ($oidTables as $t) {
                if (table_exists($t) && !column_exists($t, 'oid')) {
                    db_exec("ALTER TABLE `" . $t . "` ADD COLUMN `oid` VARCHAR(20) NULL COMMENT '全站对象编号（14 位）' AFTER `id`");
                    table_columns($t, true);
                }
            }

            $filled = oid_backfill($oidTables);

            foreach ($oidTables as $t) {
                if (table_exists($t) && column_exists($t, 'oid') && !db_index_exists($t, 'uk_oid')) {
                    db_exec("ALTER TABLE `" . $t . "` ADD UNIQUE KEY `uk_oid` (`oid`)");
                }
            }

            app_log('schema migrated to v23（全站对象编号，回填 ' . $filled . ' 条）');
            setting_set('schema_version', '23');
        } catch (Throwable $e) {
            app_log('migrate v23 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v24：网页异常监测 ----------
       前端 SDK 上报的异常/性能/接口事件明细，以及聚合出的告警记录。
       明细属高频临时数据，不纳入全站编号体系。 */
    if ($cur < 24) {
        try {
            db_exec("CREATE TABLE IF NOT EXISTS `web_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kind` VARCHAR(16) NOT NULL DEFAULT 'js' COMMENT 'js|api|resource|perf|pv|custom',
                `level` VARCHAR(8) NOT NULL DEFAULT 'error' COMMENT 'info|warn|error',
                `name` VARCHAR(191) NOT NULL DEFAULT '' COMMENT '事件名（页面/接口/自定义）',
                `page` VARCHAR(191) NOT NULL DEFAULT '',
                `msg` VARCHAR(255) NOT NULL DEFAULT '',
                `stack` TEXT NULL,
                `v1` INT NOT NULL DEFAULT 0 COMMENT '耗时 ms / 数值',
                `v2` INT NOT NULL DEFAULT 0 COMMENT '状态码 / 辅助值',
                `status` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'ok|fail|timeout',
                `browser` VARCHAR(32) NOT NULL DEFAULT '',
                `os` VARCHAR(32) NOT NULL DEFAULT '',
                `screen` VARCHAR(16) NOT NULL DEFAULT '',
                `net` VARCHAR(16) NOT NULL DEFAULT '',
                `sid` VARCHAR(40) NOT NULL DEFAULT '' COMMENT '匿名会话 id',
                `uid` INT UNSIGNED NOT NULL DEFAULT 0,
                `ip_hash` CHAR(64) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_kind_time` (`kind`, `created_at`),
                KEY `idx_name_time` (`name`, `created_at`),
                KEY `idx_sid` (`sid`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前端监测事件'");

            db_exec("CREATE TABLE IF NOT EXISTS `web_alerts` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `rule` VARCHAR(32) NOT NULL DEFAULT '',
                `level` VARCHAR(8) NOT NULL DEFAULT 'warn',
                `message` VARCHAR(255) NOT NULL DEFAULT '',
                `value` INT NOT NULL DEFAULT 0,
                `threshold` INT NOT NULL DEFAULT 0,
                `acked` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='监测告警记录'");

            app_log('schema migrated to v24（网页异常监测）');
            setting_set('schema_version', '24');
        } catch (Throwable $e) {
            app_log('migrate v24 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v25：服务端请求指标（P2） ----------
       每个被抽样的 PHP 请求落一行：路由、总耗时、DB 累计耗时与次数、
       慢查询条数与最慢语句指纹、峰值内存、首个错误。
       属高频临时数据，不纳入全站编号体系。 */
    if ($cur < 25) {
        try {
            db_exec("CREATE TABLE IF NOT EXISTS `web_srv` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `route` VARCHAR(191) NOT NULL DEFAULT '' COMMENT '脚本路由，如 api/works.php',
                `method` VARCHAR(8) NOT NULL DEFAULT '' COMMENT 'GET|POST',
                `code` SMALLINT UNSIGNED NOT NULL DEFAULT 200 COMMENT 'HTTP 状态码',
                `dur_ms` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '请求总耗时 ms',
                `db_ms` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'DB 累计耗时 ms',
                `db_n` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'DB 查询次数',
                `slow_n` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '慢查询条数',
                `slow_sql` VARCHAR(191) NOT NULL DEFAULT '' COMMENT '最慢语句指纹',
                `mem_kb` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '峰值内存 KB',
                `err` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '首个 PHP 错误/异常',
                `uid` INT UNSIGNED NOT NULL DEFAULT 0,
                `sid` VARCHAR(40) NOT NULL DEFAULT '',
                `ip_hash` CHAR(64) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_created` (`created_at`),
                KEY `idx_route_time` (`route`, `created_at`),
                KEY `idx_dur` (`dur_ms`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服务端请求指标'");

            app_log('schema migrated to v25（服务端请求指标）');
            setting_set('schema_version', '25');
        } catch (Throwable $e) {
            app_log('migrate v25 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v26：封禁 IP 的地区锚点 ----------
       同一出口 IP 背后可能是很多人（学校 / 公司 / 运营商 NAT）。
       封禁时记录「首拦者授权定位后的坐标」，之后同 IP 的人若明显不在同一片区域，
       判定为不同的人，予以放行 —— 既挡住当事人，又不牵连同 IP 的其他人。 */
    if ($cur < 26) {
        try {
            if (table_exists('banned_ips') && !column_exists('banned_ips', 'geo_lat')) {
                db_exec("ALTER TABLE `banned_ips` ADD COLUMN `geo_lat` DECIMAL(10,7) NULL COMMENT '地区锚点纬度'");
                db_exec("ALTER TABLE `banned_ips` ADD COLUMN `geo_lng` DECIMAL(10,7) NULL COMMENT '地区锚点经度'");
                db_exec("ALTER TABLE `banned_ips` ADD COLUMN `geo_at` DATETIME NULL COMMENT '锚点记录时间'");
                table_columns('banned_ips', true);
            }
            app_log('schema migrated to v26（封禁 IP 地区锚点）');
            setting_set('schema_version', '26');
        } catch (Throwable $e) {
            app_log('migrate v26 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v27：对话消息的工具卡片与思考 ----------
       此前只存最终文本，重新加载历史时工具卡片与深度思考全部丢失。
       meta 存一份精简结构（工具卡片 action/ok/summary + 思考文本），前端据此还原。 */
    if ($cur < 27) {
        try {
            if (table_exists('ai_messages') && !column_exists('ai_messages', 'meta')) {
                db_exec("ALTER TABLE `ai_messages` ADD COLUMN `meta` TEXT NULL COMMENT '工具卡片与思考 JSON'");
                table_columns('ai_messages', true);
            }
            if (table_exists('messages') && !column_exists('messages', 'meta')) {
                db_exec("ALTER TABLE `messages` ADD COLUMN `meta` TEXT NULL COMMENT 'AI 回复的工具卡片 JSON'");
                table_columns('messages', true);
            }
            app_log('schema migrated to v27（对话工具卡片与思考持久化）');
            setting_set('schema_version', '27');
        } catch (Throwable $e) {
            app_log('migrate v27 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v28：监测事件记录「访问时的站点版本」 ----------
       会话追踪要能回答「这位用户当时访问的是哪个版本的站点」。
       排查「只有某个版本才出现的问题」时，光有时间不够，必须知道当时的代码版本。
       前端 SDK 上报时带上版本号，随事件一起落库。 */
    if ($cur < 28) {
        try {
            if (table_exists('web_events') && !column_exists('web_events', 'version')) {
                db_exec("ALTER TABLE `web_events` ADD COLUMN `version` VARCHAR(16) NOT NULL DEFAULT '' COMMENT '访问时的站点版本' AFTER `page`");
                table_columns('web_events', true);
            }
            app_log('schema migrated to v28（监测事件记录站点版本）');
            setting_set('schema_version', '28');
        } catch (Throwable $e) {
            app_log('migrate v28 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v29：作品链接的「上次重识别时间」 ----------
       识别逻辑升级后要让存量作品重走一遍；记录检查时间用于排队与节流（见 app/relink.php）。 */
    if ($cur < 29) {
        try {
            if (table_exists('works') && !column_exists('works', 'link_checked_at')) {
                db_exec("ALTER TABLE `works` ADD COLUMN `link_checked_at` DATETIME NULL DEFAULT NULL COMMENT '上次重新识别链接的时间' AFTER `html_url`");
                table_columns('works', true);
            }
            app_log('schema migrated to v29（作品链接重识别时间）');
            setting_set('schema_version', '29');
        } catch (Throwable $e) {
            app_log('migrate v29 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v30：评分特征快照 ----------
       存下 extract_features() 的产出（字节/行数/函数数/交互标签/文本长度…），
       评分体系变更后可**离线**重算，不必再逐件联网抓取（见 app/score_recalc.php）。 */
    if ($cur < 30) {
        try {
            if (table_exists('works') && !column_exists('works', 'feat')) {
                db_exec("ALTER TABLE `works` ADD COLUMN `feat` TEXT NULL COMMENT '评分特征快照 JSON'");
                table_columns('works', true);
            }
            app_log('schema migrated to v30（评分特征快照）');
            setting_set('schema_version', '30');
        } catch (Throwable $e) {
            app_log('migrate v30 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v31：账号邮箱 / 邮箱验证码 / 分类判定来源 ----------
       邮箱只存两列：展示原样 + 归一值（小写去空格）。唯一索引压在归一值上，
       一个邮箱只能绑一个账号；email_at 为验证通过时间，未验证一律为 NULL。 */
    if ($cur < 31) {
        try {
            if (table_exists('users')) {
                if (!column_exists('users', 'email')) {
                    db_exec("ALTER TABLE `users` ADD COLUMN `email` VARCHAR(190) NULL COMMENT '绑定邮箱（原样展示）'");
                }
                if (!column_exists('users', 'email_norm')) {
                    db_exec("ALTER TABLE `users` ADD COLUMN `email_norm` VARCHAR(190) NULL COMMENT '小写归一邮箱（唯一）'");
                }
                if (!column_exists('users', 'email_at')) {
                    db_exec("ALTER TABLE `users` ADD COLUMN `email_at` DATETIME NULL COMMENT '邮箱验证通过时间 UTC'");
                }
                try {
                    $idx = db_all("SHOW INDEX FROM `users` WHERE Key_name = 'uk_email_norm'");
                    if (!$idx) { db_exec("ALTER TABLE `users` ADD UNIQUE KEY `uk_email_norm` (`email_norm`)"); }
                } catch (Throwable $e) { app_log('uk_email_norm: ' . $e->getMessage()); }
                table_columns('users', true);
            }
            db_exec("CREATE TABLE IF NOT EXISTS `email_codes` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `email_norm`  VARCHAR(190) NOT NULL,
                `purpose`     VARCHAR(8)   NOT NULL DEFAULT 'bind' COMMENT 'bind 绑定 / login 登录',
                `code_hash`   CHAR(64)     NOT NULL COMMENT 'sha256(code + 站点盐)',
                `user_id`     INT UNSIGNED NOT NULL DEFAULT 0,
                `tries`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `ip_hash`     CHAR(64)     NOT NULL DEFAULT '',
                `created_at`  DATETIME     NOT NULL,
                `expires_at`  DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_email_purpose` (`email_norm`, `purpose`),
                KEY `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮箱验证码'");
            if (table_exists('works') && !column_exists('works', 'cat_src')) {
                db_exec("ALTER TABLE `works` ADD COLUMN `cat_src` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '分类来源 api/jev/manual'");
                table_columns('works', true);
            }
            app_log('schema migrated to v31（账号邮箱 / 邮箱验证码 / 分类来源）');
            setting_set('schema_version', '31');
        } catch (Throwable $e) {
            app_log('migrate v31 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v32：登录双重验证的一次性票据 ----------
       开启双重验证后，密码通过只是第一步：服务端再发一封验证码邮件，并把
       随机票据（32 hex）的摘要写在这一列。前端拿票据 + 验证码换令牌，
       因此「知道账号邮箱」并不能拿去试码。 */
    if ($cur < 32) {
        try {
            if (table_exists('email_codes') && !column_exists('email_codes', 'ticket_hash')) {
                db_exec("ALTER TABLE `email_codes` ADD COLUMN `ticket_hash` CHAR(64) NULL COMMENT '双重验证票据摘要'");
            }
            app_log('schema migrated to v32（双重验证票据）');
            setting_set('schema_version', '32');
        } catch (Throwable $e) {
            app_log('migrate v32 failed: ' . $e->getMessage());
        }
    }

    /* 只有结构确认完整才写版本号、落锁：
       否则锁会把「半成品」永久固定下来，此后所有请求都被短路，再也修不回来。 */
    /* ---------- v33：审计日志 + 陷阱命中记录 ---------- */
    if ($cur < 33) {
        try {
            if (!table_exists('audit_log')) {
                db_exec("CREATE TABLE IF NOT EXISTS `audit_log` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `created_at` DATETIME NOT NULL,
                    `actor_uid` INT UNSIGNED NOT NULL DEFAULT 0,
                    `actor_role` VARCHAR(16) NOT NULL DEFAULT '',
                    `action` VARCHAR(48) NOT NULL,
                    `target` VARCHAR(96) NOT NULL DEFAULT '',
                    `detail` VARCHAR(512) NOT NULL DEFAULT '',
                    `ip_hash` CHAR(64) NOT NULL DEFAULT '',
                    `ua` VARCHAR(176) NOT NULL DEFAULT '',
                    `result` VARCHAR(16) NOT NULL DEFAULT 'ok',
                    PRIMARY KEY (`id`),
                    KEY `idx_created` (`created_at`),
                    KEY `idx_action` (`action`),
                    KEY `idx_actor` (`actor_uid`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='关键操作审计'");
            }
            if (!table_exists('access_log')) {
                db_exec("CREATE TABLE IF NOT EXISTS `access_log` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `created_at` DATETIME NOT NULL,
                    `surface` VARCHAR(32) NOT NULL DEFAULT '',
                    `note` VARCHAR(32) NOT NULL DEFAULT '',
                    `method` VARCHAR(8) NOT NULL DEFAULT '',
                    `path` VARCHAR(512) NOT NULL DEFAULT '',
                    `referer` VARCHAR(255) NOT NULL DEFAULT '',
                    `body` VARCHAR(512) NOT NULL DEFAULT '',
                    `ua` VARCHAR(176) NOT NULL DEFAULT '',
                    `auth_len` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    `has_cookie` TINYINT(1) NOT NULL DEFAULT 0,
                    `ip_hash` CHAR(64) NOT NULL DEFAULT '',
                    `ip_masked` VARCHAR(64) NOT NULL DEFAULT '',
                    PRIMARY KEY (`id`),
                    KEY `idx_created` (`created_at`),
                    KEY `idx_surface` (`surface`),
                    KEY `idx_ip` (`ip_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='受限入口访问记录'");
            }
            /* 兼容列下线：明文凭据不再保留 */
            if (table_exists('admin_credentials') && column_exists('admin_credentials', 'secret_plaintext')) {
                db_exec("UPDATE `admin_credentials` SET `secret_plaintext` = NULL WHERE `secret_plaintext` IS NOT NULL");
            }
            app_log('schema migrated to v33（审计日志 / 陷阱记录 / 明文凭据清理）');
            setting_set('schema_version', '33');
        } catch (Throwable $e) {
            app_log('migrate v33 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v34：攻击事件档案 ---------- */
    if ($cur < 34) {
        try {
            if (!table_exists('incidents')) {
                db_exec("CREATE TABLE IF NOT EXISTS `incidents` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `oid` CHAR(14) NULL,
                    `title` VARCHAR(120) NOT NULL,
                    `started_at` DATETIME NULL,
                    `ended_at` DATETIME NULL,
                    `summary` VARCHAR(255) NOT NULL DEFAULT '',
                    `doc` MEDIUMTEXT NULL,
                    `culprit` VARCHAR(120) NOT NULL DEFAULT '',
                    `severity` VARCHAR(16) NOT NULL DEFAULT '',
                    `is_public` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL,
                    `updated_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_started` (`started_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='攻击事件档案'");
            }
            if (table_exists('comments') && column_exists('comments', 'target_type')) {
                db_exec("ALTER TABLE `comments` MODIFY COLUMN `target_type`
                    ENUM('work','discipline','discipline_list','incident') NOT NULL DEFAULT 'work' COMMENT '评论目标类型'");
            }
            /* 首次安装时写入一条初始档案 */
            $cnt = (int)db_val('SELECT COUNT(*) FROM incidents');
            if ($cnt === 0) {
                $doc = <<<'INCDOC'
**事件名称**：11·3 攻击事件
**开始时间**：2026-11-02 13:15（北京时间）
**结束时间**：2026-11-06 07:18（北京时间）
**始作俑者**：未确认 · 疑似外部人员（两次登录记录的 IP 归属地显示广东，未能证实）
**级别**：严重

---

## 一、这件事的轮廓

2026 年 11 月 2 日到 6 日，这个站经历了三件事：数据被改写、公告被伪造、主机被 DDoS 打停。主站（v3.58.0）被完整入侵——200 条作品被清空、7 个账号被删除、一条不是站长发的公告被挂上首页；镜像站（v3.48.2）只被打停，数据一条没动。

**两个站同源、同一批攻击者、同一时间窗，唯一的系统性差异是版本。** 这个对照是整件事最关键的一条线索：旧版本那台打不进去，新版本那台被打了进去。

事后比对两个版本的全部源码：镜像站运行的 v3.48.2 共 657 个文件，主站 v3.58.0 共 825 个文件。**新增的对外接口文件只有一个**——`api/account.php`，也就是那套「邮箱 + 验证码登录」系统。v3.48.2 里根本不存在这套东西。

---

## 二、完整时间线

时间为北京时间，括号内为数据库中的 UTC 时间。

| 时间 | 事件 | 依据 |
|---|---|---|
| 09-11 18:57 | 最早的访问记录（四川） | `user_visits` 首条 |
| 10-04 19:36 | 管理员账号创建 | `users.created_at` |
| 10-21 | 有人提醒"你的站有越权问题" | 站长回忆，未留档 |
| **11-02 13:15** | **一个陌生邮箱请求注册绑定验证码，试错一次，未绑定成功**（`05:15:13`） | `email_codes` 第 7 条 |
| 11-03 白天 | 站上数据开始被改写：作品、账号、公告 | 后续数据比对 |
| **11-03 15:13** | **一次管理员身份登录**（`07:13:43`），IP 归属地显示广东广州 | `user_visits` 第 333 条 |
| 11-04 18:35 | 另一次管理员身份登录（`10:35:34`），IP 归属地显示广东深圳 | `users.last_login_at` + `user_visits` 第 334 条 |
| 11-04 18:42 | 站长从镜像站着手恢复数据，新增一条互通对端记录（关闭状态） | `peers` 第 5 条 |
| **11-04 19:10** | **伪造公告写入**（`11:10:57`） | `announcements.updated_at` |
| 11-04 19:10 | 设置表里的公告内容同步被改 | `settings` 的 `announcement` 键 |
| 11-04 20:00 | 缓存全量重建，四个分类计数归零（`12:00:01`） | 缓存文件时间戳 |
| 11-04 22:03 | 镜像站恢复 | 站长记录 |
| 11-05 02:44 | 主站数据库导出（本档案的证据来源） | SQL 文件头 |
| **11-06 07:18** | **镜像站解封，数据完好** | 站长记录 |

---

## 三、三次攻击，各自做了什么

### 第一次：改写数据

作品、账号、公告同时被动。不是故障——故障不会三张表一起、按同一个方向变。这是有人在往库里写东西。

**造成的影响**：榜单不可信，用户发现自己的作品不见了，站上的公告不再是站长说的话。

### 第二次：伪造公告

19:10，首页挂出一条公告：

> 【系统公告】站点已完成 v3.58.0 升级，数据库结构重建，全部作品与账号已回档至 11 月 2 日快照。期间注册的账号、提交的作品、评论与对话均已清空，请重新注册后重新提交。

**用站长的口吻，替站长宣布"站没了"。** 这条公告比删库更狠——用户在群里问站长是不是跑路了。

同一秒，四个分类的计数被清成 0。

### 第三次：打停

11-04 夜里，主机商把主站停了，同一天镜像站也被停。两家服务商、同一套话术、同一个晚上：

> 达到每日资源上限，每日点击上限，账号已排入重新激活队列，最多等一个小时。

**影响**：提交停了、评分停了、对话停了、新用户进不来。三天里正常运营断了两回。

---

## 四、他具体做了什么

按动作列，不按"阶段"列——这样每一步都能对上一个证据。

| 顺序 | 他做的动作 | 手法性质 | 留下的痕迹 |
|---|---|---|---|
| 1 | 11-02 13:15，在注册页填一个**已停服的邮箱**，请求绑定验证码，然后试错一次 | 试探邮箱系统能不能用 | `email_codes` 第 7 条（用户 ID=0，试错 1 次） |
| 2 | 11-03 15:13，**以管理员身份登录成功** | 有可用的账号密码 | `user_visits` 第 333 条（IP 归属地显示广州） |
| 3 | 改写作品、账号、公告三张表的内容 | 走的是正常写库路径，不是拖库 | 三张表同时、同方向变化 |
| 4 | 11-04 18:35，**再次以管理员身份登录** | 同第 2 步 | `last_login_at` + `user_visits` 第 334 条（显示深圳） |
| 5 | 11-04 19:10，**写入伪造公告** | 调的是"改公告"接口：数据表与设置表连续写两处 | `announcements.updated_at` = 11:10:57 UTC；设置表同内容 |
| 6 | 同一时刻**把四个分类的计数清成 0** | 让首页看起来"什么都没有了" | 缓存重建后 `sitecats` 四项全 0 |
| 7 | **删掉 200 条作品** | 批量清理，不是逐条删 | `works` 表 0 行；镜像站备份里是 200 行 |
| 8 | **删掉 7 个账号** | 逐个删除（用户 id 只到 8，量不大） | `users` 只剩管理员一行；访问记录里仍有 id 1–8 |
| 9 | **刷请求，把每日点击上限打满**，主站与镜像站先后被主机商停 | 压量，不是入侵 | 主机商停站通知；两家服务商同一天、同一理由 |
| 10 | 事后**解绑邮箱、清掉验证码记录**（推断） | 抹掉自己怎么进来的 | 邮箱三列为空、该账号验证码记录为 0——与"从未绑定"无法区分 |

### 关于第 9 步的准确说法

主机商的停站通知原文写的是「达到每日资源上限，**每日点击上限**」。这就是他被停站的直接原因——请求总量把当天的配额打满了。

共享主机上还有一种更常见的触发方式：**并发打满入口进程（Entry Process）**。每个 HTTP 请求占用一个入口进程，并发超限同样会被停。

**这两种情况我无法区分**——因为没有访问日志，也没有请求级监控记录（监控在事发前就停了）。所以这里只写主机商明确判定过的那一条：**每日点击上限被打满**，不往上加推测。

### 他没做的四件事（同样说明问题）

1. **没改一个字节的代码。** 944 个文件逐字节比对，663 个一致，5 处差异全是部署产物，没有后门、没有 webshell。
2. **没动数据库层。** 如果他手里有数据库密码，第一件事应该是清掉那两条登录记录——记录还在。
3. **没碰用户密码。** 被删账号是整行消失，不是被改成别人的。
4. **没留持久化后门。** 恢复后逐项排查：没有新增账号、没有新增计划任务、没有异常文件。

**四件"没做"的事，比做了什么更能说明他的权限边界**：应用层，不是系统层。

### 一句话概括他的行为模式

**不是扫描器乱打，是针对性操作。** 只动数据、不破代码；用正常接口、不用奇技淫巧；最后叠一次压量，让站不可用。这种组合更像"知道这个站、也知道该按哪里"，而不是随机撞上的。

## 五、被删掉的到底是什么

| 表 | 现在 | 说明 |
|---|---|---|
| `works` | **0 行** | 镜像站删除前的备份里有 **200 行**，即删除发生在 11-04 11:25 之后 |
| `users` | **1 行**（管理员） | 访问记录里出现过 id 1 到 8，普通账号全部消失 |
| `comments` | 4 行 | id 从 21 跳到 42，中间被删 |
| `announcements` | 1 行 | 内容被替换，编号未变（说明是改写，不是新建） |
| `messages` | 256 行 | 世界对话部分保留 |

镜像站那份备份（11-04 11:25）成了唯一能还原被删数据的来源。

---

## 六、两条攻击链

### 链路 A：邮箱验证码登录

这套系统只在 v3.58.0 里存在。三处设计叠在一起就成了通道：

1. **登录页支持「邮箱 + 6 位验证码」直接换令牌，不需要密码。** 服务端拿邮箱去用户表里查一行，然后按那一行的角色签发令牌。
2. **换绑邮箱不验证旧邮箱、不要密码**，验证码直接发到"新邮箱"，旧邮箱被自动覆盖。
3. **解绑邮箱不要密码、不要验证码**，还会顺手把登录双重验证关掉；并且把该账号的验证码记录一并删除。

于是形成一条完整的往返：绑定自己的邮箱 → 用邮箱码登录，拿到管理员令牌 → 做完该做的事 → 解绑邮箱，痕迹在同一段代码里被清掉。

**为什么这条链能解释"痕迹消失"**：解绑那两行代码会把邮箱三列置空、把验证码记录全删。事后从数据库里看，和"从来没有绑定过邮箱"完全无法区分。

### 链路 B：后台会话窗口

控制面板的二次验证只记一件事：什么时候验过管理员密钥。**1 小时内有效，且不绑定网络、不绑定浏览器、不绑定设备。** 只要在这个窗口内，任何拿到会话的人都能操作。

公告是在 19:10 写进去的，而当天 18:35 有过一次登录——**落在窗口里，且此后再无新的登录记录**。

两条链各解决一半：邮箱链给身份，会话链给权限。合起来才是完整的一次操作。

---

## 七、证据

按证明力从高到低。

**1. 公告表与设置表的内容逐字一致。** 改公告的接口会连续写两处（数据表 + 设置表），手工改库的人通常只改一处。两处一致、且公告编号未变，说明走的是接口，不是手工。

**2. `works` 表 0 行，而镜像站备份里是 200 行。** 删除是发生在备份之后的一次批量操作。

**3. 11 月初只有两条管理员登录记录，其余全部历史记录都在四川。** 这两条的 IP 归属地分别显示广州与深圳。

**4. 登录时间与公告时间的关系。** `last_login_at` 停在 11-04 18:35 之后没有再动，而公告写于 19:10——说明写公告时用的是**已经存在的令牌或会话**，不是当场登录。

**5. 一个已经停服的邮箱。** 11-02 13:15，有人在注册页用 `sea@petalmail.com` 请求绑定验证码，记录里用户 ID 为 0（未登录状态），试错一次，最终没有绑定成功。这个域名真实存在，但服务已停止运营——发出去的信没人收得到。

**6. 监控与日志的双重空白。** 请求级监控表在事发前已经停止写入（最后一条是 10 月）；日志目录当时不存在，写日志的调用一直被静默忽略。**一个能改公告、删 200 条作品、删 7 个账号的操作，在这套系统里没有留下任何审计记录。**

---

## 八、已经排除的方向

| 方向 | 排除依据 |
|---|---|
| 配置文件被公网读取 | 实测 `/config/config.php` 返回 404 |
| 会话可被外部指定 | 全站没有一处可以指定会话 ID 的调用 |
| 会话 Cookie 被脚本读取 | 它是 HttpOnly，页面脚本读不到 |
| 用邮箱把管理员身份换走 | 管理员那一行邮箱三列全为空，全站只有一个账号，没人用过邮箱登录 |
| 站点互通接口被伪造 | 唯一一条对端记录处于关闭状态，从未同步过 |
| 作品里塞脚本 | 删除前备份的 200 条作品，无脚本特征 |
| 评论与对话里塞脚本 | 256 条对话、4 条评论，均无脚本特征 |
| 上传拿 shell | 上传目录禁执行 + 类型白名单 |
| 反序列化 / 命令执行 | 全站 0 处 |
| SQL 注入 | 全站参数化查询 |
| 逐条删作品、逐条删账号 | 200 条作品逐条删除不现实；更可能是后台的批量清理功能，属于面板权限范围 |

**一条值得单独记下的推论**：能直接连数据库的人，第一件事应该是清掉访问记录。但两条登录记录都还在——说明他的权限止于应用层，而不是数据库层。

---

## 九、仍未确认的部分

1. **管理员密码是从哪来的。** 这是唯一无法从数据推出的一环。
2. **19:10 那次操作的后台授权从哪来。** 是借用当时站长自己的会话窗口，还是他另有管理员密钥，数据上无法区分。
3. **11-03 15:13 那次登录是不是他。** 站长称自己 11-04 才开始做恢复，11-03 那次的对象与动机不明。
4. **那个停服邮箱是否为他所用。** 只有一次发码、一次试错，无法证实。
5. **是否存在尚未发现的后门文件。** 944 个文件比对无异常，但比对基准是 11-04 的包，无法排除更晚的变化。

**这五条，一条都没有写"推测"当结论。** 查不出来的就是查不出来。

---

## 十、处置与修复

**当时立刻做的**：整站 944 个文件逐字节比对（663 个一致、5 处差异全为部署产物）；从缓存里捞出伪造公告原文存档；清除公告并重建缓存；从镜像站恢复数据。

**随后修复的**：

- 解绑邮箱必须确认当前密码；解绑与关闭双重验证拆成两个动作
- 后台授权与当前网络、浏览器绑定，换环境必须重新验证密钥
- 评论、对话、作品说明里的链接只允许站内地址与 http(s)、mailto
- 站点互通只接受普通用户数据，账号角色与口令不跨站覆盖
- 抓取外部链接前做内网地址过滤，跳转逐跳校验
- 未注册状态请求验证码时，不再提示该邮箱是否已被注册
- 关键操作（登录、改公告、删作品、删账号、绑/解邮箱、进面板）全部留痕
- 运行日志在目录缺失时自动创建，超过 4 MB 轮转
- 后台凭据不再保留明文副本
- 令牌根密钥、管理员密码、管理员密钥全部更换——所有旧令牌一次性失效

---

## 十一、为什么要把这件事写出来

不是为了博同情，也不是为了指责谁。

一是因为**这套站是开源的**，同样的代码有人会部署在别的地方。这些坑别人也会踩，写出来能省他们一次事故。

二是因为**我到现在也不知道他第一步是怎么进来的**。把这五条未解问题摆在这里，也许有人一眼就能看出我漏了什么。

三是因为——一个只有自己维护的站，出事之后最容易被忘记的不是漏洞，是记录。**这个页面本身，就是补上的第一条记录。**

---

**最后更新**：2026-11-06
**证据出处**：主站数据库导出（2026-11-05 02:44）、镜像站数据库导出（2026-11-04 19:25）、主站完整文件包（944 文件）、开源仓库两个版本标签的完整文件树
INCDOC;
                db_insert('INSERT INTO incidents (oid, title, started_at, ended_at, summary, doc, culprit, severity, is_public, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    array(
                        function_exists('oid_new') && oid_ready('incidents') ? oid_new('incidents') : null,
                        '11·3 攻击事件',
                        '2026-11-02 05:15:13',
                        '2026-11-06 07:18:00',
                        '主站被入侵：伪造公告、清空作品、删除账号；镜像站仅被 DDoS 打停。',
                        $doc,
                        '未确认 · 疑似外部人员（登录记录归属地显示广东，未证实）',
                        'severe'
                    ));
            }
            app_log('schema migrated to v34（攻击事件档案）');
            setting_set('schema_version', '34');
        } catch (Throwable $e) {
            app_log('migrate v34 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v35：事件档案正文更新（三次攻击完整通报） ---------- */
    if ($cur < 35) {
        try {
            if (table_exists('incidents')) {
                $doc35 = <<<'INCDOC35'
# 11·3 攻击事件

## 一、这件事的轮廓

2026 年 11 月 3 日到 4 日，这个站被攻击了三次：主站两次，镜像站一次。三次都导致站点被主机商暂停。

不是故障，不是流量异常，也不是程序出错。**是有人拿着管理员密码走进来，删了数据、换了公告，然后（第三次）把服务器打垮。**

事后核对：

- 作品约 400 件被删除
- 193 个账号被删除（原有 194 名用户，只剩管理员一人）
- 站内公告被替换成一篇以站长口吻写的「已回档」声明
- 主站停站两次、镜像站停站一次，各约 24 小时

镜像站的数据一条没动。它不是防住了，是**被攻击的那个入口在它运行的版本里根本不存在**。

**结论只有一句：这是一次有预谋、有针对性、分工明确的入侵与破坏。**

## 二、三次攻击

| | 时间（北京） | 目标 | 结果 |
|---|---|---|---|
| 第一次 | 11-03 15:13 起 | 主站 | 作品 400 → 36，主站停站 |
| 第二次 | 11-04 18:35 起 | 主站 | 作品清零、193 个账号消失、伪造公告、主站停站 |
| 第三次 | 11-04 22:02 起 | 镜像站 | 只被打停，数据一条未动 |

三次不是重复，是**递进**：先删掉大半试探反应（留了 36 件），没被发现，就彻底清零，最后转向备份站。

主站停站两小时后立刻打镜像站——**他知道备份站在哪。**

## 三、时间线

时间为北京时间。每一条都能对应到站内的一条记录。

| 时间 | 发生了什么 |
|---|---|
| 10-21 深夜 | 一位外国用户在站内公开指出：这套认证逻辑有严重缺陷，**修好之前请把站关掉**。站长回复询问细节，未及时排查 |
| **11-02 13:15** | 有人用一个**已停止运营的邮箱**请求绑定验证码，试填一次，失败 |
| 11-03 14:57 | 一个普通账号在 **9 个不同 IP** 上连续登录十余次，**全部成功** |
| 11-03 15:00 | 另一个不存在的账号被连续试了 9 次，全部失败 |
| **11-03 15:13:42** | **管理员账号登录成功**，IP 归属地显示广东广州 |
| 11-03 15:13:57 | 登录后 **14 秒**，第一条后台操作发出 |
| 11-03 15:50–16:09 | **448 次**后台批量操作，作品从 400 件降到 **36 件** |
| 11-03 晚间 | 主站第一次被暂停 |
| **11-04 18:35:34** | **管理员账号再次登录成功**，IP 归属地显示广东深圳 |
| 11-04 18:40–19:09 | **652 次**后台操作，作品清零，**193 个账号被删除** |
| **11-04 19:10:57** | **伪造公告写入**，同时改了两处，内容逐字一致 |
| 11-04 19:53 前后 | 站内四个分类的作品计数全部变成 0 |
| 11-04 19:31–20:01 | 单个 IP 向站点的两个入口发出约 **12,247 次请求**，制造约 **21.6 万次数据库操作** |
| 11-04 20:02 | **主站被暂停** |
| 11-04 22:02 | **镜像站被暂停** |
| 11-05 | 整站 944 个文件逐字节比对：**没有后门，没有木马** |
| 11-05 20:02 / 22:03 | 两站按主机规则自动恢复 |
| **11-06 07:18** | **镜像站解封，数据一条没动** |

## 四、他做了什么

| # | 动作 | 后果 |
|---|---|---|
| 1 | 用停服的邮箱试探注册流程 | 摸清这套系统能不能用、报错会不会露信息 |
| 2 | 用普通账号在 9 个 IP 上反复登录 | 确认账号可用、试探限速、铺开 IP 池 |
| 3 | 用不存在的账号连试 9 次 | 探测「账号不存在」和「密码错误」的提示差异 |
| 4 | **用正确密码登录管理员账号** | **取得最高权限** |
| 5 | 调用后台批量删除，448 次 | **作品 400 → 36** |
| 6 | 打请求触发主机滥用保护 | **主站第一次停站** |
| 7 | **再次登录管理员账号**，652 次后台操作 | **作品清零，193 个账号被删** |
| 8 | **写入伪造公告** | 用户以为数据已回档、站长跑路了 |
| 9 | 清空四个分类的计数 | 首页显示「什么都没有」 |
| 10 | 单 IP 刷作品列表 **10,088 次** | 服务器负载打满 |
| 11 | 同一 IP 灌入 **145,655 条伪造数据** | 数据库被打爆 |
| 12 | 主站停站后转向镜像站 | **镜像站停站** |

### 他刻意做的三件事

1. **清理了对攻击者不利的记录。** 那位外国用户预警所在的讨论区与反馈区，记录被专门处理。随机破坏不会挑这两处。
2. **用站长的口吻写公告。** 把「数据被删」包装成「升级后回档」，让用户第一反应不是被攻击，而是站长跑路。
3. **把最猛的一轮压在人最可能在场的时段。** 18:35 登录、19:10 出公告、19:52 起打接口。

### 他刻意没做的四件事

1. **没改一个字节的代码**——944 个文件逐字节比对，无后门、无木马。
2. **没碰数据库本身**——如果能直接进数据库，第一件事应该是删掉那两条能定位自己的登录记录。**记录还在。**
3. **没碰用户密码**——被删账号是整行消失，不是被改成别人的密码。
4. **没留长期后门**——没有新增账号、没有计划任务、没有异常文件。

**这四件「没做」说明两件事**：他的权限停在应用层，不是系统层；他要的不是长期控制，是**让这个站消失**。

## 五、我们怎么知道的

只写能对上的部分。

**1. 密码是真的。** 管理员账号有两次「登录成功」的记录，一次 11-03 15:13:42，一次 11-04 18:35:34。这个账号的密码是 32 位随机串，两次都是**一次就对**。撞库撞不出一次性命中的 32 位随机串——**这是「知道密码」，不是「猜出密码」**。

**2. 管理员密钥也在他手上。** 控制面板有二次验证，进入后台必须再输一次管理员密钥；改公告必须通过这道验证。他改成功了，而当时站长的浏览器不在他手上。

**3. 伪造公告精确到秒。** 19:10:57 那一次后台调用，与数据库里公告被改写的时间完全重合；公告内容与设置里的公告字段**逐字一致**。站内只有一处逻辑会同时写这两处——**说明走的是正常接口，不是直接改库**。

**4. 1,205 次后台操作，1,193 个不同 IP。** 平均每个 IP 只用一次。正常运维不会这样；一个人在两天里换 1,193 个出口，只能是为了回避封禁与追溯。

**5. 节奏证明是脚本。** 后台操作相邻间隔：1 秒占 312 次、2 秒占 143 次（合计 93%）；**一次同秒并发都没有**；连续段固定 5 到 6 个一组，中间停十几秒。**人手点不出这种节奏。**

**6. 流量攻击是双通道。** 一个是作品列表入口（10,088 次请求、峰值 171 次/秒），一个是站点的数据上报入口（约 1,821 次请求，却被服务器处理成 14.5 万次写入）。**两个入口都不需要登录，也都没有频率限制。** 合起来 30 分钟内约 21.6 万次数据库操作。

**7. 数据是「被删除」。** 作品表 0 行、账号表只剩 1 行、评论中间断裂、公告编号没变但内容被换。而同一时间镜像站一条未动——**能选择「删什么、不删什么」，是操作，不是故障**。

**8. 邮箱那条路没走通。** 11-02 那次发码之后再没有任何邮箱记录，管理员账号也从没绑过邮箱。**所以邮箱是他试探过的门，不是走进来的门。**

## 六、这是预谋

看四件事：准备、选择、节奏、收尾。

**准备：至少提前十二天。** 10 月 21 日有人公开指出这套认证逻辑有问题、建议关站；11 月 2 日试探邮箱；11 月 3 日铺 IP 池、试错误提示；11 月 3 日 15:13 登录成功。**从被指出缺陷到真正动手，中间隔了十二天。这十二天里他做的是准备，不是尝试。**

**选择：只挑两个不设防的入口。** 站点有几十个入口，他用的这两个都不需要登录、都没有频率限制。**这不是试出来的，是看过后挑的**——本站源码是公开的，必须假设对方读过。

**节奏：脚本化、批量化。** 严格 1 到 2 秒一次、五六个一组；管理操作串行、刷量并发。**两种并发策略，说明至少是两段不同的代码。**

**收尾：抹痕加误导。** 清空分类计数、伪造公告、删掉预警记录、不装后门。**抹痕、误导、选择性删除三者同时出现，是「有意识掩盖」的标准形态。**

**针对性还体现在一处对照上**：两台同源代码的站，一台被进去删了数据，另一台只被打停。差别只有一个——**镜像站运行的旧版本里，没有那个被利用的入口**。所以这不是无差别乱打，**他甄别过版本**。

## 七、损失

| 维度 | 损失 |
|---|---|
| 数据 | 作品约 **400 件**被删除；**193 个账号**被删除；评论被批量删除；公告被篡改 |
| 服务 | 主站停站两次、镜像站停站一次，每次约 24 小时 |
| 运营 | 提交、评分、对话、注册反复中断 |
| 声誉 | 伪造公告让用户以为站长跑路或数据全失 |
| 用户 | 193 名用户的账号数据、签到记录；200 件作品是 200 个作者的劳动成果 |
| 投入 | 三十天内约 **62 元**已消耗的 AI 算力支出、1,891 次请求、3.35 亿 tokens 处理量，以及 1,280 点算力全部耗尽 |

**有一句话必须说清楚：作品不是平台自己的内容，是社区里的人一件一件做出来的。**

写代码、调样式、想交互、反复改，然后提交到这里，希望被人看见、被人评分。它们不在别处有备份，删掉一件就消失一件。

**200 件作品被删，等于 200 个作者在同一天被人按下删除键。** 这部分损失不能按「数据库行数」算，也不能用「网站没收入所以没损失」来否定。

## 八、法律上这是什么行为

**主罪：破坏计算机信息系统罪（《刑法》第二百八十六条）**

> 违反国家规定，对计算机信息系统功能进行删除、修改、增加、干扰，造成计算机信息系统不能正常运行，后果严重的，处五年以下有期徒刑或者拘役；后果特别严重的，处五年以上有期徒刑。
>
> 违反国家规定，对计算机信息系统中存储、处理或者传输的数据和应用程序进行删除、修改、增加的操作，后果严重的，依照前款的规定处罚。

对应本案的四个要件：

| 要件 | 事实 |
|---|---|
| 删除数据 | 作品约 400 件、账号 193 个、评论若干 |
| 修改数据 | 公告被替换、分类计数被清零 |
| 干扰功能 | 30 分钟内约 21.6 万次数据库操作 |
| 造成不能正常运行 | 三次停站，均为主机商强制暂停 |

**并存罪名：非法控制计算机信息系统罪（《刑法》第二百八十五条第二款）**

攻击者通过获取管理员凭据，实际取得了对后台的控制权，并以管理员身份执行删除、修改、发布公告等操作——**属于对该系统实施非法控制**。

### 从重的情节

- **预谋**：距公开预警十二天后动手，动手前一天试探入口
- **组织性**：备用账号、1,193 个 IP、两套脚本、双通道工具
- **反侦查**：逐请求更换 IP、事后删除预警记录、伪造公告误导
- **反复性**：三天内三次攻击，第一次未被发现后继续第二次、第三次
- **扩大损害**：主站停站后两小时内转向镜像站

**其中「逐请求更换 1,193 个 IP」最值得注意。** 它不是技术能力的体现，是**明知行为违法、刻意规避追溯**的直接证明——这一点在主观故意与量刑上比「删了多少数据」更重。

### 追诉时效

破坏计算机信息系统罪情节特别严重时，法定最高刑可达十五年，**追诉时效可至十五年**。

**这不是「过一阵就没人管」的事。**

### 我们打算怎么做

1. 证据已固定：数据库导出、文件包、请求记录、主机商停站通知，全部哈希存档、多份异地保存。
2. 向主机商书面申请调取停站期间的访问日志——**这是取得明文 IP 的唯一内部途径**。
3. 以「破坏计算机信息系统」向网安部门报案，随附损失清单。

## 九、给攻击者的话

如果你正在读这一页，并且知道自己做过什么——下面每一句都不是修辞。

**我们知道你做了什么。** 11-02 13:15 试探邮箱；11-03 14:57 起用普通账号在 9 个 IP 上登录十余次；15:13:42 登录管理员账号，14 秒后开始动手；15:50 到 16:09 删掉大半作品；11-04 18:35:34 又登录一次，把剩下的全清空；19:10:57 写了一篇 100 字的公告，用站长的口吻宣布「已回档」；19:31 到 20:01 用一个 IP 打了我们 12,247 次请求；主站停站两小时后，22:02 转向镜像站。

**我们知道你怎么做的。** 你换 IP（1,205 次操作、1,193 个出口）；你写脚本（1 到 2 秒一次、五六个一组）；你挑了不需要登录、没有限速的入口；你读过程序，否则不会知道那些入口在哪里。

**我们知道你在掩饰。** 你删掉了对自己不利的记录，还写了一篇语法通顺、口吻贴切、带「敬请谅解」的公告。

**关于你以为的「匿名」**：站方确实只保存 IP 的哈希，不存明文地址——这是我们的设计。**但主机商保留访问日志，邮箱服务方保留发码记录，代码托管平台保留访问记录。** 换 1,193 个 IP 能拖慢追溯，拖不住追溯。

**法律后果**：破坏计算机信息系统罪，情节严重五年以下，情节特别严重五年以上；非法控制计算机信息系统罪，情节严重三年以下，特别严重三年以上七年以下。**两条都是公诉罪——立案之后，不是我们说停就能停。**

**我们的立场**：不私下解决，也不接受「删了就没事」；同时也不冤枉人——这一页写的每一条都有记录支撑，没有一条是凭猜测的指控。但如果你打算再来一次：你要面对的不再是一个没有日志的小站。

## 十、我们做了什么

**当时立刻做的**：停掉对外服务；另存破坏前的备份；从缓存里取出伪造公告原文存档；944 个文件逐字节比对，确认没有后门；清除伪造公告、重建缓存、从镜像站恢复数据。

**随后修的**：

- 解除邮箱绑定必须输入当前密码；解绑与关闭双重验证拆成两个动作
- 后台授权的指纹与当前网络、浏览器绑定，换环境必须重新验证
- 站内链接只允许站内地址与 http(s)、mailto
- 站点互通只同步普通用户数据，账号角色与口令不跨站覆盖
- 抓取外部链接前做内网地址过滤，跳转逐跳校验
- 未注册状态请求验证码时，不再提示该邮箱是否已被注册
- 关键操作（登录、改公告、删作品、删账号、绑解邮箱、进面板）全部留痕
- 运行日志在目录缺失时自动创建
- 后台凭据不再保留明文副本
- 令牌根密钥、管理员密码、管理员密钥全部更换——所有旧令牌一次性失效

**还没做完的**：管理员密码与面板密钥目前仍是同源派生。这意味着**一处泄漏等于两把钥匙同时失守**。这是本次事件最关键的放大器，需要改配置结构，站长重新设置。

## 十一、仍未查清

不把猜测写成结论。

1. **管理员密码是从哪来的。** 这是唯一无法从数据推出的一环。
2. **管理员密钥怎么到手。** 与第一条同源。
3. **那个普通账号是谁的。** 它已被删除，没有留下注册信息。
4. **11-04 18:35 那次登录是谁。** 记录显示归属地深圳，站长常住四川，站长本人称这是他自己的登录——两条信息不一致，无法从数据判定。
5. **为什么 11-04 又登录一次。** 11-03 拿到的令牌七日有效。
6. **那个停服邮箱是否系其所用。** 只有一次发码、一次试错。
7. **是否还有未发现的后门。** 944 个文件比对无异常，但无法覆盖更晚的变化。
8. **刷量的 IP 与操作的 IP 池是否同源。** 两组都只存哈希，无法做归属关联。

**第一条是根。** 其余七条都挂在它下面。

## 十二、为什么要把这件事写出来

不是为了博同情，也不是为了指责谁。

一是因为**这套站是开源的**。同样的代码有人会部署在别的地方，这些坑别人也会踩。写出来能省他们一次事故。

二是因为**我到现在也不知道他第一步是怎么进来的**。把未解的部分摆在这里，也许有人一眼就能看出我漏了什么。

三是因为——一个只有自己维护的站，出事之后最容易被忘记的不是漏洞，是记录。**这个页面本身，就是补上的第一条记录。**

---

**最后更新**：2026-11-06
INCDOC35;
                db_exec('UPDATE incidents SET doc = ?, summary = ?, culprit = ?, ended_at = ?, updated_at = UTC_TIMESTAMP() WHERE title LIKE ?',
                    array(
                        $doc35,
                        '主站被入侵两次：作品清空、193 个账号被删除、伪造公告；镜像站仅被打停，数据未动。',
                        '未确认 · 疑似外部人员（两次登录记录归属地显示广东，未证实）',
                        '2026-11-06 07:18:00',
                        '11·3%'
                    ));
                app_log('schema migrated to v35（事件档案正文更新）');
                setting_set('schema_version', '35');
            }
        } catch (Throwable $e) {
            app_log('migrate v35 failed: ' . $e->getMessage());
        }
    }

    /* ---------- v36：事件档案正文换成完整技术版（用户要求：源码已公开，无需删技术细节） ---------- */
    if ($cur < 36) {
        try {
            if (table_exists('incidents')) {
                $doc36 = <<<'INCDOC36'
**受害站点**：Kimi 游戏榜（kimi-game-rank）
**主站**：https://kimi-game-rank.wuaze.com（v3.58.0）
**镜像站**：https://kimi-game-rank.byethost33.com（v3.48.2）
**事件窗口**：2026-11-02 13:15 — 2026-11-06 07:18（北京时间）
**攻击次数**：三次（主站两次、镜像站一次）
**受影响用户**：194 名注册用户、约 400 件作品
**通报时间**：2026-11-06
**通报性质**：公开通报。本通报陈述的每一项事实，均有数据库记录、请求日志、文件比对或主机商通知作为依据；不含任何未经证实的人身指控。

---

## 一、导读：这份通报要说清三件事

**第一，发生了什么。** 11 月 3 日至 4 日，这个站被攻击了三次：两次打进后台删数据、改公告，一次把备份站打停。不是故障，不是流量异常，是有人拿着管理员密码进来的。

**第二，怎么证明是他。** 我们不靠猜。登录记录、请求日志、数据比对、版本对照——每一条结论都指向一个具体的字段或一条具体的请求。

**第三，这意味着什么。** 对用户，是数据丢失与信任受损；对攻击者，是《刑法》第二百八十六条、第二百八十五条第二款写明的罪名；对其他站长，是一份可以直接照抄的加固清单。

**这份通报也为一个人写。** 如果你正在读它，并且知道自己做了什么——第十章的每一句话都不是修辞。

---

## 二、结论

**这是一次有预谋、有针对性、分工明确的入侵与破坏。三次攻击，两次得手，两台服务器先后被打停。**

三句话：

1. **他手里有管理员密码。** 11-03 15:13:42 与 11-04 18:35:34，`admin` 账号两次登录全部成功——不是绕过登录，不是撞库碰巧，是密码直接敲对了。
2. **他连管理员密钥也有。** 站内修改公告必须通过控制面板的二次验证（管理员密钥），他改成功了。**两把钥匙都在他手上。**
3. **他删了数据，然后把服务器打垮。** 193 个账号、200 件作品被删除，公告被换成一篇以站长口吻写的"已回档"声明；随后用两个不设防的接口，在 30 分钟内制造约 **21.6 万次数据库操作**，让主机商先后停掉主站与镜像站。

三次攻击的分布：

| | 时间（北京） | 目标 | 手法 | 结果 |
|---|---|---|---|---|
| 第一次 | 11-03 15:13 起 | 主站 | 管理员凭据登录 → 批量删作品 → 压量 | 作品 400 → 36，主站停站 |
| 第二次 | 11-04 18:35 起 | 主站 | 再次登录 → 清空作品与账号 → 伪造公告 → 双通道 DDoS | 作品归零、193 个账号消失、主站停站 |
| 第三次 | 11-04 22:02 起 | 镜像站 | 纯流量压量 | 只被打停，数据一条未动 |

**攻击者的四个特征**：

- **多 IP 轮换**：1,205 次管理操作，用了 **1,193 个不同 IP**，平均一个 IP 只用一次。
- **脚本化**：请求间隔严格 1–2 秒，五六个一组，**没有一次同秒并发**——人手点不出这种节奏。
- **递进式**：先删大半（留 36 件）试探反应，未被发现后彻底清零，再转向备份站。
- **反侦查**：逐请求换 IP、事后删除对自己不利的预警记录、伪造公告把入侵包装成运维事故。

---

## 三、被攻击的是个什么东西

先把受害对象说清楚，因为它决定了这件事的性质。

| 项 | 内容 |
|---|---|
| 站点性质 | 非商业性作品排行榜，收录 Kimi 社区用户做的作品，由一个人维护 |
| 内容来源 | 全部由社区用户一件一件提交 |
| 收入 | **无**。无充值、无广告、无付费内容、无任何变现 |
| 事件前规模 | 注册用户 **194 名**，作品约 **400 件** |
| 技术架构 | PHP + MariaDB，前端原生 JS，托管在免费主机上 |
| 主机账户 | if0_42664755（InfinityFree 免费套餐） |
| 源码 | 以 AGPL-3.0 在 GitHub 公开 |
| 镜像站 | 站长自建的备份与容灾站，与主站互为副本 |

**为什么要专门写这一节**：

- **它没有钱。** 所以这不是图财——删数据和打流量都换不到一分钱。
- **它只有 194 个用户。** 所以这不是"打大站蹭名声"——收益与成本完全不成比例。
- **它的内容全属于用户。** 200 件作品是 200 个作者花时间做出来的东西，不是平台自有资产。删掉它们，等于抹掉 200 个人的劳动。

**一个无收入、单人维护、194 个用户的小站，值得提前十二天预警、铺 1,193 个 IP、写两套脚本、三天打三次、打垮两台服务器。这件事本身，就是动机的答案。**

---

## 四、三次攻击全记录

按时间顺序。每一次都写清四件事：他怎么准备的、怎么动手的、造成什么、留下什么痕迹。

### 4.1 前置：十月二十一日的预警

**10 月 21 日深夜，一位外国用户在站内「世界对话」用英文反复发警告。**

大意是：你写了一套非常糟糕的认证逻辑，**在修好之前请把站关掉**。

站内内容审核拦截了原文，他把关键词拆成好几段才发出来。站长回复问了具体问题，**没有立刻排查**。

**这段话现在回头看，不是客套，是提前十二天的预告。** 而且它同时说明：这套认证逻辑的缺陷，在公开场合是可以被看出来的——所以攻击者不可能是"偶然撞上"。

### 4.2 第一次攻击：入侵主站（11 月 3 日）

#### 动手前一天：试探

**11-02 13:15:13**，有人在注册页填入邮箱 `sea@petalmail.com`，请求发送绑定验证码。

三个细节值得注意：

- 这个邮箱的域名**真实存在**，但**服务已经停止运营**——发出去的信没人收得到。
- 这次请求是**未登录状态**发起的（`user_id=0`）。
- 验证码有效期只有两分钟，之后有人**试填过一次**，失败。

**用一收不到信的邮箱去绑定账号，本身没有任何意义。** 它唯一的作用是测试：这套邮箱系统能不能用、报错会不会泄露信息、有没有频率限制。

#### 动手当天下午：热身

**14:57 到 15:07，一个叫 `callaormfv` 的账号连续登录十余次，全部成功，每次换一个 IP。**

| 时间 | 账号 | 结果 | 来源 IP（哈希前 8 位） |
|---|---|---|---|
| 14:57:51 | `callaormfv` | 成功 | `027b6f51` |
| 14:58:00 | `callaormfv` | 成功 | `838c4c25` |
| 14:58:10 | `callaormfv` | 成功 | `f1412386` |
| 14:58:36 | `callaormfv` | 成功 | `027b6f51` |
| …（共十余次，跨 9 个 IP） | | | |
| 15:07:04 | `callaormfv` | 成功 | `f1412386` |

**这不是正常用户。** 正常人不会在十分钟内换九个 IP 登同一个账号，更不会次次成功。

同一时段还有第二件事：

**15:00:22 到 15:01:14，一个叫 `nosuchus` 的账号连续登录失败 9 次**，IP 同样在轮换。

这是在探两件事：**哪些账号存在**、**错误提示会不会泄露"账号不存在"与"密码错误"的差异**。

#### 15:13:42：管理员登录成功

**`admin` 登录成功。**

这个账号的密码是 32 位随机串。两次登录都是**一次就对**。

14 秒后——**15:13:57**——第一条管理接口调用发出。从这一刻起，剩下的都不是人在操作。

#### 15:50–16:09：批量删除

| 时间 | 管理接口调用次数 | 特征 |
|---|---|---|
| 15:50–15:59 | 230 次 | 几乎全部固定执行 12 次数据库操作 |
| 16:00–16:09 | 218 次 | 同上 |

**节奏**：间隔 1 秒占 312 次、2 秒占 143 次（合计 93%），**一次同秒并发都没有**，连续段固定 5–6 个一组，中间停十几秒。

**结果：作品从约 400 件降到 36 件。**

#### 当天晚间：主站第一次停站

请求量触发主机商的滥用保护机制。

**当时未识别为攻击，没有留下面板截图。** 这是本次调查中最可惜的一处缺失——第一次攻击的现场数据，只留下了数据库里的结果。

#### 留下的痕迹

- `user_visits` 一条：`uid=8`、IP 归属地**广东广州**、`ip_hash=d213deea…`
- `login_attempts` 一条：账号 `admin`、`success=1`
- 请求记录 491 条管理调用，间隔 1–2 秒
- 作品表从约 400 行变成 36 行

### 4.3 第二次攻击：清空主站（11 月 4 日）

#### 18:35:34：再次登录

**`admin` 第二次登录成功。** IP 归属地显示**广东深圳**。

有人会问：11-03 拿到的令牌七日有效，为什么还要再登录一次？

**我们不知道。** 这是一个未解问题，写在第十二章。可能是主动换会话，也可能是不同的人或不同的机器。

#### 18:40–19:09：清空

| 时间 | 调用次数 | 主要动作（按数据库操作次数推断） |
|---|---|---|
| 18:40–18:49 | 147 次 | 先读列表、再筛、再改 |
| 18:50–18:59 | 270 次 | 批量写入，其中 176 次是同一个批量流程 |
| 19:00–19:09 | 235 次 | 反复拉列表核对结果 |

**"读 → 筛 → 写 → 再读核对"**，这是一段带确认步骤的自动化流程，不是一个人翻后台。

**结果：作品清零，193 个账号被删除。**

#### 19:10:57：伪造公告

**这一刻，数据库里同时发生两件事**：

1. 站内公告表的内容被替换，`updated_at` 变成 `19:10:57`
2. 设置表里的公告字段同步变成同一段文字，**逐字一致**

公告全文（100 字）：

> 【系统公告】站点已完成 v3.58.0 升级，数据库结构重建，全部作品与账号已回档至 11 月 2 日快照。期间注册的账号、提交的作品、评论与对话均已清空，请重新注册后重新提交。给你带来不便，敬请谅解。

**两个要害**：

- **它用站长的口吻写成。** 读起来像站方自己发的通知。
- **它把"数据被删"包装成"系统升级后的正常回档"。** 用户的第一反应不是"被攻击了"，而是"站长是不是跑路了"。

三分钟后，站内缓存重建，首页四个分类的作品计数全部变成 **0**。

**首页看起来，这个站已经空了。**

#### 19:31–20:01：把服务器打垮

这是整件事里最隐蔽的环节，也是停站的直接原因。

**两个接口，两个通道，都不需要登录：**

| 通道 | 请求数 | 数据库操作 | 特征 |
|---|---|---|---|
| 作品列表接口 | **10,088 次** | 10,088 × 7 = **70,616 次查询** | 单 IP，峰值 **171 次/秒**，同一秒大量并发 |
| 数据上报接口 | **约 1,821 次** | **145,655 次写入** | 单请求最多收 80 条，**每条单独写一次数据库** |
| **合计** | **约 12,247 次** | **约 216,271 次** | 全部集中在 **30 分钟内** |

第二个通道的要害是**写入放大**：

**发 1 个请求，服务器要做 80 次数据库写入。** 1,821 个请求，换来 14.5 万次写库。

而且这个接口**不被请求监控采集**——监控代码里写着"不采集监测接口自身，避免自激"。

结果就是：**19:58:09 那一秒，请求日志里 0 条记录，数据库里进了 1,813 条。**

**一个人在打数据库，监控面板上一切正常。**

这也是为什么主机商比站长先发现问题——他们看到的不是流量，是 CPU 和入口进程被占满。

#### 20:02：主站被暂停

主机商的理由：「达到每日资源上限、每日点击上限」。

#### 留下的痕迹

- `user_visits` 一条：`uid=8`、IP 归属地**广东深圳**、`ip_hash=bc05bc0b…`
- `login_attempts` 一条：账号 `admin`、`success=1`
- 714 次管理调用，间隔 1–2 秒
- 19:10:57 那一条：`api/admin.php` POST 200，执行 9 次数据库操作
- 若干 1,193 个不同 IP 的记录

### 4.4 第三次攻击：打停镜像站（11 月 4 日 22:02）

**主站停站两小时后，目标转向镜像站。**

这一站的情况完全不同：

| 项 | 主站 | 镜像站 |
|---|---|---|
| 版本 | v3.58.0（825 个文件） | v3.48.2（657 个文件） |
| 数据 | 被删除、被篡改 | **一条未动** |
| 管理接口调用 | 1,205 次 | **9 次**（均为站长自己的操作） |
| 攻击形态 | 入侵 + 破坏 + 压量 | **纯压量** |
| 停站时长 | 24 小时 | 24 小时 |

**这个对照是整个事件里最重要的线索之一。**

同一批人、同一个晚上、两台同源代码的站：一台被进去删了数据，另一台只是被打停。

**原因在版本差集里。**

用两个版本的完整文件树逐文件比对（GitHub Git Data API，v3.48.2 共 657 文件 / v3.58.0 共 825 文件）：

- 新增的对外接口文件**只有 `api/account.php`** 一个
- `api/auth.php` 新增四个动作：`reg_send`、`login_2fa`、`login_send`、`login_code`
- `api/admin.php` 新增五个动作，全部在鉴权之后，不构成新攻击面

**镜像站运行的版本里，"邮箱 + 验证码登录"这套系统根本不存在。**

所以：

- **镜像站不是"防住了"，是"没有那个入口"。**
- **攻击者对两台站做过版本甄别**——这排除了"无差别压量"的可能。
- **镜像站的价值在于它保住了全部证据**：它上面有一份破坏前的完整数据（含 200 条作品），成了唯一能还原被删内容的来源。

### 4.5 恢复（11 月 5 日 — 6 日）

| 时间 | 动作 |
|---|---|
| 11-04 晚 | 确认攻击性质；停掉对外服务；另存破坏前的备份；从缓存里取出伪造公告原文存档 |
| 11-05 | 整站 **944 个文件逐字节比对**：663 个与仓库一致，其余差异全是部署产物（缓存、锁文件、上传目录） |
| 11-05 | **没有后门，没有木马，没有新增账号，没有计划任务** |
| 11-05 20:02 / 22:03 | 主站与镜像站按主机规则自动恢复 |
| 11-06 07:18 | 镜像站解封，**数据一条没动** |

---

## 五、他做了什么：15 个动作

按动作列，不按阶段列。每一条都能对应到具体的数据库字段或请求记录。

| # | 时间（北京） | 动作 | 手法 | 后果 |
|---|---|---|---|---|
| 1 | 11-02 13:15 | 用停服邮箱请求绑定验证码，试填一次 | 试探邮箱系统的可用性与错误提示 | 留下一条测试记录 |
| 2 | 11-03 14:57–15:07 | `callaormfv` 在 9 个 IP 上连续登录成功十余次 | 确认账号可用、试探限速、铺 IP 池 | — |
| 3 | 11-03 15:00–15:01 | `nosuchus` 连续登录失败 9 次 | 探测账号存在性 | — |
| 4 | **11-03 15:13:42** | **用正确密码登录 `admin`** | 持凭据登录 | **取得管理员身份** |
| 5 | 11-03 15:13:57 – 16:09 | 491 次管理调用 | 脚本，1–2 秒一次，5 连发 | — |
| 6 | 11-03 15:50–16:09 | 448 次批量调用 | 批量清理流程 | **作品 400 → 36** |
| 7 | 11-03 晚间 | 打请求触发主机滥用保护 | 资源耗尽 | **主站第一次停站** |
| 8 | **11-04 18:35:34** | **再次登录 `admin`** | 同上 | **重获管理员身份** |
| 9 | 11-04 18:40–19:09 | 652 次管理调用 | 批量删除、清空 | **作品归零** |
| 10 | 11-04 18:40–19:09 | 删除账号 | 逐个删除 | **193 个账号消失** |
| 11 | **11-04 19:10:57** | **写入伪造公告**（同时改公告表与设置表） | 调用正常"改公告"接口 | **用户误以为数据已回档** |
| 12 | 11-04 19:10 前后 | 清空四个分类计数 | 触发缓存重建 | **首页显示"什么都没有"** |
| 13 | 11-04 19:31–20:01 | 单 IP 刷作品接口 **10,088 次** | 查询洪泛，峰值 171 次/秒 | **服务器负载打满** |
| 14 | 11-04 19:46–19:58 | 同一 IP 灌入 **145,655 条事件** | **写入放大**，1,821 请求 → 14.5 万次写库 | **数据库被打爆** |
| 15 | 11-04 20:02 / 22:02 | 主站、镜像站先后被停 | 借用平台资源规则 | **两站各停 24 小时** |

### 5.1 他刻意做的三件事

1. **清理对自己不利的记录。** 那位外国用户预警的"世界对话"与"反馈问题"两处，记录被专门处理。随机破坏不会挑这两处。
2. **用站长的口吻宣布"已回档"。** 让入侵看起来像运维事故，降低用户追问与举报的意愿。
3. **把最猛的一轮压在人最可能在场的时段。** 18:35 登录、19:10 出公告、19:52 起打接口。

### 5.2 他刻意没做的四件事

1. **没改一个字节的代码。** 944 个文件逐字节比对，无后门、无木马、无可执行文件。
2. **没动数据库层。** 如果他有数据库账号，第一件事就是删掉那两条能定位自己的登录记录。**记录还在。**
3. **没碰用户密码。** 被删账号是整行消失，不是被改成别人的密码。
4. **没留持久化后门。** 没有新增账号、没有计划任务、没有异常文件。

**这四件"没做"说明两件事**：

- 他的权限边界在**应用层**，不是系统层、不是数据库层。
- 他的目的不是长期控制，而是**让这个站消失**。所以他不需要 webshell，也不需要数据库账号。

---

## 六、证据：我们掌握了什么

这一章写给三类人看：想看证据的用户、想判断可信度的同行、以及准备受理案件的人。

### 6.1 密码是真的——这不是漏洞利用，是持凭据登录

登录尝试表里有两条 `admin` 的成功记录：

| 时间 | 账号 | 结果 | 来源 IP（哈希） |
|---|---|---|---|
| 11-03 15:13:42 | `admin` | **成功** | `d7b0ff6b…` |
| 11-04 18:35:34 | `admin` | **成功** | `801d90c2…` |

这个账号的密码是 32 位随机串，两次都是**一次就对**。

**撞库撞不出一次性命中的 32 位随机串。这是"知道密码"，不是"猜出密码"。**

这条证据的意义：**攻击者不是通过某个漏洞绕过登录进来的，而是持有凭据。** 所以后面所有分析都指向"凭据从哪来"，而不是"哪里能被绕过"。

### 6.2 管理员密钥也在他手上

站点的控制面板有二次验证：进入后台必须再输一次管理员密钥，通过后 1 小时内有效。

修改公告这个动作**必须通过这道验证**。

他在 19:10:57 改成功了。而当时站长的浏览器不在他手上。

**唯一解释：他同时掌握了管理员密钥。**

这条证据的意义：他不是"拿到了一个后台会话"，而是**两把钥匙都有**。这也解释了为什么"18:35 某人在四川登录、19:10 有人在改公告"看起来不连贯——动手的人不一定是有浏览器的人。

### 6.3 伪造公告精确可定位到一秒

请求记录里有一条：

```
时间：2026-11-04 19:10:57
接口：api/admin.php
方法：POST
状态：200
数据库操作：9 次
来源 IP 哈希：7ab6c45085…
```

同一秒，数据库里：

- `announcements.updated_at` = `2026-11-04 19:10:57`
- `announcements.content` 与 `settings['announcement']` **逐字一致**

**全站只有一处代码会同时写这两处**——就是"改公告"这个接口。

**手工改数据库的人通常只改一张表，不会同步维护设置表。**

这条证据的意义：这不是"数据库被人直接动了"，而是**通过应用接口执行的操作**。它同时证明：攻击者的权限止于应用层。

### 6.4 1,205 次管理操作，1,193 个 IP

| 项 | 数值 |
|---|---|
| 管理接口调用总数 | **1,205 次**（11-03 起） |
| 其中成功 | 1,186 次 |
| 返回 403 | 18 次 |
| 去重 IP 哈希 | **1,193 个** |
| 平均每个 IP 使用次数 | **1.01 次** |

**正常运维不会这样。** 一个人在两天里换 1,193 个网络出口，只能有一个目的：**回避基于 IP 的封禁与追溯**。

这条证据的意义：它不仅证明"这是攻击"，还证明**攻击者明知自己的行为违法**——否则不需要这样的隐匿设计。

### 6.5 节奏证明这是脚本

把管理请求按秒展开，看相邻请求的间隔：

| 间隔 | 11-03（491 次） | 11-04（701 次） |
|---|---|---|
| 1.0 秒 | **312** | **399** |
| 2.0 秒 | **143** | **173** |
| 3–4 秒 | 5 | 40 |
| 5 秒以上 | 约 31 | 约 89 |
| **同一秒内并发** | **0** | **0** |
| 连续段最长 | 5 | 5 |

- **93% 与 82% 的间隔落在 1 秒或 2 秒。**
- **一次同秒并发都没有**——说明是串行循环，一次等一次返回。
- **固定五六个一组，中间停十几秒**——批处理脚本的典型形状。

**人手点不出这种节奏。**

这条证据的意义：排除了"人手动操作"，也排除了"偶然的流量异常"。这是**预先写好的程序**在跑。

### 6.6 双通道 DDoS：一个 IP，21.6 万次数据库操作

| 通道 | 请求数 | 数据库操作 | 峰值 |
|---|---|---|---|
| 作品列表接口 | 10,088 次 | 70,616 次查询 | 171 次/秒 |
| 数据上报接口 | 约 1,821 次 | 145,655 次写入 | 2,725 条/秒 |
| **合计** | **约 12,247 次** | **约 216,271 次** | 30 分钟内 |

**这两个数字要分开看**：

- 请求数 **12,247** —— 看起来不多，一天几千次请求对任何网站都不算什么。
- 数据库操作 **216,271** —— 在 30 分钟内，共享主机的数据库扛不住。

**差距从哪来**：数据上报接口单请求最多收 80 条，每条单独写一次库。**发 1 个请求，服务器做 80 次写入。** 这就是"写入放大"。

**而且这个接口不被请求监控采集**——这一条写在监控代码里，原文是"不采集监测接口自身，避免自激"。

结果：**19:58:09 那一秒，请求日志 0 条，数据库 1,813 条。**

这条证据的意义：主机商判定的"资源超额"不是流量问题，是**数据库被写入打爆**。这也解释了为什么"看起来没什么请求"却会被停站。

### 6.7 数据是"被删除"，不是"丢失"

| 表 | 现在的行数 | 事件前 | 说明 |
|---|---|---|---|
| 作品 `works` | **0** | 约 400（镜像站备份中有 200） | 全表清空，导出文件里连一条 INSERT 都没有 |
| 账号 `users` | **1** | **194** | **193 个账号被删除**，只剩管理员 |
| 评论 `comments` | 4 | 更多 | 中间 id 断裂，被批量删除 |
| 公告 `announcements` | 1 | 1 | 内容被替换，编号未变——**改写而非新建** |

**反证**：`checkins` 表里还残留着 **157 个不同用户 id、最大 id 到 236**；`user_visits` 表最大 id 为 196。这些痕迹说明：**这个站原来确实有近两百个用户**。

**同一天、同一批人，镜像站的数据一条没动。**

**能选择"删什么、不删什么"，是操作，不是故障。**

### 6.8 邮件系统是"试探过的门"，不是"走进来的门"

11-02 那次发码之后，邮箱验证码表里**再没有任何新记录**。

如果攻击者真走过"绑自己邮箱 → 邮箱码登录"这条路，必然留下发码与消费记录。

**没有。** 而且管理员账号的邮箱三列全为空——从没有人绑过邮箱。

这条证据的意义：**排除了一个看起来很合理的假设。** 很多人第一反应是"他换绑了管理员邮箱"，数据说不是。

### 6.9 版本差集锁定了攻击面

GitHub 两个版本的完整文件树比对：

| 版本 | 文件数 | 有"邮箱 + 验证码登录"系统 | 本次结果 |
|---|---|---|---|
| v3.48.2（镜像站） | 657 | **没有** | 只被打停，数据未动 |
| v3.58.0（主站） | 825 | **有** | 被入侵，数据被删 |

**新增的对外接口只有 `api/account.php` 一个。**

这条证据的意义：它把"漏洞在哪"从"全站猜"缩小到"新增的这一个系统及其配套"。同时证明攻击者做过版本甄别。

---
## 七、预谋性与针对性

判断"预谋"还是"随机"，看四件事：准备、选择、节奏、收尾。本案四项俱全。

### 7.1 准备：至少提前十二天

| 时间 | 动作 | 意义 |
|---|---|---|
| 10-21 深夜 | 公开指出这套认证逻辑有严重缺陷，建议关站整改 | **他先说了，才动手** |
| 11-02 13:15 | 用停服邮箱试探邮箱系统 | 动手前一天测试入口 |
| 11-03 14:57 起 | 普通账号在 9 个 IP 上连续登录十余次 | 确认账号可用、试探限速、铺 IP 池 |
| 11-03 15:00 起 | 试一个不存在的账号 9 次 | 探测账号存在性与错误提示差异 |
| 11-03 15:13 | `admin` 登录成功 | **真正开始** |

**从被指出缺陷到真正动手，中间隔了十二天。**

这十二天里他做的事是准备，不是尝试。这个时间差本身就是主观故意的证明：**他知道有问题、知道问题在哪、也知道怎么用。**

### 7.2 选择：只挑两个不设防的接口

站点有几十个接口。他用了哪几个？

| 接口 | 是否需要登录 | 是否有频率限制 | 是否被监控采集 |
|---|---|---|---|
| 作品列表接口 | **否** | **否** | 是 |
| 数据上报接口 | **否** | **否** | **否** |

**这两个是他刷量时用的。** 其余需要登录、需要 CSRF、有限速的接口，一个都没碰。

**这不是试出来的，是看过后挑的。**

配合另一条：本站源码以 AGPL-3.0 公开在 GitHub。**必须假设对方读过全部代码。**

**这条也决定了防御思路**：你不能靠"隐藏接口"来防御一个读过源码的人。唯一可靠的是服务端在每个请求上重新核对身份与权限。

### 7.3 节奏：脚本化、批量化、可中断可续

| 特征 | 数据 |
|---|---|
| 管理操作时长 | 11-03 约 1 小时；11-04 约 1.5 小时 |
| 请求间隔 | 1 秒或 2 秒占 82%–93% |
| 连续段 | 固定 5–6 个一组，中间停十几秒 |
| 管理操作同秒并发 | **0 次**（严格串行） |
| 刷量侧同秒并发 | **大量**（同一秒 171 次） |

**管理操作串行、刷量并发。**

同一个人的手不可能同时用两种并发策略。这说明：**至少是两段不同的代码，甚至可能是两个执行者。**

### 7.4 收尾：抹痕与误导

按顺序：

1. **清空四个分类的作品计数**——让首页看起来"什么都没有了"。
2. **伪造公告**——用站长口吻宣布"已回档至 11 月 2 日快照"。
3. **清理预警所在的两处记录**——世界对话、反馈问题。
4. **不装后门、不改代码**——不留可供追查的技术痕迹。

**抹痕 + 误导 + 选择性删除，三者同时出现，是"有意识掩盖"的标准形态。**

一个只想搞破坏的人，不会写一篇语法通顺、口吻贴切、还带"敬请谅解"的公告。

### 7.5 针对性：不是随机撞上的

| 判据 | 说明 |
|---|---|
| 目标专一 | 三次攻击全压在这两台站上，别处没碰 |
| 删除有指向 | 只清对攻击者不利的预警记录 |
| 三波递进 | 先删大半（留 36 件），再彻底清零，再转向备份站 |
| 不图财 | 站点无收入、无支付、无数据可卖 |
| **版本甄别** | 镜像站只有流量攻击、无数据破坏——因为那个版本没有邮箱登录入口 |
| 时机选择 | 压在站长登录后的窗口内动手 |
| 双站意识 | 主站停站 **2 小时** 后立刻转向镜像站 |

**最后两条最能说明问题。**

- "版本甄别"：如果只是无差别压量，不会出现"一台删数据、另一台只打流量"的分化。这种分化只能来自"他知道两台跑的不是同一个版本"。
- "双站意识"：随机扫描器不会知道这个站还有一个 byethost33 的镜像。

**随机扫描的典型特征是：广撒网、不区分对象、不关心结果。本案完全相反——只打两个站、区分版本、关心"删得干净不干净"。**

---

## 八、造成的损失

### 8.1 损失总表

| 维度 | 损失 | 依据 |
|---|---|---|
| **数据** | 作品约 **400 件**被删除；**193 个账号**被删除（原有 194 名用户）；评论被批量删除；公告被篡改 | 数据库现状 vs 镜像站备份（备份中有 200 条作品） |
| **服务** | 主站停站两次（11-03 晚间；11-04 20:02 → 11-05 20:02）；镜像站停站一次（11-04 22:02 → 11-05 22:03） | 主机商暂停通知 |
| **运营** | 提交、评分、对话、注册反复中断，主站累计 24 小时以上 | 同上 |
| **声誉** | 伪造公告导致用户误以为站方跑路或数据全失；社区出现质疑 | 用户反馈与群内讨论 |
| **恢复成本** | 944 个文件逐字节比对、数据库核对与恢复、缓存重建、安全加固与三次发版 | 操作记录 |
| **运营投入损失** | 30 天内 **约 62 元**已消耗直接支出（DeepSeek 21.9953 元 + ima 算力约 40 元）、1,891 次请求、3.35 亿 tokens、1,280 算力全部耗尽，投入归零重来 | 两个平台的账单与用量记录（8.4 节） |
| **用户损失** | 193 名用户的账号数据、签到记录、作品数据被删除；200 件作品是 200 个作者的劳动成果 | 数据库与备份对照 |

### 8.2 为什么"数据损失"不能按行数算

**作品不是平台自有的内容资产。**

每一件作品，都是社区里的一个人花时间做出来的：写代码、调样式、想交互、反复改，然后提交到这个站上，希望被人看见、被人评分。

它们不在别处有备份。删掉一件，就是消失一件。

**200 件作品被删，等于 200 个作者的劳动成果在同一天被人按下删除键。** 这部分损失不能只按"数据库行数"计算，也不能用"网站没收入所以没损失"来否定。

同样，**193 个账号被删**——不是 193 行数据，是 193 个人在这个社区里的身份、签到记录、评论历史。

### 8.3 可用于报案的损失量化（模板）

报案时，"后果严重"需要用数字支撑。下面这张表可以直接填：

| 项目 | 计算方式 | 金额 |
|---|---|---|
| 数据恢复工时 | 数据库核对、比对、恢复的实际小时数 × 时薪 | 待填 |
| 文件完整性核验工时 | 944 个文件逐字节比对的实际小时数 × 时薪 | 待填 |
| 安全加固开发工时 | 三次发版（审计、指纹、权限收口）的实际小时数 × 时薪 | 待填 |
| 服务中断损失 | 停站时长 × 站点日均运营价值（可按替代成本法估算） | 待填 |
| 数据重建成本 | 200 件作品若需重新征集的时间与组织成本 | 待填 |
| 应急处置成本 | 事件期间的全部投入 | 待填 |
| **合计** | | **目标：≥10,000 元** |

**说明**：即使站点没有收入，投入的工时也是可核算的经济损失。这一栏填满，"造成经济损失一万元以上"的认定就有支撑。

### 8.4 运营投入：这个站不是零成本

有人会说"网站没收入，所以删了也没损失"。这句话是错的。**成本与收入是两件事。**

站方保留了两个平台的全部账单与交易记录，覆盖开发、日常运维与事件处理三个时期：

| 来源 | 计费方式 | 金额 | 明细 |
|---|---|---|---|
| **DeepSeek API** | 按量计费（后付费） | **21.9953 元** | 2026-10-08 至 11-06，17 天有消费；**1,891 次请求**；输入 8,220,032 tokens（未命中缓存）+ 326,966,912 tokens（命中缓存）+ 输出 1,509,529 tokens |
| **ima 算力** | 预付费充值，**已全部消耗** | **约 40.00 元**（购买 800 算力，按 5 元/100 算力计） | 用量页显示：**购买 800/800、赠送 480/480，合计 1,280 算力，100% 用完**；其中 200 算力原定 2027-09-25 过期、50 算力 2026-11-06 过期 |
| **合计** | | **约 61.99 元** | 其中 21.9953 元（DeepSeek 账单）与 25 元（5 笔交易流水）有直接凭证，其余约 15 元按同一单价推算 |

**两项都已消耗完，不存在"还剩余额"的空间**：

- DeepSeek：按 token 结算，**21.9953 元已实际支出**，账单按天可查。
- ima：购买 800 算力 + 活动赠送 480 算力，**1,280 算力全部用完**，用量页显示两条进度条均为 100%。

**也就是说：这个站三十天的开发与运维，光 AI 算力就消耗了约 62 元，且全部为已发生支出。** 日均区间 0.0089 元 — 4.3945 元，峰值出现在 10-31（4.3945 元，制作求助视频那天）。

**口径说明（避免被质疑）**：

- 21.9953 元：来自平台导出的按天账单，精确到小数点后四位。
- 25 元：来自 5 笔交易记录截图（每笔"购买 100 算力 -5 元"）。
- 余下约 15 元（3 笔 × 100 算力）：由用量页"购买 800 算力已用完"与同一单价推算，**为保守估计，不作唯一依据**。
- 赠送的 480 算力不计入金额（免费获得），但计入消耗量。

**按日期看，能对上事件节奏**：

| 时间 | 请求数 | 对应的事 |
|---|---|---|
| 10-21 | 110 次 | 收到预警那天，在查认证逻辑 |
| 10-24 — 10-27 | 136 / 133 / 114 / 164 次 | 日常开发与迭代 |
| 10-31 | **501 次** | 求助视频与梳理材料 |
| 11-03 | 12 次 | 第一次攻击当天（站被打停，人能做的很少） |
| 11-04 | 125 次 | 第二次攻击当天 |
| 11-05 | **350 次** | 取证、比对、复盘 |
| 11-06 | 139 次 | 文档与加固 |

**这三条的意义**：

1. **它是一个有真实支出的项目。** 不是"随手做着玩"，是持续 30 天、按天付费在维护。
2. **它证明了投入的时间成本。** 1,891 次请求、3.35 亿 tokens 的处理量，对应的是一个人三十天的实际劳动。
3. **它是可以直接举证的书面凭证。** 两家平台的账单都是可导出、可核对的第三方记录——**比自述工时更硬**。

**同时也要诚实**：约 62 元这个数字本身远达不到"一万元以上"的门槛。它的作用是**反证"无收入 = 无损失"的错误逻辑**，并为 8.3 的工时折算提供参照——它证明这个站确实在被持续投入，而不是闲置。

---

## 九、法律定性

### 9.1 涉嫌罪名（一）：破坏计算机信息系统罪

**《刑法》第二百八十六条**

> 第一款：违反国家规定，对计算机信息系统功能进行删除、修改、增加、干扰，造成计算机信息系统不能正常运行，后果严重的，处五年以下有期徒刑或者拘役；后果特别严重的，处五年以上有期徒刑。
>
> 第二款：违反国家规定，对计算机信息系统中存储、处理或者传输的数据和应用程序进行删除、修改、增加的操作，后果严重的，依照前款的规定处罚。

**构成要件逐条对应：**

| 要件 | 本案事实 | 证据 |
|---|---|---|
| 违反国家规定 | 未经授权进入他人系统并实施破坏 | 无授权记录；登录凭据非本人提供 |
| **删除**数据 | 作品约 400 件、账号 193 个、评论若干被删除 | 数据库现状 vs 备份 |
| **修改**数据 | 公告被替换为伪造内容；四个分类计数被清零 | 公告表 `updated_at`、缓存时间戳 |
| **干扰**功能 | 30 分钟内约 21.6 万次数据库操作，系统无法响应 | 请求记录 + 事件灌入记录 |
| 造成不能正常运行 | 主站停站两次、镜像站停站一次，均为主机商强制暂停 | 主机商暂停通知 |

**量刑区间**：后果严重 → **五年以下有期徒刑或拘役**；后果特别严重 → **五年以上有期徒刑**。

### 9.2 涉嫌罪名（二）：非法控制计算机信息系统罪

**《刑法》第二百八十五条第二款**

> 违反国家规定，侵入前款规定以外的计算机信息系统或者采用其他技术手段，获取该计算机信息系统中存储、处理或者传输的数据，或者对该计算机信息系统实施非法控制，情节严重的，处三年以下有期徒刑或者拘役，并处或者单处罚金；情节特别严重的，处三年以上七年以下有期徒刑，并处罚金。

**对应本案**：攻击者通过获取管理员凭据，实际取得了对后台管理功能的控制权，并以管理员身份执行删除、修改、发布公告等高权限操作——**属于"对该计算机信息系统实施非法控制"**。

**量刑区间**：情节严重 → **三年以下**；情节特别严重 → **三年以上七年以下**。

### 9.3 "后果严重"怎么认定

《最高人民法院、最高人民检察院关于办理危害计算机信息系统安全刑事案件应用法律若干问题的解释》（法释〔2011〕19 号）第四条列举了"后果严重"的情形：

| 条文 | 本案 | 评估 |
|---|---|---|
| （一）造成十台以上系统的主要软件或硬件不能正常运行 | 1 个系统 | 不满足台数，但停站事实成立 |
| （二）对二十台以上系统中的数据进行删除、修改、增加 | 1 个系统，但删除 193 个账号、400 件作品 | **台数不达标，删除行为本身成立** |
| （三）违法所得五千元以上或造成经济损失一万元以上 | 站点无收入；工时与恢复成本可核算 | **可通过损失举证达成** |
| （四）造成为一万以上用户提供服务的系统不能正常运行累计一小时以上 | 停站累计远超一小时；注册用户 194 名 | 用户规模不足 |
| （五）造成其他严重后果 | 三次停站 + 193 个账号数据灭失 + 200 件作品灭失 + 伪造公告引发信任危机 | **兜底条款，可主张** |

**必须诚实写清楚**：本案在"台数"和"用户规模"两项量化指标上偏弱——站点小、用户少、无收入。**这不是行为不严重，而是量化标准对小型站点不利。**

**但同时也要写清楚另一半**：

- "对数据进行删除操作"是 286 条第二款的**要件事实**，不因用户数少而消失。
- 法释里"二十台以上系统"按**系统台数**算，不按数据条数算——所以它构不成加重情节，**但也抹不掉行为本身**。
- 三条停站、193 个账号、400 件作品、一篇伪造公告，合起来完全可以主张第（五）项"其他严重后果"。

**现实路径**：第 9.1 节的损失量化表填满，走（三）通道；同时主张（五）兜底。

### 9.4 从重情节

| 情节 | 事实 | 法律意义 |
|---|---|---|
| **预谋** | 距公开预警十二天后动手；动手前一天试探邮箱 | 排除"一时冲动"，属直接故意 |
| **组织性** | 备用账号、代理 IP 池（1,193 个）、两套脚本、双通道工具 | 非单人临时行为 |
| **反侦查** | 逐请求更换 IP；删除预警记录；伪造公告误导 | **明知违法、刻意规避，主观恶性重** |
| **反复性** | 三天内三次攻击，第一次未被发现后继续第二、第三次 | 排除"过失"与"误操作" |
| **扩大损害** | 主站停站后两小时内转向镜像站 | 主动追击备份节点 |
| **破坏性** | 193 个账号数据被删、400 件作品被删、三次停站、声誉损害 | 后果叠加 |

**其中"逐请求更换 1,193 个 IP"最值得注意。**

它不是技术能力的体现，而是**明知行为违法、刻意规避追溯**的直接证明。**在主观故意与量刑上，这一条比"删了多少数据"更有分量。**

### 9.5 其他法律责任

| 依据 | 内容 |
|---|---|
| 《网络安全法》第二十七条 | 任何个人和组织不得从事非法侵入他人网络、干扰他人网络正常功能、窃取网络数据等危害网络安全的活动 |
| 《网络安全法》第六十三条 | 违反第二十七条的，没收违法所得，处五日以下拘留，可并处五万元以上五十万元以下罚款；情节较重的，处五日以上十五日以下拘留，可并处十万元以上一百万元以下罚款 |
| 《治安管理处罚法》第二十九条 | 侵入计算机信息系统、干扰计算机信息系统正常功能等，处五日以上十日以下拘留 |
| 《民法典》第一千一百六十五条 | 过错侵害他人民事权益造成损害的，应承担侵权责任 |
| 《民法典》第一千一百九十四条 | 网络用户、网络服务提供者利用网络侵害他人民事权益的，应承担侵权责任 |

### 9.6 追诉时效

《刑法》第八十七条：法定最高刑为五年以上不满十年有期徒刑的，经过十年；法定最高刑为十年以上有期徒刑的，经过十五年。

- 破坏计算机信息系统罪（286 条）情节特别严重时，法定最高刑可达十五年 → **追诉时效可至十五年**。
- 非法控制计算机信息系统罪（285 条二款）情节特别严重时，法定最高刑七年 → **追诉时效十年**。

**换句话说：这不是"过一阵就没人管了"的事。**

### 9.7 建议的处置路径

1. **固定证据**。本报告引用的数据库导出、文件包、请求记录、面板导出、主机商停站通知，全部做哈希存档，多份异地保存。
2. **向主机商书面调取日志**。账户被暂停期间的访问日志，主机商后台通常只保留有限时间——**这是取得明文 IP 的唯一内部途径，应尽快申请**。
3. **报案**。以"破坏计算机信息系统"向网安部门报案，随附本报告与第 8.3 节的损失清单。管辖地为受害人所在地或服务器所在地公安机关。
4. **损失举证**。把第 8.3 节的表格填满，形成书面材料。
5. **同步固定外部证据**。涉事邮箱域名的注册信息；GitHub 仓库的访问与克隆记录（**保留期仅 14 天，需立即导出**）。

---

## 十、给攻击者的话

这一章是写给一个人的。

如果你正在读，并且知道自己做过什么，那么下面每一句都不是修辞，是事实。

### 10.1 我们知道你做了什么

不是"大概知道"，是逐条对得上：

- 你在 11 月 2 日 13:15，用一个停服的邮箱试过我们的注册流程。
- 你在 11 月 3 日 14:57 开始，用一个普通账号在 9 个 IP 上连续登录十余次，全部成功。
- 你在 15:00 试了一个不存在的账号 9 次。
- 你在 **15:13:42** 用管理员密码登录成功，**14 秒后**开始调管理接口。
- 你在 15:50 到 16:09 之间调用了 448 次批量接口，把作品从 400 件删到 36 件。
- 你在 11 月 4 日 **18:35:34** 又登录了一次，然后调用了 652 次，把剩下的全清空。
- 你在 **19:10:57** 写了一篇 100 字的公告，用站长的口吻宣布"已回档"。
- 你在 19:31 到 20:01 之间，用一个 IP 打了我们 12,247 次请求，其中 145,655 条是伪造的数据上报——**让数据库在 30 分钟里做了 21.6 万次操作**。
- 主站停站两小时后，你在 22:02 转向镜像站，又打了一次。

**这些不是推测。每一条都在数据库里，有字段、有时间、有编号。**

### 10.2 我们知道你怎么做的

- 你换 IP，换得很勤——1,205 次管理操作，1,193 个不同出口。
- 你写脚本，节奏是 1 到 2 秒一次，五六个一组。
- 你挑了不需要登录、没有限速、不被监控采集的接口。
- 你读过程序——否则你不会知道数据上报接口单次最多收 80 条、每条单独写库。
- 你事后删掉了对自己不利的记录，还把入侵写成了一次"系统升级"。

**我们把这些都写进了这份通报，包括你怎么掩饰的。**

### 10.3 我们掌握了什么

- 完整的数据库导出（含全部请求记录与前端埋点）
- 完整的站点文件包（944 个文件）
- 两个版本的完整文件树（用于证明镜像站为什么没被打进去）
- 主机商的两次停站通知
- 事件期间的异常检测记录与告警

**全部做了哈希存档，多份异地保存。**

### 10.4 关于你的"匿名"

我们知道你换 IP 是为了什么：**让你觉得没有人能追到你。**

这个思路只对了一半。

- 站方确实只存 IP 的哈希——我们不保存明文地址，这是我们一贯的设计。
- **但主机商保留访问日志。** 我们的服务器不在你手里，日志不在你手里。
- **邮箱服务方保留注册与发码记录。** 如果那条邮箱线索有用，那边的记录比我们这边全。
- **代码托管平台保留访问与克隆记录。** 谁在什么时候拉过这份源码，平台有账。

**换 1,193 个 IP 能拖慢追溯，但拖不住追溯。** 你已经多花了这份力气，说明你当时就知道自己在做什么。

### 10.5 法律后果

- **破坏计算机信息系统罪**（《刑法》286 条）：情节严重，五年以下；情节特别严重，五年以上。
- **非法控制计算机信息系统罪**（《刑法》285 条二款）：情节严重，三年以下；情节特别严重，三年以上七年以下。
- **追诉时效十年至十五年。**

**这两条罪名不是"网站告你"才成立——是公诉罪。** 立案之后，不是我们说停就能停的。

### 10.6 我们的立场

三句话：

1. **我们不打算私下解决。** 不会因为"删掉数据就没事"，也不会接受任何形式的"和解换沉默"。
2. **我们也不会冤枉人。** 这份通报里的每一条都有证据支撑，没有一条是基于猜测的人身指控。如果你认为其中哪一条与事实不符，可以按法律途径提出异议。
3. **但如果你还打算再来一次**：你要面对的不再是一个没有日志的小站。日志已经有了，审计已经有了，指纹绑定已经有了，两个最脆的接口也已经补上。

### 10.7 最后一句

这个站没有收入，只有 194 个用户。它不值得你花十二天准备、1,193 个 IP、两套脚本、三天三次攻击。

**你做的这些，法律上有名字。**

---

## 十一、我们做了什么

### 11.1 事发当时

| 时间 | 动作 |
|---|---|
| 11-04 晚 | 确认性质；停掉对外服务；另存破坏前的备份；从缓存里取出伪造公告原文存档 |
| 11-05 | 944 个文件逐字节比对（663 个一致，其余为部署产物）；确认无后门、无木马 |
| 11-05 | 清除伪造公告、重建缓存、从镜像站恢复数据 |

### 11.2 代码层面的加固

| 修复项 | 解决的问题 |
|---|---|
| 登录、改公告、删作品、删账号、绑/解邮箱、进面板——全部落审计表 | 原来日志目录不存在，写日志的调用一直被静默忽略 |
| 日志目录缺失时自动创建，单文件超 4 MB 轮转 | 同上 |
| 解除邮箱绑定必须输入当前密码；解绑与关闭双重验证拆成两个动作 | 账号接管辅助 |
| 面板授权的指纹与当前网络、浏览器绑定，换环境必须重新验证 | 会话被搬走即可复用 |
| 站内链接只允许站内地址与 `http(s)`、`mailto` | 脚本注入 |
| 站点互通只同步普通用户数据，账号角色与口令不跨站覆盖 | 跨站越权 |
| 抓取外部链接前做内网地址过滤，跳转改为逐跳手动校验 | SSRF |
| 未登录发码不再提示邮箱是否已注册 | 账号枚举 |
| 后台凭据不再保留明文副本 | 明文与哈希并存 |
| 令牌根密钥、管理员密码、管理员密钥全部更换 | 所有旧令牌一次性失效 |

### 11.3 仍未完成的

| 项 | 原因 |
|---|---|
| **面板密钥与登录密码分离派生** | 这是本次事件最关键的放大器。目前两者同源，**一处泄露等于两把钥匙同时失守**。需要改配置结构，站长需重新设置 |
| 令牌回库校验 | 改动面大，需单独发版 |
| 令牌加密加完整性校验 | 同上 |
| 审计日志的查看界面 | 目前只能查库 |

### 11.4 对用户的交代

- 数据已从镜像站备份恢复，恢复的是 11 月 4 日的状态。
- 期间提交但未进入备份的作品，无法找回。这一点不掩饰。
- 所有账号的登录凭据已更换，旧令牌全部失效。
- 如果你发现账号无法登录，请重新注册或联系站长。

---

## 十二、仍未查清

不把猜测写成结论。

| # | 问题 | 缺什么 |
|---|---|---|
| 1 | **管理员密码从哪来。** 这是唯一无法从数据推出的一环 | 密码的生成、保存、复制路径；是否曾出现在截图、聊天记录、浏览器保存口令中 |
| 2 | **管理员密钥怎么到手** | 与第 1 条同源——需确认线上配置里密钥与密码是否确实同值派生 |
| 3 | **`callaormfv` 是谁的账号** | 该账号已被删除，库中无痕；需从更早备份取注册时间与来源 |
| 4 | **11-04 18:35 那次登录的归属** | 记录显示归属地深圳，站长常住四川；站长本人称这是他自己的登录。两条信息不一致，无法从数据判定 |
| 5 | **为何 11-04 又登录一次**（11-03 的令牌七日有效） | 需令牌签发与使用记录，站内未保存 |
| 6 | **那个停服邮箱是否系其所用** | 只有一次发码、一次试错，无法证实 |
| 7 | **是否存在未发现的后门** | 944 文件比对无异常，但基准是 11-04 的包，无法覆盖更晚变化 |
| 8 | **刷量 IP 与入侵 IP 池是否同源** | 两组哈希不同，站方只存哈希、无明文 IP，无法做归属与 ASN 关联 |

**第 1 条是根。** 其余七条都挂在它下面：只要知道他怎么拿到密码，密钥、账号、时机就都能对上。

**为什么要把"没查清"公开写出来**：

因为一份只写"我都知道"的通报，是没有人信的。**把不知道的部分标出来，已知的部分才站得住。**

---

## 十三、附录

### 13.1 证据文件

| 材料 | 内容 | 规模 |
|---|---|---|
| 主站数据库导出 | 全部数据表，含 `user_visits`、`login_attempts`、`announcements`、`web_srv`、`web_events` | 55 MB |
| 镜像站数据库导出 | 破坏前的完整备份（含 200 条作品） | 503 KB（解压后） |
| 主站文件包 | 944 个文件，用于与仓库逐字节比对 | 5.4 MB |
| 异常检测面板导出 | 主站 / 镜像站 7 天路由统计、事件、告警 | 2.66 MB / 2.59 MB |
| 攻击现场截图 | 两次暂停通知、预警对话 8 张 | 9 张 JPG |
| 开源仓库版本树 | v3.48.2（657 文件）与 v3.58.0（825 文件）完整文件树 | — |

### 13.2 关键查询（可复现）

```sql
-- 两次管理员登录
SELECT id, user_id, ip_masked, location, ip_hash, created_at
FROM user_visits WHERE created_at >= '2026-11-01' ORDER BY id;

-- 登录尝试（含账号与成败）
SELECT id, user_norm, success, ip_hash, created_at
FROM login_attempts WHERE created_at >= '2026-11-02' ORDER BY created_at;

-- 公告被改写的时刻
SELECT id, oid, updated_at, content FROM announcements;
SELECT v FROM settings WHERE k = 'announcement';

-- 数据缺失
SELECT (SELECT COUNT(*) FROM users) AS users,
       (SELECT COUNT(*) FROM works) AS works,
       (SELECT COUNT(*) FROM comments) AS comments;

-- 伪造公告那一条请求
SELECT created_at, route, method, code, db_n, ip_hash FROM web_srv
WHERE created_at BETWEEN '2026-11-04 11:10:50' AND '2026-11-04 11:11:05';

-- 管理操作全序列（用于节奏分析）
SELECT created_at, code, db_n, ip_hash FROM web_srv
WHERE route = 'api/admin.php' AND method = 'POST'
  AND created_at >= '2026-11-03' ORDER BY created_at;

-- 刷量峰值
SELECT LEFT(created_at, 16) AS minute, COUNT(*) n FROM web_srv
WHERE route = 'api/works.php' AND method = 'POST'
  AND created_at >= '2026-11-04 11:00:00'
GROUP BY minute ORDER BY n DESC LIMIT 10;

-- 埋点灌入（写入放大）
SELECT ip_hash, COUNT(*) n, MIN(created_at), MAX(created_at)
FROM web_events WHERE name LIKE '/probe/%'
GROUP BY ip_hash ORDER BY n DESC;
```

### 13.3 给其他站长的加固清单

这条不是给本案用的，是给别人用的——同样的代码可能部署在别处。

| 优先级 | 检查项 |
|---|---|
| **最高** | 管理员密码与面板密钥必须**独立派生、独立轮换**。同源等于只有一把钥匙 |
| **最高** | 任何接收数据写入的公开接口，必须有鉴权或令牌 + 严格频率限制 + 批量写入 |
| **最高** | 请求监控不要排除自身接口。至少保留"请求总量 + 来源"的计数 |
| **高** | 管理员操作全程落审计表：谁、何时、从哪、做了什么、结果 |
| **高** | 令牌校验回库比对角色与封禁状态，不要只信令牌内容 |
| **高** | 面板二次验证绑定网络与设备指纹，换环境必须重验 |
| **中** | 批量删除必须有二次确认 + 可回滚 |
| **中** | 内容里的链接做协议白名单，禁止 `javascript:` |
| **中** | 抓取外部链接前过滤内网地址，关闭自动跳转 |
| **中** | 未登录接口不要泄露"账号/邮箱是否存在" |

### 13.4 一句话收尾

这个站没有收入，只有 194 个用户。攻击者花的心思——提前试探、铺 1,193 个 IP、写两套脚本、伪造公告、三天打三次、打垮两台服务器——**远超这个站本身的价值**。

所以这不是图利，是图它消失。

**而这类行为，法律上有名字，也有刑期。**

---

**通报结束**
**证据存档完成时间**：2026-11-06
INCDOC36;
                db_exec('UPDATE incidents SET doc = ?, summary = ?, culprit = ?, ended_at = ?, updated_at = UTC_TIMESTAMP() WHERE title LIKE ?',
                    array(
                        $doc36,
                        '三次攻击：主站两次被入侵（作品清空、193 个账号删除、伪造公告），镜像站被打停；含完整时间线、证据链与法律定性。',
                        '未确认 · 疑似外部人员（两次登录记录归属地显示广东，未证实）',
                        '2026-11-06 07:18:00',
                        '11·3%'
                    ));
                app_log('schema migrated to v36（事件档案正文-技术版）');
                setting_set('schema_version', '36');
            }
        } catch (Throwable $e) {
            app_log('migrate v36 failed: ' . $e->getMessage());
        }
    }

    $ok = true;
    foreach (array('comments', 'feedback') as $t) {
        if (table_exists($t) && !column_exists($t, 'content_norm')) { $ok = false; }
    }
    if (table_exists('comments') && !column_exists('comments', 'is_blocked')) { $ok = false; }
    foreach (array('country', 'province', 'city') as $c) {
        if (table_exists('user_visits') && !column_exists('user_visits', $c)) { $ok = false; }
    }
    foreach (array('or_models', 'or_state', 'ai_daily_quota', 'ai_answer_cache',
                   'discipline_reports', 'banned_ips',
                   'peers', 'sync_ids', 'sync_tombs') as $t) {
        if (!table_exists($t)) { $ok = false; }
    }
    if (table_exists('user_visits') && !column_exists('user_visits', 'ip_hash')) { $ok = false; }
    if (table_exists('login_attempts') && !column_exists('login_attempts', 'user_norm')) { $ok = false; }
    if (table_exists('users') && !column_exists('users', 'ban_until')) { $ok = false; }
    if (table_exists('discipline_reports') && !column_exists('discipline_reports', 'ban_until')) { $ok = false; }
    if (table_exists('discipline_reports') && !column_exists('discipline_reports', 'tamper_count')) { $ok = false; }
    foreach (array('works', 'comments', 'messages', 'ai_messages', 'discipline_reports', 'feedback', 'announcements') as $t) {
        if (table_exists($t) && !column_exists($t, 'oid')) { $ok = false; }
    }
    foreach (array('web_events', 'web_alerts', 'web_srv') as $t) {
        if (!table_exists($t)) { $ok = false; }
    }
    if (table_exists('web_events') && !column_exists('web_events', 'version')) { $ok = false; }
    if (table_exists('comments') && !column_exists('comments', 'target_type')) { $ok = false; }
    if (table_exists('ai_usage') && !column_exists('ai_usage', 'provider')) { $ok = false; }
    if (table_exists('banned_ips') && !column_exists('banned_ips', 'geo_lat')) { $ok = false; }
    if (table_exists('works') && !column_exists('works', 'link_checked_at')) { $ok = false; }
    if (table_exists('works') && !column_exists('works', 'feat')) { $ok = false; }
    if (!table_exists('audit_log')) { $ok = false; }
    if (!table_exists('access_log')) { $ok = false; }
    if (!table_exists('incidents')) { $ok = false; }
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
        'works'    => array('vote_count', 'peak_score', 'link_checked_at', 'feat'),
        'user_visits' => array('country', 'province', 'city'),
        'or_models'      => array('model_id', 'name', 'context_length', 'is_active', 'fetched_at'),
        'or_state'       => array('refresh_date', 'api_used', 'api_limit', 'site_day', 'site_used'),
        'ai_daily_quota' => array('user_id', 'day', 'used'),
        'ai_answer_cache'=> array('q_norm', 'question', 'answer', 'hits', 'updated_at'),
        'ai_usage'       => array('user_id', 'provider', 'total_tokens', 'created_at'),
        'web_events'     => array('version'),
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


/** 拥有全站统一编号的表 */
function oid_target_tables(): array
{
    return array('works', 'comments', 'messages', 'ai_messages', 'discipline_reports', 'feedback', 'announcements');
}

/**
 * 为历史行回填对象编号：按其创建日期（UTC）生成，逐条避免表内重复。
 * 幂等——只处理 oid 为空的行，重复调用安全。
 */
function oid_backfill(array $tables): int
{
    $filled = 0;
    foreach ($tables as $t) {
        if (!table_exists($t) || !column_exists($t, 'oid')) { continue; }
        $timeCol = column_exists($t, 'created_at') ? 'created_at'
                 : (column_exists($t, 'updated_at') ? 'updated_at' : '');
        $sel = $timeCol !== '' ? '`id`, `' . $timeCol . '` AS ts' : '`id`';
        try {
            foreach (db_all('SELECT ' . $sel . ' FROM `' . $t . "` WHERE `oid` IS NULL OR `oid` = ''") as $r) {
                $ts  = (string)(isset($r['ts']) ? $r['ts'] : '');
                $ymd = preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $ts, $m) ? ($m[1] . $m[2] . $m[3]) : gmdate('Ymd');
                $oid = oid_generate($t, $ymd);
                db_exec('UPDATE `' . $t . '` SET `oid` = ? WHERE `id` = ?', array($oid, (int)$r['id']));
                $filled++;
            }
        } catch (Throwable $e) { /* 单表异常不影响其余表 */ }
    }
    return $filled;
}
