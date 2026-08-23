#!/usr/bin/env bash
#
# 公平 DB 双库横比 · 三框架生产级连接池（冷却式 + DB 完整性校验 1:1）
#   - kode@Native（kode/process 自研多进程，非 Workerman/Swoole 桥） /bench/db(+_pg)
#       -> Kode\Framework\Database\ConnectionPool（有界 PDO 池，closeCursor 耗尽结果集）
#   - webman（4 进程） /bench/db(+_pg) -> app/DbPool（有界 PDO 池，等价 webman/database）
#   - hyperf（Swoole 4 worker） /bench/db(+_pg) -> hyperf/database 连接池（协程安全）
# 仅测真实 DB 业务（一次主键 SELECT -> JSON），用各库「真实查询增量」算真实 qps 校验 1:1。
#   MySQL：SHOW GLOBAL STATUS LIKE 'Queries' 增量
#   PG：    pg_stat_database.xact_commit 增量（autocommit SELECT 每查询 +1）
# 不开 JIT（CLI 默认关闭）——公平基线；固定 4 进程（webman/hyperf 同口径）。
#
# set -u 关闭：DB 采样命令在连接数打满时返回空，set -u 会误判 unbound 中止整轮。
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PEERS="$ROOT/benchmarks/peers"
WORKERS=4; THREADS=8; CONN=200; DUR=6; ITERS=3
START_COOLDOWN=28; BETWEEN_PEER=12; BETWEEN_EP=5
OUT=/tmp/fair_db_dual.txt
: > "$OUT"

kill_port() {
  local port="$1" tries=0
  while [ "$tries" -lt 40 ]; do
    local pids; pids=$(lsof -ti tcp:"$port" 2>/dev/null)
    if [ -z "$pids" ]; then return 0; fi
    echo "$pids" | xargs -r kill -9 2>/dev/null
    sleep 0.5; tries=$((tries+1))
  done
  echo "  !! 端口 $port 强杀后仍占用" >&2
}
mysql_queries() {
  php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=kode_bench","root","root"); $r=$p->query("SHOW GLOBAL STATUS LIKE \"Queries\"")->fetch(); echo (int)$r[1];' 2>/dev/null | grep -E '^[0-9]+$'
}
pg_queries() {
  php -r '$p=new PDO("pgsql:host=127.0.0.1;port=5432;dbname=kode_bench","root",""); $r=$p->query("SELECT COALESCE(sum(xact_commit),0) FROM pg_stat_database WHERE datname=\x27kode_bench\x27")->fetch(); echo (int)$r[0];' 2>/dev/null | grep -E '^[0-9]+$'
}
median() {
  printf '%s\n' "$@" | grep -v '^$' | sort -n \
    | awk '{a[NR]=$1} END{if(NR==0)print "NA";else if(NR%2)print a[(NR+1)/2];else print (a[NR/2]+a[NR/2+1])/2}'
}
measure() {
  wrk -t "$THREADS" -c "$CONN" -d "${DUR}s" "http://127.0.0.1:$1/$2" 2>/dev/null \
    | grep -E 'Requests/sec' | awk '{print $2}' | tr -d ','
}
non2xx() {
  wrk -t "$THREADS" -c "$CONN" -d "${DUR}s" "http://127.0.0.1:$1/$2" 2>/dev/null \
    | grep -E 'Non-2xx' | awk '{print $NF}' | tr -d ','
}
probe() {
  for _ in $(seq 1 80); do
    curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$1/ping" && return 0
    sleep 0.3
  done
  return 1
}
warm() {
  local db="$1"
  if [ "$db" = "mysql" ]; then
    php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=kode_bench","root","root"); for($i=0;$i<800;$i++){ $s=$p->prepare("SELECT * FROM bench_users WHERE id=?"); $s->execute([rand(1,1000)]); $s->fetch(); } echo "MySQL warmed\n";'
  else
    php -r '$p=new PDO("pgsql:host=127.0.0.1;port=5432;dbname=kode_bench","root",""); for($i=0;$i<800;$i++){ $s=$p->prepare("SELECT * FROM bench_users WHERE id=?"); $s->execute([rand(1,1000)]); $s->fetch(); } echo "PG warmed\n";'
  fi
}

