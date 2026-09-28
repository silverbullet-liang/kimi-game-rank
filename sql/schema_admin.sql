-- ============================================================
--  管理员凭证库（独立数据库，与主库物理分离）
--  只存 sha256 之前的原始文本；验证时现算 sha256 比对（hash_equals）
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_credentials` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`         VARCHAR(64)  NOT NULL,
  `secret_plaintext` VARCHAR(255) NOT NULL COMMENT 'sha256 之前的原始密钥文本',
  `created_at`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员凭证';

-- ---------- 副管理员（权限独立，token 不共用） ----------
CREATE TABLE IF NOT EXISTS `sub_admins` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`         VARCHAR(64)  NOT NULL,
  `username_norm`    VARCHAR(64)  NOT NULL,
  `user_id`          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '主库 users.id',
  `secret_plaintext` VARCHAR(255) NOT NULL COMMENT 'sha256 之前的原始密钥',
  `created_at`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sub_norm` (`username_norm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='副管理员';
