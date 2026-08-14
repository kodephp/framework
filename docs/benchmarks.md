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

## 三、同类框架基准（TechEmpower Round 22 · plaintext）

下面给出**同条件（相同硬件/口径难以完全对齐）下公开可查的 plaintext 基准**，用于把 kode 的实测数字放进行业坐标，
避免「自家压测自说自话」。plaintext 是各框架最简单的 `Hello, World!` 式响应，代表**纯框架内核调度下限**。

| 框架 | 运行时模型 | R22 plaintext 吞吐 (~req/s) | 备注 |
|---|---|---:|---|
| **kode/framework** | CLI 单进程 boot 一次 + 循环 handle（本报告口径） | **~25k**（全栈 /ping，p99 ~0.045ms）/ ~25k（内核）/ 基线随机器状态浮动 | 真实每请求成本，含生产默认中间件（实时数字见 `../benchmarks/report.md`） |
| Laravel | FPM（默认）/ Octane(Swoole) | **26.7k** | 默认 FPM 仅凭 plaintext；Octane 可数倍提升 |
| Slim 4 | FPM | **89.6k** | 极简微框架，近乎零中间件开销 |
| Symfony | FPM / Runtime | **65k** | 需 Runtime 优化才接近该值 |
| CodeIgniter 4 | FPM | **20.6k** | 传统全栈 PHP |
| Yii | FPM | **146k** | |
| Flight | FPM | **190k** | 极简路由派发 |
| webman | Swoole 常驻 | **96k – 400k+** | 常驻内存 + 原生协程，量级与 Swoole 本身相当 |
| Hyperf | Swoole 常驻 | **100k – 400k+** | 注解驱动 + 常驻，协程密集场景更高 |
| Laravel + Adapterman | Swoole 常驻适配 | 14.8k → **103k**（约 7×） | 仅把 FPM 换成常驻事件循环即得 ~7× |

> 口径说明：
> - 上表 R22 数字为 TechEmpower 公开轮次 plaintext 场景，**运行环境与本机（macOS · PHP 8.3 · CLI 单进程）不同**，
>   仅作数量级参考，不能直接与本报告逐位相减。
> - **关键区分**：FPM/CLI 模型下「每张请求重建容器」，而 webman/Hyperf/Adapterman/Laravel-Octane 均为
>   **常驻内存 + 事件循环**，boot 成本摊薄到海量请求上——这正是它们与 FPM 类框架量级拉开的核心原因。
> - kode 的设计目标运行时同样是 **Swoole/Swow/Fiber 常驻**（见第四节），CLI 单进程压测测得的是「每请求处理成本」，
>   常驻部署下通过多 worker 横向扩展，且每协程独立上下文、boot 仅一次。因此在**功能全开**前提下，
>   kode 全栈吞吐已与 Laravel（默认 ~26.7k）**同量级**，远高于其早期基线 140 req/s（见第四节根因）。

---

## 四、响应速度差距与根因的客观解读

实测（见 `report.md`）中，kode 全栈 /ping 的**诚实**吞吐约 **25k req/s**（p99 ~0.045ms）。早期曾报 ~17.8k / ~17.3k，
均为**测量伪影**而非框架变慢（见下方第 1 点）。吞吐低于 Slim 主要是**架构定位差异**，
但早期 140 的「灾难级」数字并非内核慢，而是两个**每请求副作用**未移出请求路径。正确诊断如下：

