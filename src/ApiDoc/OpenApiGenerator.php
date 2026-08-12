<?php

declare(strict_types=1);

namespace Kode\Framework\ApiDoc;

use Kode\Framework\ApiDoc\Attributes\OpenApi;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\App;
use Kode\Http\Routing\Route;

/**
 * OpenAPI 3.0 生成器（框架本地薄实现）。
 *
 * 扫描 {@see App} 中已注册的全部路由，自动产出基础 OpenAPI 3.0 spec：
 *
 *  - info：来自 config/apidoc.php（title / version / description / contact）；
 *  - servers：可选；
 *  - paths：按路由路径聚合，方法 → operation；
 *  - operationId：命名路由优先，否则 `{method}_{slug}`；
 *  - parameters：路径参数（kode/http 的 `{id}` 语法与 OpenAPI 兼容）。
 *
 * 控制器方法上的 {@see OpenApi} 属性用于补充 summary / description / tags /
 * requestBody / responses / deprecated（由 RouteRegistry 在扫描期登记）。
 *
 * 本类不重新实现路由/属性机制，仅消费 kode/http 与 kode/attributes 的产物。
 */
final class OpenApiGenerator
{
    /** 跳过自动生成的辅助方法（GET 路由已隐含 HEAD / OPTIONS 由框架托管） */
    private const SKIP_METHODS = ['HEAD', 'OPTIONS'];

    /**
     * @param array<string, mixed> $config config/apidoc.php
     */
    public function __construct(
        private readonly App $app,
        private readonly RouteRegistry $registry,
        private readonly array $config = [],
    ) {
    }

    /**
     * 生成完整 OpenAPI 3.0 spec 数组。
     *
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $paths = [];

        foreach ($this->app->getRouter()->getRoutes() as $route) {
            $this->appendRoute($paths, $route);
        }

        // 仅保留有操作的路径
        $paths = array_filter($paths, static fn(array $methods): bool => $methods !== []);

        return [
            'openapi' => '3.0.3',
            'info' => $this->buildInfo(),
            'servers' => $this->buildServers(),
            'paths' => $paths,
        ];
    }

    /**
     * 生成格式化的 JSON（供 /docs/openapi.json 直接返回）。
     */
    public function toJson(): string
    {
        return json_encode(
            $this->generate(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * 将单条路由并入 paths 树。
     *
     * @param array<string, mixed> $paths
     */
    private function appendRoute(array &$paths, Route $route): void
    {
        $path = $this->normalizePath($route->getPattern());
        $params = $this->pathParameters($route->getPattern(), $route->getParameters());
        $meta = $this->registry->openApiOf($route);

        if (!isset($paths[$path])) {
            $paths[$path] = [];
        }

        foreach ($route->getMethods() as $method) {
            if (in_array($method, self::SKIP_METHODS, true)) {
                continue;
            }
            $httpMethod = strtolower($method);
            $paths[$path][$httpMethod] = $this->buildOperation($route, $method, $params, $meta);
        }
    }

    /**
     * 构建单个 operation 对象。
     *
     * @param list<array{name: string, in: string, required: bool, schema: array<string, string>}> $params
     * @param OpenApi|null $meta
     * @return array<string, mixed>
     */
    private function buildOperation(Route $route, string $method, array $params, ?OpenApi $meta): array
    {
        $operation = [
            'operationId' => $this->operationId($route, $method),
            'parameters' => $params,
        ];

        // 默认 200 响应（可被 #[OpenApi] 覆盖）
        if ($meta === null || $meta->responses === null) {
            $operation['responses'] = [
                200 => ['description' => 'OK'],
            ];
        }

        if ($meta !== null) {
            $operation = array_merge($operation, $meta->toOperationFragment());
        }

        return $operation;
    }

    /**
     * 生成 operationId。
     */
    private function operationId(Route $route, string $method): string
    {
        if ($route->getName() !== null) {
            return $route->getName();
        }

        $slug = trim(preg_replace('#[^a-zA-Z0-9]+#', '_', $route->getPattern()), '_');
        $slug = $slug === '' ? 'root' : $slug;

        return strtolower($method) . '_' . $slug;
    }

    /**
     * 将 kode/http 路径规范化为 OpenAPI 模板。
     *
     * kode/http 允许 `{id:\d+}`（带正则约束）与 `{id?}`（可选）两种写法；
     * OpenAPI 仅支持 `{id}` 形式，故：
     *  - 去掉约束（`:\d+`）；
     *  - 去掉可选标记（`?`，OpenAPI 用 required=false 表达可选）。
     */
    private function normalizePath(string $pattern): string
    {
        return preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\??\}#', '{$1}', $pattern) ?? $pattern;
    }

    /**
     * 从路径与已知参数名构建路径参数定义。
     *
     * @param list<string> $paramNames
     * @return list<array<string, mixed>>
     */
    private function pathParameters(string $pattern, array $paramNames): array
    {
        $result = [];

        foreach ($paramNames as $name) {
            $required = !str_contains($pattern, "{{$name}?}");
            $result[] = [
                'name' => $name,
                'in' => 'path',
                'required' => $required,
                'schema' => ['type' => 'string'],
            ];
        }

        return $result;
    }

    /**
     * 构建 info 对象。
     *
     * @return array<string, mixed>
     */
    private function buildInfo(): array
    {
        $info = [
            'title' => (string) ($this->config['title'] ?? 'API'),
            'version' => (string) ($this->config['version'] ?? '1.0.0'),
        ];

        if (!empty($this->config['description'])) {
            $info['description'] = (string) $this->config['description'];
        }
        if (!empty($this->config['contact'])) {
            $info['contact'] = (array) $this->config['contact'];
        }

        return $info;
    }

    /**
     * 构建 servers 对象（未配置则返回空数组，由客户端按当前 host 补全）。
     *
     * @return list<array<string, string>>
     */
    private function buildServers(): array
    {
        $servers = $this->config['servers'] ?? null;
        if (is_array($servers) && $servers !== []) {
            return array_values(array_map(static fn($s): array => (array) $s, $servers));
        }

        return [];
    }
}
