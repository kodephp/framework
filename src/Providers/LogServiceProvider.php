<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Framework\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * 日志服务提供者（Monolog，遵循 PSR-3）
 */
final class LogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(LoggerInterface::class, function (): LoggerInterface {
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('logging', []);

            return LoggerFactory::create($config);
        });

        // 'log' 作为 LoggerInterface 的别名，便于 resolve('log')。
        $this->container->alias('log', LoggerInterface::class);
    }
}
