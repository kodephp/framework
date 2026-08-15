# 数据库全频谱压测对比（v0.8.33）

> 生成日期：2026-08-15
> 机器：macOS（Apple Silicon，11 逻辑核），PHP 8.3.33，ext-swoole / ext-pdo_mysql / ext-pdo_pgsql 已加载
> 负载工具：**wrk**（`-t8 -c200 -d8s`，每端点取 3 次中位数）
> 框架版本：**kode/framework v0.8.33**（含已固化的 `patches/kode-database-pdoconnection.patch`）

本文档回答三个问题：

1. 在 kode 常驻服务里，**不同 ORM / 数据库**的真实 DB 查询吞吐差多少？
2. **Doctrine 是否更快**？如何接入？
3. **为什么 MySQL 比 pgsql 快**？

---

## 0. 测量方法（避免「看起来像 FPM」的伪影）

- 服务以 **Swoole HTTP Server 常驻**（8 worker），`kode_swoole_server.php` 包裹 `App::handle`，
  与 webman/hyperf **同形态**——绝非 FPM 每请求重建容器。
- 12 个端点 = {raw PDO, kode 原生查询构造器, Eloquent, Doctrine DBAL, ThinkORM} × {MySQL, pgsql}，
  每个端点执行「**一次主键 SELECT + 返回 JSON**」，公平对比「框架 + 各 ORM + 各 DB」的端到端吞吐。
- 负载用多线程 `wrk`（`ab` 单线程本地回环仅 ~3 万上限，会反向成为瓶颈，已在 peer 对比中废弃）。
- **横比看比值，不看绝对数**：本机跨次运行有 ±20~30% 热漂移；同轮内相对比例抵消本机负载方差。

---

## 1. 全频谱结果（wrk，3 次中位数，req/s）

| 数据层 | MySQL (rps) | pgsql (rps) | mysql ÷ pgsql |
|---|---:|---:|---:|
| 原生 PDO（`new PDO` + 手写 SQL） | **39.8k** | 26.2k | 1.52× |
| **kode 原生查询构造器**（含 PdoConnection 补丁） | 38.1k | **34.5k** | 1.10× |
| Eloquent（illuminate/database ^11） | 22.9k | 21.5k | 1.07× |
| **Doctrine DBAL**（`driver='symfony'`） | **40.6k** | **40.8k** | 1.00× |
| ThinkORM（topthink/think-orm ^3） | 27.9k | 25.0k | 1.12× |

> 复现：`bash benchmarks/run_spectrum.sh`（需先 `php benchmarks/setup_bench_dbs.php` 建表灌数）。

### 关键解读

1. **kode 原生已追平裸 PDO**：经 `patches/kode-database-pdoconnection.patch` 后，
   kode 原生 MySQL **21.6k → 38.1k（+77%）**、pgsql **17.5k → 34.5k（+98%）**，
   与手写 PDO 几乎并列（差距 < 5%）。过去 kode 原生落后裸 PDO，根因是常驻内存下的「每查 `SELECT 1` 探活 +
   无预编译语句缓存」双重拖累，补丁已消除。
2. **Doctrine 是全频谱最快之一**：MySQL 40.6k / pgsql 40.8k，**两侧几乎同速**，且略快于裸 PDO。
   原因见第 3 节——Doctrine 默认缓存预编译语句，抹平了 pgsql 扩展查询协议的多往返开销。
3. **Eloquent 最慢**（~22k）：其查询构造器 + 结果水合（hydration）在每请求分配上更重；
   在 I/O 绑定场景下与 DB 延迟相关性低，但对「极限 SELECT 吞吐」敏感。
4. **ThinkORM 居中**（~26~28k）。

---

## 2. Doctrine 如何接入（kode 已内置桥接，零框架改动）

kode/database 在 `vendor/kode/database/src/Connection/Bridge/` 提供多 ORM 桥接，
其中 **`SymfonyBridge` 本质即 Doctrine DBAL**（`use Doctrine\DBAL\Connection; use Doctrine\DBAL\DriverManager;`）。
`SymfonyConnector` 在 `class_exists(\Doctrine\DBAL\DriverManager::class)` 时返回 `SymfonyBridge`，
否则回退 `PdoConnection`。

