#!/usr/bin/env bash
#
# 常驻内存框架「同条件」压测对比运行器（wrk 版，单一可信入口）
# =====================================================================
# 设计目标（对应压测文档口径）：
#   1. 同机器 / 同 worker 数 / 同 wrk 参数 / 同两条路由，保证横向可比。
#   2. 两档数据都测：
#        /ping        -> hello world（最小响应，内核上限）
#        /bench/json  -> 业务输出（内存构造 50 条记录 JSON，无 DB，隔离框架开销）
#   3. 对比集合（全部走 Kode::serve 真实生产路径或对应框架真实入口）：
#        - 引擎天花板：swoole_raw / workerman_raw（裸引擎，零中间件）
#        - Workerman 系框架：webman（≈零中间件）
#        - Swoole 系框架：hyperf（自带 DI / 可观测）
#        - 本框架：kode·lean / kode·default
#            默认运行时 = kode/process Native（自研纯 PHP 多进程）
#            经 KODE_RUNTIME 可切换为 swoole / workerman
#            → 本运行器把三运行时都跑一遍，直接回答「自研 native vs swoole vs workerman」。
#   4. kode/process 5.2.31 的 Native（F1）/ Swoole（F2）并发缺陷已在 5.2.36 修复，
#      故三运行时均可正常压测（详见 benchmarks/kode-process-issues.md）。
#
# 抗噪：WARMUP 预热 + COOLDOWN 冷却消除热降频；ITERS 取中位数抗单轮热抖动。
# 代理陷阱：no_proxy='*' 防 curl 走代理返 502；wrk 本身不走代理。
# 内存：php -d memory_limit=512M 防 /bench/json 全 ORM boot 触默认 128M 上限崩。
#
# 用法：bash benchmarks/peers/run.sh
#
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
WARMUP=5
COOLDOWN=10
MEM=512M

probe() { # port
  local port="$1"
  for _ in $(seq 1 60); do
    if curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$port/ping"; then return 0; fi
    sleep 0.3
  done
  return 1
}

measure() { # port path -> rps（空则 NA）
  wrk -t "$THREADS" -c "$CONN" -d "${DUR}s" "http://127.0.0.1:$1/$2" 2>/dev/null | grep -E 'Requests/sec' | awk '{print $2}' | tr -d ','
}

bench() { # name start_cmd port [profile] [runtime]
  local name="$1" start="$2" port="$3" profile="${4:-}" runtime="${5:-}"
  echo "------------------------------------------------------------"
  echo "[$name] port=$port workers=$WORKERS${profile:+, profile=$profile}${runtime:+, runtime=$runtime}"
  env BENCH_PORT="$port" BENCH_WORKERS="$WORKERS" ${profile:+KODE_PROFILE="$profile"} ${runtime:+KODE_RUNTIME="$runtime"} \
      no_proxy='*' NO_PROXY='*' $start >"/tmp/bench_${name}.log" 2>&1 &
  local pid=$!
  if ! probe "$port"; then echo "  !! $name 未能就绪（见 /tmp/bench_${name}.log）"; kill -9 "$pid" 2>/dev/null; return 1; fi
  sleep "$COOLDOWN"
  wrk -t "$THREADS" -c "$CONN" -d "${WARMUP}s" "http://127.0.0.1:$port/ping" >/dev/null 2>&1
  for path in ping bench/json; do
    wrk -t "$THREADS" -c "$CONN" -d "${WARMUP}s" "http://127.0.0.1:$port/$path" >/dev/null 2>&1
    local vals=""
    for _ in $(seq 1 "$ITERS"); do
      local r; r=$(measure "$port" "$path")
      vals="$vals $r"
      sleep 2
    done
    local median
    median=$(printf '%s\n' $vals | grep -v '^$' | sort -n | awk '{a[NR]=$1} END{ if(NR==0){print "NA"} else if(NR%2){print a[(NR+1)/2]} else {print (a[NR/2]+a[NR/2+1])/2} }')
    printf "  %-12s median=%-12s runs:%s\n" "/$path" "$median" "$vals"
    sleep 3
  done
  kill -TERM "$pid" 2>/dev/null; sleep 1
}

# 引擎天花板（裸引擎，零中间件）
bench "swoole_raw"    "php $PEERS/swoole_raw/server.php"       8101
bench "workerman_raw" "php $PEERS/workerman_raw/server.php start" 8102
# 框架 peers（真实入口）
bench "webman"        "php $PEERS/webman/kode_server.php start" 8091
bench "hyperf"        "php $PEERS/hyperf/bin/hyperf.php start"  9501
# 本框架 · lean（三运行时）
bench "kode_lean_native"     "php $PEERS/kode_swoole_server.php" 8097 lean    native
bench "kode_lean_swoole"     "php $PEERS/kode_swoole_server.php" 8098 lean    swoole
bench "kode_lean_workerman"  "php $PEERS/kode_swoole_server.php" 8094 lean    workerman
# 本框架 · default（三运行时）
bench "kode_default_native"     "php $PEERS/kode_swoole_server.php" 8099 default native
bench "kode_default_swoole"     "php $PEERS/kode_swoole_server.php" 8100 default swoole
bench "kode_default_workerman"  "php $PEERS/kode_swoole_server.php" 8093 default workerman

echo "============================================================"
echo "workers=$WORKERS  wrk: -t $THREADS -c $CONN -d ${DUR}s  iters=$ITERS"
echo "本框架默认运行时 = native（自研多进程）；swoole/workerman 经 KODE_RUNTIME 切换"
