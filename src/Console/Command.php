<?php

declare(strict_types=1);

namespace Kode\Framework\Console;

use Kode\Console\Command as BaseCommand;
use Kode\Console\Input;
use Kode\Console\Kernel;
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

    /**
     * 用户传了、但本命令不认识的选项 / 标志。
     *
     * 为什么要命令自己问一遍：console 对不认识的名字一律照收（记进 flags/options）却不执行，
     * 于是 `kode migrate:reset --pretend` 会「以为传了 dry-run、实际把全库回滚了」，
     * 退出码还是 0。写库命令上这类误会最贵，报错并返回非 0 是更便宜的做法。
     *
     * 内核的全局标志（`-v`、`--no-ansi` 等）由 Kernel 自己消费，但同样躺在 Input 里，
     * 名单取自 Kode\Console\Kernel::globalFlagNames()（单点，命令侧不抄表）。
     *
     * 比对严格按字面名字：console 的 resolveName() 只做「短别名 → 长名」，
     * 不做下划线/连字符互换 —— `--dry_run` 与 `--dry-run` 是两个键，命令读不到前者。
     * 这里若替用户"顺手容错"，就等于把「传了个读不到的拼法、命令照原样执行」再次放行。
     *
     * @param list<string> $known 本命令认识的选项名
     *
     * @return list<string> 未知选项，元素形如 `--foo`
     */
    protected function unknownOptions(array $known): array
    {
        $allow = [];
        foreach ($known as $name) {
            $allow[$name] = true;
        }
        foreach (Kernel::globalFlagNames() as $name) {
            $allow[$name] = true;
        }

        $given = array_merge(
            array_keys($this->input->options()),
            array_keys($this->input->flags())
        );

        $unknown = [];
        foreach (array_unique($given) as $name) {
            if (!isset($allow[$name])) {
                $unknown[] = '--' . $name;
            }
        }

        return $unknown;
    }

    /**
     * 有未知选项时报错并给出本命令的用法行，返回退出码 1；没有则返回 null。
     *
     * ```php
     * if (($bad = $this->rejectUnknownOptions(['step', 'pretend'])) !== null) {
     *     return $bad;
     * }
     * ```
     *
     * 唯一的例外是 `--help` / `-h`：按惯例它们是「问怎么写」，绝不该被执行，
     * 而 console 的内核只在命令名那个位置认这两个 token（`kode -h migrate`），
     * 落到命令里就会变成「未知选项 → 静默忽略 → 迁移照跑」。这里就地出帮助页并返回 0。
     *
     * @param list<string> $known 本命令认识的选项名
     */
    protected function rejectUnknownOptions(array $known): ?int
    {
        $unknown = $this->unknownOptions($known);
        if ($unknown === []) {
            return null;
        }

        if (array_intersect($unknown, ['--help', '--h']) !== []) {
            $this->showHelp($this->input, $this->output);

            return 0;
        }

        $this->warn('未识别的选项: ' . implode(', ', $unknown));
        // 用法行直接取自命令自己的 usage：与 `kode help <cmd>` 同源，不会两处说法不一致
        $this->line('  本命令支持的用法: ' . $this->usage);

        return 1;
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
