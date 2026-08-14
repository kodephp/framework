# 压测对比与同类框架功能矩阵

本文档说明 kode/framework 的压测方法、与同类框架的**功能矩阵**对比，以及对响应速度差距的**客观解读**。
实时吞吐/延迟数字见 [`../benchmarks/report.md`](../benchmarks/report.md)（由 `php benchmarks/run.php` 生成）。

---

## 一、压测口径

| 项 | 说明 |
|---|---|
| 测量对象 | 常驻内存运行时（Swoole/Swow/Fiber 长生命周期）下的**每请求成本**：boot 一次，循环调用 `HttpApp::handle(ServerRequest)`，排除 HTTP 服务器与进程启动噪声 |
| 场景 | `kode · 全栈` / `kode · 内核（最小中间件）` / `baseline · 裸 PHP` / `Slim 4 · (ping/json)` |
| 路由 | `/ping`（最小响应）、`/bench/json`（DI 解析 + 50 条记录 JSON 序列化，模拟真实控制器） |
| 指标 | 吞吐量 req/s、p50/p95/p99 延迟（毫秒，hrtime 纳秒时钟 + 线性插值百分位） |
| 环境 | 单进程 PHP-CLI，OPcache 开启；建议关闭 JIT tracing（本负载下反而拖慢 kode） |

> 说明：限流在压测中强制关闭（否则高并发触发 429）；其余生产默认中间件保留，以测得真实全栈成本。

---

## 二、功能矩阵（kode vs 同类框架）

能力维度 | **kode/framework** | Laravel | Symfony | Slim 4 | CodeIgniter 4
---|---|---|---|---|---
统一运行时（Swoole/Swow/Fiber/并行） | ✅ 一等公民（kode/runtime） | ⚠️ 需 Octane/RoadRunner | ⚠️ 需 Runtime/Swoole 包 | ❌ | ❌
边缘韧性：熔断 / 重试 / 超时 / 幂等 | ✅ **内置**（breaker·retry·timeout·idempotency） | ⚠️ 需生态（如 illuminate/circuit-breaker） | ⚠️ 需生态 | ❌ | ❌
分布式锁 / 多租户存储隔离 | ✅ 内置 | ⚠️ 需包 | ⚠️ 需包 | ❌ | ❌
OTLP 追踪 / `/metrics` 探针 | ✅ 内置 | ⚠️ 需包 | ⚠️ 需包 | ❌ | ❌
配置中心 / 服务发现 | ✅ 内置 | ⚠️ 需包 | ⚠️ 需包 | ❌ | ❌
属性路由 + 全局限流（含分布式） | ✅ 内置 | ✅ | ✅ | ⚠️ 中间件 | ✅
AOP / 事件总线 / 消息队列 | ✅ 内置（kode/aop·event·messaging·queue） | ⚠️ 需包 | ⚠️ 需包 | ❌ | ⚠️ 有限
数据库迁移 / 种子 / 多连接 | ✅ 内置（kode/database） | ✅ | ✅ | ❌ | ✅
零依赖薄壳 Provider 范式 | ✅ 设计约定（契约 + 内置零依赖后端 + 事件 + 助手 + Provider） | ❌ | ❌ | ❌ | ❌
学习曲线 / 极简度 | 中（能力多） | 中 | 高 | ✅ 极低 | 低

图例：✅ 开箱即用 · ⚠️ 需额外包/配置 · ❌ 不支持/非设计目标

**结论**：kode 的定位是「电池全包的现代化企业级全栈框架」，把边缘韧性、可观测性、分布式原语、配置/服务发现等
生产必需能力做成**内核级内置**；Laravel/Symfony 通过庞大生态补齐但需自行接线；Slim/CI 走极简路线，开箱能力很少。

---

## 三、响应速度差距的客观解读

实测（见 `report.md`）中，kode 在单进程合成压测下对 trivial 路由的吞吐显著低于 Slim。这不是「框架缺陷」，而是**架构定位的代价**：

1. **单请求开销 = 全包能力的成本**
   kode 在每条请求上运行 DI 驱动的全局中间件解析、事件派发、熔断/重试注册表、属性路由与连接生命周期收口。
   即便剥离全部可选中间件（内核场景），单请求开销几乎不变 → 瓶颈在**框架内核的路由分发 + 容器解析 + PSR-7 对象创建**，
   而非某个具体中间件。

2. **与 Slim 的差距 = 定位差异，非同台竞技**
   Slim 仅路由 + 中间件，单请求近乎零开销；kode 用单请求开销换取上述全部开箱能力。绝对 req/s 不直接可比，
   应结合功能矩阵综合评估「为每单位吞吐付出的能力密度」。

3. **生产部署面向常驻运行时**
   kode 的设计目标运行时是 Swoole/Swow/Fiber 长生命周期进程：boot 一次、多请求复用容器与路由表，boot 成本被摊薄，
   并通过多 worker 横向扩展。本压测测量的是「每请求处理」成本，不代表部署上限。

4. **何时选更轻底座**
   对纯吞吐极度敏感的边缘/网关服务，可评估 Slim、Workerman 等更轻量底座；对需要韧性、可观测、多租户、配置中心的中后台，
   kode 的开箱密度显著更高。

---

## 四、复现

```bash
# 框架根目录
php -d opcache.enable_cli=1 benchmarks/run.php            # 生成 kode + 裸 PHP 基线对比

# 可选：加入 Slim 4 对等框架（隔离安装，不污染框架 vendor）
cd benchmarks/peers/slim && composer install
php -d opcache.enable_cli=1 benchmarks/run.php            # 再次运行即含 Slim 真实对比

# 可调采样量
BENCH_ITERS=2000 BENCH_WARMUP=800 php benchmarks/run.php
```
