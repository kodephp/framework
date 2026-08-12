# Kode Framework 开发文档

欢迎使用 **Kode Framework** —— 一个以 [kode](https://github.com/kodephp) 生态（core / runtime / di / router / http / console / aop / cache / event / jwt …）为基座、组合 Monolog / Symfony Validator 等成熟包的现代化 PHP API 框架。最低 PHP 8.3+，开箱即多进程服务。

## 文档地图

| 文档 | 适合谁 | 内容 |
| --- | --- | --- |
| [入门指南](getting-started.md) | 第一次用 | 环境 → 三步跑起来 → 第一个接口 → 请求/响应 → 校验 → 错误处理 → 运行与排错 |
| [进阶用法](advanced.md) | 要落地业务 | 路由全解、写中间件、鉴权、限流、熔断、定时任务、多进程、缓存/队列/数据库/事件/HTTP、配置、日志、门面与助手、控制台、DI 与服务提供者、AOP、插件、部署、测试 |

> 建议顺序：先读完「入门指南」照着敲一遍跑通第一个接口，再回看「进阶用法」按需取用。

## 一句话定位

- **薄核 + 接线点**：框架只做「启动、容器、路由、统一响应、异常、中间件、韧性层」等地基，能力尽量复用 kode 生态包，不重复造轮子（与 webman / Hyperf 的「最小内核 + Composer 生态」理念一致）。
- **默认结构化错误**：异常自动转为 JSON，含出错文件/行号与调用链，便于直接定位源码；生产环境自动收敛细节。
- **少写样板**：短方法取参（`input/query/post/param`）、统一响应助手、门面与全局助手，业务代码保持简洁。

## 版本

- 当前版本：**[v0.7.4](https://github.com/kodephp/framework/releases)**
- 包名：`kode/framework`（Composer）
- 仓库：<https://github.com/kodephp/framework>
