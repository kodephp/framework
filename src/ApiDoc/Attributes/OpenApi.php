<?php

declare(strict_types=1);

namespace Kode\Framework\ApiDoc\Attributes;

use Attribute;

/**
 * 在控制器方法上补充 OpenAPI 文档片段。
 *
 * 框架的 OpenApiGenerator 会扫描路由并自动生成基础 spec（paths / methods /
 * 路径参数），本属性用于补充「框架无法从路由定义推断」的语义信息：
 *
 *  - summary / description：操作摘要与说明；
 *  - tags：分组标签（如 'user' / 'order'）；
 *  - parameters：方法参数（query / header / cookie），用 {@see OpenApiParameter} 声明；
 *  - requestBody：请求体结构，用 {@see OpenApiRequestBody} 声明；
 *  - responses：各状态码响应（含字段与示例），用 {@see OpenApiResponse} 声明；
 *  - deprecated：标记该接口已废弃。
 *
 * 所有字段可选；不标注则仅生成基础骨架（默认 200 响应）。
 *
 * 为什么需要显式声明而非全自动？自动生成只能拿到「路由 + 路径参数」，而查询参数、
 * 请求体字段、响应结构属于业务语义，反射无法可靠推断（控制器方法通常仅接收 $req）。
 * 因此提供 `#[OpenApi]` 结构化声明 + `apidoc:generate` 命令，让开发者主动补全。
 *
 * @example
 * #[OpenApi(
 *     summary: '创建商品',
 *     tags: ['product'],
 *     parameters: [new OpenApiParameter('expand', 'query', 'string', description: '展开的关联')],
 *     requestBody: new OpenApiRequestBody(
 *         properties: ['name' => ['type' => 'string'], 'price' => ['type' => 'number']],
 *         required: ['name'],
 *     ),
 *     responses: [
 *         201 => new OpenApiResponse(201, '已创建', properties: ['id' => ['type' => 'integer']]),
 *         422 => new OpenApiResponse(422, '校验失败'),
 *     ],
 * )]
 * public function store() { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class OpenApi
{
    /**
     * @param string|null $summary            操作摘要
     * @param string|null $description        操作说明
     * @param list<string> $tags              分组标签
     * @param list<OpenApiParameter>|null $parameters 方法参数（query/header/cookie）
     * @param OpenApiRequestBody|array|null $requestBody 请求体（对象或原始 OpenAPI 片段）
     * @param array<int, OpenApiResponse|array<string, mixed>>|null $responses 响应映射（状态码 => 声明）
     * @param bool $deprecated                是否废弃
     */
    public function __construct(
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly array $tags = [],
        public readonly ?array $parameters = null,
        public readonly OpenApiRequestBody|array|null $requestBody = null,
        public readonly ?array $responses = null,
        public readonly bool $deprecated = false,
    ) {
    }

    /**
     * 将属性数据转为可合并的 OpenAPI 操作片段。
     *
     * @return array<string, mixed>
     */
    public function toOperationFragment(): array
    {
        $fragment = [];

        if ($this->summary !== null) {
            $fragment['summary'] = $this->summary;
        }
        if ($this->description !== null) {
            $fragment['description'] = $this->description;
        }
        if ($this->tags !== []) {
            $fragment['tags'] = $this->tags;
        }
        if ($this->deprecated) {
            $fragment['deprecated'] = true;
        }
        if ($this->parameters !== null) {
            $fragment['parameters'] = $this->normalizeParameters($this->parameters);
        }
        if ($this->requestBody !== null) {
            $fragment['requestBody'] = $this->requestBody instanceof OpenApiRequestBody
                ? $this->requestBody->toArray()
                : $this->requestBody;
        }
        if ($this->responses !== null) {
            $fragment['responses'] = $this->normalizeResponses($this->responses);
        }

        return $fragment;
    }

    /**
     * 将参数列表统一为标准数组（支持 OpenApiParameter 对象或原始数组）。
     *
     * @param list<OpenApiParameter|array<string, mixed>> $params
     * @return list<array<string, mixed>>
     */
    private function normalizeParameters(array $params): array
    {
        return array_values(array_map(
            static fn($p): array => $p instanceof OpenApiParameter ? $p->toArray() : (array) $p,
            $params
        ));
    }

    /**
     * 将响应映射统一为标准 OpenAPI responses（状态码 => Response Object）。
     *
     * 支持两种写法：
     *  - [201 => new OpenApiResponse(201, ...), 404 => new OpenApiResponse(404, ...)]
     *  - [200 => ['description' => 'OK', ...]]（原始 OpenAPI 片段，键即状态码）
     *
     * @param array<int, OpenApiResponse|array<string, mixed>> $responses
     * @return array<int, array<string, mixed>>
     */
    private function normalizeResponses(array $responses): array
    {
        $out = [];
        foreach ($responses as $key => $resp) {
            if ($resp instanceof OpenApiResponse) {
                $out[$resp->status] = $resp->toArray();
            } elseif (is_array($resp)) {
                $out[$key] = $resp;
            }
        }

        return $out;
    }
}
