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
#        - 本框架：能力梯度 off → full（默认运行时 = native 自研多进程）
#            框架默认 opt-in：cors/security/locale/resilience/logging/observability 等
#            全部 config 默认 false，开发者按需开启。本运行器逐级叠加，定位每项成本。
#            经 KODE_RUNTIME 可切换为 swoole / workerman（梯度统一跑 native 以隔离「能力成本」）。
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

# =====================================================================
# 一、同类框架（自然配置，作锚点）—— 先跑（机器最冷，数字最干净）
# =====================================================================
echo "########## 同类框架（自然配置，作锚点）##########"
bench "swoole_raw"    "php $PEERS/swoole_raw/server.php"       8101
bench "workerman_raw" "php $PEERS/workerman_raw/server.php start" 8102
bench "webman"        "php $PEERS/webman/kode_server.php start" 8091
bench "hyperf"        "php $PEERS/hyperf/bin/hyperf.php start"  9501

# =====================================================================
# 二、本框架「能力梯度」压测（默认运行时 = native 自研多进程）
#   框架默认 opt-in：以下能力 config 默认全 false。本梯度从零中间件(off) 逐级叠加，
#   定位「每项能力的边际吞吐成本」，给开发者最直观的取舍数据。
#   档位：L0 全关 → L1 边缘(cors+security+locale) → L2 +resilience
#         → L3 +logging → L4 +observability → L5 full(全开)
#   抗热降频：每档跑 正向 + 反向 两遍，取两遍各自中位后再取中，抵消顺序热偏置。
# =====================================================================
mkdir -p /tmp/kode_grad

bench_kode() { # label profile enable port
  local label="$1" profile="$2" enable="$3" port="$4"
  echo "------------------------------------------------------------"
  echo "[$label] profile=$profile enable='$enable' port=$port workers=$WORKERS runtime=native"
  env BENCH_PORT="$port" BENCH_WORKERS="$WORKERS" KODE_PROFILE="$profile" KODE_RUNTIME=native \
      ${enable:+KODE_ENABLE="$enable"} no_proxy='*' NO_PROXY='*' \
      php -d memory_limit=$MEM "$PEERS/kode_swoole_server.php" >"/tmp/bench_${label}.log" 2>&1 &
  local pid=$!
  if ! probe "$port"; then echo "  !! $label 未就绪（见 /tmp/bench_${label}.log）"; kill -9 "$pid" 2>/dev/null; return 1; fi
  sleep "$COOLDOWN"
  for path in ping bench/json; do
    local sf; sf=$(printf '%s' "$path" | tr '/' '_')   # bench/json -> bench_json（避免路径含 / 写成目录）
    local vals=""
    for _ in $(seq 1 "$ITERS"); do
      local r; r=$(measure "$port" "$path"); vals="$vals $r"; sleep 2
    done
    local median
    median=$(printf '%s\n' $vals | grep -v '^$' | sort -n | awk '{a[NR]=$1} END{if(NR==0)print "NA";else if(NR%2)print a[(NR+1)/2];else print (a[NR/2]+a[NR/2+1])/2}')
    printf "  %-12s median=%-12s runs:%s\n" "/$path" "$median" "$vals"
    echo "$median" >> "/tmp/kode_grad/${label}_${sf}.txt"
    sleep 3
  done
  kill -TERM "$pid" 2>/dev/null; sleep 1; pkill -f kode_swoole_server.php 2>/dev/null; sleep 1
}

median_file() { # file -> median of its lines
  printf '%s\n' $(cat "$1" 2>/dev/null) | grep -v '^$' | sort -n | awk '{a[NR]=$1} END{if(NR==0)print "NA";else if(NR%2)print a[(NR+1)/2];else print (a[NR/2]+a[NR/2+1])/2}'
}

LEVELS=(
  "L0:off:"
  "L1:off:cors,security,locale"
  "L2:off:cors,security,locale,resilience"
  "L3:off:cors,security,locale,resilience,logging"
  "L4:off:cors,security,locale,resilience,logging,observability"
  "L5:full:"
)
PORTS=(8200 8201 8202 8203 8204 8205)

run_gradient_pass() { # forward|reverse
  local dir="$1"
  local idxs; if [ "$dir" = "forward" ]; then idxs="0 1 2 3 4 5"; else idxs="5 4 3 2 1 0"; fi
  for i in $idxs; do
    IFS=':' read -r lab prof en <<< "${LEVELS[$i]}"
    bench_kode "kode_${lab}" "$prof" "$en" "${PORTS[$i]}"
  done
}

echo "########## 本框架能力梯度（native 运行时，2-pass 抗热降频）##########"
run_gradient_pass forward
run_gradient_pass reverse

echo ""
echo "================ 本框架能力梯度汇总（/ping · /bench/json，rps 中位）================"
printf "%-34s %12s %14s\n" "档位(能力)" "/ping" "/bench/json"
for i in 0 1 2 3 4 5; do
  IFS=':' read -r lab prof en <<< "${LEVELS[$i]}"
  p=$(median_file "/tmp/kode_grad/kode_${lab}_ping.txt")
  b=$(median_file "/tmp/kode_grad/kode_${lab}_bench_json.txt")
  printf "%-34s %12s %14s\n" "$lab" "$p" "$b"
done
echo "本框架默认运行时 = native（自研多进程）；swoole/workerman 经 KODE_RUNTIME 切换"
