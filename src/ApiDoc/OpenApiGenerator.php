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

    /** 生成的 spec 缓存：路由表不变时复用，避免每个 /docs/openapi.json 请求重复遍历/反射。 */
    private ?array $specCache = null;

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
     * 结果按「提前扫描缓存」策略缓存：路由表在启动期一次性注册完毕（含
     * #[OpenApi] 补充片段与参数，均于扫描期登记），故后续生成直接复用首份 spec。
     * 运行期动态注册路由时调用 {@see invalidate()} 使缓存失效。
     *
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        if ($this->specCache !== null) {
            return $this->specCache;
        }

        $paths = [];

        foreach ($this->app->getRouter()->getRoutes() as $route) {
            $this->appendRoute($paths, $route);
        }

        // 仅保留有操作的路径
        $paths = array_filter($paths, static fn(array $methods): bool => $methods !== []);

        return $this->specCache = [
            'openapi' => '3.0.3',
            'info' => $this->buildInfo(),
            'servers' => $this->buildServers(),
            'paths' => $paths,
        ];
    }

    /**
     * 使 spec 缓存失效（运行期动态注册/移除路由后调用）。
     */
    public function invalidate(): void
    {
        $this->specCache = null;
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
     * 找出「文档不完整」的操作，供 `apidoc:generate --check` 在 CI 中强制补全。
     *
     * 判定标准：
     *  - 既无 summary 也无 description（操作不可读）；
     *  - 未声明 200 响应（响应结构缺失）。
     *
     * @param array<string, mixed> $spec generate() 的产物
     * @return list<array{path: string, method: string, reasons: list<string>}>
     */
    public function findIncomplete(array $spec): array
    {
        $issues = [];

        foreach (($spec['paths'] ?? []) as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }
            foreach ($methods as $method => $op) {
                if (!is_array($op)) {
                    continue;
                }
                $reasons = [];

                if (empty($op['summary']) && empty($op['description'])) {
                    $reasons[] = 'missing summary/description';
                }

                $responses = $op['responses'] ?? [];
                if (!isset($responses[200]) && !isset($responses['200'])) {
                    $reasons[] = 'missing 200 response';
                }

                if ($reasons !== []) {
                    $issues[] = [
                        'path' => $path,
                        'method' => $method,
                        'reasons' => $reasons,
                    ];
                }
            }
        }

        return $issues;
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
     * 路径参数由路由模式自动提取；#[OpenApi] 声明的 query/header 参数与之去重合并；
     * 响应默认 200，被属性声明覆盖。
     *
     * @param list<array<string, mixed>> $pathParams 自动提取的路径参数
     * @param OpenApi|null $meta 方法上的补充片段
     * @return array<string, mixed>
     */
    private function buildOperation(Route $route, string $method, array $pathParams, ?OpenApi $meta): array
    {
        $operation = [
            'operationId' => $this->operationId($route, $method),
            'parameters' => $pathParams,
            'responses' => [200 => ['description' => 'OK']],
        ];

        if ($meta !== null) {
            $fragment = $meta->toOperationFragment();

            // 合并显式声明的 query/header 参数（按 name|in 去重，避免与路径参数重复）
            if (isset($fragment['parameters'])) {
                $operation['parameters'] = $this->mergeParameters($operation['parameters'], $fragment['parameters']);
                unset($fragment['parameters']);
            }

            // 声明的响应覆盖默认 200
            if (isset($fragment['responses'])) {
                $operation['responses'] = $fragment['responses'];
                unset($fragment['responses']);
            }

            $operation = array_merge($operation, $fragment);
        }

        return $operation;
    }

    /**
     * 将路径参数与显式声明的参数按 `name|in` 去重合并。
     *
     * @param list<array<string, mixed>> $path
     * @param list<array<string, mixed>> $declared
     * @return list<array<string, mixed>>
     */
    private function mergeParameters(array $path, array $declared): array
    {
        $seen = [];
        foreach ($path as $p) {
            $seen[($p['name'] ?? '') . '|' . ($p['in'] ?? 'path')] = true;
        }
        foreach ($declared as $p) {
            $key = ($p['name'] ?? '?') . '|' . ($p['in'] ?? 'query');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $path[] = $p;
            }
        }

        return $path;
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
     * 参数结构化：
     *  - 名称与位置（in: path）来自路由模式；
     *  - required 由可选标记（{name?}）推导；
     *  - schema 类型由约束推断：{id:\d+} → integer，{price:\d+\.\d+} → number，
     *    无约束其余 → string（OpenAPI 无法表达正则约束，故以标量类型近似）。
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
                'schema' => ['type' => $this->paramType($pattern, $name)],
            ];
        }

        return $result;
    }

    /**
     * 从路径约束推断 OpenAPI 标量类型。
     *
     * @param string $name 参数名（字母数字下划线，无正则特殊字符）
     */
    private function paramType(string $pattern, string $name): string
    {
        if (preg_match('#\{' . preg_quote($name, '#') . ':([^}]*)\}#', $pattern, $m) === 1) {
            $constraint = $m[1];
            // 约束是正则字面量（如 \d+\.\d+），逐 token 字面匹配：
            // \\d（字面反斜杠+d）、\+（字面加号）、\.（字面点）。
            if (preg_match('#^\\\\d\\+\\\\\\.\\\\d\\+$#', $constraint) === 1) {
                return 'number';
            }
            if (preg_match('#^\\\\d\\+$#', $constraint) === 1) {
                return 'integer';
            }
        }

        return 'string';
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
