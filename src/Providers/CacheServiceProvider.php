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

            // 配置归一化：config/cache.php 各 store 用 `driver` 键，而 CacheManager
            // 按 `type` 建驱动；缺 `type` 时回退 file，导致命名 redis store 永远不生效。
            // 此处补齐，未声明的保持原样。
            if (isset($config['stores']) && is_array($config['stores'])) {
                foreach ($config['stores'] as $name => $store) {
                    if (is_array($store) && !isset($store['type']) && isset($store['driver'])) {
                        $config['stores'][$name]['type'] = $store['driver'];
                    }
                }
            }

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
