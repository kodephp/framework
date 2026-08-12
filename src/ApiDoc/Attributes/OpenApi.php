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
 *  - requestBody：请求体 schema（OpenAPI 片段数组）；
 *  - responses：自定义响应（覆盖默认 200）；
 *  - deprecated：标记该接口已废弃。
 *
 * 可选：不标注则仅生成基础骨架。
 *
 * @example
 * #[OpenApi(
 *     summary: '获取用户详情',
 *     tags: ['user'],
 *     responses: [
 *         200 => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
 *         404 => ['description' => '用户不存在'],
 *     ],
 * )]
 * public function show($req) { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class OpenApi
{
    /**
     * @param string|null $summary      操作摘要
     * @param string|null $description  操作说明
     * @param list<string> $tags        分组标签
     * @param array|null   $requestBody 请求体 OpenAPI 片段
     * @param array|null   $responses   响应 OpenAPI 片段（method => 片段）
     * @param bool         $deprecated  是否废弃
     */
    public function __construct(
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly array $tags = [],
        public readonly ?array $requestBody = null,
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
        if ($this->requestBody !== null) {
            $fragment['requestBody'] = $this->requestBody;
        }
        if ($this->responses !== null) {
            $fragment['responses'] = $this->responses;
        }

        return $fragment;
    }
}
