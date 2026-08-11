<?php

declare(strict_types=1);

namespace Kode\Framework\Console;

use Kode\Console\Command as BaseCommand;
use Kode\Console\Input;
use Kode\Console\Output;

/**
 * 框架控制台命令基类
 *
 * 在 kode/console 之上做了一层精简：
 *   - fire() 自动把 Input/Output 存到 $this->input / $this->output，再转调 handle()；
 *   - 子类只需实现 handle()，不再写 fire(Input $in, Output $out)；
 *   - 内置 arg()/flag()/opt() 与 info()/line()/warn()/error()/success() 快捷方法，
 *     不再需要 $this->input->argument() / $this->output->writeln() 这种繁琐写法。
 *
 * 用 #[AsCommand] 声明命令名即可：
 *   #[AsCommand(name: 'greet', description: '打招呼', usage: 'greet {name?} {--shout:bool}')]
 */
abstract class Command extends BaseCommand
{
    protected Input $input;

    protected Output $output;

    #[\Override]
    public function fire(Input $in, Output $out): int
    {
        $this->input = $in;
        $this->output = $out;

        return $this->handle();
    }

    /**
     * 命令逻辑入口（子类实现）。
     */
    abstract protected function handle(): int;

    // ---- 输入快捷 ----

    protected function arg(string|int $key, mixed $default = null): mixed
    {
        return $this->input->arg($key, $default);
    }

    protected function args(): array
    {
        return $this->input->args();
    }

    protected function flag(string $name, bool $default = false): bool
    {
        return $this->input->flag($name, $default);
    }

    protected function opt(string $name, mixed $default = null): mixed
    {
        return $this->input->opt($name, $default);
    }

    // ---- 输出快捷 ----

    protected function info(string $message): void
    {
        $this->output->info($message);
    }

    protected function line(string $message = ''): void
    {
        $this->output->line($message);
    }

    protected function comment(string $message): void
    {
        $this->output->comment($message);
    }

    protected function warn(string $message): void
    {
        $this->output->warn($message);
    }

    protected function error(string $message): void
    {
        $this->output->error($message);
    }

    protected function success(string $message): void
    {
        $this->output->success($message);
    }

    protected function table(array $headers, array $rows): void
    {
        $this->output->table($headers, $rows);
    }
}
