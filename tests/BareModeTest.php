<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Validation\ValidationException;

/**
 * 裸模式（安全可关闭）：对标 webman 默认内核。
 *
 * `http.exception_middleware=false` + `http.connection_cleanup=false` 时，全局管道
 * 只剩 kode/http 默认的 JsonErrorHandlerMiddleware（dispatcher 裸栈），应用须仍能
 * 正常运行。v1.3.4 起默认栈摘除了内层 JsonError（否则它先行捕获一切异常，框架
 * ExceptionMiddleware 恒不可达），因此默认档与裸档的错误形态真实分叉：
 *  - 默认档：ValidationException → 422（含字段明细）；普通异常 → 结构化 JSON；
 *  - 裸档：一切 handler 异常 → 500 E1500 极简 JSON（kode/http 默认行为）。
 * 关闭 connection_cleanup 的代价不变：泄漏事务不再自动回滚，auth 上下文不再
 * 自动清理，裸跑须由业务自行保证事务闭合（与 webman 默认内核一致）。
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

    public function testDefaultStackReturnsStructured422(): void
    {
        // 默认双开关开启：内层 JsonError 已摘除，ValidationException 真正走框架
        // 结构化路径（422 + 字段明细），与裸档的 500 E1500 分叉。
        $this->bootApp();

        $this->app()->http()->get('/_bare/invalid', static function (): never {
            throw new ValidationException([['field' => 'name', 'message' => 'required']]);
        });

        $r = $this->get('/_bare/invalid');
        $r->assertStatus(422);
        $this->assertStringContainsString('name', $r->body());
        $this->assertStringNotContainsString('E1500', $r->body());
    }

    public function testDefaultStackRuntimeExceptionIsStructured(): void
    {
        // 普通异常默认档走 ExceptionManager 结构化（不再是 E1500 极简体）。
        $this->bootApp();

        $this->app()->http()->get('/_bare/boom', static function (): never {
            throw new \RuntimeException('bare-boom-marker');
        });

        $r = $this->get('/_bare/boom');
        $r->assertStatus(500);
        $this->assertStringNotContainsString('E1500', $r->body());
    }
}
