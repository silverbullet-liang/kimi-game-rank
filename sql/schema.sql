-- ============================================================
--  Kimi游戏榜 数据库结构  v1.0
--  MySQL 5.5+ / utf8mb4
--  说明：install.php 会自动执行本文件（主库）；管理员凭证库单独建。
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 用户表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(64)  NOT NULL COMMENT 'NFC 归一化后的原始用户名（展示用）',
  `username_norm`   VARCHAR(64)  NOT NULL COMMENT '唯一性规整值（去空格/空白/不可打印）',
  `role`            ENUM('user','admin','subadmin') NOT NULL DEFAULT 'user' COMMENT '身份（徽章/权限）',
  `uid8`            CHAR(8)      NULL COMMENT '8 位可逆 UID（管理员/副管理员为豹子号）',
  `password_hash`   VARCHAR(255) NOT NULL COMMENT 'PBKDF2(md5->sha256+utc->pbkdf2) 链路结果',
  `salt`            VARCHAR(64)  NOT NULL COMMENT '每用户随机盐',
  `registered_at`   DATETIME     NOT NULL COMMENT '注册时间 UTC，参与密码链路',
  `is_banned`       TINYINT(1)   NOT NULL DEFAULT 0,
  `settings`        TEXT         NULL COMMENT 'JSON：notify/ip_record/glass_mode/theme/accent',
  `last_login_at`   DATETIME     NULL,
  `created_at`      DATETIME     NOT NULL COMMENT '系统写入时间 UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username_norm` (`username_norm`),
  UNIQUE KEY `uk_uid8` (`uid8`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户';

-- ------------------------------------------------------------
-- 用户访问记录
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_visits` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `ip_masked`  VARCHAR(64)  NOT NULL COMMENT '脱敏 IP：前三字符+后两字符',
  `location`   VARCHAR(128) NOT NULL DEFAULT '' COMMENT '归属地（展示用，由下面三列拼成）',
  `country`    VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '国家/地区',
  `province`   VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '省份',
  `city`       VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '城市',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户访问记录';

-- ------------------------------------------------------------
-- IP 归属地缓存（降第三方 QPS）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ip_cache` (
  `ip_hash`    CHAR(64)     NOT NULL COMMENT 'sha256(ip)',
  `ip_masked`  VARCHAR(64)  NOT NULL,
  `payload`    TEXT         NOT NULL COMMENT 'API 返回 JSON',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`ip_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP归属地缓存';

-- ------------------------------------------------------------
-- 作品表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `works` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `community_id`   VARCHAR(64)  NOT NULL COMMENT 'Kimi 社区作品 ID（feedId/momentId）',
  `title`          VARCHAR(255) NOT NULL,
  `intro`          TEXT         NULL COMMENT '简介',
  `author_name`    VARCHAR(64)  NOT NULL DEFAULT '',
  `author_avatar`  VARCHAR(255) NOT NULL DEFAULT '',
  `category`       ENUM('game','tool','literature','fanart') NOT NULL DEFAULT 'game',
  `share_link`     VARCHAR(255) NOT NULL DEFAULT '',
  `html_url`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '作品 HTML 直链',
  `like_num`       INT UNSIGNED NOT NULL DEFAULT 0,
  `comment_num`    INT UNSIGNED NOT NULL DEFAULT 0,
  `collect_num`    INT UNSIGNED NOT NULL DEFAULT 0,
  `images`         TEXT         NULL COMMENT '图片 URL JSON',
  `score`          TEXT         NULL COMMENT '六维分 JSON {creativity,experience,depth,cost,attitude,heat}',
  `total_score`    INT          NOT NULL DEFAULT 0,
  `heat_score`     INT          NOT NULL DEFAULT 0 COMMENT '热度分（单列便于排序，兼容 MySQL 5.5+）',
  `rating`         VARCHAR(4)   NOT NULL DEFAULT 'C',
  `has_html`       TINYINT(1)   NOT NULL DEFAULT 0,
  `is_hidden`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '下架标记',
  `added_by`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '录入管理员 1=admin',
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_community` (`community_id`),
  KEY `idx_category_score` (`category`, `total_score`),
  KEY `idx_cold` (`heat_score`, `total_score`),
  KEY `idx_updated` (`updated_at`),
  KEY `idx_title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作品';

-- ------------------------------------------------------------
-- 作品分数历史（诸神榜 = 历史峰値）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `work_score_history` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_id`     INT UNSIGNED NOT NULL,
  `total_score` INT          NOT NULL,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_work` (`work_id`, `total_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作品历史最高分';

-- ------------------------------------------------------------
-- 作品点赞（单用户唯一）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `work_votes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_work_user` (`work_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作品投票';

-- ------------------------------------------------------------
-- 评论（支持任意层级楼中楼）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `comments` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `parent_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `root_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `content`    TEXT         NOT NULL,
  `is_deleted` TINYINT(1)   NOT NULL DEFAULT 0,
  `deleted_by` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0未删 1用户自删 2管理删除',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_work` (`work_id`, `id`),
  KEY `idx_root` (`root_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论';

-- ------------------------------------------------------------
-- 评论点赞（单用户唯一）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `comment_votes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_comment_user` (`comment_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论点赞';

-- ------------------------------------------------------------
-- 世界对话消息
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `content`     TEXT         NOT NULL COMMENT '文本内容；表情以 [名称] 形式内嵌',
  `msg_type`    VARCHAR(12)  NOT NULL DEFAULT 'text' COMMENT 'text | image',
  `media_url`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '图片消息的地址（本地或代理）',
  `is_recalled` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_time` (`id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='世界对话';

-- ------------------------------------------------------------
-- AI 对话消息
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `role`       ENUM('user','assistant') NOT NULL,
  `content`    MEDIUMTEXT   NOT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI对话';

-- ------------------------------------------------------------
-- AI 用量（token/次数/额度统计）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_usage` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED NOT NULL COMMENT '0=管理员',
  `prompt_tokens`    INT UNSIGNED NOT NULL DEFAULT 0,
  `completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_tokens`     INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI用量';

-- ------------------------------------------------------------
-- 反馈
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `feedback` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `content`     TEXT         NOT NULL,
  `is_public`   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1公开 0私密',
  `admin_reply` TEXT         NULL,
  `replied_at`  DATETIME     NULL,
  `is_deleted`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_public_time` (`is_public`, `id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='反馈';

-- ------------------------------------------------------------
-- 公告
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `content`    VARCHAR(512) NOT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告';

-- ------------------------------------------------------------
-- 登录尝试（限速用）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash`    CHAR(64)     NOT NULL,
  `success`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录尝试';

-- ------------------------------------------------------------
-- Token 黑名单（退出登录 / 强制下线）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `token_blacklist` (
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `idx_expire` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='token黑名单';

-- ------------------------------------------------------------
-- 站点设置 KV
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT        NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点设置';

-- ------------------------------------------------------------
-- 访问/行为统计日聚合（控制面板图表）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stats_daily` (
  `day`            DATE         NOT NULL,
  `signups`        INT UNSIGNED NOT NULL DEFAULT 0,
  `logins`         INT UNSIGNED NOT NULL DEFAULT 0,
  `votes`          INT UNSIGNED NOT NULL DEFAULT 0,
  `work_updates`   INT UNSIGNED NOT NULL DEFAULT 0,
  `ai_calls`       INT UNSIGNED NOT NULL DEFAULT 0,
  `ai_tokens`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `comment_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `message_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `feedback_count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='日统计';

-- ------------------------------------------------------------
-- 同步日志（自动拉取作品）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sync_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mode`       VARCHAR(16) NOT NULL DEFAULT 'manual',
  `found`      INT UNSIGNED NOT NULL DEFAULT 0,
  `inserted`   INT UNSIGNED NOT NULL DEFAULT 0,
  `updated`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='同步日志';

-- 公告默认一条
INSERT IGNORE INTO `announcements` (`id`, `content`, `updated_at`)
VALUES (1, '欢迎来到 Kimi游戏榜 —— 专属六维综合评分，权威榜单。', UTC_TIMESTAMP());

SET FOREIGN_KEY_CHECKS = 1;

-- 结构版本（供自动迁移使用）
INSERT IGNORE INTO `settings` (`k`, `v`) VALUES ('schema_version', '2');


-- 每日签到（AI 额度加成）
CREATE TABLE IF NOT EXISTS `checkins` (
  `user_id` INT UNSIGNED NOT NULL COMMENT '0=管理员',
  `checkin_date` DATE NOT NULL COMMENT '站点本地日期',
  `streak_after` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '签到后的连续天数',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `checkin_date`),
  KEY `idx_checkins_month` (`user_id`, `checkin_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日签到';
