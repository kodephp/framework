#!/usr/bin/env bash
#
# kode·lean / kode·default × 三运行时（swoole / native / workerman）对等压测
# 锚：webman（Workerman 系框架，独立实现，不依赖 kode/process）
#
# 口径（与 PEER_BENCHMARK §2 一致）：
#   - 同机器、同 11 worker（= swoole_cpu_num() / Workerman count / Native workers）
#   - wrk -t8 -c200 -d8s，每端点 3 次迭代取中位数（抗本机热噪声）
#   - 每 peer 间 COOLDOWN=15 防 CPU 热降频造成「先测的快、后测的慢」
#   - no_proxy='*' 防 curl 走代理返 502；wrk 本身不走代理
#   - php -d memory_limit=512M 防 /bench/json 全 ORM boot 触默认 128M 上限崩
#
set -u
cd "$(dirname "$0")/../.." || exit 1

PEERS="$PWD/benchmarks/peers"
MEM=512M
WORKERS=11
WARMUP=8
ITERS=3
DUR=8
COOLDOWN=15
THREADS=8
CONN=200

bench() {
  local name="$1" script="$2" port="$3" profile="$4" runtime="$5" extra="${6:-}"
  local rps_ping=() rps_json=()
  echo "============================================================"
  echo "[$name] port=$port workers=$WORKERS profile=$profile runtime=${runtime:-auto} extra=[$extra] mem=$MEM"
  # 清理可能的同名进程锁（Workerman pid 文件按脚本名）
  pkill -f "$(basename "$script")" 2>/dev/null || true
  sleep 1
  env BENCH_PORT="$port" BENCH_WORKERS="$WORKERS" KODE_PROFILE="$profile" ${runtime:+KODE_RUNTIME="$runtime"} \
      no_proxy='*' NO_PROXY='*' \
      php -d memory_limit=$MEM "$script" $extra >"/tmp/bench_${name}.log" 2>&1 &
  local srv=$!
  sleep $WARMUP
  # 预热 + 探活
  curl -s --noproxy '*' -m 3 -o /dev/null -w "  warmup /ping: HTTP %{http_code}\n" "http://127.0.0.1:$port/ping" || true
  for ep in ping "bench/json"; do
    local median=0
    local runs=()
    for i in $(seq 1 $ITERS); do
      local out
      out=$(no_proxy='*' NO_PROXY='*' wrk -t$THREADS -c$CONN -d${DUR}s "http://127.0.0.1:$port/$ep" 2>&1)
      local r
      r=$(echo "$out" | grep -E 'Requests/sec' | awk '{print $2}' | tr -d ',')
      runs+=("$r")
      echo "  /$ep run$i: $r rps"
    done
    # 中位数
    local sorted
    sorted=$(printf '%s\n' "${runs[@]}" | sort -n)
    median=$(echo "$sorted" | sed -n "$(( (ITERS+1)/2 ))p")
    if [ "$ep" = "ping" ]; then rps_ping="$median"; else rps_json="$median"; fi
  done
  kill -TERM "$srv" 2>/dev/null || true
  pkill -f "$(basename "$script")" 2>/dev/null || true
  echo "  >>> [$name] MEDIAN  /ping=$rps_ping  /bench/json=$rps_json"
  echo "$name|$rps_ping|$rps_json" >> /tmp/three_runtime_summary.tsv
  echo "  (cooldown ${COOLDOWN}s)"
  sleep $COOLDOWN
}

rm -f /tmp/three_runtime_summary.tsv
# 锚（webman 需 start 子命令；其事件循环走 Swoole，故 runtime 标注 swoole-eventloop 仅作说明）
bench "webman_anchor"        "$PEERS/webman/kode_server.php"    8091 default "" "start"
# kode·lean 三运行时
bench "kode_lean_swoole"     "$PEERS/kode_swoole_server.php"   8093 lean swoole
bench "kode_lean_native"     "$PEERS/kode_swoole_server.php"   8094 lean native
bench "kode_lean_workerman"  "$PEERS/kode_swoole_server.php"   8095 lean workerman
# kode·default 三运行时
bench "kode_default_swoole"   "$PEERS/kode_swoole_server.php"   8096 default swoole
bench "kode_default_native"   "$PEERS/kode_swoole_server.php"   8097 default native
bench "kode_default_workerman" "$PEERS/kode_swoole_server.php"  8098 default workerman

echo "============================================================"
echo "SUMMARY (median rps, 3 iters each):"
printf "%-22s %12s %12s\n" "peer" "/ping" "/bench/json"
while IFS='|' read -r n p j; do
  printf "%-22s %12s %12s\n" "$n" "$p" "$j"
done < /tmp/three_runtime_summary.tsv
