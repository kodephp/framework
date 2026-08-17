#!/usr/bin/env bash
#
# 公平「同运行时 / 同中间件」对标（kode vs webman vs hyperf）
# ---------------------------------------------------------------------
# 关键修正（对应 v0.8.41 审计）：旧 run.sh 把 kode 梯度跑在 KODE_RUNTIME=native，
# 而 webman/hyperf 跑在 Workerman+Swoole —— 这是历史上「kode L0 远慢于 webman」的
# 伪结论根因。本脚本全部 peer 统一在 **Workerman 驱动**（kode KODE_RUNTIME=workerman，
# webman/hyperf 本就以 Workerman/Swoole 运行），并额外叠加「同类型中间件 ON」档位，
# 直接回答：开启中间件后，三者从 hello world(/ping) 到 数据库业务(/bench/db) 的真实差距。
#
# 抗噪：WARMUP 预热 + COOLDOWN 冷却消除热降频；ITERS 取中位抗单轮抖动。
# 端口：kode 8200/8201 · webman 8091 · hyperf 9501
#
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
PHP="php"; WRK="wrk"
WORKERS=$(sysctl -n hw.ncpu 2>/dev/null || nproc 2>/dev/null || echo 8)
THREADS=8; CONN=200; DUR=8; ITERS=3; WARMUP=5; COOLDOWN=15
SUMMARY=/tmp/fair_summary.txt
: > "$SUMMARY"

probe() {
  local port="$1"
  for _ in $(seq 1 80); do
    if curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$port/ping"; then return 0; fi
    sleep 0.3
  done
  return 1
}
measure() {
  wrk -t "$THREADS" -c "$CONN" -d "${DUR}s" "http://127.0.0.1:$1/$2" 2>/dev/null \
    | grep -E 'Requests/sec' | awk '{print $2}' | tr -d ','
}
median() {
  printf '%s\n' "$@" | grep -v '^$' | sort -n \
    | awk '{a[NR]=$1} END{if(NR==0)print "NA";else if(NR%2)print a[(NR+1)/2];else print (a[NR/2]+a[NR/2+1])/2}'
}

# bench_one name startcmd stopcmd port dbpath
bench_one() {
  local name="$1" start="$2" stop="$3" port="$4" db="$5"
  echo "===== [$name] port=$port workers=$WORKERS ====="
  local log="/tmp/fair_${name}.log"
  no_proxy='*' NO_PROXY='*' bash -c "$start" >"$log" 2>&1 &
  local launcher=$!
  if ! probe "$port"; then
    echo "  !! $name 未能就绪（见 $log）"
    bash -c "$stop" >/dev/null 2>&1; kill -9 "$launcher" 2>/dev/null
    for p in ping bench/json "$db"; do echo "$name /$p NA" >>"$SUMMARY"; done
    return 1
  fi
  sleep "$COOLDOWN"
  wrk -t "$THREADS" -c "$CONN" -d "${WARMUP}s" "http://127.0.0.1:$port/ping" >/dev/null 2>&1
  for path in ping bench/json "$db"; do
    local vals=""
    for _ in $(seq 1 "$ITERS"); do
      local r; r=$(measure "$port" "$path"); vals="$vals $r"; sleep 2
    done
    local m; m=$(median $vals)
    printf "  %-14s median=%-12s runs:%s\n" "/$path" "$m" "$vals"
    echo "$name /$path $m" >>"$SUMMARY"
    sleep 3
  done
  bash -c "$stop" >/dev/null 2>&1; sleep 1
  echo ""
}

echo "########## 公平对标：kode vs webman vs hyperf（Workerman 驱动 · /ping·/bench/json·DB 同条件）##########"

# 基线（零中间件）+ 开启同类型中间件，交错排列以抵消热降频顺序偏置
bench_one "webman_OFF" \
  "cd $PEERS/webman && WEBMAN_MW=off BENCH_PORT=8091 BENCH_WORKERS=$WORKERS php kode_server.php start -d" \
  "cd $PEERS/webman && php kode_server.php stop; pkill -f 'webman/kode_server.php'" \
  8091 "/bench/db"

bench_one "kode_L0_off" \
  "cd $PEERS && KODE_PROFILE=off KODE_RUNTIME=workerman BENCH_PORT=8200 BENCH_WORKERS=$WORKERS php -d memory_limit=512M kode_swoole_server.php" \
  "pkill -f kode_swoole_server.php" \
  8200 "/bench/raw/mysql"

bench_one "hyperf_OFF" \
  "cd $PEERS/hyperf && HYPERF_MW=off php bin/hyperf.php start" \
  "pkill -f 'hyperf/bin/hyperf.php'" \
  9501 "/bench/db"

bench_one "webman_ON" \
  "cd $PEERS/webman && WEBMAN_MW=on BENCH_PORT=8091 BENCH_WORKERS=$WORKERS php kode_server.php start -d" \
  "cd $PEERS/webman && php kode_server.php stop; pkill -f 'webman/kode_server.php'" \
  8091 "/bench/db"

bench_one "kode_ON_sameType" \
  "cd $PEERS && KODE_PROFILE=off KODE_ENABLE=cors,security,logging KODE_RUNTIME=workerman BENCH_PORT=8201 BENCH_WORKERS=$WORKERS php -d memory_limit=512M kode_swoole_server.php" \
  "pkill -f kode_swoole_server.php" \
  8201 "/bench/raw/mysql"

bench_one "hyperf_ON" \
  "cd $PEERS/hyperf && HYPERF_MW=on php bin/hyperf.php start" \
  "pkill -f 'hyperf/bin/hyperf.php'" \
  9501 "/bench/db"

echo "================ 汇总（rps 中位）================"
printf "%-14s %12s %14s %14s\n" "peer" "/ping" "/bench/json" "/bench/db(raw)"
for name in webman_OFF kode_L0_off hyperf_OFF webman_ON kode_ON_sameType hyperf_ON; do
  p=$(awk -v n="$name" -v p="/ping" '$1==n && $2==p {print $3}' "$SUMMARY")
  j=$(awk -v n="$name" -v p="/bench/json" '$1==n && $2==p {print $3}' "$SUMMARY")
  d=$(awk -v n="$name" -v p="/bench/db" '$1==n && $2==p {print $3}' "$SUMMARY")
  d=${d:-$(awk -v n="$name" -v p="/bench/raw/mysql" '$1==n && $2==p {print $3}' "$SUMMARY")}
  printf "%-14s %12s %14s %14s\n" "$name" "${p:-NA}" "${j:-NA}" "${d:-NA}"
done
echo "（kode 的 DB 端点为 /bench/raw/mysql，与 webman/hyperf 的 /bench/db 同为裸 PDO 主键 SELECT）"