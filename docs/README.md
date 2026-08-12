# Kode Framework 开发文档

欢迎使用 **Kode Framework** —— 一个以 [kode](https://github.com/kodephp) 生态（core/runtime/di/router/http/console/aop/cache/event/jwt…）为基座、组合 Monolog / Symfony Validator 等成熟包的现代化 PHP 全栈框架。最低 PHP 8.3+，开箱即多进程服务。

## 文档地图

| 文档 | 适合人群 | 内容 |
| --- | --- | --- |
| [入门指南](getting-started.md) | 小白 / 初级 | 三步跑起来第一个接口、统一响应、收参、校验 |
| [高级用法](advanced.md) | 进阶 / 高级 | 路由分组嵌套、中间件、别名、异常映射、DI/门面、JWT、韧性层、插件、部署 |

> 建议顺序：先读完「入门指南」照着敲一遍，再回看「高级用法」按需取用。

## 一句话定位

- **地基思维**：框架只做「薄核 + 接线点」，能力尽量复用 kode 生态包，不重复造轮子。
- **开发者友好**：统一响应信封 `{code, msg, data}`、异常自动转信封、短方法取参，让你少写样板代码。
- **企业级默认**：多进程 HTTP 服务、全局安全头、统一异常处理、限流/熔断/国际化均已接线。

## 版本

- 当前版本：**[v0.7.0](https://github.com/kodephp/framework/releases)**
- 包名：`kode/framework`（Composer）
- 仓库：<https://github.com/kodephp/framework>
