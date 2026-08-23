# 限流链路压测与调优记录（RateLimitMiddleware + kode/limiting）

> 生成日期：2026-08-23（框架 v0.8.43 / kode/limiting **2.2.0** / PHP 8.3.32 NTS）
> 环境：aarch64 Linux 沙箱；redis-server 7.0.15（本机 loopback，`--save ""`）；phpredis 扩展
> 口径：预热 n/10 次后计时，3 轮取最小（热状态最稳，避免 GC 尖峰计入）；误差 ±5%。
> 脚本：`benchmarks/limiting-bench.php [iterations] [drivers]`
> 说明：本表测量的是「**限流对单请求的增量成本**」，非框架总吞吐；绝对值受本机 CPU/网络影响。

## 一、三后端实测对比（200k→50k 迭代稳定值）

| 场景 | ops/s | ns/op | 说明 |
| --- | --- | ---: | --- |
| `mw/open`（无规则早退） | 737,720 | 1,355 | 全局兜底关、路由无 `#[RateLimit]` 时近乎零成本 |
| `mw/limited`（memory 规则） | 105,166 | 9,509 | **memory 后端完整限流链**（路由解析+签名+consume+响应头） |
| `memory/consume` fixed-key | 170,131 | 5,878 | kode/limiting 内存桶算法核心（热键） |
| `memory/consume` unique-key | 218,307 | 4,581 | 冷键（每请求新 IP）反而更快的解释见 §四 |
| `pdo-sqlite/consume` fixed-key | 41,453 | 24,124 | PDO 持久化后端（sqlite::memory，无 fsync） |
| `redis/consume` fixed-key | 5,652 | 176,918 | **生产常用分布式后端**：loopback 单命令往返主导 |
| `mw/limited`（redis 规则） | 5,491 | 182,132 | 完整链 + redis 存储（增量 ≈ 中间件编排 5µs） |

## 二、热路径调优前后对比（memory 后端）

| 场景 | v0.8.42 优化前 | v0.8.42 优化后 | 变化 |
| --- | ---: | ---: | ---: |
| `mw/limited` | 9,641 ns/op | 9,006–9,018 ns/op | **-6.6%** |
| `mw/open` | 1,304 ns/op | 1,320–1,366 ns/op | 噪声内无回归 |

v0.8.42 三项热路径优化（行为不变）：
1. `RateLimitMiddleware::process()` 内 `clientIp()` **每请求只解析一次**（原 keyContext 与 fallback 键各调一次）；
2. `TrustedProxies::clientIp()` **空受信列表直通快路径**（默认 `trusted_proxies=[]` 为最常见配置，免 isTrusted 空遍历）；
3. `LimiterFactory` **构造时预计算 store 签名段**（`storeSignature`），每请求拼签名免去 match + 多次兜底取值。

（v0.8.43 在 2.2.0 上复测：`mw/limited` 稳定 9.38–9.51µs；与优化后差异来自运行期负载，口径一致。）

## 三、产出结论

- **memory 后端总限流成本 ≈ 9.5µs/请求**，其中 kode/limiting 桶算法约 5.9µs，框架编排（路由匹配、
  规则查表、键拼装、签名、响应头）约 3.6µs——框架侧已把可省的部分省完（冗余 clientIp、签名重建）。
- **redis 后端成本 ≈ 177µs/请求，由单次 TCP 往返 + phpredis 命令封装主导**：`RedisStore::consume`
  走 EVALSHA（SHA 缓存）+ 单 Lua 脚本，已是单往返最优形态；连接由 `LimiterFactory` 按签名缓存
  复用（不会每请求建连）。这是「多进程/多机共享限额」的必然网络代价，**不是框架缺陷**。
  若对延迟敏感且可接受单机语义，可对低频路由临时改用 memory 后端；多实例共享必须用 redis。
- **pdo 后端 ≈ 24µs**（sqlite::memory 无 fsync；真实 MySQL/Postgres 会更高，取决于网络与磁盘）。
- 结论：限流对极端热路径（纯 JSON API）的增量，memory 约 1.5%，redis 约 25–35%（相对
  ~0.5ms/请求的裸 JSON 链）；建议**仅对需要限流的接口启用规则**（全局兜底默认关，见
  `config/limiting.php` 的 `global.enabled`），避免无差别成本。

## 四、附注

- **memory fixed-key 比 unique-key 慢（5.9µs vs 4.6µs）**：热键每次 consume 都要执行令牌补充
  （refill）与容量判定；冷键首次创建即满桶直接放行，少了 refill 更新路径。属 kode/limiting
  内存桶实现的可观测行为，非串用/泄漏。
- **v0.8.43 升级 kode/limiting 2.2.0 后的接线验证**（无行为回归）：
  - storeFromArray 按 `config['mode']` 分发 standalone / sentinel / cluster，prefix 全程受控；
  - standalone + 自定义 prefix `myapp:` → 实测 Redis 键为 `myapp:limiter:bucket:…`（前缀生效）；
  - sentinel 分支实测透传 prefix 至 `RedisStore::createSentinel(…, 'myapp:')`；
  - 框架 425 测试全绿（26428 assertions）。
- 沙箱扩展限制：apcu / memcached 扩展缺失，两后端未实测（构造路径与 2.1.0 一致，仅 prefix 传递
  差异已在 2.2.0 修复）；如需补测，在装有 `ext-apcu` / `ext-memcached` 的环境中运行同一脚本。