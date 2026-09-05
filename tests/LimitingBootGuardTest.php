<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\RouteRegistry;

/**
 * 限流关闭时跳过显式路由 #[RateLimit] 扫描（启动开销守卫）。
 *
 * scanExplicitRateLimits 会全路由表反射 + 构建限流器工厂；limiting.enabled=false
 * 时限流中间件本就不会挂载，登记结果无人消费，应直接跳过（与 feature/csrf/韧性
 * 各段守卫同范式）。
 *
 * 用启动期真实显式路由（[类, 方法] handler 经 app/routes/*.php glob 加载）
 * 端到端验证守卫，而非启动后手动注册（启动后注册的路由本就不会被扫描）。
 */
final class LimitingBootGuardTest extends TestCase
{
    /** 两次引导配置互斥（enabled=false/true），必须重建 CoreApp 单例防串扰。 */
    protected bool $independentApp = true;

    private const GUARD_FILE = __DIR__ . '/skeleton/app/routes/_guard_ratelimit.php';

    public function testExplicitRateLimitScanSkippedWhenLimitingDisabled(): void
    {
        $this->writeGuardRoute();
        try {
            $this->configOverrides = ['limiting' => ['enabled' => false]];
            $this->bootApp();

            $route = $this->guardRoute();

            $this->assertSame(
                [],
                resolve(RouteRegistry::class)->rateLimitsOf($route),
                '限流关闭时显式路由不应登记 #[RateLimit] 规则',
            );
        } finally {
            $this->removeGuardRoute();
        }
    }

    public function testExplicitRateLimitScanRunsWhenLimitingEnabled(): void
    {
        $this->writeGuardRoute();
        try {
            $this->configOverrides = ['limiting' => ['enabled' => true]];
            $this->bootApp();

            $route = $this->guardRoute();

            $this->assertNotSame(
                [],
                resolve(RouteRegistry::class)->rateLimitsOf($route),
                '限流开启时类级 #[RateLimit] 应被登记（守卫不得误杀）',
            );
        } finally {
            $this->removeGuardRoute();
        }
    }

    private function writeGuardRoute(): void
    {
        $this->removeGuardRoute();
        @mkdir(dirname(self::GUARD_FILE), 0777, true);
        file_put_contents(self::GUARD_FILE, <<<'PHP'
            <?php

            use Kode\Http\App;

            return function (App $app): void {
                $app->get('/_guard/ratelimit', [\app\http\controllers\ProductsController::class, 'index']);
            };
            PHP);
    }

    private function removeGuardRoute(): void
    {
        if (is_file(self::GUARD_FILE)) {
            unlink(self::GUARD_FILE);
        }
    }

    private function guardRoute(): object
    {
        $result = $this->app()->http()->getRouter()->match('GET', '/_guard/ratelimit');
        $this->assertTrue($result->isFound(), '守卫测试路由应已在启动期注册');
        $this->assertNotNull($result->route);

        return $result->route;
    }
}
