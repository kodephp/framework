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

- `kode · lean`（仅路由+异常+连接收口）达裸 Swoole 天花板 **96%/74%**（174k/137k），约为 webman 的 **92%/76%**，/ping 超 hyperf **14%**；但 **`/bench/json` 仅 74%/76%**，明显低于 `/ping`——根因是 `HttpBridge::toRaw` 纯 PHP 序列化 + kode/process 统一运行时 I/O（详见 PEER_BENCHMARK §3/§6），这是 kode·lean 尚未超过 webman（/bench/json 137k < 179k）的真实主因；
- `kode · default`（完整企业栈）**86k/59k**，约为 lean 的 **50%/43%**（公平冷却口径下低于自带 DI 的 hyperf，属完整企业栈定位差异，非缺陷）；
- 默认栈 ~30% 折损是**功能对价**（cors/安全头/限流/韧性/追踪/审计），其中链路追踪全采样是单项最大税（~45%，已通过采样默认 0.1 修复）。

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
  MySQL **21.6k → 38.1k（+77%）**、pgsql **17.5k → 34.5k（+98%）**，已追平原生 PDO；
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

## 测量口径与数字解读

- **工具**：`wrk -t8 -c200 -d8s`，等 worker=8，主键 SELECT，median-of-3。
- **横比看比值，不看绝对数**：本机（笔记本）跨次运行有 ±20~30% 热漂移；同轮内 kode 与各基线的相对比例可抵消本机负载方差。
- **绝对 rps 不可跨机器/跨时刻直接相减**；结论均以「同条件下相对比例」表述。
- 所有数字均来自上述两套工具的真实 wrk 输出，非 TechEmpower / FPM 公开数字。
