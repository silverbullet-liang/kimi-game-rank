<?php
/**
 * 限时节日皮肤
 * ------------------------------------------------------------
 * 每个节日一套**独立主题**（配色、装饰、动效都不共用），只在对应日期窗口内生效，
 * 过窗口自动回退到用户自选皮肤。判定一律用北京时间，与访问者本机时区无关。
 *
 * 本文件是节日规则的唯一数据源：index.php 把结果下发给前端，
 * theme.js 与「我的 → 外观设置」都从这里取，避免两处规则各写一份而漂移。
 *
 * 'dark' => true 的节日会强制暗色（不论用户偏好的深浅色）。
 */
declare(strict_types=1);

/** 节日目录：窗口用北京时间的「月/日」表示。day 表示仅当日；from/to 表示跨日窗口。 */
function festival_catalog(): array
{
    return array(
        'festival' => array(
            'name' => '国庆专版',
            'desc' => '盛世红金 · 限时呈现',
            'dark' => false,
            'from' => array(9, 30),
            'to'   => array(10, 8),
        ),
        'xinhai' => array(
            'name' => '辛亥纪念',
            'desc' => '铁血首义 · 双十纪念',
            'dark' => false,
            'day'  => array(10, 10),
        ),
        'kmyc' => array(
            'name' => '抗美援朝纪念',
            'desc' => '雄赳赳 · 当日主题',
            'dark' => false,
            'day'  => array(10, 25),
        ),
        'halloween' => array(
            'name' => '万圣夜',
            'desc' => '暗夜惊魂 · 强制暗色',
            'dark' => true,
            'day'  => array(10, 31),
        ),
    );
}

/** 给定「月日」（如 1001 = 10 月 1 日）落在哪个节日窗口；没有则空串。抽出来便于单测。 */
function festival_key_for_md(int $md): string
{
    foreach (festival_catalog() as $key => $f) {
        if (isset($f['day'])) {
            if ($md === (int)$f['day'][0] * 100 + (int)$f['day'][1]) { return $key; }
            continue;
        }
        $from = (int)$f['from'][0] * 100 + (int)$f['from'][1];
        $to   = (int)$f['to'][0]   * 100 + (int)$f['to'][1];
        if ($from <= $to) {
            if ($md >= $from && $md <= $to) { return $key; }
        } else {                                  // 跨年窗口（预留）
            if ($md >= $from || $md <= $to) { return $key; }
        }
    }
    return '';
}

/** 当前生效的节日 key（北京时间）；没有则空串 */
function festival_now(): string
{
    $md = (int)gmdate('n', time() + 8 * 3600) * 100 + (int)gmdate('j', time() + 8 * 3600);
    return festival_key_for_md($md);
}

/** 下发给前端的节日数据（供 theme.js / index.php 的首屏脚本使用） */
function festival_client(): array
{
    return array('now' => festival_now(), 'list' => festival_catalog());
}
