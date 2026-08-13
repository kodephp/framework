<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Console\Kernel;
use Kode\Framework\Console\Commands\ApiDocGenerateCommand;
use Kode\Framework\Console\Commands\DbSeedCommand;
use Kode\Framework\Console\Commands\MakeCommandCommand;
use Kode\Framework\Console\Commands\MakeControllerCommand;
use Kode\Framework\Console\Commands\MakeMiddlewareCommand;
use Kode\Framework\Console\Commands\MakeMigrationCommand;
use Kode\Framework\Console\Commands\MakeModelCommand;
use Kode\Framework\Console\Commands\MessagingConsumeCommand;
use Kode\Framework\Console\Commands\MigrateCommand;
use Kode\Framework\Console\Commands\MigrateResetCommand;
use Kode\Framework\Console\Commands\MigrateRollbackCommand;
use Kode\Framework\Console\Commands\ProcessCheckCommand;
use Kode\Framework\Console\Commands\ProcessListCommand;
use Kode\Framework\Console\Commands\ProcessStartCommand;
use Kode\Framework\Console\Commands\QueueWorkCommand;
use Kode\Framework\Console\Commands\RouteListCommand;
use Kode\Framework\Console\Commands\ScheduleListCommand;
use Kode\Framework\Console\Commands\ScheduleRunCommand;
use Kode\Framework\Console\Commands\ScheduleWorkCommand;
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
        $kernel->add(ProcessListCommand::class);
        $kernel->add(ProcessCheckCommand::class);
        $kernel->add(ProcessStartCommand::class);
        $kernel->add(MigrateCommand::class);
        $kernel->add(MigrateRollbackCommand::class);
        $kernel->add(MigrateResetCommand::class);
        $kernel->add(MakeControllerCommand::class);
        $kernel->add(MakeModelCommand::class);
        $kernel->add(MakeMigrationCommand::class);
        $kernel->add(MakeMiddlewareCommand::class);
        $kernel->add(MakeCommandCommand::class);
        $kernel->add(DbSeedCommand::class);
        $kernel->add(ApiDocGenerateCommand::class);

        // 消费端命令（修复「框架最大坑」：此前只有生产者被绑定，没有任何运行消费进程的手段）
        $kernel->add(QueueWorkCommand::class);
        $kernel->add(MessagingConsumeCommand::class);

        // 调度命令（修复「调度不运行」：此前 ScheduleDispatcher 从未被实例化 / 接线）
        $kernel->add(ScheduleRunCommand::class);
        $kernel->add(ScheduleWorkCommand::class);
        $kernel->add(ScheduleListCommand::class);
    }
}
