#!/bin/bash
# monitor collect 端到端验证：伪造被拒、真实入库、单会话 PV 限速
cd /sandbox/workspace/kimi-game-rank || exit 1
BASE="http://127.0.0.1:8088/api/monitor.php?action=collect"
CHROME="Mozilla/5.0 (Linux; Android 13; 2211133C) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36"
SQL() { mariadb --socket=/run/mysqld/mysqld.sock kimgr_mon -N -e "$1"; }
CNT() { SQL "SELECT COUNT(*) FROM web_events;"; }

echo "初始行数: $(CNT)"

echo "--- 1. 伪造：curl UA + browser 空 + sid=s_test123"
curl -s -X POST "$BASE" -H "Content-Type: application/json" -A "curl/8.5.0" \
  -d '{"sid":"s_test123","page":"/","version":"3.60.5","browser":"","os":"","screen":"","net":"","uid":0,"events":[{"kind":"pv","name":"/","status":"ok"}]}'
echo " -> 行数 $(CNT)（应仍为 0）"

echo "--- 2. 伪造：浏览器 UA 但 browser 字段空"
curl -s -X POST "$BASE" -H "Content-Type: application/json" -A "$CHROME" \
  -d '{"sid":"smw7q48cqrzxaqv","page":"/","version":"3.60.7","browser":"","os":"","screen":"","net":"","uid":0,"events":[{"kind":"pv","name":"/","status":"ok"}]}'
echo " -> 行数 $(CNT)（应仍为 0）"

echo "--- 3. 伪造：UA 与 browser 不匹配（报 Safari，UA 是 Chrome）"
curl -s -X POST "$BASE" -H "Content-Type: application/json" -A "$CHROME" \
  -d '{"sid":"smw7q48cqrzxaqv","page":"/","version":"3.60.7","browser":"Safari 17.2","os":"iOS","screen":"390x844","net":"4g","uid":0,"events":[{"kind":"pv","name":"/","status":"ok"}]}'
echo " -> 行数 $(CNT)（应仍为 0）"

echo "--- 4. 真实上报（4 条：pv + js + api + perf）"
curl -s -X POST "$BASE" -H "Content-Type: application/json" -A "$CHROME" \
  -d '{"sid":"smw7q48cqrzxaqv","page":"/","version":"3.60.7","browser":"Chrome 156.0.0.0","os":"Android","screen":"407x904","net":"4g","uid":0,"events":[{"kind":"pv","name":"/","status":"ok"},{"kind":"js","name":"/","msg":"TypeError: x is undefined","v1":0,"v2":0,"status":"error"},{"kind":"api","name":"/api/works","v1":320,"v2":200,"status":"ok"},{"kind":"perf","name":"lcp","v1":1800,"status":"ok"}]}'
echo " -> 行数 $(CNT)（应为 4）"

echo "--- 5. 同一 sid 连发 3 次 × 4 条 pv（累计 12 条 > 10/min）"
for i in 1 2 3; do
  curl -s -X POST "$BASE" -H "Content-Type: application/json" -A "$CHROME" \
    -d '{"sid":"smw7q48cqrzxaqv","page":"/","version":"3.60.7","browser":"Chrome 156.0.0.0","os":"Android","screen":"407x904","net":"4g","uid":0,"events":[{"kind":"pv","name":"/","status":"ok"},{"kind":"pv","name":"/","status":"ok"},{"kind":"pv","name":"/","status":"ok"},{"kind":"pv","name":"/","status":"ok"}]}'
  echo -n " "
done
echo ""
echo " -> 行数 $(CNT)（第 3 批应被限速丢弃，最多 +8）"

echo "--- 6. 另一个真实会话正常入库"
curl -s -X POST "$BASE" -H "Content-Type: application/json" -A "$CHROME" \
  -d '{"sid":"smw7q9999qrzxaqv","page":"/login?i=1","version":"3.60.7","browser":"Chrome 156.0.0.0","os":"Android","screen":"407x904","net":"3g","uid":0,"events":[{"kind":"pv","name":"/login?i=1","status":"ok"}]}'
echo " -> 行数 $(CNT)"

echo "--- 7. 落库明细校验"
SQL "SELECT kind, name, browser, sid FROM web_events ORDER BY id;"
echo "--- 8. 统计口径：pv 只数带 browser 的行"
SQL "SELECT COUNT(*) FROM web_events WHERE kind='pv' AND browser<>'';"
