# kode/framework 压测对比套件

本目录包含两套**面向常驻内存运行时**的压测工具，用于回答两类问题：

1. **框架吞吐对比**——kode 与同形态常驻内存 peer（swoole / workerman / webman / hyperf）在相同条件下的真实吞吐。
2. **数据库全频谱对比**——同一 kode 服务下，5 个数据访问层（原生 PDO / kode 查询构造器 / Eloquent / Doctrine DBAL / ThinkORM）× MySQL / pgsql 的真实吞吐。

> 设计立场：kode 是**常驻内存框架**（Swoole/Swow/Fiber 长生命周期），吞吐量级与 webman/hyperf 同档（12~18 万 rps），
> **绝非传统 FPM（~5k）**。本套件**不**与 FPM 框架做直接吞吐横比——两者运行模型根本不同（详见下方文档指针）。

---

## 目录结构

```
benchmarks/
├── README.md                      # 本说明
├── PEER_BENCHMARK.md              # 套件一：常驻内存 peer 对比报告（真实 wrk 数据）
├── DB_SPECTRUM.md                 # 套件二：数据库全频谱对比报告（真实 wrk 数据）
│
├── # —— 套件一：框架吞吐 peer 对比 ——
├── peers/
│   ├── run.sh                     # 编排：启动各 peer + wrk 压测 + 汇总
│   ├── kode_swoole_server.php     # kode 常驻服务（KODE_PROFILE=default|lean 切换档位）
│   ├── swoole_raw/                # 裸 Swoole（无中间件·天花板参照）
│   ├── workerman_raw/             # 裸 Workerman（无中间件·天花板参照）
│   ├── webman/                    # webman（Workerman 系框架）
│   └── hyperf/                    # hyperf（Swoole 系框架）
│
└── # —— 套件二：数据库全频谱对比 ——
    ├── run_spectrum.sh            # 编排：wrk 对 12 个 DB 端点压测（median-of-3）
    ├── setup_bench_dbs.php        # 建表 + 灌入基准数据（MySQL / pgsql）
    └── orm-harness/              # 独立多 ORM 依赖工程（不污染框架 composer.json）
        └── composer.json         # illuminate/database ^11 · doctrine/dbal ^4 · topthink/think-orm ^3
```

---

## 套件一：框架吞吐 peer 对比

**问题**：kode 全栈真实吞吐在同形态常驻内存框架里排第几？

**方法**：所有 peer 同机器、同 11 worker（= `swoole_cpu_num()`）、同 wrk 参数（`-t8 -c200 -d8s`，每端点取 3 次中位数）、同两条路由
（`/ping` hello world、`/bench/json` 内存构造 50 条记录 JSON，无 DB 以隔离框架开销）。

**结果**：见 [`PEER_BENCHMARK.md`](PEER_BENCHMARK.md)。结论摘要：

- `kode L0（off，零跨切面，框架默认 opt-in 全关）` 达裸 Swoole 天花板 **91%/75%**（166,971/132,693），约为 webman 的 **92%/74%**；`/bench/json` 仅 `/ping` 的 ~79%，明显低于 `/ping`——此前归因 `HttpBridge::toRaw` 序列化，PEER_BENCHMARK §6 的 A/B 已证 Swoole 单串 `end()` 最优、响应写出非主因；kode L5 与 webman 差距主因是可观测性 100% 路径固有成本（PEER_BENCHMARK §6）；
- `kode L5（full，完整企业栈）` **93,829/66,234**，约为 L0 的 **56%/50%**（公平冷却口径下低于自带 DI 的 hyperf，属完整企业栈定位差异，非缺陷）；
- 全栈约 44%/50% 折损是**功能对价**（cors/安全头/韧性/日志/追踪/会话/幂等），其中可观测性（Metrics+Trace）是单项最大税（详见 PEER_BENCHMARK §3 梯度 L3→L4、§6）。

> 注：此前「94~97%、kode_default 反超 hyperf」系旧 harness 未做 peer 间冷却、排第 6 的 hyperf 被热降频系统性压低（114k）的失真结论；补 `COOLDOWN=15` 后 hyperf 回到 151k/138k，旧结论作废。

**复现**：

```bash
bash benchmarks/peers/run.sh
```

---

## 套件二：数据库全频谱对比

**问题**：在 kode 常驻服务里，不同 ORM / 数据库的真实 DB 查询吞吐差多少？Doctrine 是否更快？MySQL 为何比 pgsql 快？

