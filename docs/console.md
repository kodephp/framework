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

---

