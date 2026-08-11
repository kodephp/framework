<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;

/**
 * 配置服务提供者
 *
 * 配置加载由 Application::loadConfig() 完成并绑定为 'config' 服务；
 * 此处保留为可扩展点（如需要合并环境变量或动态配置可在此处理）。
 */
final class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 配置已在 Application 启动阶段绑定，这里无需重复绑定。
    }
}
