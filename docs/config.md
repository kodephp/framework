# 配置与环境变量

框架采用「PHP 配置文件 + `.env` 环境变量」双层配置：`config/*.php` 放带默认值的结构配置，`.env` 放机器级差异（密钥、连接串、开关）。

## 读取配置

点号访问嵌套：

```php
config('app.debug', false);            // config/app.php 的 debug，缺省 false
config('jwt.guards.api.ttl');          // 嵌套数组用点号
config('database.connections.mysql.host');
```

`config()` 助手全局可用（控制器、服务、命令、定时任务里都能用）；需要返回整个 `Config` 实例时 `config()` 无参调用，容器内可用 `app()->config('app.name')`。

## 写配置

- `config/*.php` 每个主题一个文件（启动时全部合并为一个大数组，`config('文件夹名.键')` 读取）。
- 配置文件内部用 `env('KEY', 'default')` 读环境变量，`.env` 文件覆盖默认值。

```dotenv
# .env（生成自 .env.example，不入库）
APP_DEBUG=false          # 生产默认关闭（开发期改 true 以显示错误详情）
APP_NAME=kode-app
APP_ENV=local            # local | testing | production
JWT_SECRET=change-me
RATE_LIMIT_DRIVER=memory
DB_HOST=127.0.0.1
DB_DATABASE=kode
```

> **`.env` 不提交**（`.gitignore` 已忽略），新成员克隆项目后执行 `kode init` 从 `.env.example` 生成。

## APP_DEBUG 与错误详情

| `APP_DEBUG` | 错误响应行为 |
| --- | --- |
| `false`（**默认**） | 生产收敛：不泄露绝对路径与系统异常细节，统一返回 `config/http.php` 的 `production_message`（默认「系统繁忙，请稍后重试」），细节只写日志 |
| `true` | 响应含 `location`（出错文件/行/方法）与 `chain`（调用链），直接定位源码 |

> 建议：本地开发设 `true`；压测与生产保持 `false`（省去错误详情组装，吞吐更高）。

## 多环境

框架环境由 `APP_ENV` 决定（`config/app.php` 的 `'env' => env('APP_ENV', 'local')`），业务可经 `app()->environment('production')` 判断。

`.env` 加载机制（`src/Support/EnvLoader.php`）只读项目根 `.env`，且**幂等**：同名键若已被真实环境变量注入则跳过（12-factor：**真实环境变量优先级高于 .env 文件**）。因此多环境推荐：

- **部署环境差异用真实环境变量注入**（容器/进程管理器里 `export APP_ENV=production DB_HOST=...`），无需多份 .env；
- 本地需要多套配置时按环境轮换/生成根 `.env` 文件（如 CI 里 `cp .env.example .env && sed 填充`），框架不做 `--env` 参数切换。

> `.env` 不提交（`.gitignore` 已忽略），新成员克隆项目后执行 `kode init` 从 `.env.example` 生成。

## 文件组织（37 个主题文件）

| 领域 | 文件 |
| --- | --- |
| 应用 | `app.php`、`server.php`、`services.php` |
| HTTP | `http.php`、`routes.php`、`api.php`、`apidoc.php` |
| 数据 | `database.php`、`cache.php`、`queue.php`、`snowflake.php` |
| 安全 | `jwt.php`、`csrf.php`、`cors.php`、`security.php`、`audit.php` |
| 韧性 | `limiting.php`、`resilience.php`、`lock.php`、`idempotency.php` |
| 扩展 | `aop.php`、`event.php`、`plugins.php`、`console.php`、`schedule.php`、`process.php`、`feature.php` |
| 异步 | `messaging.php`、`parallel.php`、`session.php` |
| 可观测 | `observability.php`、`logging.php`、`health.php`、`center.php` |
| 其他 | `locale.php`、`tenant.php`、`http-client.php` |

> 配置合并历史：原 `config/response.php`（error_keys、production_message）已并入 `config/http.php`（v0.8.51）；如业务代码仍调 `config('response.*')`，请改为 `config('http.*')` 对应键。

> `server.php` 含本轮增强键：`graceful_shutdown_timeout`（优雅停机宽限，默认 30s，见 [lifecycle](lifecycle.md)）与 `watch.dirs` / `watch.exclude`（`serve --watch` 热重载监听，见 [http-server](http-server.md)）；`process.php` 含声明式进程增强（`count` / `interval` / `once` / `slots`，见 [process](process.md)）。

## 配置中心（center）

`config/center.php` 支持接入远端配置中心（远程下发覆盖本地）：

```php
'enabled' => false,
'sources' => [],   // 配置中心源，按需注册
```

默认关闭，无需配置即可工作。

## 路径类配置约定

配置期 `app()` 可能尚未就绪，路径类配置请写「相对项目根」的子路径（如 `'path' => 'storage/cache'`），框架会用真实 `path.base` 拼接成绝对路径，不要手写 `base_path()`。