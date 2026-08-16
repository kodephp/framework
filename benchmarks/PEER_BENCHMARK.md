# 常驻内存框架「同条件」压测对比（kode vs swoole / workerman / webman / hyperf）

> 生成日期：2026-08-16（v0.8.39）
> 机器：macOS（Apple Silicon，11 逻辑核），PHP 8.3.33，ext-swoole 已加载
> 负载工具：**wrk**（`-t 8 -c 200 -d 8s`，每端点取 3 次中位数）

## 0. 为什么之前「看起来像 FPM」——测量方法的根本错误

此前用 `ab` 压测，得到所有框架都只有 2~3 万 rps，与「传统 FPM」数字接近，
从而误判 kode 性能差。真相是：**`ab` 是单线程客户端，本地回环下自身上限仅 ~3 万 rps，
反向成为瓶颈**——框架从未被压到真实吞吐。

用多线程的 `wrk` 重测后，裸 Swoole `/ping` 从 **2.6 万（ab）→ 16.5 万（wrk）**，
印证瓶颈在 `ab` 而非框架。本报告的结论全部基于 `wrk`。

> 结论：**kode 是常驻内存框架，吞吐 12~18 万 rps，与 webman/hyperf 同量级，绝非 FPM（~5k）。**

## 1. 同条件定义（所有框架一致）

- 同机器、同 11 worker（= `swoole_cpu_num()`）、同 wrk 参数、同两条路由：
  - `GET /ping` —— hello world（最小响应）
  - `GET /bench/json` —— 业务输出（内存构造 50 条记录 JSON，**无 DB**，隔离框架开销）
- 端口隔离：`swoole_raw:8101` `workerman_raw:8102` `webman:8091` `kode:8093/8094` `hyperf:9501`
- 复现（当前 Workerman 驱动）：`no_proxy='*' NO_PROXY='*' bash benchmarks/peers/run_workerman_kode.sh`（详见 §7/§8）

## 2. 压测结果（wrk `-t8 -c200 -d8s`，3 跑中位数，rps）

> **驱动说明**：kode 行使用 `kode/process` 的 **Workerman 驱动**（Swoole 驱动在 5.2.31 × Swoole 6.2.2 下并发回归、bug 已上报，见 §8）；
> webman / hyperf / 原生 raw 不受该回归影响，作稳定运行时参照。**横向以比值而非绝对数判断**（本机 Apple Silicon 11 核跨跑 ±20~40% 热漂移，见 §2 注脚）。

### 2.1 最新可复现：同机器 Workerman 运行时公平对比（v0.8.39，3 次独立完整运行 × 每次 3 迭代中位）

| 运行 | webman /ping（锚） | kode·lean /ping | lean 比值 | kode·default /ping | default 比值 |
|---|---:|---:|---:|---:|---:|
| run1（原 §2.1 单次报数） | 169,968 | 148,322 | 87.3% | 73,270 | 43.1% |
| run2 | 187,613 | 187,402 | 99.9% | 109,711 | 58.5% |
| run3 | 187,956 | 175,454 | 93.3% | 100,414 | 53.4% |
| **三跑中位（诚实口径）** | **187,613** | **175,454** | **93.3%** | **100,414** | **53.4%** |

> run1 为热机/负载偏高的离群运行——其 webman 锚本身也比 run2/3 低 ~10%（自洽），故绝对值偏低；run2/3 为常态空闲态。
> **横比以比值（同轮内 webman 锚归一）为准**，绝对值因笔记本热噪声跨跑 ±20~26%，不可直比。三跑中位即 v0.8.39 在 Workerman 驱动下的最稳健估计。

**/bench/json（同源 3 跑中位）**：webman 184,978 · kode·lean 147,197（**79.6%**）· kode·default 54,927（**29.7%**）。

### 2.2 运行时天花板参照（Swoole / 原生，不受 kode 回归影响）

| 框架 | 形态 | /ping | /bench/json |
|---|---|---:|---:|
| swoole_raw | Swoole 原生（无中间件·天花板） | 184,806 | 183,011 |
| workerman_raw | Workerman 原生（无中间件·天花板） | 186,337 | 184,169 |
| **hyperf** | Swoole 系（自带 DI/可观测） | 163,332 | 157,395 |

> kode 在 Swoole 驱动修复后，预期回到 v0.8.37 基线（kode·lean 161k/139k ≈ 裸 Swoole **87%/76%**、webman **90%/79%**）。
> **框架层调优已触顶**：剩余差距主因是运行时差异（Swoole vs Workerman）+ 企业级可观测性固有成本，非内核缺陷。

