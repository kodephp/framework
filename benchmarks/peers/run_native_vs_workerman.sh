#!/usr/bin/env bash
# Native（自研纯 PHP master-worker 多进程）vs Workerman 驱动 同等条件对比。
# 问题：使用自研多进程（kode/process Native 驱动，默认运行时）是否更好？
# 设计：webman=Workerman 锚；kode·lean / kode·default 各跑 Workerman 与 Native 两种驱动，
#       同机器、同 11 worker、同 wrk 参数，横比「同框架不同运行时」的吞吐差距。
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
WORKERS=$(sysctl -n hw.ncpu)
THREADS=8; CONN=200; DUR=8; ITERS=3; WARMUP=8; COOLDOWN=15
MEM=512M

probe() { local port="$1"; for _ in $(seq 1 60); do curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$port/ping" && return 0; sleep 0.3; done; return 1; }
measure() { wrk -t "$THREADS" -c "$CONN" -d "${DUR}s" "http://127.0.0.1:$1/$2" 2>&1 | grep -E 'Requests/sec|Socket errors' | tr '\n' ' '; echo; }

bench() {
  local name="$1" script="$2" port="$3" args="${4:-}" profile="${5:-}" runtime="${6:-}"
  echo "------------------------------------------------------------"
  echo "[$name] port=$port workers=$WORKERS profile=$profile runtime=${runtime:-auto} mem=$MEM args=[$args]"
  pkill -f "$script" 2>/dev/null; sleep 1
  env BENCH_PORT="$port" BENCH_WORKERS="$WORKERS" ${profile:+KODE_PROFILE="$profile"} ${runtime:+KODE_RUNTIME="$runtime"} \
      php -d memory_limit=$MEM "$script" $args >"/tmp/bench_${name}.log" 2>&1 &
  local pid=$!
  if ! probe "$port"; then echo "  !! $name 未就绪（见 /tmp/bench_${name}.log）"; kill -9 "$pid" 2>/dev/null; return 1; fi
  sleep "$COOLDOWN"
  wrk -t "$THREADS" -c "$CONN" -d "${WARMUP}s" "http://127.0.0.1:$port/ping" >/dev/null 2>&1
  for path in ping bench/json; do
    wrk -t "$THREADS" -c "$CONN" -d "${WARMUP}s" "http://127.0.0.1:$port/$path" >/dev/null 2>&1
    local vals=""
    for _ in $(seq 1 "$ITERS"); do
      local r; r=$(measure "$port" "$path")
      vals="$vals | $r"
      sleep 2
    done
    printf "  /%-12s runs:%s\n" "$path" "$vals"
    sleep 3
  done
  kill -TERM "$pid" 2>/dev/null; sleep 1
}

# webman = Workerman 锚（公平运行时基线）
bench "webman"                  "$PEERS/webman/kode_server.php"       8091 "start" "" ""
# kode·lean：Workerman vs Native
bench "kode_lean_workerman"     "$PEERS/kode_swoole_server.php"       8095 ""      lean    workerman
bench "kode_lean_native"       "$PEERS/kode_swoole_server.php"       8097 ""      lean    native
# kode·default：Workerman vs Native
bench "kode_default_workerman"  "$PEERS/kode_swoole_server.php"       8096 ""      default workerman
bench "kode_default_native"     "$PEERS/kode_swoole_server.php"       8098 ""      default native

echo "============================================================"
echo "workers=$WORKERS mem=$MEM  wrk: -t $THREADS -c $CONN -d ${DUR}s"
