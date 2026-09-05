<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Validation\ValidationException;

/**
 * 裸模式（安全可关闭）：对标 webman 默认内核。
 *
 * `http.exception_middleware=false` + `http.connection_cleanup=false` 时，全局管道
 * 只剩 kode/http 默认的 JsonErrorHandlerMiddleware（dispatcher 裸栈），应用须仍能
 * 正常运行。实测锁定的行为对等性（2026-09-05）：
 *  - 默认栈内 handler 抛出的异常本就由内层 JsonError 先行捕获（E1500 极简 JSON），
 *    ExceptionMiddleware 的 422/结构化分支在默认栈中不可达——因此关闭它不改变
 *    任何 handler 异常的对外形态（本测试双档同断言锁定该对等性）；
 *  - 真正的行为差只在 ConnectionCleanup：泄漏事务不再自动回滚，auth 上下文不再
 *    自动清理（裸跑须由业务自行保证事务闭合，与 webman 默认内核一致）。
 */
final class BareModeTest extends TestCase
{
    /** 各用例配置互斥，必须重建 CoreApp 单例防串扰。 */
    protected bool $independentApp = true;

    /**
     * L0 全关覆写（复用 kode_test 压测口径）：除 kode/http 默认异常中间件外，
     * 其余全局中间件与两层安全全部关闭。
     *
     * @return array<string, mixed>
     */
    private function l0Overrides(): array
    {
        return [
            'http' => ['exception_middleware' => false, 'connection_cleanup' => false],
            'security' => ['request_id' => false, 'enabled' => false],
            'logging' => ['access_log' => ['enabled' => false]],
            'cors' => ['enabled' => false],
            'locale' => ['enabled' => false],
            'limiting' => ['enabled' => false],
            'session' => ['enabled' => false],
            'tenant' => ['enabled' => false, 'storage' => ['enabled' => false]],
            'feature' => ['enabled' => false],
            'csrf' => ['enabled' => false],
            'observability' => [
                'tracing' => ['enabled' => false],
                'metrics' => ['enabled' => false],
            ],
        ];
    }

    public function testBarePipelineHasOnlyDefaultMiddleware(): void
    {
        $this->configOverrides = $this->l0Overrides();
        $this->bootApp();

        $this->assertSame(
            1,
            $this->app()->http()->getDispatcher()->getRemainingCount(),
            '裸模式全局管道应只剩 kode/http 默认异常中间件',
        );

        $this->get('/ping')
            ->assertStatus(200)
            ->assertSee('pong');
    }

    public function testBareValidationExceptionFallsThroughTo500(): void
    {
        $this->configOverrides = $this->l0Overrides();
        $this->bootApp();

        $this->app()->http()->get('/_bare/invalid', static function (): never {
            throw new ValidationException([['field' => 'name', 'message' => 'required']]);
        });

        $r = $this->get('/_bare/invalid');
        $r->assertStatus(500);
        $this->assertStringContainsString('E1500', $r->body());
        $this->assertStringNotContainsString('chain', $r->body());
    }

    public function testDefaultStackHasIdenticalErrorShape(): void
    {
        // 默认双开关开启：内层 JsonError 先行捕获，handler 异常形态与裸模式一致
        //（守卫不得改变错误语义；结构化分支仅隔离单测覆盖）。
        $this->bootApp();

        $this->app()->http()->get('/_bare/invalid', static function (): never {
            throw new ValidationException([['field' => 'name', 'message' => 'required']]);
        });

        $r = $this->get('/_bare/invalid');
        $r->assertStatus(500);
        $this->assertStringContainsString('E1500', $r->body());
    }
}