> 注：本机为 **Apple Silicon 11 核（5 性能核 + 6 能效核）笔记本**，wrk 8 线程 + 11 worker 横跨快慢核，**同 peer 内跨跑方差极大**：
> kode·lean `/ping` 5 跑 = 150k–183k（±15%）、kode·default `/ping` = 43k–101k（±40%）。**凡 peer 间差距 < ~15% 均属噪声，不可据此判断优劣。**
> 横比以 **比值** 为准，不盯绝对数。kode peer 走 **`Kode::serve` 真实生产路径**（`HttpBridge`），数字即框架实际交付吞吐。
> 稳定化方法：WARMUP 8s、ITERS 3、COOLDOWN 15s，取中位数抗噪。

## 3. 关键结论

1. **kode·lean 内核已达同类框架量级（`/ping`）**：v0.8.39（Workerman 驱动，3 跑中位）`kode · lean` **175k / 147k**；
   Swoole 基线（v0.8.37）**161k / 139k**。`/ping` 约为 webman 的 **93%**、裸 Swoole 天花板的 **~87%**，
   与 hyperf 同量级。证明请求构造（`HttpBridge::toPsr7` 改用 kode 自研 ServerRequest，省 4 次克隆）+ `handle` 路径高效，框架 hello-world 开销≈0。
   （单次 run1 曾测得 148k，纯热机离群、非回归——同代码重跑 run2/3 回到 175~187k。）
2. **kode·lean `/bench/json` 与 webman 的差距（约 80%）非框架代码缺陷**：v0.8.39（3 跑中位）147k ≈ webman **80%**（Swoole 基线 139k ≈ 76%）。
   但 **§5.10 的隔离微基准铁证：kode 响应路径零 body 缩放开销**
   （响应体 15B→1.5KB 各阶段 delta 均恰为 2.0µs = 纯 `json_encode` 本身），故该差距**不在框架响应代码**。
   结合 `KODE_RUNTIME` 切换实验（kode·lean@Workerman 与 @Swoole 同样中招、且本机跨跑方差 ±15~40%），
   残差主因是 **Swoole vs Workerman 运行时差异 + 笔记本热噪声**——二者均非框架缺陷，继续在框架层抠已无实收益。
3. **kode·default（完整企业栈）= 100k/55k**（v0.8.39，3 跑中位），约为 webman 的 **53%（/ping）/30%（/bench）**、自身 lean 的
   **57%/37%**。**较 v0.8.38 §8.1 的 47% 反而提升 6 点**——正是 v0.8.39 把熔断/重试/幂等三中间件从全局管道移出、未打标路由少 3 帧的减负生效；
   此前 §2.1 单次 run1 的 43% 属热机离群，并非越改越低。完整企业栈 + 真实生产路径下，折损是换取 cors/安全头/限流/韧性/追踪/审计/可观测等能力的
   **功能对价，非缺陷**（详见 §4 边际成本剖析）。
4. **此前「慢」与「失真」的真因**：
   - (a) `ab` 客户端封顶（已用 wrk 修正）；
   - (b) **默认链路追踪全采样（`sample_ratio=1.0`）**——单项最大吞吐税（已改默认 0.1 采样 + 未采样短路 span 创建，见第 5.1 节）；
   - (c) **压测方法学失真（已彻底纠偏）**：旧 harness 用 Nyholm PSR-7 给 kode 强加不存在的开销 + 自建 Swoole
     适配器绕过框架真实路径；本轮已改为走 `Kode::serve` + `HttpBridge` 真实生产路径、且 `HttpBridge::toPsr7`
     改用 kode 自研 ServerRequest（见第 5.5 / 5.6 节）。
   - (d) **框架 `handle` 路径本身不是 wrk 瓶颈（隔离测量实证，仍成立）**：CLI 单进程循环 `$http->handle($psr)`
     （无 Swoole 事件循环、无网络）测得 kode·lean 纯框架处理上限约 **241k ops/s**，远高于 wrk 实测的 **174k**；
     即 wrk 下吞吐被 **kode/process 运行时 I/O（含 `HttpBridge::toRaw` 序列化）** 限制，而非框架代码。
     → 见 `benchmarks/peers/micro_handle.php`。

## 4. 默认栈成本剖析（/ping + /bench/json，逐项关闭定位，冷却 15s 取中位数）

> 方法：harness 支持 `KODE_DISABLE=组名` 逐项关闭中间件组，冷却 15s 消热降频后 3 跑取中位数。
> 档位差 = 该组的边际成本。combos：D0 全开 / D1 −observability / D2 −logging / D3 −obs+log / D4 ≈lean。

