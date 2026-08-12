<?php

declare(strict_types=1);

namespace Kode\Framework\ApiDoc\Attributes;

use Attribute;

/**
 * 描述一个响应（按状态码区分）。
 *
 * 配合 {@see OpenApi} 的 `responses` 字段使用（以状态码为键的映射）。
 * 自动生成默认仅产出 `200 => {description: OK}`，本类用于声明真实响应结构与示例。
 *
 * @example
 * new OpenApiResponse(200, 'OK', properties: ['id' => ['type' => 'integer']], example: ['id' => 1])
 * new OpenApiResponse(404, '资源不存在')
 */
#[Attribute(Attribute::TARGET_ALL)]
final class OpenApiResponse
{
    /**
     * @param int $status 状态码（作为 responses 映射的键）
     * @param string $description 响应说明
     * @param string $type 根类型（通常 object）
     * @param array<string, array<string, mixed>> $properties 字段名 => OpenAPI schema 片段
     * @param mixed $example 整体示例
     * @param array<string, mixed> $headers 响应头（OpenAPI Header Object 片段）
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $description = 'OK',
        public readonly string $type = 'object',
        public readonly array $properties = [],
        public readonly mixed $example = null,
        public readonly array $headers = [],
    ) {
    }

    /**
     * 转为 OpenAPI Response Object 数组。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $schema = ['type' => $this->type];
        if ($this->properties !== []) {
            $schema['properties'] = $this->properties;
        }
        if ($this->example !== null) {
            $schema['example'] = $this->example;
        }

        $resp = [
            'description' => $this->description,
            'content' => [
                'application/json' => ['schema' => $schema],
            ],
        ];
        if ($this->headers !== []) {
            $resp['headers'] = $this->headers;
        }

        return $resp;
    }
}
