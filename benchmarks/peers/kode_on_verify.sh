#!/usr/bin/env bash
# 聚焦验证：kode ON(cors,security,logging) 修复后 /bench/json 是否仍崩（OOM）。
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
WORKERS=$(sysctl -n hw.ncpu 2>/dev/null || echo 8)
THREADS=8; CONN=200; DUR=8; ITERS=3
PORT=8201
kill_port() { local t=0; while [ $t -lt 40 ]; do pids=$(lsof -ti tcp:$1 2>/dev/null); [ -z "$pids" ] && return 0; echo "$pids"|xargs -r kill -9 2>/dev/null; sleep 0.5; t=$((t+1)); done; }
measure() { wrk -t $THREADS -c $CONN -d ${DUR}s "http://127.0.0.1:$1/$2" 2>/dev/null|grep -E 'Requests/sec'|awk '{print $2}'|tr -d ','; }
median() { printf '%s\n' "$@"|grep -v '^$'|sort -n|awk '{a[NR]=$1}END{if(NR==0)print "NA";else if(NR%2)print a[(NR+1)/2];else print (a[NR/2]+a[NR/2+1])/2}'; }
probe() { for _ in $(seq 1 80); do curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$1/ping" && return 0; sleep 0.3; done; return 1; }

kill_port $PORT
echo "===== [kode_ON_sameType] port=$PORT ====="
no_proxy='*' NO_PROXY='*' bash -c "cd $PEERS && KODE_PROFILE=off KODE_ENABLE=cors,security,logging KODE_RUNTIME=workerman BENCH_PORT=$PORT BENCH_WORKERS=$WORKERS php -d memory_limit=512M kode_swoole_server.php" >/tmp/kode_on_verify.log 2>&1 &
lp=$!
if ! probe $PORT; then echo "!! not ready"; kill_port $PORT; kill -9 $lp; exit 1; fi
sleep 60
wrk -t $THREADS -c $CONN -d 5s "http://127.0.0.1:$PORT/ping" >/dev/null 2>&1
for path in ping bench/json bench/raw/mysql; do
  v=""
  for _ in $(seq 1 $ITERS); do r=$(measure $PORT $path); v="$v $r"; sleep 2; done
  m=$(median $v)
  printf "  /%-16s median=%-12s runs:%s\n" "$path" "$m" "$v"
  sleep 12
done
echo "=== OOM check ==="
grep -c "Allowed memory size" /tmp/kode_on_verify.log 2>/dev/null && echo "OOM lines above" || echo "no OOM"
kill_port $PORT
