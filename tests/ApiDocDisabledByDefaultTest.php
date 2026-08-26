<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Testing\TestCase;

/**
 * apidoc 默认关闭验证：未显式开启时 /docs 与 /docs/openapi.json 均不挂载。
 *
 * 覆盖用户拍板项「apidoc 默认关」：config/apidoc.php enabled=false 时
 * ApiDocServiceProvider::boot() 直接返回，文档端点返回 404。
 */
final class ApiDocDisabledByDefaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 不设置 configOverrides：使用真实 config/apidoc.php（enabled=false）。
        // 要求独立进程级实例：与启用 apidoc 的测试类（ApiDocTest）配置意图冲突。
        $this->independentApp = true;
        $this->bootApp(getcwd());
    }

    public function testDocsJsonEndpointNotMountedWhenDisabled(): void
    {
        $this->get('/docs/openapi.json')->assertStatus(404);
    }

    public function testSwaggerUiEndpointNotMountedWhenDisabled(): void
    {
        $this->get('/docs')->assertStatus(404);
    }

    public function testApidocGeneratorStillResolvableWhenDisabled(): void
    {
        // 关闭只影响端点挂载；OpenApiGenerator 单例与 apidoc:generate 命令仍可用。
        $generator = resolve(\Kode\Framework\ApiDoc\OpenApiGenerator::class);
        $spec = $generator->generate();

        self::assertSame('3.0.3', $spec['openapi']);
        self::assertArrayHasKey('paths', $spec);
    }
}