**方法**：`kode_swoole_server.php` 暴露 12 个端点 = {raw PDO, kode 原生查询构造器, Eloquent, Doctrine DBAL, ThinkORM} × {MySQL, pgsql}，
每个端点执行「一次主键 SELECT + 返回 JSON」。`run_spectrum.sh` 用 wrk 对全部端点压测（median-of-3）。
多 ORM 依赖隔离在 `orm-harness/`，不污染框架 `composer.json`。

**结果**：见 [`DB_SPECTRUM.md`](DB_SPECTRUM.md)。结论摘要（v0.8.33，rps）：

| 数据层 | MySQL | pgsql |
|---|---:|---:|
| 原生 PDO | 39.8k | 26.2k |
| kode 原生（含 PdoConnection 补丁） | 38.1k | 34.5k |
| Eloquent | 22.9k | 21.5k |
| **Doctrine DBAL**（`driver='symfony'`） | **40.6k** | **40.8k** |
| ThinkORM | 27.9k | 25.0k |

- kode 原生经 `patches/kode-database-pdoconnection.patch` 补丁（去 `SELECT 1` 探活 + 预编译语句缓存）后，
  MySQL **21.6k → 38.1k（+77%）**、pgsql **17.5k → 34.5k（+98%）**，已追平原生 PDO
  （注：该补丁语义已由上游 **kode/database 1.15.7** 合入，框架 v0.8.45 起直接使用纯净包，不再打此补丁）；
- Doctrine 因缓存预编译语句抹平协议开销，pgsql 与 mysql 几乎同速（40.8k ≈ 40.6k）；
- MySQL 比 pgsql 快的根因在**扩展查询协议多往返 + pdo_pgsql 驱动开销**，非「pgsql 是更慢的数据库」。

**复现**：

```bash
# 1) 准备多 ORM 依赖（一次性）
cd benchmarks/orm-harness && composer install && cd ../..

# 2) 建表 + 灌基准数据（需本地 MySQL / pgsql，见 setup_bench_dbs.php 顶部连接配置）
php benchmarks/setup_bench_dbs.php

# 3) 启动全频谱服务（默认 :8093，worker 数 = swoole_cpu_num()，本机 11）
php benchmarks/peers/kode_swoole_server.php &

# 4) 跑全频谱压测
bash benchmarks/run_spectrum.sh
```

---

## 套件三：热路径微基准（限流 / 数据库 / HTTP 响应链）

**问题**：限流对单请求的增量成本是多少？kode/database 1.15.7 预编译语句缓存的收益有多大？
kode/http 3.4.7 合入 emit 直发 + StringStream 后，响应体处理离「裸 json_encode」还有多远？

**方法**：单进程微基准（预热后 3 轮取最小），见 `limiting-bench.php`（限流三后端：memory / redis /
pdo-sqlite）、`database-bench.php`（PdoConnection 语句缓存 vs 原生每次 prepare vs 框架 ConnectionPool）
与 `http-bench.php`（/bench/json 与 /ping 响应链快速 vs PSR-7 通用路径 vs 原生基线）。

**结果**：

- 限流：见 [`limiting-bench.md`](limiting-bench.md)。memory 后端完整链 ≈ **9.5µs/请求**、
  redis ≈ **174µs**（单次 TCP 往返主导）、pdo-sqlite ≈ **22µs**。
- 数据库：见 [`database-bench.md`](database-bench.md)。1.15.7 语句缓存命中 SELECT **802.9 ns/op**
  vs 原生每次 prepare **1821.5 ns/op（-56%，吞吐 ↑2.27×）**；真实 MySQL/pgsql 因 PREPARE 含网络往返，
  收益更大。
- HTTP：见 [`http-bench.md`](http-bench.md)。3.4.7 全响应链仅比裸 `json_encode` 慢 **+10.4%**
  （5534 vs 5014 ns/op）；/ping 快速路径 **426 ns/op**（比 PSR-7 通用路径快 27%）——响应体不再是
  与 webman/hyperf 的差距主因，剩余杠杆在运行时桥接与功能对价（见 PEER_BENCHMARK）。

**复现**：

```bash
php benchmarks/limiting-bench.php 50000 memory,redis,pdo   # 需 redis-server :6379
php benchmarks/database-bench.php 20000
php benchmarks/http-bench.php 100000
```

---

## 测量口径与数字解读

- **工具**：`wrk -t8 -c200 -d8s`，等 worker=8，主键 SELECT，median-of-3。
- **横比看比值，不看绝对数**：本机（笔记本）跨次运行有 ±20~30% 热漂移；同轮内 kode 与各基线的相对比例可抵消本机负载方差。
- **绝对 rps 不可跨机器/跨时刻直接相减**；结论均以「同条件下相对比例」表述。
- 所有数字均来自上述两套工具的真实 wrk 输出，非 TechEmpower / FPM 公开数字。
