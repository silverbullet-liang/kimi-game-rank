<?php
/**
 * 全站对象编号（oid）
 * ------------------------------------------------------------
 * 编码：YYYY MM DD TT RRRR —— 共 14 位
 *   4 位年份 + 2 位月份 + 2 位日期（生成时的 UTC 日期）
 *   + 2 位类型码（每种对象一个固定码）+ 4 位随机数字。
 * 评论 / 违纪通报 / 世界对话 / AI 对话 / 作品 / 反馈 / 公告
 * 各占一个类型码，且各对应唯一一张表，因此「同一张表内不重复」
 * 即等价于「全站不重复」。
 */
declare(strict_types=1);

/** 对象类型码（2 位）：表名 → 码 */
function oid_type_code(string $table): string
{
    static $map = array(
        'comments'           => '01',
        'discipline_reports' => '02',
        'messages'           => '03',
        'ai_messages'        => '04',
        'works'              => '05',
        'feedback'           => '06',
        'announcements'      => '07',
    );
    $t = str_replace('`', '', $table);
    return isset($map[$t]) ? $map[$t] : '00';
}

/** 按指定 UTC 日期（Ymd）为该表生成一个「表内不重复」的编号 */
function oid_generate(string $table, string $ymd): string
{
    $t    = str_replace('`', '', $table);
    $code = oid_type_code($t);
    for ($i = 0; $i < 50; $i++) {
        $oid = $ymd . $code . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        try {
            if (db_val('SELECT 1 FROM `' . $t . '` WHERE `oid` = ? LIMIT 1', array($oid)) === null) {
                return $oid;
            }
        } catch (Throwable $e) {
            return $oid;   // 表 / 列不可用：直接返回，写入端负责兜底
        }
    }
    /* 极端情况（同一天同类型用尽 4 位随机空间）：仍返回一个编号，
       唯一索引会兜底拦截重复，避免死循环。 */
    return $ymd . $code . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

/** 生成一个新对象编号（UTC 当日） */
function oid_new(string $table): string
{
    return oid_generate($table, gmdate('Ymd'));
}

/** 目标表是否已就绪 oid 列（迁移未跑成时跳过编号，不影响写入） */
function oid_ready(string $table): bool
{
    return col_ok(str_replace('`', '', $table), 'oid');
}
