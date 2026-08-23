# 数据库链路压测记录（kode/database 1.15.7 语句缓存 + 框架连接路径）

> 生成日期：2026-08-23（框架 v0.8.45 / kode/database **1.15.7** / PHP 8.3.32 NTS）
> 环境：aarch64 Linux 沙箱；pdo-sqlite（`:memory:`，无 fsync——度量的是 **SQL 执行层相对成本**，
> 真实 MySQL/Postgres 的绝对值更高，但缓存路径相对每次 PREPARE 的收益只增不减，见 §三）。
> 口径：预热 n/10 次后计时，3 轮取最小（热状态最稳）；同机背靠背，误差 ±5%。
> 脚本：`benchmarks/database-bench.php [iterations]`
> 数据：单行点查 `SELECT name, email FROM users WHERE id = ?`（1000 行预热库）。

## 一、实测结果（20k 迭代，1 主键点查）

| 场景 | ops/s | ns/op | 说明 |
| --- | ---: | ---: | --- |
| `pdo-connection/select` | 1,245,510 | **802.9** | kode/database 1.15.7 `PdoConnection::select`——命中 `stmtCache` 预编译语句（`stmtCache` 条目数实测 2：INSERT+SELECT 各 1） |
| `raw-pdo/prepare+fetchAll` | 549,007 | 1,821.5 | 原生 PDO 每次 `prepare($sql)`——无语句缓存对照组 |
| `framework/pool.queryAll` | 495,446 | 2,018.4 | 框架 `ConnectionPool::queryAll`——每资 prepare + `closeCursor` 归还 |

## 二、核心结论

- **1.15.7 预编译语句缓存收益：单查询开销 -55.9%（1821.5 → 802.9 ns/op），吞吐 ↑2.27×**。
  缓存路径已对齐 Doctrine/Laravel 的语句复用语义：同一 SQL 文本首次 PREPARE 后复用
  `PDOStatement` 直接 `execute`，缓存满 `STMT_CACHE_LIMIT`（256）条时不再新增、仍可用，
  `disconnect()` 时清空。
- **`framework/pool.queryAll`（2018 ns/op）与 raw-pdo（1822 ns/op）同量级**：说明框架连接池
  自身编排（borrow/release/closeCursor）≈ 无附加成本，差距全部来自「每次 PREPARE」；
  业务走 `PdoConnection::select`（kode/database 原生连接器）可获得缓存收益。
- **去探活收益已在 1.15.7 源码级确认**：`ensureConnected()` 已连接即复用、不再每次查询
  `SELECT 1`（消灭每查 1 次的额外 DB 往返）；连接失效由 PDOException 在执行语句时暴露、
  捕获后断连重连一次（`select`/`insert`/`update`/`delete` 一致）。框架全量回归
  **425 tests / 26428 assertions 全绿**（含 ConnectionLifecycleTest 的 release/断连/事务路径）。

## 三、对生产（MySQL/Postgres）的推断

- pdo-sqlite 的 PREPARE 是纯本进程开销（无网络往返）；MySQL/Postgres 每次 PREPARE 都含一次
  **网络往返 + 服务端语句解析**，因此语句缓存（PREPARE 只在 256 条 SQL 文本内出现一次）在
  真实数据库上的收益**显著大于**上表 -56% 的本地度量，且常驻内存场景（kode/process Native、
  Swoole 协程）收益最大——每请求复用同一批 SQL 时，DB 往返从「每查 1 次」降为「每 SQL 文本 1 次」。
- 仍是网络/磁盘主导的场景（跨机房 DB、大结果集）不在此表口径内；该场景瓶颈在 I/O 而非 PREPARE。

## 四、版本轨迹

- 1.15.5：每次查询 `SELECT 1` 探活 + 每次 `prepare`（框架补丁承载语句缓存，见
  `patches/kode-database-pdoconnection.patch`，已删除）。
- 1.15.6：`PoolManager` 非 Swoole 自动降级 `ProcessPool` + `ScopedConnection` RAII（池缺陷修复）。
- **1.15.7**：`PdoConnection` 预编译语句缓存 + 去探活 + 断连重试（本表实测对象）；框架进 VCS
  repository（`composer.json repositories` 指向 `github.com/kodephp/database` git 直连）跟随上游，
  移除本地补丁。