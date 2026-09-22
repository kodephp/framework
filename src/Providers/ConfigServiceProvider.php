<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * 配置服务提供者
 *
 * 配置加载由 Application::loadConfig() 完成并绑定为 'config' 服务；
 * 此处做三件事：
 *  1) 应用 config/app.timezone（PHP 侧默认时区，此前无人读取）；
 *  2) fail-fast：校验 config/app.required 列出的必填配置是否齐全，缺失即启动失败；
 *  3) 生产环境告警：debug 开启且 env=production 时记录告警（避免泄露调试信息）。
 */
final class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 配置已在 Application 启动阶段绑定，这里无需重复绑定。
    }

    public function boot(): void
    {
        $this->applyTimezone();
        $this->assertRequiredConfig();
        $this->warnDebugInProduction();
    }

    /**
     * 把 config/app.timezone 落成 PHP 默认时区。
     *
     * 未接线前 date()/日志时间戳/无显式时区的 DateTimeImmutable 恒按 PHP 内置时区
     * （CLI 通常 UTC），而配置里写着 Asia/Shanghai——「配置说东八区、日志是 UTC」即由此而来。
     * 非法时区只告警不阻断启动（保留原时区），避免一个拼写错误让整站起不来。
     */
    private function applyTimezone(): void
    {
        $timezone = trim((string) $this->config('app.timezone', ''));
        if ($timezone === '') {
            return;
        }

        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            error_log('[kode] app.timezone 不是合法时区，已忽略：' . $timezone);

            return;
        }

        date_default_timezone_set($timezone);
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
        // 生产级安全头告警（H6）：secure headers 默认关闭易裸奔。
        if ($this->config('app.env') === 'production' && empty($this->config('security.enabled', false))) {
            try {
                /** @var LoggerInterface $lg2 */
                $lg2 = $this->container->get(LoggerInterface::class);
                $lg2->warning('security.enabled 在生产环境为 false，安全响应头未下发（HSTS/XFO 等），建议置 SECURITY_HEADERS_ENABLED=true。');
            } catch (\Throwable) {
            }
        }
    }
}
