#!/usr/bin/env bash
# 聚焦验证：webman ON(WEBMAN_MW=on) 冷却式复测（端口级强杀防残留）。
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
WORKERS=$(sysctl -n hw.ncpu 2>/dev/null || echo 8)
THREADS=8; CONN=200; DUR=8; ITERS=3
PORT=8091
kill_port() { local t=0; while [ $t -lt 40 ]; do pids=$(lsof -ti tcp:$1 2>/dev/null); [ -z "$pids" ] && return 0; echo "$pids"|xargs -r kill -9 2>/dev/null; sleep 0.5; t=$((t+1)); done; }
measure() { wrk -t $THREADS -c $CONN -d ${DUR}s "http://127.0.0.1:$1/$2" 2>/dev/null|grep -E 'Requests/sec'|awk '{print $2}'|tr -d ','; }
mysql_queries() { php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=kode_bench","root","root"); $r=$p->query("SHOW GLOBAL STATUS LIKE \"Queries\"")->fetch(); echo $r[1];' 2>/dev/null; }
median() { printf '%s\n' "$@"|grep -v '^$'|sort -n|awk '{a[NR]=$1}END{if(NR==0)print "NA";else if(NR%2)print a[(NR+1)/2];else print (a[NR/2]+a[NR/2+1])/2}'; }
probe() { for _ in $(seq 1 80); do curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$1/ping" && return 0; sleep 0.3; done; return 1; }

kill_port $PORT
php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=kode_bench","root","root"); for($i=0;$i<800;$i++){ $s=$p->prepare("SELECT * FROM bench_users WHERE id=?"); $s->execute([rand(1,1000)]); $s->fetch(); }' 2>/dev/null
echo "===== [webman_ON] port=$PORT ====="
no_proxy='*' NO_PROXY='*' bash -c "cd $PEERS/webman && WEBMAN_MW=on BENCH_PORT=$PORT BENCH_WORKERS=$WORKERS php kode_server.php start -d" >/tmp/webman_on_verify.log 2>&1 &
lp=$!
if ! probe $PORT; then echo "!! not ready"; kill_port $PORT; kill -9 $lp; exit 1; fi
sleep 60
wrk -t $THREADS -c $CONN -d 5s "http://127.0.0.1:$PORT/ping" >/dev/null 2>&1
before=$(mysql_queries)
for path in ping bench/json bench/db; do
  v=""
  for _ in $(seq 1 $ITERS); do r=$(measure $PORT $path); v="$v $r"; sleep 2; done
  m=$(median $v)
  printf "  /%-12s median=%-12s runs:%s\n" "$path" "$m" "$v"
  sleep 12
done
after=$(mysql_queries)
real=$(( (after - before) / (DUR * ITERS) ))
echo "  DB MySQL真实qps≈$real"
kill_port $PORT
