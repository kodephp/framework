# 健壮性设计（Robustness）

> 框架以「薄壳 + 防御性边界」为原则：不重复造 kode 生态的能力，但在**引导期、错误路径、CLI 入口**
> 这些「一旦出错就会裸奔」的关键边界上做防御，确保任何意外都不会泄漏原始堆栈、空白 500 或难以排查的启动失败。

---

## 1. 错误响应永不失明（ExceptionMiddleware）

`ExceptionMiddleware` 是最内层异常屏障，把下游未处理异常交给 `kode/exception` 统一格式化为
结构化 JSON。它有两层防御：

1. **渲染器自身失败也安全**：若 `ExceptionManager::respond()` 抛错（循环依赖、内部异常），
   `toResponse()` 捕获后回退为最小安全响应：

   ```json
   { "message": "Internal Server Error", "trace_id": "..." }
   ```

   状态码 500，并记录原始错误到日志。错误处理器**绝不会**以「裸 PHP 错误 / 空白页」结束。

2. **校验异常特例**：`ValidationException` 转为 422，含字段级错误明细，不走通用渲染。

```php
$mw->process($req, $handler); // handler 抛任何 Throwable → 结构化 JSON，而非裸栈
```

---

## 2. 链路追踪外层不可失败（TraceMiddleware）

`TraceMiddleware` 位于全局管线**最外层**，负责为每个响应（含异常响应）附加 `traceparent` /
`X-Trace-Id` / `X-Span-Id`。它同样做了防御：

- `TraceContext::ensure()` 失败 → 仅记录 warning 并降级为「无链路头」继续处理，不阻断请求；
- `responseHeaders()` 失败 → 跳过链路头，业务响应照常返回。

这样即便追踪子系统异常，错误管道也不会被「外层中间件抛错」击穿而丢失结构化错误响应。

---

## 3. .env 解析健壮性（EnvLoader）

`.env` 由 `Kode\Framework\Support\EnvLoader` 解析，容忍常见写法：

| 写法 | 结果 |
| --- | --- |
| `KEY=val` | `KEY=val` |
| `export KEY=val` | `KEY=val`（兼容 shell 导出） |
| `KEY="a=b c"` | `KEY=a=b c`（去引号） |
| `KEY=val # 注释` | `KEY=val`（行内注释，仅 `#` 前有空白时生效） |
| `URL=http://x#y` | `URL=http://x#y`（值中 `#` 前无空白，保留） |
| `# 注释` / 空行 / `NO=` 缺等号 | 跳过 |
| `=val` / `export =val` | 跳过（空 key） |
| `KEY=` | `KEY=`（空字符串） |
| 文件首行 UTF-8 BOM | 自动剥离 |

加载策略：**不覆盖**已通过真实环境变量（`$_SERVER`/`$_ENV`）注入的值，遵循 12-factor
（真实环境优先于 `.env` 文件）。

---

## 4. CLI 启动失败优雅退出（bin/kode）

所有命令（`console` / `cron` / `schedule:list` / `serve`）在执行 `Application::make()` 或
服务启动时的任何未捕获异常，都会被 `KodeCli::fail()` 收口：

```text
❌ RuntimeException: 应用启动失败（/path/to/app）：Class "App\No\Such\Provider" not found
```

- 以**非 0 退出码（1）**结束，便于 CI / 编排系统感知失败；
- `APP_DEBUG=true` 时附上完整堆栈便于本地排查；
- 不会把原始 PHP 堆栈直接抛给用户。

---

## 5. 容器未启动守卫（resolve 助手）

在 `Application::make()` 完成之前调用 `resolve()` / `logger()` 等助手，不再触发
「call to a member function make() on null」的晦涩致命错误，而是抛出清晰提示：

```text
RuntimeException: 服务容器尚未启动，无法解析 [cache]。请确保在 Application::make() 引导完成后再调用助手函数。
```

---

## 6. 启动失败清晰化（Application::bootstrap）

`CoreApp::boot()` 抛出的任何错误都会被包成带上下文的 `RuntimeException`，保留原始异常为
`previous` 以便追溯：

```text
RuntimeException: 应用启动失败（/path/to/app）：<原始原因>
```

配合第 4 节的 CLI 收口，开发者能立即定位是「配置缺失 / provider 类不存在 / 路径错误」等问题。

---

## 设计取舍

- **不安装全局 `set_exception_handler`**：kode/process、kode/core 运行时自身接管进程级异常，
  框架只在 HTTP 中间件与 CLI 入口做「边界级」防御，避免与运行时冲突（详见
  `ExceptionServiceProvider` 注释）。
- **错误响应形态 100% 由 kode/exception 决定**：框架只负责「兜底不裸奔」，不重新发明错误渲染。
