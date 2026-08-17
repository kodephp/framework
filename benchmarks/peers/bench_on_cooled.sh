#!/usr/bin/env bash
#
# 同类型中间件（ON）档 · 冷却式 + DB 完整性校验复测（端口级强杀防残留）
# ---------------------------------------------------------------------
# 修正上一轮污染根因：旧 harness 用 `pkill -f 'hyperf/bin/hyperf.php'` 等模式，
# 实际进程 cmdline 是 `php bin/hyperf.php start`（无 hyperf/ 前缀），pkill 永不命中，
# 导致上一轮 peer 残留在端口上、probe 误判就绪、新配置服务器 bind 失败（Address already in use），
# 测到的其实是「上一个残留服务器」——数字全部失真。
#
# 本脚本改用 **端口级强杀**（lsof + kill -9）作为起停唯一手段，确保每次测量都是「干净全新实例」。
#
# 协议（压测沙箱 gotcha 铁律）：
#   • 起点冷却 60s（首 peer 首测前让 CPU 回 boost 基线）
#   • 端点间冷却 12s（/ping → /bench/json → DB）
#   • peer 间冷却 20s
#   • MySQL 预热 800 次 SELECT
#   • DB 端点必做完整性校验：MySQL `Queries` 计数器 delta 算真实 qps
#
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
WORKERS=$(sysctl -n hw.ncpu 2>/dev/null || nproc 2>/dev/null || echo 8)
THREADS=8; CONN=200; DUR=8; ITERS=3
START_COOLDOWN=60; BETWEEN_EP=12; BETWEEN_PEER=20
OUT=/tmp/fair_on_cooled.txt
: > "$OUT"

# 默认不开 JIT：CLI SAPI 默认 opcache.enable_cli=Off 且 jit_buffer_size=0（JIT 关闭），
# 这是「框架额外开销」的公平基线——不靠对 kode 更有利的 JIT 旋钮去缩差距。
# 如需看「生产 JIT 态」对比，设 WITH_JIT=1 再跑（此时 kode 受益更大，但仍低于 webman）。
if [ "${WITH_JIT:-0}" = "1" ]; then
  JIT_FLAGS="-d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=tracing"
else
  JIT_FLAGS=""
fi

kill_port() { # port
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

# bench_on name startcmd port db
bench_on() {
  local name="$1" start="$2" port="$3" db="$4"
  kill_port "$port"                       # 起测前强杀端口残留，确保干净实例
  echo "===== [$name] port=$port workers=$WORKERS ====="
  no_proxy='*' NO_PROXY='*' bash -c "$start" >"/tmp/fairon_${name}.log" 2>&1 &
  local launcher=$!
  if ! probe "$port"; then
    echo "  !! $name 未就绪（见 /tmp/fairon_${name}.log）"
    kill_port "$port"; kill -9 "$launcher" 2>/dev/null
    echo "$name FAIL" >>"$OUT"; return 1
  fi
  sleep "$START_COOLDOWN"; START_COOLDOWN=$BETWEEN_PEER   # 仅首个 peer 用 60s
  wrk -t "$THREADS" -c "$CONN" -d 5s "http://127.0.0.1:$port/ping" >/dev/null 2>&1   # warmup

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

echo "########## ON 档冷却式 + DB 完整性校验复测（端口强杀防残留）##########"
php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=kode_bench","root","root"); for($i=0;$i<800;$i++){ $s=$p->prepare("SELECT * FROM bench_users WHERE id=?"); $s->execute([rand(1,1000)]); $s->fetch(); } echo "MySQL warmed\n";'

bench_on "webman_ON" \
  "cd $PEERS/webman && WEBMAN_MW=on BENCH_PORT=8091 BENCH_WORKERS=$WORKERS php $JIT_FLAGS kode_server.php start -d" \
  8091 "bench/db"

bench_on "kode_ON_sameType" \
  "cd $PEERS && KODE_PROFILE=off KODE_ENABLE=cors,security,logging KODE_AUDIT=off KODE_RUNTIME=workerman BENCH_PORT=8201 BENCH_WORKERS=$WORKERS php $JIT_FLAGS -d memory_limit=512M kode_swoole_server.php" \
  8201 "bench/raw/mysql"

# hyperf_ON：已修复脚手架缺陷（app/Middleware/* 原 `use Hyperf\HttpServer\Contract\MiddlewareInterface`
# 不存在，正确为 `Psr\Http\Server\MiddlewareInterface`，启动即 fatal）。现 4 个中间件接口已改正，
# 与 kode/webman ON 同类型（CORS+Security头+链路ID+访问日志）可比。HYPERF_MW=on 由 middlewares.php 读取。
bench_on "hyperf_ON" \
  "cd $PEERS/hyperf && HYPERF_MW=on php $JIT_FLAGS -d memory_limit=1G bin/hyperf.php start" \
  9501 "bench/db"

echo "================ ON 档汇总 ================"
cat "$OUT"
