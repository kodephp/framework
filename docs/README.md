# Kode Framework 开发文档

欢迎使用 **Kode Framework** —— 一个以 [kode](https://github.com/kodephp) 生态（core / runtime / di / router / http / console / aop / cache / event / jwt …）为基座、组合 Monolog / Symfony Validator 等成熟包的现代化 PHP API 框架。最低 PHP 8.3+，开箱即多进程服务。

## 推荐阅读顺序

1. 先读 **[入门指南](getting-started.md)**，照着敲一遍跑通第一个接口；
2. 再按下方「文档地图」按需取用各个主题；
3. 遇到概念拿不准，回到入门指南对应的小节。

## 文档地图

### 一、入门
| 文档 | 内容 |
| --- | --- |
| [入门指南](getting-started.md) | 环境 → 一句话安装（composer create-project）→ 第一个接口 → 请求/响应 → 校验 → 错误处理 → 运行与排错 |

### 二、路由与 HTTP
| 文档 | 内容 |
| --- | --- |
| [路由全解](routing.md) | 属性路由、多文件 routes/*.php、REST 资源、参数、分组、来源标签 |
| [请求对象](request.md) | input / query / post / param / header / 文件上传 |
| [响应对象](response.md) | json / error / redirect / noContent，统一输出约定 |
| [多进程 HTTP 服务](http-server.md) | serve 命令、worker、热重载、常驻进程 |

### 三、请求处理
| 文档 | 内容 |
| --- | --- |
| [自己写中间件](middleware.md) | PSR-15 管道、CORS / RequestId / SecurityHeaders、自定义中间件 |
| [参数校验](validation.md) | Symfony Validator、属性校验、失败映射 |
| [异常处理](errors.md) | 结构化错误、开发者友好错误页、生产收敛 |

### 四、安全
| 文档 | 内容 |
| --- | --- |
| [鉴权（JWT）](auth.md) | kode/jwt 门面、Guard、续期、黑名单（revoke/isBlacklisted） |
| [限流](rate-limit.md) | kode/limiting 多算法、属性、分布式 |
| [熔断](circuit-breaker.md) | kode/fibers CircuitBreaker 保护下游 |
| [跨域 CORS 与安全响应头](cors.md) | 预检、安全头中间件 |
| [安全与合规](security-compliance.md) | 安全响应头(CSP/COOP/CORP)、审计日志、API 版本化 |
| [可观测性](observability.md) | 指标(Prometheus) + 链路追踪(W3C traceparent) + /metrics |
| [API 文档自动化](api-docs.md) | OpenAPI 3.0 生成 + /docs Swagger UI + #[OpenApi] |
| [健壮性设计](robustness.md) | 错误处理器防御、链路外层不可失败、.env 解析、CLI 优雅退出、容器守卫 |

### 五、数据层
| 文档 | 内容 |
| --- | --- |
| [数据库](database.md) | kode/database 薄封装：Schema 门面、Model、迁移命令、标识符安全 |
| [缓存](cache.md) | kode/cache（PSR-16）文件 / Redis |
| [队列](queue.md) | kode/queue 内建 Worker、不可变消息 |
| [事件](events.md) | kode/event 派发 / 监听 / 订阅者 |
| [消息总线](messaging.md) | kode/messaging 长连接 / 实时协议 |
| [国际化](i18n.md) | 多模块多域（module::key）、symfony/translation |
| [日志](logging.md) | Monolog 通道、级别、上下文 |

### 六、扩展机制
| 文档 | 内容 |
| --- | --- |
| [DI 与服务提供者](di.md) | 容器、singleton / bind / alias、ServiceProvider 启动钩子 |
| [门面与全局助手](facades.md) | 实例式门面、resolve() / app() 等助手 |
| [AOP 切面](aop.md) | kode/aop 属性切面、环绕通知 |
| [插件](plugins.md) | PluginInterface + PluginManager，复用路由/事件/控制台 |
| [控制台命令](console.md) | 命令定义、参数/选项、bin/kode console |

### 七、异步与运维
| 文档 | 内容 |
| --- | --- |
| [定时任务](scheduling.md) | #[Cron] 属性扫描、常驻调度、集群模式、kode/scheduling 引擎 |
| [配置与环境变量](config.md) | config() 读取、.env、多环境 |
| [部署到生产](deployment.md) | 进程管理器拉起、生产 .env、健康检查 |
| [测试](testing.md) | PHPUnit、安全/功能用例、离线隔离约定 |

## 一句话定位

- **薄核 + 接线点**：框架只做「启动、容器、路由、统一响应、异常、中间件、韧性层」等地基，能力尽量复用 kode 生态包，不重复造轮子（与 webman / Hyperf 的「最小内核 + Composer 生态」理念一致）。
- **默认结构化错误**：异常自动转为 JSON，含出错文件/行号与调用链，便于直接定位源码；生产环境自动收敛细节。
- **少写样板**：短方法取参（`input/query/post/param`）、统一响应助手、门面与全局助手，业务代码保持简洁。

## 版本

- 当前版本：**[v0.8.7](https://github.com/kodephp/framework/releases)**
- 包名：`kode/framework`（Composer）
- 仓库：<https://github.com/kodephp/framework>
