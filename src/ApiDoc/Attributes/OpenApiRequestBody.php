<?php

declare(strict_types=1);

namespace Kode\Framework\ApiDoc\Attributes;

use Attribute;

/**
 * 描述请求体（默认 application/json）。
 *
 * 每个属性是一段 OpenAPI Schema 片段，例如 `['type' => 'string', 'description' => '名称']`。
 * 配合 {@see OpenApi} 的 `requestBody` 字段使用，覆盖自动生成无法推断的入参结构。
 *
 * @example
 * new OpenApiRequestBody(
 *     properties: ['name' => ['type' => 'string'], 'price' => ['type' => 'number']],
 *     required: ['name'],
 *     example: ['name' => '手机', 'price' => 999],
 * )
 */
#[Attribute(Attribute::TARGET_ALL)]
final class OpenApiRequestBody
{
    /**
     * @param string $type        根类型（通常 object）
     * @param array<string, array<string, mixed>> $properties 字段名 => OpenAPI schema 片段
     * @param list<string> $required 必填字段名
     * @param string|null $description 说明
     * @param mixed $example 整体示例
     */
    public function __construct(
        public readonly string $type = 'object',
        public readonly array $properties = [],
        public readonly array $required = [],
        public readonly ?string $description = null,
        public readonly mixed $example = null,
    ) {
    }

    /**
     * 转为 OpenAPI Request Body Object 数组。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $schema = ['type' => $this->type];
        if ($this->properties !== []) {
            $schema['properties'] = $this->properties;
        }
        if ($this->required !== []) {
            $schema['required'] = array_values($this->required);
        }
        if ($this->example !== null) {
            $schema['example'] = $this->example;
        }

        $body = [
            'content' => [
                'application/json' => ['schema' => $schema],
            ],
        ];
        if ($this->description !== null) {
            $body['description'] = $this->description;
        }

        return $body;
    }
}