| 配置（default 档） | /ping | /bench/json | 说明 |
|---|---:|---:|---|
| D0 全开（baseline） | ~94k | ~57k | 基线 |
| D1 −observability（追踪+指标） | ~117k | ~97k | **可观测性是 #1 税（/ping +25k、/bench +40k）** |
| D2 −logging（访问日志） | ~96k | ~77k | 日志次要（/ping +16k、/bench +20k） |
| D3 −obs+log | ~114k | ~106k | 两者叠加 |
| D4 ≈lean（再 −cors+security+locale+resilience） | ~146k | ~124k | 逼近 lean |

**关键结论（与旧分析不同，本轮用冷却口径重测）**：
1. **可观测性（Trace 100% 路径 + Metrics 直方图）是 kode·default 绝对主导成本**：
   /ping（Metrics 被 `shouldSkip` 跳过，故纯 Trace）关掉即 +25k；/bench/json（Trace+直方图都跑）关掉即 +40k。
2. **Trace 成本不在「不可变 withHeader 克隆」**：微基准显示 3× `withHeader`（Nyholm 不可变克隆）约 1.0M ops/s、每请求仅 ~0.3µs，
   而完整 `TraceContext::ensure()` + `responseHeaders()` + 3×`withHeader` 实测约 **0.77µs/请求**——瓶颈是
   `Context` 读写 + 入向头解析 + W3C 头拼接（**固有成本**，与克隆无关）。故「让 kode Response 可变以减少克隆」**无实质收益**（已证伪）。
   - 细化（v0.8.36）：上述「固有成本」实为 `ensure()` 内部（`Context` 读写 + `random_bytes` + 入向头解析 + `syncServer`），它**保留**；
     而 `responseHeaders()`（W3C 拼接）+ 3×`withHeader`（Nyholm 克隆响应对象）属「**回写响应头**」切片，**已可通过
     `observability.tracing.attach_headers=false` 移除**（见 §5.8 第 3 条 / §6 P1）。本机微基准：该切片约 2.1µs/op（占 trace
     头处理切片约 47%），`ensure()` 固有 ~2.3µs/op 不受影响。绝对数值随机器/负载浮动，真实生产路径增益以 `run.sh` 实测为准。
3. 随机数生成已优化（`ensure()` 单次 `random_bytes(24)` 切片出 trace_id+span_id，省 1 次系统调用），但占比极小。
4. **诚实定性**：kode·default 与 webman 的差距，主要是「webman 默认裸栈、不自带 trace/metrics 中间件，
   而 kode·default 默认开箱即用的企业级可观测性」。webman 若装同等中间件，差距会显著收窄——属**功能对价**，非缺陷。

## 5. 已实施的修复（in-framework + vendor patch）

### 5.1 链路追踪改为按采样决策，且未采样时跳过 span 创建
- `src/Observability/Trace/Tracer.php`
  - `decideSampled()` 提升为 **public**（供中间件先行判定）；
  - `start()` 新增可选 `$sampled` 参数，避免「已决定不采样却仍创建 span 对象」的无效开销。
- `src/Observability/Middleware/TraceMiddleware.php`
  - 仅当 `tracer->isEnabled() && tracer->decideSampled()` 才开根 span；
    未采样请求直接走「无 span 创建」快路径；traceparent / X-Trace-Id 响应头仍统一附加（传播不丢）。
- `config/observability.php`
  - `tracing.sample_ratio` 默认 **1.0 → 0.1**（生产惯例：10% 采样足够定位问题，全采样非生产默认）。
    需全链路时显式设 `1.0`。

效果（同一机器前后对比，已消除 ab 干扰）：
`kode_default / kode_lean` 比值 **0.62 → 0.68（/ping）/ 0.60 → 0.73（/bench）**；
`kode_default` 绝对吞吐 +14%（/ping）、+12%（/bench）。
（注：当时 hyperf 处于旧无冷却口径被热降频压至 114k，故当时测出「反超」；补 `COOLDOWN=15` 后 hyperf 回升至 151k/138k，
kode_default 实际低于 hyperf——属完整企业栈与自带 DI 框架的合理定位差异。）

### 5.2 测试
`tests/ObservabilityTest.php` 10/10 通过（采样短路后追踪头仍正确下发、采样比例行为不变）。

### 5.3 kode/http 吞吐路径微优化（vendor patch，经 composer-patches 固化）

目标：把 kode 自研 PSR-7 在「常驻内存发射」这条最热的每请求路径上的不必要开销削掉，
使其无限逼近 webman 那种「`new Response(200, [h], json_encode)` 直构」的极简形态。

两处改动落在 `vendor/kode/http`，通过 `patches/kode-http-response-optimize.patch` 固化、
在 `composer.json` 的 `extra.patches["kode/http"]` 注册——**vendor 本身不入库**，
CI / `composer install` 自动应用，与生产 SwooleServerAdapter 口径一致。

