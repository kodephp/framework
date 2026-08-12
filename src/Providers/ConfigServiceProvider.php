<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * 配置服务提供者
 *
 * 配置加载由 Application::loadConfig() 完成并绑定为 'config' 服务；
 * 此处做两件企业级启动保障：
 *  1) fail-fast：校验 config/app.required 列出的必填配置是否齐全，缺失即启动失败；
 *  2) 生产环境告警：debug 开启且 env=production 时记录告警（避免泄露调试信息）。
 */
final class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 配置已在 Application 启动阶段绑定，这里无需重复绑定。
    }

    public function boot(): void
    {
        $this->assertRequiredConfig();
        $this->warnDebugInProduction();
    }

    /**
     * 校验必填配置（config/app.required），缺失则启动即失败。
     *
     * @return void
     */
    private function assertRequiredConfig(): void
    {
        $required = $this->config('app.required', []);
        if (!is_array($required)) {
            return;
        }

        $missing = [];
        foreach ($required as $key) {
            $value = $this->config((string) $key);
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                '启动校验失败：以下必填配置缺失或为空 -> ' . implode(', ', $missing)
                . '（请在 .env / config 中补全，或调整 config/app.required）'
            );
        }
    }

    /**
     * 生产环境开启 debug 属安全隐患，记录告警（不阻断启动，便于就地修复）。
     *
     * @return void
     */
    private function warnDebugInProduction(): void
    {
        if ($this->config('app.debug', false) && $this->config('app.env') === 'production') {
            /** @var LoggerInterface $logger */
            $logger = $this->container->get(LoggerInterface::class);
            $logger->warning('app.debug 在生产环境为 true，存在信息泄露风险，请关闭');
        }
    }
}
