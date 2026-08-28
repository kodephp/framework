# Kode Framework

一个以 [kode](https://github.com/kodephp) 生态组件为基座、组合 Monolog / Symfony Validator 等成熟包的**现代化 PHP API 框架**。最低 PHP 8.3+，开箱即多进程常驻服务，错误默认返回可追踪的结构化 JSON。

> 设计立场和 webman / Hyperf 一致：**薄内核 + 复用 Composer 生态**。框架只做「启动、容器、路由、统一响应、异常、中间件、韧性层」等地基，其余能力（JWT、限流、缓存、队列、数据库、事件、HTTP 客户端、消息、国际化、Snowflake、定时任务、多进程……）全部来自 kode 生态包，业务代码不变即可切换运行时（Fiber 协程 / 多进程 / 多线程 / Swoole / 分布式）。

---

## 5 分钟跑起来

```bash
# 1. 一句话安装：下载框架 + composer install + 初始化（项目名 myapp 写在包名后）
composer create-project kode/framework myapp
cd myapp

# 2. 启动多进程 HTTP 服务（默认 http://127.0.0.1:9527）
php bin/kode serve

# 3. 验证
curl http://127.0.0.1:9527/health
# {"status":"ok","service":"kode-app","version":"0.9.0","php":"8.3.x","env":"local","time":"..."}
```

> 安装时 `composer create-project` 会自动生成 `.env` 与 `storage/` 目录。
> 若把框架作为依赖引入已有项目：`composer require kode/framework`，再把仓库里的 `app/`、`config/`、`bin/`、`lang/`、`database/` 复制进项目根，然后 `php vendor/bin/kode init`。

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
| 定时任务 | `#[Cron]` + `bin/kode cron` | kode/process 定时器 |
| 多进程服务 | `bin/kode serve`（--watch 热重载） | kode/process |
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
| [入门指南](../kode框架文档/docs/getting-started.md) | 环境、安装、第一个接口、请求/响应、校验、错误、运行与排错 |
| [开发文档总览](../kode框架文档/docs/README.md) | 路由全解、中间件编写、鉴权、限流、熔断、定时任务、多进程、缓存/队列/数据库/事件/HTTP、配置、日志、门面与助手、控制台、DI 与服务提供者、AOP、插件、部署、测试（docs/ 文档地图） |

> 建议顺序：先照「入门指南」把第一个接口跑通，再按需查阅「进阶用法」。

---

## 版本

- 当前版本：**[v0.9.0](https://github.com/kodephp/framework/releases)**
- 包名：`kode/framework`（Composer）
- 仓库：<https://github.com/kodephp/framework>

## 许可证

MIT（兼容 `kode/jwt` 的 Apache-2.0；重新分发请保留其第三方许可说明）。
