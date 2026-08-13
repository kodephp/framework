<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Core\Config\Config;
use Kode\Framework\Feature\FeatureAttributeReader;
use Kode\Framework\Feature\FeatureManager;
use Kode\Framework\Feature\FeatureRegistry;

/**
 * Feature Flags 服务提供者（框架原生，无对应 kode 包）。
 *
 * 绑定：
 *  - FeatureRegistry    路由 → flag 登记表（每次请求由中间件查询）
 *  - FeatureManager     开关判定核心（enabled / rollout 灰度 / 动态 resolver）
 *  - 'feature'          别名，便于 resolve('feature')
 */
final class FeatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(FeatureRegistry::class, fn(): FeatureRegistry => new FeatureRegistry());

        $this->container->singleton(FeatureAttributeReader::class, fn(): FeatureAttributeReader => new FeatureAttributeReader());

        $this->container->singleton(FeatureManager::class, function (): FeatureManager {
            return new FeatureManager($this->container->make(Config::class));
        });

        $this->container->alias('feature', FeatureManager::class);
    }

    public function boot(): void
    {
    }
}
