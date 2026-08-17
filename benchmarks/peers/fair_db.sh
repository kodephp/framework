#!/usr/bin/env bash
#
# 公平「同运行时 / 同中间件」DB 端点对标（MySQL 预热后，消除冷 InnoDB 偏置）
# 仅测 /bench/db（webman/hyperf）与 /bench/raw/mysql（kode），6 peer 交错。
#
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
PHP="php"; WRK="wrk"
WORKERS=$(sysctl -n hw.ncpu 2>/dev/null || nproc 2>/dev/null || echo 8)
THREADS=8; CONN=200; DUR=6; ITERS=3
SUMMARY=/tmp/fair_db_summary.txt
: > "$SUMMARY"

# MySQL 预热：把 bench_users 全量加载进 InnoDB buffer pool，消除「首个 DB peer 冷启动」偏置
php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=kode_bench","root","root"); for($i=0;$i<800;$i++){ $s=$p->prepare("SELECT * FROM bench_users WHERE id=?"); $s->execute([rand(1,1000)]); $s->fetch(); } echo "MySQL warmed (bench_users in buffer pool)\n";'

measure() {
  wrk -t "$THREADS" -c "$CONN" -d "${DUR}s" "http://127.0.0.1:$1/$2" 2>/dev/null \
    | grep -E 'Requests/sec' | awk '{print $2}' | tr -d ','
}
median() {
  printf '%s\n' "$@" | grep -v '^$' | sort -n \
    | awk '{a[NR]=$1} END{if(NR==0)print "NA";else if(NR%2)print a[(NR+1)/2];else print (a[NR/2]+a[NR/2+1])/2}'
}
bench_db() {
  local name="$1" start="$2" stop="$3" port="$4" path="$5"
  echo "===== [$name] port=$port path=$path ====="
  no_proxy='*' NO_PROXY='*' bash -c "$start" >"/tmp/fairdb_$name.log" 2>&1 &
  local launcher=$!
  local ready=0
  for _ in $(seq 1 80); do
    curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$port/ping" && ready=1 && break
    sleep 0.3
  done
  if [ "$ready" -ne 1 ]; then
    echo "  !! $name 未就绪（见 /tmp/fairdb_$name.log）"
    bash -c "$stop" >/dev/null 2>&1; kill -9 "$launcher" 2>/dev/null
    echo "$name $path NA" >>"$SUMMARY"; return 1
  fi
  sleep 8
  local vals=""
  for _ in $(seq 1 "$ITERS"); do
    local r; r=$(measure "$port" "$path"); vals="$vals $r"; sleep 2
  done
  local m; m=$(median $vals)
  printf "  %s median=%s runs:%s\n" "$path" "$m" "$vals"
  echo "$name $path $m" >>"$SUMMARY"
  bash -c "$stop" >/dev/null 2>&1; sleep 1
  return 0
}

echo "########## 公平 DB 对标（MySQL 预热 · Workerman/Swoole · /bench/db 同条件）##########"
bench_db "webman_OFF"       "cd $PEERS/webman && WEBMAN_MW=off BENCH_PORT=8091 BENCH_WORKERS=$WORKERS php kode_server.php start -d" "cd $PEERS/webman && php kode_server.php stop; pkill -f 'webman/kode_server.php'" 8091 "/bench/db"
bench_db "kode_L0_off"      "cd $PEERS && KODE_PROFILE=off KODE_RUNTIME=workerman BENCH_PORT=8200 BENCH_WORKERS=$WORKERS php -d memory_limit=512M kode_swoole_server.php" "pkill -f kode_swoole_server.php" 8200 "/bench/raw/mysql"
bench_db "hyperf_OFF"       "cd $PEERS/hyperf && HYPERF_MW=off php bin/hyperf.php start" "pkill -f 'hyperf/bin/hyperf.php'" 9501 "/bench/db"
bench_db "webman_ON"        "cd $PEERS/webman && WEBMAN_MW=on BENCH_PORT=8091 BENCH_WORKERS=$WORKERS php kode_server.php start -d" "cd $PEERS/webman && php kode_server.php stop; pkill -f 'webman/kode_server.php'" 8091 "/bench/db"
bench_db "kode_ON_sameType" "cd $PEERS && KODE_PROFILE=off KODE_ENABLE=cors,security,logging KODE_RUNTIME=workerman BENCH_PORT=8201 BENCH_WORKERS=$WORKERS php -d memory_limit=512M kode_swoole_server.php" "pkill -f kode_swoole_server.php" 8201 "/bench/raw/mysql"
bench_db "hyperf_ON"        "cd $PEERS/hyperf && HYPERF_MW=on php bin/hyperf.php start" "pkill -f 'hyperf/bin/hyperf.php'" 9501 "/bench/db"
echo "======== DB 汇总（rps 中位）========"
cat "$SUMMARY"