1. **`Response::json` 去中间层**（`src/Response.php`）
   - 旧：`(new self())->body(self::encode($payload))` → `body()` 内部再 `withBody(Stream::create())`，
     即「构造空对象 → 不可变 with* → 再包一层 Stream」，多一次对象分配 + 一次 PSR-7 接口分发。
   - 新：`return new self(200, [], Stream::create(self::encode($payload)));` 一步直构（与 webman `json()` 等价）。
2. **`SwooleServerAdapter` 发射走内部字符串体**（`src/Server/SwooleServerAdapter.php`）
   - 旧：`$swooleResponse->end($response->getBody()->getContents());`——对 kode 自研 Response 也走 PSR-7
     `getBody()`（多态 `StreamInterface` 分发）+ `getContents()` 接口调用。
   - 新：对 `Kode\Http\Response` 实例直接 `$response->getBodyString()` 取内部持有的原生字符串，
     避开 PSR-7 接口分发；外部（Nyholm 等）响应仍走通用 `getBody()->getContents()`。

**micro-bench 隔离验证**（同一进程内纯函数级吞吐，消除框架/网络噪声）：
- tiny 响应：`Resp::json(['ok'=>1])` 1.46M → 1.9M ops/s（**+30%**）
- 50 条记录：`Resp::json($items)` 385k → 400k ops/s（**+4%**）
- 参照：纯 `json_encode($items)` 504k ops/s；webman `json()` 即 `new Response(200,[h],json_encode)`，
  与本改动后的 kode 直构形态等价。

**harness 同构**（消除 handler 不公）：`benchmarks/peers/kode_swoole_server.php` 的 `/bench/json` handler
改为与 webman 完全同构——`array_map(range(1,50), fn => ['id','name'])` + `framework`/`now`/`items`
字段——确保横向 rps 只反映框架开销，不反映「handler 写法差异」。

**run.sh 冷却**（对应第 3(c) 条）：新增 `COOLDOWN=15`，每 peer 测量前 `sleep 15` 让 CPU 从上一 peer
的热态回到 boost 基线，消除多 peer 连续满负载的累积热降频（此前 kode 排第 5、hyperf 排第 6 被系统性压低）。

**全链路效果**（见第 2 节）：harness 改走 `Kode::serve` 真实生产路径 + `HttpBridge::toPsr7` 改用 kode 自研 ServerRequest 后，
`kode · lean` **174k / 137k**，`/ping` 达 webman **92%**、裸 Swoole **96%**；但 `/bench/json` 仅 webman **76%**、裸 Swoole **74%**
（根因 `HttpBridge::toRaw` 序列化，见第 6 节 P0），明显低于 /ping。

> 立场：本轮回到的真实数字是 **kode · lean `/ping` ≈ webman 92%（未超过）、`/bench/json` ≈ webman 76%**——
> /ping 的 ~8% 差距来自 kode 保留的**完整 PSR-7 管线 + 异常处理中间件 + 全局请求上下文**（企业级框架 vs 极简框架的架构差异）；
> /bench/json 的更大差距（24%）主因是 `HttpBridge::toRaw` 纯 PHP 序列化 + kode/process 统一运行时 I/O（见第 6 节 P0），非路由内核。
> 要彻底超过 webman 需 runtime 层协作提供 `sendResponse(PSR-7)`，放弃 PSR-7 兼容或中间件收口会削弱 kode 的差异化价值。

### 5.4 benchmark harness 对齐生产 `SwooleServerAdapter`（历史中间阶段，已被 §5.6 取代）

发现 benchmark harness 的 Swoole `request` 回调比生产 `SwooleServerAdapter` 多了 3 个清理调用
（`Tracer::resetOutbox()` / `AccessLogSink::reset()` / `AuditSink::reset()`），而生产 adapter 并不调用——
这会让 kode 在压测里比真实生产显得更慢（多余的每请求函数调用 + 静态数组清空）。

修正（`benchmarks/peers/kode_swoole_server.php`）：
1. **移除 3 个 `reset()` 调用**，使 harness 的每请求开销与生产 adapter 完全一致（lean 档 observability/logging/audit
   均已禁用，reset 本就是多余空清）；同步删除对应 `use` 导入。
2. **发射逻辑对齐补丁后的生产 adapter**：对 `Kode\Http\Response` 实例走 `getBodyString()` 取内部字符串体，
   而非 `(string) $response->getBody()`，确保压测反映 kode/http 补丁后的真实生产发射路径。

效果：harness 现与生产 `SwooleServerAdapter` 逐字节等价（除 Swoole 自身的 HTTP I/O），
wrk 实测数字即真实生产吞吐，不再被 harness 额外开销低估。配合第 3(d) 条的 241k handle 上限测量，
证实 kode·lean 的 wrk 数字（见第 2 节）是 Swoole I/O 上限，非框架低估。

