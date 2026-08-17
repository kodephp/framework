#!/usr/bin/env bash
#
# 零中间件（OFF）基线 · 冷却式 + DB 完整性校验（端口级强杀防残留）
# 与 bench_on_cooled.sh 同协议；用于复测 §4.1.1，消除残留服务器污染。
#
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
WORKERS=$(sysctl -n hw.ncpu 2>/dev/null || nproc 2>/dev/null || echo 8)
THREADS=8; CONN=200; DUR=8; ITERS=3
START_COOLDOWN=60; BETWEEN_EP=12; BETWEEN_PEER=20
OUT=/tmp/fair_off_cooled.txt
: > "$OUT"

kill_port() {
  local port="$1" tries=0
  while [ "$tries" -lt 40 ]; do
    local pids; pids=$(lsof -ti tcp:"$port" 2>/dev/null)
    if [ -z "$pids" ]; then return 0; fi
    echo "$pids" | xargs -r kill -9 2>/dev/null
    sleep 0.5; tries=$((tries+1))
  done
  echo "  !! 端口 $port 强杀后仍占用" >&2
}
measure() {
  wrk -t "$THREADS" -c "$CONN" -d "${DUR}s" "http://127.0.0.1:$1/$2" 2>/dev/null \
    | grep -E 'Requests/sec' | awk '{print $2}' | tr -d ','
}
mysql_queries() {
  php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=kode_bench","root","root"); $r=$p->query("SHOW GLOBAL STATUS LIKE \"Queries\"")->fetch(); echo $r[1];' 2>/dev/null
}
median() {
  printf '%s\n' "$@" | grep -v '^$' | sort -n \
    | awk '{a[NR]=$1} END{if(NR==0)print "NA";else if(NR%2)print a[(NR+1)/2];else print (a[NR/2]+a[NR/2+1])/2}'
}
probe() {
  for _ in $(seq 1 80); do
    curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$1/ping" && return 0
    sleep 0.3
  done
  return 1
}
bench_off() {
  local name="$1" start="$2" port="$3" db="$4"
  kill_port "$port"
  echo "===== [$name] port=$port workers=$WORKERS ====="
  no_proxy='*' NO_PROXY='*' bash -c "$start" >"/tmp/fairoff_${name}.log" 2>&1 &
  local launcher=$!
  if ! probe "$port"; then
    echo "  !! $name 未就绪（见 /tmp/fairoff_${name}.log）"
    kill_port "$port"; kill -9 "$launcher" 2>/dev/null
    echo "$name FAIL" >>"$OUT"; return 1
  fi
  sleep "$START_COOLDOWN"; START_COOLDOWN=$BETWEEN_PEER
  wrk -t "$THREADS" -c "$CONN" -d 5s "http://127.0.0.1:$port/ping" >/dev/null 2>&1
  local pv=""
  for _ in $(seq 1 "$ITERS"); do local r; r=$(measure "$port" ping); pv="$pv $r"; sleep 2; done
  local pm; pm=$(median $pv)
  printf "  /ping        median=%-12s runs:%s\n" "$pm" "$pv"
  sleep "$BETWEEN_EP"
  local jv=""
  for _ in $(seq 1 "$ITERS"); do local r; r=$(measure "$port" bench/json); jv="$jv $r"; sleep 2; done
  local jm; jm=$(median $jv)
  printf "  /bench/json  median=%-12s runs:%s\n" "$jm" "$jv"
  sleep "$BETWEEN_EP"
  local before; before=$(mysql_queries)
  local dv=""
  for _ in $(seq 1 "$ITERS"); do local r; r=$(measure "$port" "$db"); dv="$dv $r"; sleep 2; done
  local dm; dm=$(median $dv)
  local after; after=$(mysql_queries)
  local real; real=$(( (after - before) / (DUR * ITERS) ))
  local flag="✅1:1"
  # dm 可能为浮点（wrk 输出带小数），bash [ -gt ] 比不了浮点会直接报错导致标志假绿，
  # 改用 awk 做浮点比较：报告 rps > 真实 qps×2 即判定「并发跳查」（虚高）。
  awk "BEGIN{exit !(${dm:-0} > ${real:-0}*2)}" && flag="❌跳查"
  printf "  /$db  median=%-12s runs:%s | MySQL真实qps≈%s %s\n" "$dm" "$dv" "$real" "$flag"
  echo "$name ping=$pm json=$jm db_reported=$dm db_real=$real" >>"$OUT"
  kill_port "$port"; sleep 1
  echo ""
}
echo "########## OFF 基线冷却式 + DB 完整性校验复测（端口强杀防残留）##########"
php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=kode_bench","root","root"); for($i=0;$i<800;$i++){ $s=$p->prepare("SELECT * FROM bench_users WHERE id=?"); $s->execute([rand(1,1000)]); $s->fetch(); } echo "MySQL warmed\n";'
bench_off "webman_OFF" \
  "cd $PEERS/webman && WEBMAN_MW=off BENCH_PORT=8091 BENCH_WORKERS=$WORKERS php kode_server.php start -d" \
  8091 "bench/db"
bench_off "kode_L0_off" \
  "cd $PEERS && KODE_PROFILE=off KODE_RUNTIME=workerman BENCH_PORT=8200 BENCH_WORKERS=$WORKERS php -d memory_limit=512M kode_swoole_server.php" \
  8200 "bench/raw/mysql"
bench_off "hyperf_OFF" \
  "cd $PEERS/hyperf && HYPERF_MW=off php bin/hyperf.php start" \
  9501 "bench/db"
echo "================ OFF 基线汇总 ================"
cat "$OUT"
