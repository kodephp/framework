#!/usr/bin/env bash
# 全频谱压测：hello world → 内存 JSON → 10 个「真实 DB 业务查询」端点
# （raw PDO / kode 原生 / Eloquent / Doctrine / ThinkPHP）×（MySQL / pgsql）
# 等 worker=8，wrk -t8 -c200 -d8s，每端点 3 轮取中位数。
#
# 用法：bash benchmarks/run_spectrum.sh [profile=default] [port=8093] [rounds=3]
set -u

PROFILE="${1:-default}"
PORT="${2:-8093}"
ROUNDS="${3:-3}"
WRK=/opt/homebrew/bin/wrk
SERVER=benchmarks/peers/kode_swoole_server.php
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd "$REPO"

PATHS=(
  /ping
  /bench/json
  /bench/raw/mysql       /bench/raw/pgsql
  /bench/kode/mysql      /bench/kode/pgsql
  /bench/eloquent/mysql  /bench/eloquent/pgsql
  /bench/doctrine/mysql  /bench/doctrine/pgsql
  /bench/think/mysql     /bench/think/pgsql
)

echo "=== kode spectrum benchmark | profile=$PROFILE port=$PORT workers=8 rounds=$ROUNDS ==="

# 启动 server
KODE_PROFILE="$PROFILE" BENCH_PORT="$PORT" BENCH_WORKERS=8 php "$SERVER" >/tmp/kode_spectrum.log 2>&1 &
SRV=$!
# 等待 /ping 就绪
for i in $(seq 1 30); do
  if php -r "@file_get_contents('http://127.0.0.1:$PORT/ping'); exit(0);" 2>/dev/null; then break; fi
  sleep 0.5
done

echo
printf "%-22s | %12s | %10s\n" "endpoint" "rps(median)" "us/req"
printf "%-22s-+-%12s-+-%10s\n" "----------------------" "------------" "----------"

for p in "${PATHS[@]}"; do
  vals=()
  for r in $(seq 1 "$ROUNDS"); do
    out=$($WRK -t8 -c200 -d8s "http://127.0.0.1:$PORT$p" 2>/dev/null)
    v=$(echo "$out" | grep -oE "Requests/sec:[[:space:]]+[0-9.]+" | grep -oE "[0-9.]+" | head -1)
    [ -n "$v" ] && vals+=("$v")
  done
  if [ ${#vals[@]} -eq 0 ]; then
    printf "%-22s | %12s | %10s\n" "$p" "FAIL" "-"
    continue
  fi
  # median
  IFS=$'\n' sorted=($(sort -n <<<"${vals[*]}")); unset IFS
  n=${#sorted[@]}; mid=$(( (n-1)/2 ))
  med=${sorted[$mid]}
  us=$(awk "BEGIN{printf \"%.1f\", 1000000/$med}")
  printf "%-22s | %12s | %10s\n" "$p" "$med" "$us"
done

kill $SRV 2>/dev/null
wait $SRV 2>/dev/null
echo
echo "=== server log tail ==="
tail -5 /tmp/kode_spectrum.log 2>/dev/null
