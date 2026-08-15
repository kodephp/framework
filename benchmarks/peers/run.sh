#!/usr/bin/env bash
#
# 常驻内存框架「同条件」压测对比运行器（wrk 版）
# ------------------------------------------------------------
# 关键修正：ab 是单线程客户端，在本地回环下自身上限仅 ~3 万 rps，
# 会「反过来」成为瓶颈，使所有框架都卡在 2~3 万（假象）。
# 故统一改用多线程 wrk（-t 8 -c 200 -d 10s）测真实吞吐。
#
# 统一：相同机器 / 相同 worker 数 / 相同 wrk 参数 / 相同两条路由
#   /ping        -> hello world（最小响应）
#   /bench/json  -> 业务输出（内存构造 50 条记录 JSON，无 DB，隔离框架开销）
#
# 用法：bash benchmarks/peers/run.sh

set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
PHP="php"
WRK="wrk"
WORKERS=$(sysctl -n hw.ncpu 2>/dev/null || nproc 2>/dev/null || echo 8)
THREADS=8
CONN=200
DUR=8
ITERS=3

probe() { # port
  local port="$1"
  for _ in $(seq 1 40); do
    if curl -s -o /dev/null -m 1 "http://127.0.0.1:$port/ping"; then return 0; fi
    sleep 0.3
  done
  return 1
}

# 单次测量：返回 rps（空则 NA）
measure() { # port path
  wrk -t "$THREADS" -c "$CONN" -d "${DUR}s" "http://127.0.0.1:$1/$2" 2>/dev/null | grep -E 'Requests/sec' | awk '{print $2}'
}

bench() { # name start_cmd port [profile]
  local name="$1" start="$2" port="$3" profile="${4:-}"
  echo "------------------------------------------------------------"
  echo "[$name] port=$port workers=$WORKERS${profile:+, profile=$profile}"
  env BENCH_PORT="$port" BENCH_WORKERS="$WORKERS" ${profile:+KODE_PROFILE="$profile"} $start >"/tmp/bench_${name}.log" 2>&1 &
  local pid=$!
  if ! probe "$port"; then echo "  !! $name 未能就绪（见 /tmp/bench_${name}.log）"; kill -9 "$pid" 2>/dev/null; return 1; fi
  for path in ping bench/json; do
    local vals=""
    for _ in $(seq 1 "$ITERS"); do
      local r; r=$(measure "$port" "$path")
      vals="$vals $r"
    done
    local median; median=$(printf '%s\n' $vals | grep -v '^$' | sort -n | awk '{a[NR]=$1} END{ if(NR==0){print "NA"} else if(NR%2){print a[(NR+1)/2]} else {print (a[NR/2]+a[NR/2+1])/2} }')
    printf "  %-12s median=%-12s runs:%s\n" "/$path" "$median" "$vals"
  done
  kill -TERM "$pid" 2>/dev/null
  sleep 1
}

bench "swoole_raw"    "php $PEERS/swoole_raw/server.php"       8101
bench "workerman_raw" "php $PEERS/workerman_raw/server.php start" 8102
bench "webman"        "php $PEERS/webman/kode_server.php start" 8091
bench "kode_default"  "php $PEERS/kode_swoole_server.php"       8093 default
bench "kode_lean"     "php $PEERS/kode_swoole_server.php"       8094 lean
bench "hyperf"        "php $PEERS/hyperf/bin/hyperf.php start"  9501

echo "============================================================"
echo "workers=$WORKERS  wrk: -t $THREADS -c $CONN -d ${DUR}s"
