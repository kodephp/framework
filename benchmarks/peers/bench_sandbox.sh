#!/usr/bin/env bash
#
# 沙箱/CI 交叉验证压测（Linux 通用，无 ext-swoole 环境）
# ------------------------------------------------------
# 目的：在无 Swoole 扩展的 Linux 环境（CI 沙箱）做「同 Workerman 运行时」横向比值校验，
#       验证 macOS PEER_BENCHMARK.md 的结论在另一平台/运行时（Workerman 默认 Select 事件循环）下成立：
#       kode L0 与 webman / workerman_raw 的差距是否仍 100% 集中在 PSR-7 桥接层（KODE_LEAN 旁路）。
#
# 口径（与 macOS §4.1.1 对齐，仅并发/时长适配沙箱小核数）：
#   - 全部 peer 走 Workerman 运行时（kode 经 KODE_RUNTIME=workerman 委托 Kode::serve）
#   - 无 JIT（CLI 默认 opcache.enable_cli=Off），不开任何跨切面中间件（webman WEBMAN_MW=off）
#   - wrk -t4 -c60 -d6s，每端点 3 轮取中位；正向+反向两遍取均值（抵消顺序偏置/热态漂移）
#   - 端口：workerman_raw 7102 / webman 7091 / kode_L0 7200 / kode_LEAN 7201
#   - **绝对 rps 仅限本机同条件横比，跨机器不可比**（沙箱 2 核 Select 循环绝对值远低于 macOS 11 核 Swoole）
#
# 运行：bash benchmarks/peers/bench_sandbox.sh
# 依赖：wrk、curl、fuser；php 在 PATH 或传 PHP_BIN=/path/to/php
#
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
PHP="${PHP_BIN:-php}"
WORKERS=$(nproc 2>/dev/null || echo 2)
THREADS=4; CONN=60; DUR=6; ITERS=3
OUT=/tmp/sandbox_bench.txt
: > "$OUT"

WRK_FLAGS=(-t "$THREADS" -c "$CONN" -d "${DUR}s")

kill_port() {
  local port="$1" tries=0
  while [ "$tries" -lt 20 ]; do
    if ! fuser -k "${port}/tcp" >/dev/null 2>&1; then return 0; fi
    sleep 0.4; tries=$((tries+1))
  done
  echo "  !! 端口 $port 强杀后仍占用" >&2
}
measure() {
  wrk "${WRK_FLAGS[@]}" "http://127.0.0.1:$1/$2" --latency 2>/dev/null \
    | grep -E 'Requests/sec' | awk '{print $2}' | tr -d ','
}
median() {
  printf '%s\n' "$@" | grep -v '^$' | sort -n \
    | awk '{a[NR]=$1} END{if(NR==0)print "NA";else if(NR%2)print a[(NR+1)/2];else print (a[NR/2]+a[NR/2+1])/2}'
}
probe() {
  for _ in $(seq 1 60); do
    curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$1/ping" && return 0
    sleep 0.3
  done
  return 1
}

run_peer() {
  local name="$1" start="$2" port="$3"
  kill_port "$port"
  echo "===== [$name] port=$port workers=$WORKERS ====="
  no_proxy='*' NO_PROXY='*' bash -c "$start" >"/tmp/sandbox_${name}.log" 2>&1 &
  local launcher=$!
  if ! probe "$port"; then
    echo "  !! $name 未就绪（见 /tmp/sandbox_${name}.log）"
    kill_port "$port"; kill -9 "$launcher" 2>/dev/null
    echo "$name FAIL" >>"$OUT"; return 1
  fi
  sleep 2
  local pv="" jv=""
  for _ in $(seq 1 "$ITERS"); do pv="$pv $(measure "$port" ping)"; sleep 1; done
  for _ in $(seq 1 "$ITERS"); do jv="$jv $(measure "$port" bench/json)"; sleep 1; done
  local pm jm; pm=$(median $pv); jm=$(median $jv)
  printf "  /ping        median=%-12s runs:%s\n" "$pm" "$pv"
  printf "  /bench/json  median=%-12s runs:%s\n" "$jm" "$jv"
  echo "$name ping=$pm json=$jm" >>"$OUT"
  kill_port "$port"; sleep 1
}

echo "########## 沙箱交叉验证（Linux · Workerman 默认事件循环 · 无 JIT · workers=$WORKERS）##########"
echo "PHP: $($PHP -v | head -1)"

# 正向
run_peer "workerman_raw" "cd $PEERS/workerman_raw && BENCH_PORT=7102 BENCH_WORKERS=$WORKERS $PHP server.php start" 7102
run_peer "webman_OFF"   "cd $PEERS/webman && WEBMAN_MW=off BENCH_PORT=7091 BENCH_WORKERS=$WORKERS $PHP kode_server.php start" 7091
run_peer "kode_L0_off"  "cd $PEERS && KODE_PROFILE=off KODE_RUNTIME=workerman BENCH_PORT=7200 BENCH_WORKERS=$WORKERS $PHP kode_swoole_server.php" 7200
run_peer "kode_LEAN"    "cd $PEERS && KODE_PROFILE=off KODE_RUNTIME=workerman KODE_LEAN=1 BENCH_PORT=7201 BENCH_WORKERS=$WORKERS $PHP kode_swoole_server.php" 7201
# 反向（抵消顺序偏置）
run_peer "kode_LEAN_r"  "cd $PEERS && KODE_PROFILE=off KODE_RUNTIME=workerman KODE_LEAN=1 BENCH_PORT=7201 BENCH_WORKERS=$WORKERS $PHP kode_swoole_server.php" 7201
run_peer "kode_L0_r"    "cd $PEERS && KODE_PROFILE=off KODE_RUNTIME=workerman BENCH_PORT=7200 BENCH_WORKERS=$WORKERS $PHP kode_swoole_server.php" 7200
run_peer "webman_r"     "cd $PEERS/webman && WEBMAN_MW=off BENCH_PORT=7091 BENCH_WORKERS=$WORKERS $PHP kode_server.php start" 7091
run_peer "workerman_raw_r" "cd $PEERS/workerman_raw && BENCH_PORT=7102 BENCH_WORKERS=$WORKERS $PHP server.php start" 7102

echo "================ 汇总(runs) ================"
cat "$OUT"