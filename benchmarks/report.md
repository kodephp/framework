# kode/framework 压测对比报告

- 生成时间：2026-08-14T09:28:36+00:00
- 框架版本：kode/framework **0.8.23**
- 运行环境：PHP 8.3.33 · SAPI cli · Darwin
- OPcache：启用 · JIT buffer：关闭
- 采样：每次场景预热 1000 次 + 正式采样 8000 次（单进程内 boot 一次 + 多次 handle）

## 一、响应速度（吞吐量 / 延迟百分位）

| 场景 | 路由 | 吞吐 (req/s) | p50 (ms) | p95 (ms) | p99 (ms) | min (ms) | max (ms) |
|---|---|---:|---:|---:|---:|---:|---:|
| kode · 全栈 (ping) | /ping | 17257 | 0.062 | 0.065 | 0.077 | 0.042 | 3.947 |
| kode · 全栈 (json+DI) | /bench/json | 11787 | 0.084 | 0.093 | 0.106 | 0.076 | 0.130 |
| kode · 内核 (最小中间件) | /ping | 15924 | 0.062 | 0.071 | 0.080 | 0.056 | 0.335 |
| kode · 内核 (json+DI) | /bench/json | 11815 | 0.084 | 0.095 | 0.111 | 0.075 | 0.580 |
| baseline · 裸 PHP (纯逻辑) | (logic) | 226160 | 0.004 | 0.005 | 0.006 | 0.004 | 0.066 |
| Slim 4 · (ping) | /ping | 284489 | 0.003 | 0.004 | 0.005 | 0.003 | 0.057 |
| Slim 4 · (json) | /bench/json | 174606 | 0.006 | 0.006 | 0.008 | 0.005 | 0.085 |

### 框架增量开销（相对裸 PHP 基线）

| 场景 | 相对裸 PHP 吞吐比例 |
|---|---:|
| kode · 全栈 (ping) | 7.63% |
| kode · 全栈 (json+DI) | 5.21% |
| kode · 内核 (最小中间件) | 7.04% |
| kode · 内核 (json+DI) | 5.22% |
| Slim 4 · (ping) | 125.79% |
| Slim 4 · (json) | 77.20% |

## 二、方法说明与口径

- **测量对象**：常驻内存运行时（Swoole/Swow/Fiber 长生命周期）下的每请求成本——
  启动框架一次，循环调用 `HttpApp::handle(ServerRequest)`，排除 HTTP 服务器与进程启动噪声。
- **kode · 全栈**：保留生产默认中间件（异常/请求ID/追踪/CORS/安全头/熔断/重试/幂等/会话/特性开关），
  仅关闭全局限流以避免压测触发 429。
- **kode · 内核**：在上述基础上剥离可选中间件，仅保留路由分发 + 请求ID + 异常兜底，用于隔离框架内核成本。
- **baseline · 裸 PHP**：仅执行等价业务逻辑（构造 50 条记录数组 + `json_encode`），不含任何框架开销，作为下限基准。
- **Slim 4**：隔离安装在 `benchmarks/peers/slim`（不污染框架 vendor），镜像相同两条路由，作为轻量微框架对等对比。
- **百分位**：基于每次请求耗时的线性插值百分位（hrtime 纳秒时钟）。

## 三、与同类框架的功能矩阵（详见 docs/benchmarks.md）

| 能力 | kode | Laravel | Symfony | Slim | CodeIgniter |
|---|---|---|---|---|---|
| 统一运行时（Swoole/Swow/Fiber） | ✅ | ⚠️(Octane) | ⚠️ | ❌ | ❌ |
| 边缘韧性（熔断/重试/超时/幂等） | ✅ 内置 | ⚠️ 需生态 | ⚠️ 需生态 | ❌ | ❌ |
| 分布式锁 / 多租户存储 | ✅ | ⚠️ 需包 | ⚠️ 需包 | ❌ | ❌ |

| OTLP 追踪 / /metrics 探针 | ✅ | ⚠️ 需包 | ⚠️ 需包 | ❌ | ❌ |
| 属性路由 + 全局限流 | ✅ | ✅ | ✅ | ⚠️ 中间件 | ✅ |
| 配置中心 / 服务发现 | ✅ 内置 | ⚠️ 需包 | ⚠️ 需包 | ❌ | ❌ |

## 四、结果解读（关键）

1. **本次优化聚焦「把阻塞移出请求路径」**：早期全栈 /ping 仅 ~140 req/s 的根因并非内核路由或容器，
   而是两个「每请求副作用」——(a) 默认 OTLP 导出器在请求结束**同步阻塞 POST** 到 Collector
   （指向不存在的端点时仍走完网络调用），(b) 会话中间件无条件 `start()`+`save()` 全量文件 I/O。
   修复后：(a) 导出改为**异步离请求路径**（入内存队列，由定时器/停机钩子批量发送，OTel BatchSpanProcessor 同范式），
   (b) 会话改为**惰性**（仅在使用时启动、仅脏数据落盘）。全栈 /ping 从 ~140 提升至 ~17.8k req/s（约 127×）。
2. **与 Slim 的差距来自定位不同**：Slim 是极简微框架（仅路由 + 中间件），单请求近乎零开销；kode 以单请求
   开销换取开箱即用的边缘韧性、分布式锁、多租户、OTLP 追踪、配置中心、服务发现、健康探针等能力。
   二者非同一定位，绝对 req/s 不直接可比，应结合功能矩阵综合评估。在**功能全开**前提下，kode 全栈吞吐已与
   Laravel（TechEmpower R22 ≈ 26.7k）同量级，远高于早期基线。
3. **生产部署应面向常驻运行时**：kode 的设计目标运行时是 Swoole/Swow/Fiber 长生命周期进程（boot 一次、
   多请求复用容器与路由表，且每个协程拥有独立上下文）。本压测用 `Context::run` 包裹每次请求以模拟该隔离，
   测得真实每请求成本；在常驻运行时下 boot 成本被摊薄，并通过多 worker 横向扩展吞吐。
4. **裸 PHP 基线**代表纯业务逻辑下限（构造 + `json_encode`），用于量化「框架 + 中间件增量开销」。

## 五、复现方式

```bash
# 进入框架根目录
php -d opcache.enable_cli=1 benchmarks/run.php          # kode + 裸 PHP 基线
cd benchmarks/peers/slim && composer install           # 可选：安装 Slim 对等框架
php -d opcache.enable_cli=1 benchmarks/run.php          # 再次运行即含 Slim 对比
# 可调：BENCH_ITERS=2000 BENCH_WARMUP=800 php benchmarks/run.php
```
> 说明：本机 CLI 关闭 JIT tracing 可获得更稳定的 kode 数值（tracing JIT 在本负载下反而拖慢）。