1. **早期 140 req/s 的真实根因：同步阻塞的每请求副作用（已修复）**
   - (a) 默认 OTLP 导出器在请求结束时**同步阻塞 `curl` POST** 到 Collector（`flush_on_request_end=true`），
     即便端点不存在也走完整个网络调用，单请求追加约 800µs。
   - (b) 会话中间件对**每条**请求无条件 `start()` + `save()` 全量文件 I/O，即便响应从不使用会话。
   - 修复范式（OTel `BatchSpanProcessor` 同思路）：(a) 导出改为**异步离请求路径**——请求路径仅把 span 入内存队列（µs 级），
     真实发送由 `register_shutdown_function`（FPM/CLI/worker 退出）或应用显式周期 `drain()` 执行；(b) 会话改为**惰性**
     （`LazySessionMiddleware`：仅在使用时启动、仅脏数据 `save()`、按需下发 `Set-Cookie`）。
   - 效果：全栈 /ping 由 ~140 提升至**生产真实的 ~25k req/s（约 178×）**，p99 由 30–700ms 长尾收敛到 ~0.045ms 量级。
   - 口径修正（v0.8.24）：v0.8.23 压测报出的 ~17.3k 仍被第二个伪影低估——追踪 `Tracer::$outbox` 是进程级静态队列，
     在「单进程 CLI 循环、每请求不 drain」的压测里无限累积，`enqueueFlush()` 的 `array_merge` 逐迭代变慢；
     v0.8.24 压测改为每请求 `resetOutbox()`（贴合生产「响应后离路径 drain」行为），测得诚实的 ~25k。
     **框架运行时代码并未因此变快，是测量口径被修正。**
   - 隔离验证：用 `Context::run()` 包裹每次请求（模拟 Swoole 每协程独立上下文，消除 CLI 单进程全局状态累积伪影）后，
     内核级与全栈均测得 ~25k——说明「可选中间件」开销已被压到很低，二者接近主要由 GC 绑定噪声主导。

2. **与 Slim 的差距 = 定位差异，非同台竞技**
   Slim 仅路由 + 中间件，单请求近乎零开销；kode 用单请求开销换取上述全部开箱能力。绝对 req/s 不直接可比，
   应结合功能矩阵综合评估「为每单位吞吐付出的能力密度」。

3. **生产部署面向常驻运行时**
   kode 的设计目标运行时是 Swoole/Swow/Fiber 长生命周期进程：boot 一次、多请求复用容器与路由表，boot 成本被摊薄，
   并通过多 worker 横向扩展。本压测测量的是「每请求处理」成本，不代表部署上限。

4. **何时选更轻底座**
   对纯吞吐极度敏感的边缘/网关服务，可评估 Slim、Workerman 等更轻量底座；对需要韧性、可观测、多租户、配置中心的中后台，
   kode 的开箱密度显著更高。

5. **kode 是「分配 / GC 绑定」的——跨机绝对数字不可直接横比**
   本机裸 PHP 基线与 Slim 在两次运行间可差 2.4×，而 kode 全栈稳定停在 ~25k：说明其吞吐受**每请求对象分配与 GC**
   主导，而非原始 CPU 频率。推论：
   - 单进程微基准里，微小的单点分配削减（如去掉一次 Uri 克隆）被 GC 噪声淹没，不构成可测增益；
   - 继续提响应的真实杠杆是**系统性减少每请求分配**，最大的待办是把**访问日志也做成「离路径异步导出」**（与追踪同范式），
     把同步格式化/写入移出热路径；但诚实预估其量级需重新实测，不宜沿用早期被伪影放大的 ~8µs 估值。
   - **p99 / max 在 CLI 单进程紧循环里高度噪声化**（曾出现某次跑分内核 json+DI p99 3.7ms、max 137ms 的离群，
     重跑即回归 0.07ms 量级）——这是 GC / autoload 抖动，不是框架回归；**以 p50 / 中位数判断趋势更可靠**。

---

## 五、复现

```bash
# 框架根目录
php -d opcache.enable_cli=1 benchmarks/run.php            # 生成 kode + 裸 PHP 基线对比

# 可选：加入 Slim 4 对等框架（隔离安装，不污染框架 vendor）
cd benchmarks/peers/slim && composer install
php -d opcache.enable_cli=1 benchmarks/run.php            # 再次运行即含 Slim 真实对比

# 可调采样量
BENCH_ITERS=2000 BENCH_WARMUP=800 php benchmarks/run.php
```
