<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Cache\CacheManager;

/**
 * 缓存配置归一化：config/cache.php 各 store 用 `driver` 键，
 * CacheManager 按 `type` 建驱动；Provider 须补齐，否则命名 store
 * 静默回退 file（曾导致跨 worker 热缓存全部 miss）。
 *
 * 注：不断言 redis store 实例化（kode/cache 1.0.0 的 RedisStore
 * 在当前 PHP 下有兼容问题，见 vendor；用 memory 驱动验证归一化逻辑）。
 */
final class CacheProviderTest extends TestCase
{
    public function testDriverKeyNormalizedToType(): void
    {
        $this->configOverrides = ['cache' => [
            'default' => 'file',
            'stores' => [
                'file' => ['driver' => 'file'],
                'local' => ['driver' => 'memory'],
            ],
        ]];
        $this->bootApp();

        $store = resolve(CacheManager::class)->store('local');

        $this->assertInstanceOf(\Kode\Cache\Store\MemoryStore::class, $store);
    }
}
