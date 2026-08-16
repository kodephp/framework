#!/usr/bin/env bash
# 聚焦对比：webman(锚) vs kode·lean@Swoole vs kode·lean@Workerman
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
PHP="php"
WRK="wrk"
WORKERS=$(sysctl -n hw.ncpu 2>/dev/null || nproc 2>/dev/null || echo 8)
THREADS=8; CONN=200; DUR=8; ITERS=3; WARMUP=3; COOLDOWN=15

probe() { local port="$1"; for _ in $(seq 1 40); do curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$port/ping" && return 0; sleep 0.3; done; return 1; }
measure() { wrk -t "$THREADS" -c "$CONN" -d "${DUR}s" "http://127.0.0.1:$1/$2" 2>/dev/null | grep -E 'Requests/sec' | awk '{print $2}'; }

bench() {
  local name="$1" start="$2" port="$3" profile="${4:-}" runtime="${5:-}"
  echo "------------------------------------------------------------"
  echo "[$name] port=$port workers=$WORKERS profile=$profile runtime=${runtime:-auto}"
  env BENCH_PORT="$port" BENCH_WORKERS="$WORKERS" ${profile:+KODE_PROFILE="$profile"} ${runtime:+KODE_RUNTIME="$runtime"} $start >"/tmp/bench_${name}.log" 2>&1 &
  local pid=$!
  if ! probe "$port"; then echo "  !! $name 未就绪"; kill -9 "$pid" 2>/dev/null; return 1; fi
  sleep "$COOLDOWN"
  wrk -t "$THREADS" -c "$CONN" -d "${WARMUP}s" "http://127.0.0.1:$port/ping" >/dev/null 2>&1
  for path in ping bench/json; do
    wrk -t "$THREADS" -c "$CONN" -d "${WARMUP}s" "http://127.0.0.1:$port/$path" >/dev/null 2>&1
    local vals=""
    for _ in $(seq 1 "$ITERS"); do local r; r=$(measure "$port" "$path"); vals="$vals $r"; sleep 2; done
    local median; median=$(printf '%s\n' $vals | grep -v '^$' | sort -n | awk '{a[NR]=$1} END{ if(NR==0){print "NA"} else if(NR%2){print a[(NR+1)/2]} else {print (a[NR/2]+a[NR/2+1])/2} }')
    printf "  %-12s median=%-12s runs:%s\n" "/$path" "$median" "$vals"
    sleep 3
  done
  kill -TERM "$pid" 2>/dev/null; sleep 1
}

bench "webman"             "php $PEERS/webman/kode_server.php start" 8091
bench "kode_lean_swoole"   "php $PEERS/kode_swoole_server.php"      8094 lean swoole
bench "kode_lean_workerman" "php $PEERS/kode_swoole_server.php"     8095 lean workerman

echo "============================================================"
echo "workers=$WORKERS wrk: -t $THREADS -c $CONN -d ${DUR}s"
