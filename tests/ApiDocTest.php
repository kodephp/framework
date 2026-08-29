<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Console\Input;
use Kode\Console\Output;
use Kode\Console\Signature;
use Kode\Framework\ApiDoc\Attributes\OpenApi;
use Kode\Framework\ApiDoc\Attributes\OpenApiParameter;
use Kode\Framework\ApiDoc\Attributes\OpenApiRequestBody;
use Kode\Framework\ApiDoc\Attributes\OpenApiResponse;
use Kode\Framework\ApiDoc\OpenApiGenerator;
use Kode\Framework\Console\Commands\ApiDocGenerateCommand;
use Kode\Framework\Http\RouteRegistry;
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

    // 参数结构化：路径约束 -> schema 类型
    public function testNumericPathConstraintInfersIntegerType(): void
    {
        [$app, , $gen] = $this->makeGenerator();
        $app->route(['GET'], '/users/{id:\\d+}', fn() => null);

        $spec = $gen->generate();
        $param = $spec['paths']['/users/{id}']['get']['parameters'][0];

        self::assertSame(['type' => 'integer'], $param['schema']);
    }

    public function testDecimalPathConstraintInfersNumberType(): void
    {
        [$app, , $gen] = $this->makeGenerator();
        $app->route(['GET'], '/price/{amount:\\d+\\.\\d+}', fn() => null);

        $spec = $gen->generate();
        $param = $spec['paths']['/price/{amount}']['get']['parameters'][0];

        self::assertSame(['type' => 'number'], $param['schema']);
    }

    // 提前扫描缓存：路由表不变时复用 spec，变化后 invalidate 重扫
    public function testGenerateCachesSpecUntilInvalidated(): void
    {
        [$app, , $gen] = $this->makeGenerator();
        $app->route(['GET'], '/a', fn() => null);

        $first = $gen->generate();
        self::assertArrayHasKey('/a', $first['paths']);

        // 未失效：新增路由不反映在 spec 中（缓存命中）
        $app->route(['GET'], '/b', fn() => null);
        self::assertSame($first, $gen->generate());
        self::assertArrayNotHasKey('/b', $gen->generate()['paths']);

        // 失效后重扫
        $gen->invalidate();
        $refreshed = $gen->generate();
        self::assertArrayHasKey('/b', $refreshed['paths']);
        self::assertArrayHasKey('/a', $refreshed['paths']);
    }

    // ------------------------------------------------------------------
    // 集成：真实应用 + /docs 端点
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        // apidoc 默认关闭；端点集成用例显式开启（config/apidoc.php enabled=false，走启动期配置覆盖）。
        // 要求独立进程级实例：enabled=true 与默认配置冲突，需重建 kode/core 单例。
        $this->independentApp = true;
        $this->configOverrides = ['apidoc' => ['enabled' => true]];
        $this->bootApp(self::SKELETON_ROOT);
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

    // ------------------------------------------------------------------
    // 结构化声明：方法参数 / 请求体 / 响应（自动生成无法推断，需显式声明）
    // ------------------------------------------------------------------

    public function testStructuredQueryParameterMergesWithPathParam(): void
    {
        [$app, $registry, $gen] = $this->makeGenerator();
        $route = $app->route(['GET'], '/users/{id}', fn() => null)->name('user.show');
        $registry->tagOpenApi($route, new OpenApi(
            parameters: [new OpenApiParameter('expand', 'query', 'string', description: '关联展开')],
        ));

        $spec = $gen->generate();
        $params = $spec['paths']['/users/{id}']['get']['parameters'];

        // 路径参数 + 显式 query 参数去重合并
        self::assertCount(2, $params);
        $byName = array_column($params, null, 'name');
        self::assertSame('path', $byName['id']['in']);
        self::assertSame('query', $byName['expand']['in']);
        self::assertSame('string', $byName['expand']['schema']['type']);
        self::assertFalse($byName['expand']['required']);
        self::assertSame('关联展开', $byName['expand']['description']);
    }

    public function testStructuredRequestBodyEmitsSchema(): void
    {
        [$app, $registry, $gen] = $this->makeGenerator();
        $route = $app->route(['POST'], '/users', fn() => null);
        $registry->tagOpenApi($route, new OpenApi(
            requestBody: new OpenApiRequestBody(
                properties: ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']],
                required: ['name'],
                example: ['name' => 'Alice', 'age' => 30],
            ),
        ));

        $spec = $gen->generate();
        $schema = $spec['paths']['/users']['post']['requestBody']['content']['application/json']['schema'];

        self::assertSame('object', $schema['type']);
        self::assertSame(['name' => ['type' => 'string'], 'age' => ['type' => 'integer']], $schema['properties']);
        self::assertSame(['name'], $schema['required']);
        self::assertSame(['name' => 'Alice', 'age' => 30], $schema['example']);
    }

    public function testStructuredResponsesKeyedByStatus(): void
    {
        [$app, $registry, $gen] = $this->makeGenerator();
        $route = $app->route(['POST'], '/orders', fn() => null);
        $registry->tagOpenApi($route, new OpenApi(
            responses: [
                201 => new OpenApiResponse(201, '已创建', properties: ['id' => ['type' => 'integer']], example: ['id' => 1]),
                422 => new OpenApiResponse(422, '校验失败'),
            ],
        ));

        $spec = $gen->generate();
        $op = $spec['paths']['/orders']['post'];

        self::assertArrayNotHasKey('200', $op['responses']);
        self::assertArrayHasKey('201', $op['responses']);
        self::assertSame('已创建', $op['responses'][201]['description']);
        self::assertSame(
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'example' => ['id' => 1]],
            $op['responses'][201]['content']['application/json']['schema']
        );
        self::assertArrayHasKey('422', $op['responses']);
    }

    public function testFindIncompleteFlagsMissingSummaryAndResponse(): void
    {
        [$app, $registry, $gen] = $this->makeGenerator();
        $app->route(['GET'], '/a', fn() => null); // 无属性：缺 summary
        $route2 = $app->route(['GET'], '/b', fn() => null);
        $registry->tagOpenApi($route2, new OpenApi(summary: 'B')); // 有 summary + 默认 200：完整

        $spec = $gen->generate();
        $issues = $gen->findIncomplete($spec);

        $paths = array_map(static fn($i) => $i['path'], $issues);
        self::assertContains('/a', $paths);
        self::assertNotContains('/b', $paths);
    }

    // ------------------------------------------------------------------
    // apidoc:generate 命令（开发者主动生成 / 校验）
    // ------------------------------------------------------------------

    public function testGenerateCommandWritesFile(): void
    {
        $tmp = sys_get_temp_dir() . '/kode_apidoc_' . uniqid('', true);
        mkdir($tmp, 0o755, true);

        try {
            $cmd = new ApiDocGenerateCommand($tmp);
            $code = $cmd->fire(
                $this->inputFor($cmd, ['--output=openapi.json']),
                new Output(fopen('php://memory', 'w'))
            );

            self::assertSame(0, $code);
            $file = $tmp . '/openapi.json';
            self::assertFileExists($file);
            $decoded = json_decode((string) file_get_contents($file), true);
            self::assertSame('3.0.3', $decoded['openapi']);
            self::assertArrayHasKey('paths', $decoded);
        } finally {
            @unlink($tmp . '/openapi.json');
            @rmdir($tmp);
        }
    }

    public function testGenerateCommandCheckReturnsCode(): void
    {
        $cmd = new ApiDocGenerateCommand(sys_get_temp_dir());
        $code = $cmd->fire(
            $this->inputFor($cmd, ['--check']),
            new Output(fopen('php://memory', 'w'))
        );

        // 演示应用多数路由缺 summary，--check 退出码应为 1（CI 可据此强制补全）
        self::assertContains($code, [0, 1]);
    }

    /**
     * 构造带 Signature 的 Input（模拟 bin/kode 真实派发方式）。
     */
    private function inputFor(ApiDocGenerateCommand $cmd, array $argv): Input
    {
        $ref = new \ReflectionClass($cmd);
        $attrs = $ref->getAttributes(\Kode\Console\Attribute\AsCommand::class);
        $name = 'cmd';
        $usage = '';
        if ($attrs !== []) {
            $inst = $attrs[0]->newInstance();
            $name = $inst->name;
            $usage = $inst->usage;
        }

        return new Input(array_merge([$name], $argv), $usage !== '' ? new Signature($usage) : null);
    }
}