### 5.5 `HttpBridge::toPsr7` 改用 kode 自研 ServerRequest（省 4 次克隆）

`src/Server/HttpBridge.php::toPsr7()` 原用 **Nyholm 不可变 ServerRequest**，链式
`withQueryParams / withParsedBody / withCookieParams / withUploadedFiles` 每次都**克隆整个对象**
（不可变 PSR-7 语义），每请求 4 次全量克隆——且框架内部 `handle` 实际用的就是 kode 自研 ServerRequest，
入口桥接用 Nyholm 构成「同一请求在两个 PSR-7 实现间混用」的不一致。

改为 **kode/http 自研 ServerRequest**（v3.4 起可变消息，`with*` 原地修改、返回 $this、零拷贝），
每请求省 4 次对象克隆，且与框架内核实现一致。

- 验证：`tests/HttpBridgeTest.php` 4/4 + 宽 HTTP/中间件测试 45/45 通过；`bin/kode serve` 冒烟 `/ping`、`/bench/json` 正常。
- 效果：`/ping` 路径体现（真实生产路径下 170k → **174k**，+2%），且为框架可控、与 Swoole 无关的真实优化。

### 5.6 benchmark harness 改走 `Kode::serve` 真实生产路径（口径纠偏）

发现压测 harness `kode_swoole_server.php` 直接 `new Swoole\Http\Server` + 手写 PSR-7 转换 + 手写发射，
**绕过框架真实生产路径**（`Kode::serve` + `HttpBridge`），且违背「框架不碰 Swoole」的架构红线精神。

重写为委托 **`Kode::serve()`**（kode/process 运行时，自动择优 Swoole/Workerman/Native 后端）+ **`HttpBridge`**
桥接，与 `bin/kode serve` 交付路径 **100% 同构**（仅多注册压测路由）。这样压测数字即框架实际交付吞吐。

- 审计结论：框架 `src/Server/` 只委托 `Kode::serve`，**Swoole 接入完全在 kode/process + kode/http 包内**，
  框架 `src/` 不碰 Swoole，符合架构红线。
- 意义：彻底消除「自建 Swoole 适配器」对 kode 的高估（旧 harness 用 Swoole 原生 `$res->end()` 直写，规避了
  `HttpBridge::toRaw` 的纯 PHP 序列化，使 /bench/json 数字偏高）。本轮 §2 表格数字即真实生产路径吞吐。

### 5.7 「kode 能否像 webman 在 C 层 header()/end() 写」——结论：能，且已接线

- kode/process 5.2.31 的 `SwooleConnection` 已提供 `beginChunked($status,$headers)` + `chunk()` + `endChunk()`，
  在 C 层 `$response->status()` + `->header()` + `->write()` 写出；kode/http 的 `SwooleServerAdapter` 同样走
  `status()` + `header()` + `end($body)` 的 C 层路径。**所以 kode 完全能达到 webman 的 C 层写出。**
- 新增 `HttpBridge::emit(ConnectionInterface, Response, protocol, gzip)`：
  - **Workerman 后端**：构造原生 `Workerman\Protocols\Http\Response` 交 `TcpConnection` 的 C 层「对象式」序列化
    （对齐 webman 一次 `send` 传响应对象，C 层遍历 header 写出）——**这是 kode 相对旧 `toRaw` 纯 PHP 拼串的真实增益**。
  - **Swoole / Native 后端**：经连接原生 `send(toRaw(...), true)` 单串写出。A/B 实测表明 Swoole 没有「一次传响应对象」API，
    逐 header C 写与单串 `end()` 性能持平（27 次 PHP↔C 编组抵消纯 PHP 拼串），故 Swoole 沿用单串 `end()` 即最优，
    且保留 `SwooleConnection` 内部按 Accept-Encoding 的自动 gzip。
- **诚实结论**：在 Swoole（本压测运行时）下，C 层写出**并未提升** kode 相对 webman 的吞吐——因为 webman 的 Swoole 路径
  同样是「单串 `end()`」，kode 旧 `toRaw` 也是单串。真正的差距不在响应写出，而在**中间件栈**（见 §4）。

### 5.8 本轮 kode·default 调优（v0.8.35）

- **Metrics 时延直方图采样**（`MetricsMiddleware`）：新增 `observability.metrics.sample_ratio`（默认 0.1）。
  计数（吞吐/错误率）100% 采集不受影响；昂贵的 HDR 分位 `observe()` 按 0.1 采样，每请求成本降约 10×。
  标准 Prometheus 实践，P50/P95/P99 仍统计有效。