因此「`driver => 'symfony'`」= 走 Doctrine，仅两步：

```php
// 1) 安装 Doctrine（独立 harness 或框架 composer.json 均可）
composer require doctrine/dbal

// 2) config/database.php 里把连接 driver 改为 symfony
return [
    'connections' => [
        'mysql' => [
            'driver'   => 'symfony',          // ← 即 Doctrine DBAL 桥接
            'host'     => '127.0.0.1',
            'database' => 'bench',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8mb4',
        ],
    ],
];
```

`src/Providers/DatabaseServiceProvider.php` 遍历 `connections` 逐条 `Db::addConnection()`，
整条配置透传给 kode/database `ConnectionFactory`——**无需改框架代码即可切换底层 ORM**。
本压测的 Doctrine 端点正是这样接入的。

---

## 3. 为什么 MySQL 比 pgsql 快（根因）

测量中 raw PDO 是分水岭：**mysql 39.8k vs pgsql 26.2k（+52%）**。这**不代表「pgsql 是更慢的数据库」**，
而是测的是**单主键 SELECT + localhost + 极限并发**下的「驱动 / 协议开销」：

| 因子 | MySQL (pdo_mysql) | pgsql (pdo_pgsql) |
|---|---|---|
| 线协议 | 文本/二进制**单往返**（query → result） | **扩展查询协议**（Parse → Bind → Describe → Execute → Sync，多往返） |
| 驱动开销 | 轻量 | 偏重（每次 Execute 附带 Describe 往返） |
| 语句缓存 | 无（raw PDO 场景）→ 差距被放大 | 无（raw PDO 场景）→ 多往返直接暴露 |

**关键反证**：Doctrine 因缓存预编译语句（省去重复 Parse/Bind），pgsql 端 **40.8k ≈ mysql 40.6k**——
证明 pgsql 的慢来自「每次重新走扩展查询协议」，而非引擎本身。一旦语句被缓存复用，协议差距消失。

实际生产建议：

- 选 MySQL：本测量口径下原生驱动更轻，极限 SELECT 吞吐更高；
- 选 pgsql：用 **Doctrine / 带语句缓存的层**（或 kode 原生补丁后的预编译缓存）即可抹平差距；
- 二者均为优秀的生产级数据库，选型应看事务/JSON/扩展生态，而非本微基准的绝对 rps。

---

## 4. 已固化的性能修复（v0.8.33）

补丁 `patches/kode-database-pdoconnection.patch`（经 `cweagans/composer-patches` 在 `composer install` 时自动打上）：

- **去 `SELECT 1` 探活**：旧实现每查一次多 1 次 DB 往返，是常驻内存下最大拖累；改为「已连接则直接复用」。
- **预编译语句缓存**（按 SQL 文本，上限 256 条，连接断开清空）：省去常驻场景下每请求一次的 PREPARE 网络往返，对齐 Doctrine/Laravel 行为。
- **连接失效自动重连重试一次**：执行语句遇 `PDOException` 时重连重试，避免常驻进程遇到断开连接后雪崩。

效果（同机前后对比）：kode 原生 MySQL **21.6k → 38.1k（+77%）**、pgsql **17.5k → 34.5k（+98%）**。

> 补丁作用于 `vendor/kode/database`（不入库），由 `composer.json` 的 `extra.patches` 声明自动应用；
> 升级 kode/database 时若上游已合入等价修复，可移除该补丁。

---

## 5. 复现

```bash
# 1) 多 ORM 依赖（一次性，隔离在 orm-harness，不污染框架 composer.json）
cd benchmarks/orm-harness && composer install && cd ../..

# 2) 建表 + 灌基准数据（连接配置见 setup_bench_dbs.php 顶部）
php benchmarks/setup_bench_dbs.php

# 3) 启动全频谱服务（默认 :8093，8 worker）
php benchmarks/peers/kode_swoole_server.php &

# 4) 全频谱压测（wrk 对 12 端点，median-of-3）
bash benchmarks/run_spectrum.sh
```

端点清单（`run_spectrum.sh` 自动遍历）：

```
/raw/mysql      /raw/pgsql
/kode/mysql     /kode/pgsql
/eloquent/mysql /eloquent/pgsql
/doctrine/mysql /doctrine/pgsql
/think/mysql    /think/pgsql
```
