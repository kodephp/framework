# 控制台命令

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

内置方法：`arg()` / `flag()` / `opt()` / `info()` / `line()` / `warn()` / `error()` / `success()` / `table()`。运行：`php bin/kode console greet Kode --shout`。

框架内置：`route:list`、`schedule:list`、`cron`（以及 `new` / `serve` 这类 `bin/kode` 一级命令）。

#### 脚手架命令（make:*）

日常开发中用 `make:*` 一键生成标准骨架，默认写到当前项目根（`bin/kode` 始终在项目根执行）：

```bash
php bin/kode make:controller Product     # 生成 app/Http/Controllers/ProductController.php
php bin/kode make:model Product           # 生成 app/Models/Product.php（表名推测 products）
php bin/kode make:migration create_products_table   # 生成 database/migrations/YYYY_mm_dd_HHiiii_create_products_table.php
php bin/kode make:middleware Auth         # 生成 app/Http/Middleware/AuthMiddleware.php
php bin/kode make:command SendNewsletter  # 生成 app/Console/Commands/SendNewsletterCommand.php（命令名 send_newsletter）
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
| `cron` / `schedule:list` | 定时任务调度 |
| `migrate` / `migrate:rollback` / `migrate:reset` | 迁移 |
| `db:seed [--class=]` | 数据填充 |
| `make:controller` / `make:model` / `make:migration` / `make:middleware` / `make:command` | 脚手架 |
| `route:list` / `process:list` / `process:check` | 诊断 |
| `console <args...>` | 透传执行任意 `#[AsCommand]` 命令 |

---