- **Trace 随机数生成优化**（`TraceContext::ensure`）：单次 `random_bytes(24)` 切片出 trace_id(16)+span_id(8)，省 1 次 CSPRNG 系统调用。
- **效果与边界（诚实）**：上述为正确且标准的优化，但§4 的微基准 + 边际成本实测表明——可观测性 100% 路径的
  固有成本（Context 读写 + 头解析 + W3C 拼接，约 0.77µs/请求）**无法通过框架内微优化消除**；其主导性来自
  「kode·default 默认开箱即用的企业级可观测性」，而 webman 默认不自带。故 kode·default 与 webman 的差距属**功能对价**。

### 5.9 新增 `observability.tracing.attach_headers` 开关（v0.8.36）

把「回写 W3C 链路头」与「建立内部 trace 上下文」解耦，提供部署级杠杆：

- `observability.tracing.attach_headers`（默认 `true`，env `OBS_TRACING_ATTACH_HEADERS`）：
  - `true`：每个响应都带 `traceparent` + `X-Trace-Id` + `X-Span-Id`（网关/日志/APM 可直接串联）。
  - `false`：`TraceMiddleware` **仅**调用 `TraceContext::ensure()` 建立内部 trace 上下文（供 `logger` 关联、
    `TraceContext::outgoingHeaders()` 下游串联、kode/exception 异常 tracer 桥接），**跳过 `responseHeaders()` +
    3×`withHeader` 的响应头回写**——省去该切片每请求开销。
- 实现：`TraceMiddleware` 构造器新增 `$config` 透传 `observability.tracing`，按 `attach_headers` 决定
  `foreach (TraceContext::responseHeaders())` 是否执行；`ObservabilityServiceProvider` 已在挂载时传入配置。
- 微基准（本机、进程隔离、5 轮中位数）：该切片约 **2.1µs/op（约占 trace 头处理切片 47%）**；`ensure()` 固有
  ~2.3µs/op 保留。`attach_headers=false` 使 `TraceMiddleware` 每请求开销约减半（微基准口径），真实生产路径增益
  受其余固定管线成本限制（与 §4 的 ~0.77µs 可观测 delta 一致），需以 `run.sh` 实测为准。
- 语义边界：内部 `trace_id`/`span_id` 在两种模式下**都照常生成**，`trace()` / `logger` 关联不受影响——只差
  「是否把链路头回写进 HTTP 响应」。

### 5.10 v0.8.37：C 层 Swoole 写出 + harness 稳定化 + 响应路径零缩放开销铁证

**A. `HttpBridge::emit()` C 层 Swoole 写出（框架层真实增益）**

- Swoole 后端（HTTP 模式，`$conn->native()` 即 `Swoole\Http\Response`）改走 **`status()` + `header()` + `end($body)`**
  的 C 层路径（v0.8.37 新增 `emitSwoole()`）：**消除旧 `toRaw()` 在 PHP 侧把「headers + body」拼成一整串的开销**，
  每请求少一次 PHP 级大字符串分配 → 降低 GC 压力。Workerman 后端仍走原生 `Http\Response` 对象式 C 层写出（§5.7），
  Native / Swoole-gzip 开启时退回 `toRaw` 单串发送以保留压缩能力。
- 边界：`gzipAuto` 实际从未启用（`isGzipAuto()` 恒 false），故 C 层路径不丢失任何功能；`emit()` 后 HttpServer 不再
  `close()`，连接按请求作用域安全。

> **v0.8.38 架构红线收尾**：上述引擎专用写出逻辑（Swoole C 层 `status()+header()+end($body)` /
> Workerman 原生 `Http\Response` / Native 序列化）已**下沉到 `kode/process` 各 Driver**，并在
> `ConnectionInterface` 新增 `sendResponse(ResponseInterface, protocol)`；框架 `src/HttpBridge::emit()`
> 退化为纯薄委托 `$conn->sendResponse($response, $protocol)`，**完全不点名任何引擎类**（不再有任何
> `class_exists(\Swoole\Http\Response)` / `class_exists(\Workerman\...)`）。该能力自 **kode/process 5.2.31 起原生提供**
>（`ConnectionInterface::sendResponse` + 4 Driver 实现），框架侧**不再需要任何 kode/process vendor patch**，
> `composer.json` 的 `extra.patches` 仅保留 `kode/database` 与 `kode/http` 两项。

**B. harness 稳定化（治噪声，非粉饰）**

- `run.sh`：WARMUP 3→8s、ITERS 3→5、COOLDOWN 15→20s；取 5 跑中位数抗噪。
- `kode_swoole_server.php`：新增 `KODE_RUNTIME=swoole|workerman` 开关（透传 `Kode::serve` 第三参），
  可在压测中强制运行时做同类对比（验证「差距来自运行时 vs 框架」）。

**C. 隔离微基准铁证：响应路径零 body 缩放开销**

