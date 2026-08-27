# 控制台命令

**存放约定**：自定义命令放到 `app/console/` 下（单层目录，无需再建 `Commands` 子目录），命名空间为 `app\console\`，类名以 `Command` 结尾——框架启动时自动扫描注册（`ConsoleServiceProvider` 扫描 `app/console/*Command.php`）。

继承 `Kode\Framework\Console\Command`，用 `#[AsCommand]` 声明；一个命令一个类（kode/console 限制）：

```php
use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;

#[AsCommand(name: 'greet', description: '打招呼', usage: 'greet {name?} {--shout:bool}')]
final class GreetCommand extends Command
{
    protected function handle(): int
    {
        $name = $this->arg('name') ?? 'World';
        $this->info($this->flag('shout') ? strtoupper("Hello, {$name}!") : "Hello, {$name}!");
        return 0;
    }
}
```

内置方法：`arg()` / `flag()` / `opt()` / `info()` / `line()` / `warn()` / `error()` / `success()` / `table()`。运行：`php bin/kode console greet Kode --shout`（或简写 `php bin/kode greet`——用户命令自动走控制台内核）。

## 内置命令

`bin/kode` 一级命令一览（详见 `php bin/kode help`）：

| 命令 | 作用 |
| --- | --- |
| `new <path> [--install]` | 从框架骨架生成独立项目 |
| `init` | 生成 `.env` + `storage/` |
| `serve [--host] [--port] [--workers] [--watch]` | 启动多进程 HTTP 服务（见 [http-server](http-server.md)） |
| `cron` | 定时任务调度器（按 `config/cron.php` 触发 `schedule:*` 任务） |
| `schedule:list` | 列出已注册的定时任务 |
| `console <args...>` | 透传执行任意 `#[AsCommand]` 命令 |
| `migrate` / `migrate:rollback` / `migrate:reset` | 迁移 |
| `db:seed [--class=]` | 数据填充 |
| `make:controller` / `make:model` / `make:migration` / `make:middleware` / `make:command` | 脚手架 |
| `route:list` / `process:list` / `process:check` | 诊断 |

其余内置命令（渲染为 `#[AsCommand]`）用 `php bin/kode console <name>` 或简写 `php bin/kode <name>` 运行：

| 分组 | 命令 | 说明 |
| --- | --- | --- |
| 路由 | `route:list [--compact] [--group=] [--method=] [--source=] [--rate-limit] [--columns=]` | 列路由；`--source=app:admin` 按多应用标签过滤（见 [routing](routing.md) 第 2 章），`--rate-limit` 只看限流路由 |
| 调度 | `schedule:list` / `schedule:run` / `schedule:work [--interval=60]` | `run` 单轮（crontab 触发）；`work` 常驻轮询，秒级任务更及时——**生产二选一，别两个都挂** |
| 队列 | `queue:work [--queue=mail,default] [--tries=3] [--once] [--memory=128] ...` | 常驻消费守护；`--once` 跑空即退（CI / 补数）。处理来自 `config/queue.php` 的 `workers` 与 `#[AsJob]` 自动发现 |
| 后台进程 | `process:start` / `process:list` / `process:check` | 按 `config/process.php` 声明式启动常驻进程（真正 fork 各 worker，需 ext-pcntl + ext-posix，见 [process](process.md)） |
| 消息 | `messaging:consume` | 消费消息中间件订阅 |
| 健康 | `health:check [--ready] [--json]` | 命令行健康自检（同 `/health` 探针逻辑） |
| 中心配置 | `config:center:reload` | 远程配置中心变更后应用侧重载生效 |
| API 文档 | `apidoc:generate` | 生成 OpenAPI 规格（默认关闭，见 [api-docs](api-docs.md)） |
| 锁 / 幂等 | `lock:list` / `idempotency:list` / `idempotency:forget` | 分布式锁与幂等键诊断 |
| 租户 | `tenant:storage:list` | 租户存储盘点 |
| 追踪 / 审计 | `tracing:flush` / `audit:recent` / `service:list` | 追踪缓冲落盘、审计记录、服务发现列表 |

#### 脚手架命令（make:*）

日常开发中用 `make:*` 一键生成标准骨架，默认写到当前项目根（`bin/kode` 始终在项目根执行）：

```bash
php bin/kode make:controller Product     # 生成 app/http/controllers/ProductController.php
php bin/kode make:model Product           # 生成 app/models/Product.php（表名推测 products）
php bin/kode make:migration create_products_table   # 生成 database/migrations/YYYY_mm_dd_HHiiii_create_products_table.php
php bin/kode make:middleware Auth         # 生成 app/http/middleware/AuthMiddleware.php
php bin/kode make:command SendNewsletter  # 生成 app/console/SendNewsletterCommand.php（命令名 send_newsletter）
```

- 文件已存在时会跳过（提示用 `--force` 覆盖）：`php bin/kode make:controller Product --force`。
- 生成后用 `php bin/kode console <name>` 或简写 `php bin/kode <name>` 直接运行（用户命令自动走控制台内核）。

#### 数据库填充（db:seed）

```bash
php bin/kode db:seed                      # 运行 database/seeders/DatabaseSeeder
php bin/kode db:seed --class=UsersSeeder  # 运行指定 seeder
```

`bin/kode` 一级命令一览（详见 `php bin/kode help`）：

| 命令 | 作用 |
| --- | --- |
| `new <path> [--install]` | 从框架骨架生成独立项目 |
| `init` | 生成 `.env` + `storage/` |
| `serve [--host] [--port] [--workers] [--watch]` | 启动多进程 HTTP 服务 |
| `cron` | 定时任务调度器（按 `config/cron.php` 触发 `schedule:*` 任务） |
| `schedule:list` | 列出已注册的定时任务 |
| `migrate` / `migrate:rollback` / `migrate:reset` | 迁移 |
| `db:seed [--class=]` | 数据填充 |
| `make:controller` / `make:model` / `make:migration` / `make:middleware` / `make:command` | 脚手架 |
| `route:list` / `process:list` / `process:check` | 诊断 |

---

