<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\ApiDoc\Attributes\OpenApi;
use Kode\Framework\ApiDoc\OpenApiGenerator;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Testing\TestCase;
use Kode\Http\App;

/**
 * API 文档自动化测试：OpenApiGenerator 单元 + /docs 与 /docs/openapi.json 端点集成。
 */
final class ApiDocTest extends TestCase
{
    // ------------------------------------------------------------------
    // OpenApiGenerator 单元（确定性，自建 App + RouteRegistry）
    // ------------------------------------------------------------------

    private function makeGenerator(array $config = []): array
    {
        $app = new App();
        $registry = new RouteRegistry();

        return [$app, $registry, new OpenApiGenerator($app, $registry, $config)];
    }

    public function testGeneratesOpenApi303Skeleton(): void
    {
        [$app, , $gen] = $this->makeGenerator(['title' => 'Demo', 'version' => '2.1.0']);
        $app->route(['GET'], '/users/{id:\\d+}', fn() => null)->name('user.show');

        $spec = $gen->generate();

        self::assertSame('3.0.3', $spec['openapi']);
        self::assertSame('Demo', $spec['info']['title']);
        self::assertSame('2.1.0', $spec['info']['version']);
        self::assertArrayHasKey('/users/{id}', $spec['paths']);
        self::assertArrayHasKey('get', $spec['paths']['/users/{id}']);
        self::assertSame('user.show', $spec['paths']['/users/{id}']['get']['operationId']);
    }

    public function testPathParametersAreRequiredByDefault(): void
    {
        [, , $gen] = $this->makeGenerator();
        $app = new App();
        $app->route(['GET'], '/users/{id}', fn() => null);
        // 重新构造以使用同一 app
        $gen = new OpenApiGenerator($app, new RouteRegistry(), []);

        $spec = $gen->generate();
        $params = $spec['paths']['/users/{id}']['get']['parameters'];

        self::assertCount(1, $params);
        self::assertSame('id', $params[0]['name']);
        self::assertSame('path', $params[0]['in']);
        self::assertTrue($params[0]['required']);
        self::assertSame(['type' => 'string'], $params[0]['schema']);
    }

    public function testOptionalPathParamMarkedNotRequiredAndNormalized(): void
    {
        [$app, , $gen] = $this->makeGenerator();
        $app->route(['GET'], '/search/{page?}', fn() => null);

        $spec = $gen->generate();

        self::assertArrayHasKey('/search/{page}', $spec['paths']);
        $params = $spec['paths']['/search/{page}']['get']['parameters'];
        self::assertSame('page', $params[0]['name']);
        self::assertFalse($params[0]['required']);
    }

    public function testOpenApiAttributeMergesMetadata(): void
    {
        [$app, $registry, $gen] = $this->makeGenerator();
        $route = $app->route(['POST'], '/orders', fn() => null);

        $registry->tagOpenApi($route, new OpenApi(
            summary: '创建订单',
            tags: ['order'],
            requestBody: ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            responses: [201 => ['description' => '已创建']],
        ));

        $spec = $gen->generate();
        $op = $spec['paths']['/orders']['post'];

        self::assertSame('创建订单', $op['summary']);
        self::assertSame(['order'], $op['tags']);
        self::assertArrayHasKey('requestBody', $op);
        self::assertArrayHasKey('201', $op['responses']);
        self::assertArrayNotHasKey('200', $op['responses']);
    }

    public function testHeadAndOptionsAreSkipped(): void
    {
        [$app, , $gen] = $this->makeGenerator();
        $app->route(['GET'], '/x', fn() => null); // GET 自动附带 HEAD

        $spec = $gen->generate();
        $methods = array_keys($spec['paths']['/x']);

        self::assertSame(['get'], $methods);
    }

    public function testToJsonIsValidJson(): void
    {
        [, , $gen] = $this->makeGenerator();
        $app = new App();
        $app->route(['GET'], '/ping', fn() => null);
        $gen = new OpenApiGenerator($app, new RouteRegistry(), []);

        $json = $gen->toJson();
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertSame('3.0.3', $decoded['openapi']);
        self::assertStringContainsString('"openapi": "3.0.3"', $json);
    }

    // ------------------------------------------------------------------
    // 集成：真实应用 + /docs 端点
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(getcwd());
    }

    public function testOpenApiJsonEndpoint(): void
    {
        $res = $this->get('/docs/openapi.json');
        $res->assertStatus(200);

        $body = $res->body();
        self::assertStringContainsString('"openapi": "3.0.3"', $body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('paths', $decoded);
    }

    public function testSwaggerUiEndpoint(): void
    {
        $res = $this->get('/docs');
        $res->assertStatus(200);

        $body = $res->body();
        self::assertStringContainsString('swagger-ui', $body);
        self::assertStringContainsString('swagger-ui-bundle.js', $body);
        self::assertStringContainsString('/docs/openapi.json', $body);
    }

    public function testProductShowCarriesOpenApiAttribute(): void
    {
        $res = $this->get('/docs/openapi.json');
        $spec = json_decode($res->body(), true);

        self::assertArrayHasKey('/products/{id}', $spec['paths']);
        $op = $spec['paths']['/products/{id}']['get'];
        self::assertSame('获取商品详情', $op['summary']);
        self::assertSame(['product'], $op['tags']);
        self::assertArrayHasKey('404', $op['responses']);
    }
}
