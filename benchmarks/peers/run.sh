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
WARMUP=3   # 预热秒数：每 peer / 每路由测量前拉满 boost 时钟，消除冷启动与热降频偏置
COOLDOWN=15 # 冷却秒数：每 peer 测量前让 CPU 从上一 peer 的热态回到 boost 基线，消除累积热降频偏置（笔记本多 peer 连续满负载会持续降频）

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
  # 测量前先冷却，让 CPU 从上一 peer 的热态回到 boost 基线，消除累积热降频偏置
  sleep "$COOLDOWN"
  # 每 peer 启动时先整体预热（拉满 boost 时钟、热 CPU/OPcache 缓存），避免冷启动首测偏低
  wrk -t "$THREADS" -c "$CONN" -d "${WARMUP}s" "http://127.0.0.1:$port/ping" >/dev/null 2>&1
  for path in ping bench/json; do
    # 路由级预热：每条路由测量前单独预热，使 /bench/json 不因「排在 /ping 后、连续满负载热降频」而失真
    wrk -t "$THREADS" -c "$CONN" -d "${WARMUP}s" "http://127.0.0.1:$port/$path" >/dev/null 2>&1
    local vals=""
    for _ in $(seq 1 "$ITERS"); do
      local r; r=$(measure "$port" "$path")
      vals="$vals $r"
      sleep 2   # 轮间冷却，平抑瞬时热抖动
    done
    local median; median=$(printf '%s\n' $vals | grep -v '^$' | sort -n | awk '{a[NR]=$1} END{ if(NR==0){print "NA"} else if(NR%2){print a[(NR+1)/2]} else {print (a[NR/2]+a[NR/2+1])/2} }')
    printf "  %-12s median=%-12s runs:%s\n" "/$path" "$median" "$vals"
    sleep 3     # 路由间冷却，消除热累积偏置
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
