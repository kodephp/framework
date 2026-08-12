<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Process\ProcessManager;
use Psr\Log\LoggerInterface;

/**
 * 常驻进程服务提供者。
 *
 * 绑定 Kode\Framework\Process\ProcessManager 为单例，并按 config/process.php 的
 * workers 列表自动注册 worker。底层真正的多进程运行委托给 kode/process 的 Daemon。
 * 门面 Process / 助手 process() 即可访问。
 */
final class ProcessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ProcessManager::class, function (): ProcessManager {
            $manager = new ProcessManager();

            // 若日志已就绪，注入以便 Daemon 的监督/重生日志落到框架日志。
            try {
                $manager->setLogger($this->container->get(LoggerInterface::class));
            } catch (\Throwable) {
                // 日志未就绪则用 NullLogger（单测 / 极早期）。
            }

            /** @var array<string, mixed> $config */
            $config = (array) $this->config('process', []);
            $manager->registerFromConfig($config);

            return $manager;
        });

        $this->container->alias('process.manager', ProcessManager::class);
    }
}
