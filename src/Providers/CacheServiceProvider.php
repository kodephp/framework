<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Cache\CacheManager;
use Kode\Framework\Providers\ServiceProvider;

/**
 * 缓存服务提供者（kode/cache）
 *
 * kode/cache 支持 file / redis / memcached / apcu / sqlite 等多种驱动，
 * 多后端、分布式锁、原子计数器等能力开箱即用。
 */
final class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(CacheManager::class, function (): CacheManager {
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('cache', []);

            $manager = new CacheManager();
            $manager->setConfig($config);

            if (isset($config['default'])) {
                $manager->setDefaultDriver((string) $config['default']);
            }

            return $manager;
        });

        $this->container->alias('cache', CacheManager::class);
    }
}
