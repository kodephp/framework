<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Health\HealthChecked;
use Kode\Framework\Health\HealthChecker;

/**
 * 健康子系统服务提供者（薄壳层）。
 *
 * 始终接线：框架内置 /health、/health/live、/health/ready、/ping 端点与 health:check 命令
 * 依赖 HealthChecker 单例，故本 Provider 无条件绑定（不引入独立开关）。
 *
 * 绑定：
 *  - HealthChecker：聚合 db/cache/queue + 自定义闭包 + 自动能力感知探针（配置中心 / 服务发现 /
 *    追踪 / 租户存储）；注入事件派发闭包，使每次探测（HTTP 就绪探针 / CLI）后派发
 *    {@see HealthChecked}（依赖未就绪时可用于告警 / 指标）。
 */
final class HealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(HealthChecker::class, function (): HealthChecker {
            return new HealthChecker(
                (array) $this->config('health', []),
                $this->container,
                static fn (HealthChecked $event): object => event($event),
            );
        });
        $this->container->alias('health', HealthChecker::class);
    }
}
