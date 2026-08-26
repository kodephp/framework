<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\Concerns\GeneratesFiles;

/**
 * 生成自定义控制台命令。
 *
 *   bin/kode make:command SendNewsletter
 *   bin/kode make:command SendNewsletter --force
 */
#[AsCommand(
    name: 'make:command',
    description: '生成控制台命令（app/console）',
    usage: 'make:command {name} {--force}',
)]
final class MakeCommandCommand extends Command
{
    use GeneratesFiles;

    public function __construct(string $basePath = '')
    {
        parent::__construct();
        $this->basePath = $basePath;
    }

    protected function handle(): int
    {
        $raw = (string) $this->arg(0, '');
        if ($raw === '') {
            $this->error('请提供命令名：make:command SendNewsletter');

            return 1;
        }

        $class = $this->studly($raw);
        if (!str_ends_with($class, 'Command')) {
            $class .= 'Command';
        }

        // 命令名：去 Command 后缀后转 snake，下划线即控制台子命令分隔（send_newsletter）。
        $cmdName = $this->snake(preg_replace('/Command$/', '', $class));

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace app\console;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;

/**
 * {$class}（由 make:command 生成）
 */
#[AsCommand(
    name: '{$cmdName}',
    description: '由 make:command 生成的命令',
    usage: '{$cmdName}',
)]
final class {$class} extends Command
{
    protected function handle(): int
    {
        \$this->info('Hello from {$cmdName}');

        return 0;
    }
}

PHP;

        $path = $this->path('app/console/' . $class . '.php');
        if (!$this->writeFile($path, $content, $this->flag('force', false))) {
            $this->warn("已存在，跳过（用 --force 覆盖）：{$path}");

            return 0;
        }

        $this->success("已生成命令：{$path}（运行：bin/kode {$cmdName}）");

        return 0;
    }
}