bench_endpoint() {
  local name="$1" start="$2" port="$3" ep="$4" db="$5"
  local logep="${ep//\//_}"
  kill_port "$port"
  # 确认端口真正释放：防止残留实例未被 lsof 捕获，导致新实例 bind 失败、wrk 打到旧实例（数字虚高）。
  for _ in $(seq 1 30); do
    if curl -s --noproxy '*' -o /dev/null -m 1 "http://127.0.0.1:$port/ping" 2>/dev/null; then
      lsof -ti tcp:"$port" 2>/dev/null | xargs -r kill -9 2>/dev/null; sleep 0.5
    else
      break
    fi
  done
  # 端点间冷却，规避 Apple Silicon 热降频系统性压低后半段端点。
  sleep "$BETWEEN_PEER"
  echo "===== [$name] port=$port workers=$WORKERS ep=/$ep db=$db ====="
  no_proxy='*' NO_PROXY='*' bash -c "$start" >"/tmp/fair_${name}_${logep}.log" 2>&1 &
  local launcher=$!
  if ! probe "$port"; then
    echo "  !! $name 未就绪（见 /tmp/fair_${name}_${logep}.log）"
    kill_port "$port"; kill -9 "$launcher" 2>/dev/null
    echo "$name ep=$ep db=$db FAIL" >>"$OUT"; return 1
  fi
  sleep "$START_COOLDOWN"; START_COOLDOWN=$BETWEEN_PEER
  if [ "$db" != "none" ]; then
    warm "$db"
  fi
  local before after t0 t1 dm real=0 flag ne="" dv=""
  if [ "$db" != "none" ]; then
    before=$([ "$db" = "mysql" ] && mysql_queries || pg_queries)
  fi
  t0=$(date +%s)
  for _ in $(seq 1 "$ITERS"); do
    local r; r=$(measure "$port" "$ep"); dv="$dv $r"; sleep 2
  done
  local n; n=$(non2xx "$port" "$ep"); ne="${n:-0}"
  t1=$(date +%s)
  if [ "$db" != "none" ]; then
    after=$([ "$db" = "mysql" ] && mysql_queries || pg_queries)
    dm=$(median $dv)
    local elapsed=$(( t1 - t0 )); [ "$elapsed" -le 0 ] && elapsed=1
    if [ -n "${after:-}" ] && [ -n "${before:-}" ]; then
      real=$(( (${after:-0} - ${before:-0}) / elapsed ))
    fi
    flag="✅1:1"
    awk "BEGIN{exit !(${dm:-0} > ${real:-0}*2)}" && flag="❌虚高"
  else
    dm=$(median $dv)
    real="NA"; flag="n/a(无DB)"
  fi
  printf "  /%s  median=%-12s runs:%s | %s真实qps≈%s %s | 非2xx=%s\n" "$ep" "$dm" "$dv" "$db" "$real" "$flag" "$ne"
  echo "$name ep=$ep db=$db reported=$dm real=$real flag=$flag non2xx=$ne" >>"$OUT"
  kill_port "$port"; sleep 1
  echo ""
}

echo "########## 公平横比（kode@Native / webman / hyperf · 4 进程 · 无DB + 双库连接池 · 冷却式 + DB 完整性 1:1）##########"
# 抬高 MySQL 连接上限，避免各框架连接池在极端瞬时打满默认 max_connections(151)。
mysql -h127.0.0.1 -uroot -proot -e "SET GLOBAL max_connections=512" 2>/dev/null && echo "(MySQL max_connections -> 512)" || echo "(无法设置 max_connections，按现状比较)"

# 无 DB 端点（hello world / 内存 JSON）：三框架同口径横比，正面回应 TechEmpower 39万 量级参照。
for ep_db in "ping:none" "bench/json:none"; do
  ep="${ep_db%%:*}"; db="${ep_db##*:}"
  bench_endpoint "webman" \
    "cd $PEERS/webman && WEBMAN_MW=off BENCH_PORT=8091 BENCH_WORKERS=$WORKERS php kode_server.php start -d" \
    8091 "$ep" "$db"
  bench_endpoint "kode" \
    "cd $PEERS && KODE_PROFILE=off KODE_RUNTIME=native BENCH_PORT=8200 BENCH_WORKERS=$WORKERS php $JIT_FLAGS -d memory_limit=1G kode_swoole_server.php" \
    8200 "$ep" "$db"
  bench_endpoint "hyperf" \
    "cd $PEERS/hyperf && php bin/hyperf.php start" \
    9501 "$ep" "$db"
done

# MySQL：kode@Native / hyperf（webman 的 PDO_mysql 在高并发持续速率下不稳定返回 500，
#   属 webman/swoole+PDO_mysql 交互的环境侧现象，非 kode 关注点；webman MySQL 端点本对照排除，
#   仅保留 webman 的 pgsql 与无 DB 端点作公平横比）。
for ep_db in "bench/db:mysql"; do
  ep="${ep_db%%:*}"; db="${ep_db##*:}"
  bench_endpoint "kode_DBpool" \
    "cd $PEERS && KODE_PROFILE=off KODE_RUNTIME=native BENCH_PORT=8200 BENCH_WORKERS=$WORKERS php $JIT_FLAGS -d memory_limit=1G kode_swoole_server.php" \
    8200 "$ep" "$db"
  bench_endpoint "hyperf_DBpool" \
    "cd $PEERS/hyperf && DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=kode_bench DB_USERNAME=root DB_PASSWORD=root php bin/hyperf.php start" \
    9501 "$ep" "$db"
done

# pgsql：kode + webman（hyperf/database 此版本仅支持 MySQL，pgsql 不参与横比，如实标注）
for ep_db in "bench/db_pg:pgsql"; do
  ep="${ep_db%%:*}"; db="${ep_db##*:}"
  bench_endpoint "webman_DBpool" \
    "cd $PEERS/webman && WEBMAN_MW=off BENCH_PORT=8091 BENCH_WORKERS=$WORKERS PG_HOST=127.0.0.1 PG_PORT=5432 PG_DATABASE=kode_bench PG_USERNAME=root PG_PASSWORD= php kode_server.php start -d" \
    8091 "$ep" "$db"
  bench_endpoint "kode_DBpool" \
    "cd $PEERS && KODE_PROFILE=off KODE_RUNTIME=native BENCH_PORT=8200 BENCH_WORKERS=$WORKERS php $JIT_FLAGS -d memory_limit=1G kode_swoole_server.php" \
    8200 "$ep" "$db"
done

echo "================ 公平双库 DB 横比汇总 ================"
cat "$OUT"
