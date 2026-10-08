<?php
/**
 * 用户名字符限制回归：换行与可堆叠/装饰字符一律拒绝（zalgo 等常见骚扰文本手法）。
 * 运行：php tools/verify_username.php
 * 离线运行，不连数据库、不访问网络。
 */
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';

$GLOBALS['fail_n'] = 0;
$GLOBALS['pass_n'] = 0;
function ck(string $name, $got, $want): void
{
    $ok = ($got === $want);
    $ok ? $GLOBALS['pass_n']++ : $GLOBALS['fail_n']++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* 合法：普通中英文、数字、预组合字母（NFC 后的合法形态） */
ck('中文名放行',           username_has_bad_chars('小明同学'), false);
ck('英文数字下划线放行',   username_has_bad_chars('user_01.Hello'), false);
ck('预组合 é 放行',        username_has_bad_chars("café"), false);
ck('预组合 ñ 放行',        username_has_bad_chars('señor'), false);
ck('日文假名放行',         username_has_bad_chars('テスト'), false);

/* 拒绝：换行与回车 */
ck('换行拒绝',             username_has_bad_chars("abc\ndef"), true);
ck('回车拒绝',             username_has_bad_chars("abc\rdef"), true);
ck('CRLF 拒绝',            username_has_bad_chars("abc\r\ndef"), true);

/* 拒绝：可堆叠组合附加符（zalgo 堆叠文本） */
ck('U+0300 组合符拒绝',    username_has_bad_chars("a\u{0300}b"), true);
ck('zalgo 多层堆叠拒绝',   username_has_bad_chars("a\u{0300}\u{0316}\u{033D}\u{0347}b"), true);
ck('U+1DC0 组合符拒绝',    username_has_bad_chars("a\u{1DC0}b"), true);
ck('U+20D0 组合符拒绝',    username_has_bad_chars("a\u{20D0}b"), true);
ck('U+FE20 半组合拒绝',    username_has_bad_chars("a\u{FE20}b"), true);

/* 拒绝：变体选择符与 TAG（隐形 emoji / 隐形水印） */
ck('U+FE0F 变体拒绝',      username_has_bad_chars("a\u{FE0F}b"), true);
ck('U+FE0E 变体拒绝',      username_has_bad_chars("a\u{FE0E}b"), true);
ck('TAG 字符拒绝',         username_has_bad_chars("a\u{E0041}b"), true);
ck('蒙文变体选择符拒绝',   username_has_bad_chars("a\u{180B}b"), true);

/* 拒绝：双向控制与残留零宽（防绕过显示顺序） */
ck('U+202E 双向拒绝',      username_has_bad_chars("abc\u{202E}def"), true);
ck('U+2060 词连接符拒绝',  username_has_bad_chars("a\u{2060}b"), true);
ck('U+FEFF 拒绝',          username_has_bad_chars("a\u{FEFF}b"), true);

printf("\n%d 项，%d 通过，%d 失败\n", $GLOBALS['pass_n'] + $GLOBALS['fail_n'], $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
