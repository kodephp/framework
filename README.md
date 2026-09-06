# Kode Framework

一个以 [kode](https://github.com/kodephp) 生态组件为基座、组合 Monolog / Symfony Validator 等成熟包的**现代化 PHP API 框架**。最低 PHP 8.3+，开箱即多进程常驻服务，错误默认返回可追踪的结构化 JSON。

> 设计立场和 webman / Hyperf 一致：**薄内核 + 复用 Composer 生态**。框架只做「启动、容器、路由、统一响应、异常、中间件、韧性层」等地基，其余能力（JWT、限流、缓存、队列、数据库、事件、HTTP 客户端、消息、国际化、Snowflake、定时任务、多进程……）全部来自 kode 生态包，业务代码不变即可切换运行时（Fiber 协程 / 多进程 / 多线程 / Swoole / 分布式）。

---

## 5 分钟跑起来

```bash
# 1. 安装：下载骨架 + composer install + 初始化（项目名 myapp 写在包名后）
#    骨架仓库 = kode/skeleton；本仓库（kode/framework）自 v1.2.0 起为纯内核，作为依赖被引入
composer create-project kode/skeleton myapp \
  --repository='{"type":"vcs","url":"https://github.com/kodephp/skeleton.git"}' \
  --stability=dev
cd myapp

# 2. 启动多进程 HTTP 服务（默认 http://127.0.0.1:9527）
php kode start

# 3. 验证
curl http://127.0.0.1:9527/health
# {"status":"ok","service":"kode-app","version":"1.3.6","php":"8.3.33","env":"local","time":0.52}
# time = health check 方法执行耗时（毫秒）
```

> **为什么多了 `--repository`**：`kode/skeleton` 与 `kode/framework` 目前都**未提交到 Packagist**，
> 直接 `composer create-project kode/skeleton myapp` 会报 `Could not find package ... with stability stable`。
> 显式指定 VCS 仓库即可安装；骨架的 `composer.json` 已声明 framework 的 VCS 仓库，依赖可正常解析。

> 安装时 `composer create-project` 会自动执行 `php kode init`，生成 `.env`（含强随机 `JWT_SECRET`，权限 0600）与 `storage/` 目录。
> 若把框架作为依赖引入已有项目：在 `composer.json` 声明 framework 的 VCS 仓库后 `composer require kode/framework`，再从
> [kode/skeleton](https://github.com/kodephp/skeleton) 复制 `app/`、`config/`、`lang/`、
> `database/`、`kode` 到项目根，然后 `php kode init`（控制台命令走 `php kode console ...`）。（本仓库不再自带这些骨架目录。）

第一个接口：

```php
// app/http/controllers/HelloController.php
namespace app\http\controllers;

use Kode\Framework\Http\Controller;

final class HelloController extends Controller
{
    public function say(): array
    {
        $name = $this->input('name', '世界');
        return ['hello' => $name];          // 直接返回数组 → 自动 JSON 化
    }
}
```

```php
// app/routes.php
use Kode\Http\App;
use app\http\controllers\HelloController;

return function (App $app): void {
    $app->get('/hello', fn() => resolve(HelloController::class)->say());
};
```

```bash
curl "http://127.0.0.1:9527/hello?name=Kode"   # {"hello":"Kode"}
```

---

## 服务运维命令（对标 workerman）

启动时会打印进程表横幅，一眼看到**协议 / 用户 / worker 名 / 监听地址与端口 / 进程数 / 状态**：

```text
Kode[kode] start in PRODUCTION mode
--- KODE ---------------------------------------------------------------------
Kode Framework version:1.3.6          PHP version:8.3.33
Runtime:native                   Event-Loop:event
--- WORKERS ------------------------------------------------------------------
proto    user       worker           listen                       processes  status
http     Zhuanz     kode-http        http://127.0.0.1:9527        8          [OK]
------------------------------------------------------------------------------
项目根目录：/srv/myapp
Press Ctrl+C to stop. Start success.
```

| 命令 | 作用 |
| --- | --- |
| `php kode start` | 前台启动（非 production 默认热重载，`--no-watch` 关闭；`serve` 为别名） |
| `php kode start -d` | **守护进程模式**（脱离终端，写 PID 文件，用 `stop` 停止） |
| `php kode status` | workerman 风格状态表：GLOBAL STATUS + 逐进程 PROCESS STATUS |
| `php kode status [--port P] [--pid=N]` | 查看服务状态（`--port` 定位多实例，`--pid` 看单进程） |

> 多实例（v1.3.3）：不同端口实例的 PID/日志/状态隔离在 `storage/runtime/<port>/`（如 `kode start --port 9599`），
> 用 `kode stop/status --port 9599` 精确操作该实例；老版本单文件（`storage/runtime/kode.pid`）自动兼容一次。
| `php kode stop [--port P] [-g]` | 停止服务（默认优雅停机，`-g` 强杀；`--port` 定位多实例） |
| `php kode reload [-d]` | 全量重载（等价 stop 后 start；默认前台，`-d` 进守护） |
| `php kode restart` | 运行中平滑滚动 worker；未运行按 `start` 拉起 |

> 命令约定（v1.3.3 起）：`reload`＝重载所有（stop＋start），`restart`＝只平滑滚动
> worker。注意这与 workerman 的命名相反（那边 restart 是全量、reload 是平滑），
> 为统一记忆：**带 e 的 reload 做“全套”（rEload＝Everything），短小的 restart 做“滚动”（rolling）**。

`status` 输出示例（`connections` / `total_request` / `qps` 来自各 worker 的 1Hz 心跳）：

```text
----------------------------------------------GLOBAL STATUS----------------------------------------------
Kode Framework version:1.3.6        PHP version:8.3.33
start time:2026-08-30 12:36:36    run 0 days 0 hours 1 minutes
master pid:81664      runtime:native     event-loop:event    load average:0.35, 0.31, 0.28
1 workers       3 processes
worker_name      processes  status
kode-http        3          [OK]
----------------------------------------------PROCESS STATUS---------------------------------------------
pid      memory    listening                      worker_name    connections  total_request  qps    status
81667    12.00M    http://127.0.0.1:9527          kode-http#0    0            128            3      [idle]
```

与 workerman 的**已知差异（如实标注，不做伪装）**：不输出 `exit_status` / `exit_count` 两列——
workerman 在 master 里收割子进程并记录退出码，而本框架 master 循环位于 `kode/process` 内部，
业务层观测不到子进程退出码；与其填 0 假装「零退出」误导排障，不如不列。

进程表数据写在 `storage/runtime/`（可用 `config/server.php` 的 `runtime_path` 改），
纯运行时产物，随时可删，下一心跳自动重建。

> **Ctrl+C 为什么能立刻退出**：`kode/process` 收到停机信号后固定空等一整个
> `graceful_shutdown_timeout`（骨架默认 30s）——空闲服务按 Ctrl+C 也要等满 30s。
> 框架在 v1.2.0 补了「快速排空」看门狗：收到信号后一旦在途请求归零就立即结束事件循环，
> 空闲退出从「等满宽限」压到 ≤0.5s；真有在途请求时仍走完整宽限，不丢请求。

---

## 为什么选它

| 痛点 | 本框架的做法 |
| --- | --- |
| 错误排查难 | 异常默认返回结构化 JSON，含 `location`（出错文件/行/方法）与 `chain`（完整调用链），开发期直接定位源码 |
| 重复造轮子 | 能力全部委托 kode 生态包，框架只做薄适配；包升级即能力升级 |
| 性能 / 常驻 | `kode/process` 多进程常驻内存（零扩展依赖，不锁 Swoole/Workerman） |
| 多运行时 | 一套业务代码，Fiber / 多进程 / 多线程 / Swoole / 分布式通吃 |
| 约定清晰 | 路由双模型（属性 + 闭包）、`app/routes/*.php` 即插即用、插件自动发现 |

---

## 内置能力一览

| 能力 | 怎么用 | 底层包 |
| --- | --- | --- |
| 路由 | 属性 `#[Get]` / 闭包 `app/routes.php` / `app/routes/*.php` | kode/router + kode/attributes |
| 请求 / 响应 | 控制器短方法 `input/query/post/param`；`Resp::json/error` | kode/http (PSR-7) |
| 参数校验 | `$this->validate($data, $rules)` | Symfony Validator |
| 异常处理 | 全局结构化 JSON（location/chain/trace_id） | kode/exception |
| 鉴权 / JWT | `jwt()->issue()`、`AuthMiddleware` | kode/jwt |
| 限流 | `#[RateLimit]` 声明式 + 全局默认，分布式用 Redis | kode/limiting |
| 熔断 | `breaker()->run($name, $task, $fallback)` | kode/fibers (CircuitBreaker) |
| HTTP 熔断中间件 | `CircuitBreakerMiddleware`（边缘保护下游，5xx/传输异常计入，OPEN 短路 503） | 框架内置（PSR-15 薄壳层，复用 `Breaker` 注册表） |
| 重试 | `retry($op, attempts: 3)` + `BackoffStrategy` | 框架内置（固定/指数/去相关抖动，零依赖） |
| 超时 | `timeout($op, seconds: 2.0)` + `fallback` | 框架内置（fiber 真实抢占 / pcntl / sync 退化，零依赖） |
| HTTP 重试中间件 | `RetryMiddleware`（安全方法 502/503/504 自动重试，复用 retry 段退避） | 框架内置（PSR-15 薄壳层，复用 `Retry`） |
| 定时任务 | `#[Cron]` + `kode cron` | kode/process 定时器 |
| 多进程服务 | `kode start`（--watch 热重载） | kode/process |
| 缓存 / 队列 / 数据库 / 事件 / HTTP 客户端 / 消息 | `cache()/queue()/db()/event()/http()/messaging()` | kode/cache · queue · database · event · http-client · messaging |
| 国际化 | `lang()` / `LocaleMiddleware` | Symfony Translation |
| 分布式 ID | `snowflake()` | kode/process |
| 配置 / 日志 / 门面 / DI | `config()` / `logger()` / 门面 / `resolve()` | kode/core · Monolog · kode/di |
| 可观测性 | `/metrics`(Prometheus) + W3C 链路追踪 + `Metrics` 门面 | kode/context + 框架本地薄实现 |
| 运维与生命周期 | `/health` `/health/ready` `/ping` 探针 + 启动/停机事件 | kode/event + 框架本地薄实现 |
| 安全与合规 | 安全响应头(CSP/COOP/CORP) + 审计日志(脱敏/业务事件/取证) + CSRF 防护(按需挂载·csrf.failed 安全事件·csrf_token_rotate 会话固定防护) + API 版本化 | 框架本地薄实现 |
| API 文档自动化 | `/docs/openapi.json` + Swagger UI + `#[OpenApi]` | 框架本地薄实现 |

---

## 文档导航

| 文档 | 看什么 |
| --- | --- |
| [入门指南](https://github.com/kodephp/docs/getting-started.md) | 环境、安装、第一个接口、请求/响应、校验、错误、运行与排错 |
| [开发文档总览](https://github.com/kodephp/docs/README.md) | 路由全解、中间件编写、鉴权、限流、熔断、定时任务、多进程、缓存/队列/数据库/事件/HTTP、配置、日志、门面与助手、控制台、DI 与服务提供者、AOP、插件、部署、测试（docs/ 文档地图） |

> 建议顺序：先照「入门指南」把第一个接口跑通，再按需查阅「进阶用法」。

---

## 版本

- 当前版本：**[v1.3.6](https://github.com/kodephp/framework/releases)**
- 包名：`kode/framework`（Composer）
- 仓库：<https://github.com/kodephp/framework>

## 许可证

MIT（兼容 `kode/jwt` 的 Apache-2.0；重新分发请保留其第三方许可说明）。
