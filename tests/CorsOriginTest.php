<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

/**
 * CORS 单字符串来源归一化（M7）。
 *
 * kode/http 3.5 起，字符串与数组配置同权走白名单校验：请求 Origin 不在白名单时
 * 整体省略 CORS 头（绝不回显任意来源）。框架侧把非 '*' 单字符串转为单元素数组，
 * 保证白名单语义在任意 http 版本下一致。
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

        // 非白名单来源：省略而非回显，浏览器据此拦截跨源读取
        $this->assertSame('', $r->header('Access-Control-Allow-Origin'));
        $this->assertSame('', $r->header('Access-Control-Allow-Credentials'));
    }

    public function testStringOriginEchoesListedOrigin(): void
    {
        $this->bootCors('https://a.com');

        $r = $this->get('/ping', ['Origin' => 'https://a.com']);

        $this->assertSame('https://a.com', $r->header('Access-Control-Allow-Origin'));
    }
}
