# HTTP 响应链热路径压测记录（kode/http 3.4.7 + 框架响应管线）

> 生成日期：2026-08-23（框架 v0.8.46 / kode/http **3.4.7** / PHP 8.3.32 NTS）
> 环境：aarch64 Linux 沙箱；单进程微基准，预热 n/10 后 3 轮取最小（误差 ±5%）。
> 脚本：`benchmarks/http-bench.php [iterations]`
> 口径：测量「响应体构造 + 体内存化 + 提取」的服务端热路径成本——不含事件循环/连接/路由（那部分
> 受运行时与桥接影响，见 `PEER_BENCHMARK.md`）。payload 与 peer 对标路由 `/bench/json` 同构
> （50 条记录，3321 bytes）。

## 一、实测结果（100k 迭代）

| 场景 | ops/s | ns/op | 说明 |
| --- | ---: | ---: | --- |
| `json50 + getBodyString`（kode fast） | 180,695 | 5,534.2 | `Response::json()` → rawBody 持有（3.4.6+）→ `getBodyString()` 直取（3.4.7 emit 直发前提） |
| `json50 + getBody()->getContents()` | 172,621 | 5,793.0 | PSR-7 通用路径：StringStream（≤1MB，3.4.7）仍免临时流，仅多一层流分发 |
| `native json_encode`（webman 形态基线） | 199,443 | 5,014.0 | 纯 `json_encode` 字符串——webman 等"体即字符串"框架的最小路径 |
| `/ping + getBodyString`（kode fast） | 2,347,147 | **426.0** | 小体字符串响应（`new Response(200,[],'ok')`）快速路径 |
| `/ping + getBody()->getContents()` | 1,719,848 | 581.4 | 同响应 PSR-7 通用路径 |

## 二、核心结论（对标 webman/hyperf 缺口定位）

- **响应体处理已不再是「kode vs webman」差距的主因**：3.4.7 全链（构造+JSON 编码+rawBody 持有+
  字符串直取）仅比裸 `json_encode` 基线慢 **+10.4%**（5534 vs 5014 ns/op），即 Response 对象分配
  与 rawBody 机制的固定附加 ≈ 520ns/请求。这与 `PEER_BENCHMARK.md` 中「kode L0 /bench/json ≈
  webman 92%」的结论一致——剩余差距来自 **PSR-7 请求桥接（toPsr7）+ emit 编排 + 请求对象分配**，
  不在响应体。
- **/ping 型小体响应快速路径收益最大**：`getBodyString()` 直取比通用 `getContents()` **快 27%**
  （426 vs 581 ns）；StringStream（3.4.7）把每响应 `fopen('php://temp')` + 两次整段拷贝彻底摘除，
  是常驻内存吞吐路径的最后一环。
- **剩余调优杠杆在运行时侧（非响应体）**：`LazyServerRequest`/`LazyUri`（3.4.6 已自带）负责请求
  侧免 header 规范化；`KODE_LEAN=1` 旁路（跳到 toPsr7+App::handle+emit 直发 raw）已被证明可达
  webman 99.8%（见 `PEER_BENCHMARK.md` §4.3/§7）——企业栈 L5 与 L0 之间约 44%/50% 折损是
  **功能对价**（可观测性 Metrics+Trace 为单项最大税），非缺陷。

## 三、版本轨迹

- 3.4.1：懒原始体消除每请求 Stream 分配（Emitter rawBody 快速路径）。
- 3.4.6：`Response::json()` rawBody 快路径 + `getBody()` 非破坏性 + `LazyServerRequest`/`LazyUri`。
- **3.4.7**：`SwooleServerAdapter` emit 直取 `getBodyString()`（本表 fast 路径）+ `StringStream`
  纯内存流（≤1MB 免 `php://temp`）——框架两处补丁合入后，`patches/` 与 `composer.json extra.patches`
  整体移除，vendor 纯净。