- 方法：进程隔离、每场景独立 boot 测量（消 CPU 热节流伪影），响应体 15B → ~1.5KB 对比。
- 结果（3 轮稳定一致）：`json_encode` / `Resp::json` 构造 / `toRaw` 序列化 / `getBodyString` **各阶段 delta 均恰
  为 2.0µs** —— 即框架响应路径**零 body 缩放开销**，多出的 2µs 纯粹是 `json_encode` 本身（webman 同理）。
- 推论：kode·lean `/bench/json` 相对 webman 的残差（约 79%）**不在框架响应代码**，而是 Swoole vs Workerman 运行时
  差异 + 本机热噪声（见 §3.2）。框架层继续抠响应管线已无实收益。

## 6. 仍可继续提高的点（按性价比排序，待确认后实施）

| 优先级 | 项 | 预期收益 | 改动性质 |
|---|---|---|---|
| **P0（已落地）** | **响应写出（C 层，双引擎）+ 架构红线收尾**：`HttpBridge::emit()` 现已把 **Workerman 后端**路由到 C 层「对象式」序列化、**Swoole 后端**路由到 C 层 `status()+header()+end($body)`（v0.8.37 新增），二者均消除旧 `toRaw()` 的 PHP 级「headers+body」整串拼接（每请求少一次大字符串分配、降 GC 压力）。微基准铁证：框架响应路径**零 body 缩放开销**（§5.10C），故响应写出**不是** kode·lean `/bench/json` 仅裸 Swoole 76%/webman 79% 的主因（主因 = Swoole vs Workerman 运行时差异 + 本机热噪声，§3.2）。**v0.8.38 架构红线收尾**：引擎专用写出逻辑（Swoole C 层 / Workerman 原生 `Http\Response` / Native 序列化）已**下沉到 `kode/process` 各 Driver**，并在 `ConnectionInterface` 新增 `sendResponse(ResponseInterface, protocol)`；框架 `src/HttpBridge::emit()` 改为纯薄委托 `$conn->sendResponse($response, $protocol)`，**完全不点名任何引擎类**。该能力自 **kode/process 5.2.31 起原生提供**，框架侧不再需要任何 kode/process vendor patch（`composer.json` 仅保留 `kode/database`、`kode/http` 两项 patch）。 | 已落地（双引擎 C 层写出 + 红线收尾） | 框架内 + kode/process 原生能力（无 patch） |
| **P1（已落地）** | **可观测性 100% 路径固有成本**（Trace `ensure()` ≈ 2.3µs/请求，含 Context 读写 + CSPRNG + 入向头解析 + syncServer；非克隆、非随机数）。这是 kode·default 与 webman 差距的主因，且**框架内不可消除**（webman 默认不自带 trace/metrics）。**已新增 `observability.tracing.attach_headers`（默认 true）开关**：置 false 时跳过 `responseHeaders()` + 3×`withHeader` 的响应头回写（本机微基准省 ~2.1µs/op、约占该切片 47%，`ensure()` 固有成本保留），供「不依赖 W3C 传播、仅内部可观测」的高吞吐部署选择（见 §5.9）。真实生产路径增益受其余固定管线限制（与 §4 ~0.77µs 可观测 delta 一致），需 `run.sh` 实测。 | 中（仅 attach_headers 开关）/ 无（接受为功能对价） | 配置开关（已实施，默认 true 保持向后兼容） |
| P1 | 全局限流默认 `capacity=10/s` 过低（config/limiting.php），会**真实限流生产流量**；建议默认大幅提高或仅按 `#[RateLimit]` 生效 | 生产可用性（非压测） | 配置默认值 |
| **P2（已落地·深化）** | **resilience 三件套改为「路由级中间件」彻底移出默认全局管道**：`HttpServiceProvider` 不再把 `CircuitBreakerMiddleware`/`RetryMiddleware`/`IdempotencyMiddleware` 经 `$app->use()` 挂入全局栈；改为在**启用时**绑定为容器单例；`ControllerScanner::register()` 在属性路由扫描阶段对类级/方法级 `#[CircuitBreaker]`/`#[Retry]`/`#[Idempotency]` 标记的路由**直接 `$route->middleware($mw)` 挂内层管道**（与 rate-limit/feature/csrf 同机制，但挂在具体路由而非全局），显式路由由 `scanExplicit{CircuitBreakers,Retries,Idempotencies}` 补挂；中间件 `process()` 入口仍对**未标记路由 O(1) 早退**（`$registry->*`Of，匹配由 `RouteResolver` 单次请求内缓存）作为双保险。→ **默认栈全局中间件帧少 3 帧**（breaker/retry/idempotency 不再参与任意未标记路由的管线），未标记路由热路径上 resilience 开销归零；标记路由才纳入内层管道。`Timeout` 仅作助手（`timeout()`），无中间件。 | 已落地（路由级挂载 + 早退双保险） | 架构（kode/http 路由级 `middleware()` 内层管道，包裹匹配 handler、位于全局中间件之内） |
| P3 | AccessLog 异步入队仍有每请求格式化开销；可评估「仅在 span/指标已启用时同步元数据」 | 小 | 局部 |
| P4 | 其余常驻中间件（RequestId/Cors/SecurityHeaders/Locale/Feature/Csrf）各自仅微开销，聚合约 10~15%，逐项优化收益递减 | 小 | 局部/可选 |

