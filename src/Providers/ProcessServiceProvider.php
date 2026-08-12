<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Process\ProcessManager;

/**
 * 常驻进程服务提供者。
 *
 * 绑定 Kode\Framework\Process\ProcessManager 为单例，并按 config/process.php 的
 * workers 列表自动注册 worker。门面 Process / 助手 process() 即可访问。
 */
final class ProcessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ProcessManager::class, function (): ProcessManager {
            $manager = new ProcessManager();
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('process', []);
            $manager->registerFromConfig($config);

            return $manager;
        });

        $this->container->alias('process.manager', ProcessManager::class);
    }
}
