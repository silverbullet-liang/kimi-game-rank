#!/usr/bin/env node
/**
 * 自检：站内扫一扫的「只认本站登录码」判定（离线，不碰网络与摄像头）
 *
 * 直接从 assets/js/src/scan.js 里取出 qrIdOf 的实现来跑，
 * 避免测试写一份、线上跑另一份（两份正则漂移过一次就够受的）。
 */
const fs = require('fs');
const path = require('path');

const file = path.join(__dirname, '..', 'assets', 'js', 'src', 'scan.js');
const src = fs.readFileSync(file, 'utf8');
const m = src.match(/export function qrIdOf\(text\) \{[\s\S]*?\n\}/);
if (!m) { console.error('找不到 qrIdOf 实现'); process.exit(1); }
const body = m[0].replace(/^export /, '');
const qrIdOf = new Function('return (' + body + ')')();

let pass = 0, fail = 0;
function ck(cond, label) {
  if (cond) { pass++; console.log('  ok   ' + label); }
  else { fail++; console.log('  FAIL ' + label); }
}

const ID = 'a1b2c3d4e5f60718';   // 16 位
const ID32 = 'a1b2c3d4e5f60718293a4b5c6d7e8f90'; // 32 位

ck(qrIdOf('https://kimi-game-rank.de5.net/#/login?qr=' + ID) === ID, '完整链接');
ck(qrIdOf('https://kimi-game-rank.de5.net/?p=x&qr=' + ID32) === ID32, '带其它参数的链接');
ck(qrIdOf(ID) === ID, '裸编号');
ck(qrIdOf(ID.toUpperCase()) === ID, '大写自动归一');
ck(qrIdOf('  ' + ID + '  ') === ID, '前后空白可容忍');
ck(qrIdOf('https://kimi-game-rank.de5.net/#/login') === '', '没有 qr 参数 → 空');
ck(qrIdOf('https://evil.example/?qr=' + ID) === ID, '链接域名不参与判定（只取 id，不跳转）');
ck(qrIdOf('a1b2c3d4e5f6071') === '', '15 位不算');
ck(qrIdOf(ID + 'a') === '', '17 位不算（长度必须精确）');
ck(qrIdOf(ID32 + 'a') === '', '33 位不算');
ck(qrIdOf('https://x/?qr=' + ID + 'zz') === '', 'id 后紧跟字母 → 不截断凑合');
ck(qrIdOf('https://x/?qr=' + ID + '&from=pc') === ID, 'id 后跟 & 参数 → 正常取到');
ck(qrIdOf('') === '' && qrIdOf(null) === '' && qrIdOf(undefined) === '', '空值安全');
ck(qrIdOf('x'.repeat(600)) === '', '超长输入直接拒绝');

/* 产物里必须带上扫码实现，且 jsQR 不随首屏加载 */
const app = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'app.js'), 'utf8');
ck(app.indexOf('scanQrLogin') >= 0, 'app.js 含 scanQrLogin');
ck(app.indexOf("'/js/vendor/jsqr.js'") >= 0, 'jsQR 走按需加载路径');
const vendor = path.join(__dirname, '..', 'assets', 'js', 'vendor', 'jsqr.js');
ck(fs.existsSync(vendor) && fs.statSync(vendor).size > 100000, 'jsQR 文件存在');
const indexPhp = fs.readFileSync(path.join(__dirname, '..', 'index.php'), 'utf8');
ck(indexPhp.indexOf('vendor/jsqr.js') < 0, 'jsQR 没有塞进首屏');

console.log(fail === 0 ? '\n全部 ' + pass + ' 项通过' : '\n失败 ' + fail + ' / ' + (pass + fail));
process.exit(fail === 0 ? 0 : 1);
