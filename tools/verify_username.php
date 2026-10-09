<?php
/**
 * 用户名字符限制回归：换行与可堆叠/装饰字符一律拒绝（zalgo 等常见骚扰文本手法）。
 * 运行：php tools/verify_username.php
 * 离线运行，不连数据库、不访问网络。
 */
declare(strict_types=1);

/* username_suggest 会查重：桩件默认「无冲突」，可用 $GLOBALS['DBVAL_SEQ'] 控制序列 */
$GLOBALS['DBVAL'] = 0;
$GLOBALS['DBVAL_SEQ'] = array();
function db_val(string $sql, array $a = array()) {
    if ($GLOBALS['DBVAL_SEQ']) { return array_shift($GLOBALS['DBVAL_SEQ']); }
    return $GLOBALS['DBVAL'];
}

require __DIR__ . '/../app/crypto.php';          // nfc_normalize（username_suggest 用）
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

/* ---------- 存量用户名：是否需要更新 / 清洗 / 建议名 ---------- */
ck('needs_fix：正常名 → false',      username_needs_fix('小明'), false);
ck('needs_fix：含换行 → true',       username_needs_fix("小明\n同学"), true);
ck('needs_fix：含组合符 → true',     username_needs_fix("小\u{0301}明"), true);

ck('scrub：剥换行与组合符',          username_scrub("小\u{0301}明\n同学"), '小明同学');
ck('scrub：压缩连续空白',            username_scrub("a  \t b"), 'a b');
ck('scrub：正常名原样',              username_scrub('小明'), '小明');

ck('suggest：清洗后可辨识 → 保留原名', username_suggest("小明\n同学", 5, 'abcd1234'), '小明同学');
ck('suggest：清洗后为空 → 用户+uid8', username_suggest("\n\u{0301}", 5, 'abcd1234'), '用户abcd1234');
ck('suggest：无 uid8 → 用户+uid',     username_suggest("\n", 7, ''), '用户7');
ck('suggest：清洗后落到保留名 → 兜底', username_suggest("admin\n", 9, 'ff00ff00'), '用户ff00ff00');
ck('suggest：清洗后过短 → 兜底',      username_suggest("x\n", 3, 'aa11bb22'), '用户aa11bb22');
ck('suggest：超长截到 56 字',         mb_strlen(username_suggest(str_repeat('测', 80) . "\n", 1, 'aa'), 'UTF-8'), 56);

/* 重名：第一次查中、第二次落空 → 追加 -2 */
$GLOBALS['DBVAL_SEQ'] = array(1, 0);
ck('suggest：撞名 → 追加 -2',        username_suggest("小明\n", 5, 'abcd1234'), '小明-2');
$GLOBALS['DBVAL_SEQ'] = array();

ck('fix_info：正常名 → null',         username_fix_info('小明', 5, 'ab'), null);
$fi = username_fix_info("小明\n", 5, 'ab');
ck('fix_info：不合规 → current 原样',  $fi['current'], "小明\n");
ck('fix_info：不合规 → suggest 可用',  $fi['suggest'], '小明');

printf("\n%d 项，%d 通过，%d 失败\n", $GLOBALS['pass_n'] + $GLOBALS['fail_n'], $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