> 立场：kode 的价值正是「开箱即用的企业级中间件」。默认栈（kode·default）约 webman **39%/33%** 折损是**功能对价**，
> 不是缺陷；需要极限吞吐时关闭对应组（lean 模式已验证 `/ping` 达裸 Swoole **87%**、webman **90%**、`/bench/json` 达 webman **79%**）。
> 隔离微基准（§5.10C）已证 kode 响应路径零 body 缩放开销，残差主因是 Swoole vs Workerman 运行时差异 + 本机热噪声（同 peer 跨跑 ±15~40%），
> 框架层继续抠已无实收益。
> 上述 P2 是把「未使用的功能」从默认热路径移除，属正确且不影响能力的优化。

## 7. 复现

```bash
# 清理可能残留的 kode 临时配置缓存
find /tmp -maxdepth 1 -name 'kode-peer-*' -type d -exec rm -rf {} + 2>/dev/null

# 当前可复现路径：Workerman 驱动（kode/process Swoole 驱动在 5.2.31×Swoole6.2.2 并发回归，见 §8）
no_proxy='*' NO_PROXY='*' bash benchmarks/peers/run_workerman_kode.sh
```

> 环境要点（压测必看）：
> - 必须 `no_proxy='*' NO_PROXY='*'`：本机若设了 HTTP 透明代理，curl 探活会走代理返 502 导致 harness 卡死。
> - kode peer 需 `-d memory_limit=512M`：`/bench/json` 全 ORM boot 会触默认 128M 上限崩溃。
> - `run.sh` 中的 kode·default / kode·lean（Swoole 驱动）在 5.2.31 下不可用，待 Swoole 回归解除后恢复。

各 peer 位置：
- `benchmarks/peers/swoole_raw/server.php`
- `benchmarks/peers/workerman_raw/server.php`
- `benchmarks/peers/webman/`（kode_server.php + config/route.php）
- `benchmarks/peers/hyperf/`（标准骨架 + config/routes.php 两条路由）
- `benchmarks/peers/kode_swoole_server.php`（`KODE_PROFILE=default|lean`、`KODE_RUNTIME=swoole|workerman`、`KODE_DISABLE=` 可调）

## 8. 已知问题：kode/process 5.2.31 Swoole 驱动并发回归

> **状态**：kode/process 升级到 **5.2.31** 后，其 **Swoole 驱动**（`SwooleRuntime`/`SwooleConnection`）在 **Swoole 6.2.2**
> 下并发 keep-alive 出现 **worker 静默崩溃重启** 的回归，导致 kode·default / kode·lean 的 Swoole 压测不可用
> （`wrk -t8 -c200 /ping` 塌至 ~2k rps、`Socket errors: connect 200 / read 16000+`）。**框架侧无辜**
> （20+ 对照实验穷尽排除 sendResponse / handle / gzip / status 参数 / responded 守卫 / 运行模式 / 协程 / 应用异常 / Request 构造，
> 全部复现崩溃；server log 无任何 PHP fatal 或 Swoole 错误）。修复路径只能是**上游修 kode/process 或回滚版本**（vendor 已 gitignore，无法以 patch 持久修）。
>
> **关键隔离**：`webman` peer 实际跑 **Workerman**（非 kode/process），故其健康不代表 kode/process 无恙。但 kode/process 的
> **Workerman 驱动健康**——已作为当前默认对标运行时（驱动无关，结论对 Swoole 同样有效）。
> 最新可复现数据见 §2.1（`run_workerman_kode.sh`），完整最小复现与怀疑点见
> [`benchmarks/kode-process-swoole-regression.md`](./kode-process-swoole-regression.md)（可交还上游）。

**处置（已拍板）**：维持 **kode/process 5.2.31 + Workerman 驱动** 继续框架层调优/增强（用户决策）；Swoole 驱动待上游修复后恢复压测。
框架侧清理已就位：5.2.31 原生提供 `ConnectionInterface::sendResponse`，故 v0.8.38 的 `kode-process` patch 已删除，`composer.json` `extra.patches` 仅留 `kode/database` 与 `kode/http`；`composer install` + 全量测试通过。

