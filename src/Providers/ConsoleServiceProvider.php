<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Console\Kernel;
use Kode\Framework\Console\Commands\RouteListCommand;
use Kode\Framework\Providers\ServiceProvider;

/**
 * 控制台服务提供者（kode/console）
 *
 * 构建 Kernel，注册 config 中声明的命令，并自动扫描 app/Console/Commands 目录。
 */
final class ConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Kernel::class, fn(): Kernel => new Kernel());
    }

    public function boot(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->container->get(Kernel::class);
        $kernel->catchExceptions(true);

        /** @var array<int, string> $commands */
        $commands = (array) $this->config('console.commands', []);
        if ($commands !== []) {
            $kernel->addMany($commands);
        }

        $dir = $this->config('path.base') . '/app/Console/Commands';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*Command.php') ?: [] as $file) {
                $class = 'App\\Console\\Commands\\' . basename($file, '.php');
                if (class_exists($class)) {
                    $kernel->add($class);
                }
            }
        }

        // 框架内置命令（与用户命令隔离，避免命名冲突时覆盖用户）。
        $kernel->add(RouteListCommand::class);
    }
}
