#!/usr/bin/env bash
# 临时对标：kode·lean / kode·default 走 Workerman 驱动（kode/process Swoole 驱动在
# Swoole 6.2.2 下并发崩溃，已隔离为上游回归；Workerman 驱动健康，可继续框架层调优）。
# webman 作 Workerman 锚（同运行时公平对比）。
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

bench "webman"                "$PEERS/webman/kode_server.php"       8091 "start" "" ""
bench "kode_lean_workerman"   "$PEERS/kode_swoole_server.php"       8095 ""      lean    workerman
bench "kode_default_workerman" "$PEERS/kode_swoole_server.php"      8096 ""      default workerman

echo "============================================================"
echo "workers=$WORKERS mem=$MEM  wrk: -t $THREADS -c $CONN -d ${DUR}s"
