# Kode Framework 开发文档

欢迎使用 **Kode Framework** —— 一个以 [kode](https://github.com/kodephp) 生态为基座、组合 Monolog / Symfony Validator 等成熟包的现代化 PHP API 框架。最低 PHP 8.3+，开箱即多进程常驻服务，错误默认返回可追踪的结构化 JSON。

> 当前版本：**v0.8.51** · 包名：`kode/framework` · 仓库：<https://github.com/kodephp/framework>

## 学习路径

| 阶段 | 目标 | 必读 |
| --- | --- | --- |
| **① 入门** | 10 分钟跑起第一个接口 | [入门指南](getting-started.md) → [配置与环境变量](config.md) |
| **② 基础** | 把接口写规范 | [路由](routing.md) → [请求](request.md) → [响应](response.md) → [校验](validation.md) → [异常](errors.md) → [中间件](middleware.md) |
| **③ 进阶** | 接入数据、消息与异步能力 | 见下方「数据与基础设施」「并发与进程」 |
| **④ 高级** | 扩展框架、加固生产 | 见下方「扩展机制」「安全与韧性」「生产与运维」 |

> 参考 webman / Hyperf 的设计哲学：**薄内核 + 复用 Composer 生态**。框架只做「启动、容器、路由、统一响应、异常、中间件、韧性层」等地基，其余能力来自 kode 生态包；业务代码不变即可切换运行时（Fiber 协程 / 多进程 / 多线程 / Swoole / 分布式）。

## 一、入门

| 文档 | 内容 |
| --- | --- |
| [入门指南](getting-started.md) | 环境要求 → 安装 → 目录结构 → 第一个接口 → 请求/响应 → 校验 → 运行与排错 |
| [配置与环境变量](config.md) | `config()` 读取、`.env`、`APP_DEBUG`、生产环境约束 |
| [控制台命令](console.md) | `bin/kode` 常用命令、`make:*` 生成器、自定义命令 |
| [多进程 HTTP 服务](http-server.md) | `serve` 命令、worker 数量、热重载、常驻进程 |

## 二、基础：HTTP 请求处理

| 文档 | 内容 |
| --- | --- |
| [路由全解](routing.md) | 属性路由、`app/routes.php` 闭包路由、REST 资源、参数、分组、来源标签 |
| [请求对象](request.md) | `input / query / post / param / only` 短方法、Header、文件上传、PSR-7 |
| [响应对象](response.md) | `json / error / redirect / noContent`，标准 JSON 输出约定 |
| [参数校验](validation.md) | Symfony Validator、字符串管道规则、422 失败映射 |
| [异常处理](errors.md) | 结构化错误 JSON、`location`/`chain`、生产收敛、自定义错误码 |
| [自己写中间件](middleware.md) | PSR-15 管道、框架内置中间件、自定义中间件 |
| [日志](logging.md) | Monolog 通道、级别、上下文、AccessLog |
| [门面与全局助手](facades.md) | `Facade`、`resolve() / app() / config()` 等助手 |

## 三、进阶：数据与基础设施

| 文档 | 内容 |
| --- | --- |
| [数据库](database.md) | kode/database 薄封装：连接池、Schema 门面、Model、迁移命令、标识符安全 |
| [缓存](cache.md) | kode/cache（PSR-16）：file / array / redis |
| [队列](queue.md) | kode/queue 内建 Worker、#[AsJob] 自动发现、不可变消息 |
| [事件](events.md) | kode/event 派发 / 监听 / 订阅者 |
| [定时任务](scheduling.md) | `#[Cron]` 属性扫描、类级/方法级、常驻调度器 |
| [消息总线](messaging.md) | kode/messaging 长连接 / 实时协议 |
| [HTTP 客户端](http-client.md) | kode/http-client（PSR-18）：超时、重试、中间件 |
| [国际化](i18n.md) | 多模块多域（`module::key`）、symfony/translation、Accept-Language |

## 四、并发与进程

| 文档 | 内容 |
| --- | --- |
| [自定义进程](process.md) | `app/process` 常驻 Worker：心跳、消费者、清理器 |
| [应用生命周期](lifecycle.md) | 启动引导、ServiceProvider 阶段、优雅退出 |
| [多运行时](http-server.md) | Native / Swoole / Workerman 切换（`KODE_RUNTIME`） |

## 五、安全与韧性

| 文档 | 内容 |
| --- | --- |
| [鉴权（JWT）](auth.md) | kode/jwt 门面、Guard、续期、黑名单 |
| [限流](rate-limit.md) | kode/limiting 多算法、属性、分布式 |
| [熔断](circuit-breaker.md) | kode/fibers CircuitBreaker 保护下游 |
| [跨域 CORS 与安全响应头](cors.md) | 预检、安全头中间件 |
| [CSRF 防护](csrf.md) | token 校验、排除路径、审计 |
| [安全与合规](security-compliance.md) | 审计日志、API 版本化、安全头（CSP/COOP/CORP） |

## 六、扩展机制

| 文档 | 内容 |
| --- | --- |
| [DI 与服务提供者](di.md) | 容器、singleton / bind / alias、ServiceProvider 启动钩子 |
| [AOP 切面](aop.md) | kode/aop 属性切面、前置/环绕通知、自动发现 |
| [插件](plugins.md) | PluginInterface + PluginManager，复用路由/事件/控制台 |
| [可观测性](observability.md) | 指标（Prometheus）+ 链路追踪（W3C traceparent）+ `/metrics` |
| [API 文档自动化](api-docs.md) | OpenAPI 3.0 生成 + `/docs` Swagger UI + `#[OpenApi]` |
| [健壮性设计](robustness.md) | 错误处理器防御、链路外层不可失败、.env 解析、CLI 优雅退出 |

## 七、生产与运维

| 文档 | 内容 |
| --- | --- |
| [部署到生产](deployment.md) | 进程管理器拉起、生产 `.env`、健康检查、多实例 |
| [测试](testing.md) | PHPUnit、安全/功能用例、离线隔离约定 |
| [性能基线存档](benchmarks.md) | v0.8.51 真机横比结论与压测方法（历史归档） |
| [1.x 生产化路线](roadmap-v1x.md) | 0.9→1.0 版本路线、横比规程、清理决策 |
| [kode 包问题清单](dev/kode-package-issues.md) | vendor 侧已知问题与修复状态（仅供包侧/维护者） |
| [kode/process 问题清单](dev/kode-process-issues.md) | process 运行时缺陷修复记录（已合入 5.2.36） |

## 一句话定位

- **薄核 + 接线点**：框架只做「启动、容器、路由、统一响应、异常、中间件、韧性层」等地基，能力尽量复用 kode 生态包，不重复造轮子（与 webman / Hyperf 的「最小内核 + Composer 生态」理念一致）。
- **默认结构化错误**：异常自动转为 JSON，含出错文件/行号与调用链，便于直接定位源码；生产环境自动收敛细节。
- **少写样板**：短方法取参（`input / query / post / param`）、统一响应助手、门面与全局助手，业务代码保持简洁。