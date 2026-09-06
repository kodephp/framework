<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

/**
 * CORS 单字符串来源归一化（M7）。
 *
 * vendor CorsMiddleware 仅对数组做白名单校验；框架侧把非 '*' 单字符串转为
 * 单元素数组，确保任意请求 Origin 不会被回显。
 */
final class CorsOriginTest extends TestCase
{
    /** 与 TestCase 默认引导隔离（覆盖 cors 整段配置）。 */
    protected bool $independentApp = true;

    private function bootCors(string $allowedOrigins): void
    {
        $this->configOverrides = ['cors' => [
            'enabled' => true,
            'allowed_origins' => $allowedOrigins,
        ]];
        $this->bootApp();
    }

    public function testStringOriginDoesNotReflectArbitraryOrigin(): void
    {
        $this->bootCors('https://a.com');

        $r = $this->get('/ping', ['Origin' => 'https://evil.com']);

        $this->assertSame('https://a.com', $r->header('Access-Control-Allow-Origin'));
    }

    public function testStringOriginEchoesListedOrigin(): void
    {
        $this->bootCors('https://a.com');

        $r = $this->get('/ping', ['Origin' => 'https://a.com']);

        $this->assertSame('https://a.com', $r->header('Access-Control-Allow-Origin'));
    }
}
