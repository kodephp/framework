<?php

declare(strict_types=1);

namespace Kode\Framework\ApiDoc\Attributes;

use Attribute;

/**
 * 描述一个 OpenAPI 操作参数（query / header / cookie / path）。
 *
 * 路径参数已由框架从路由模式 `{id}` 自动提取；本类用于补充「自动生成无法推断」的
 * 查询参数、请求头、Cookie 等。配合 {@see OpenApi} 的 `parameters` 字段使用。
 *
 * @example
 * new OpenApiParameter('page', 'query', 'integer', required: false, description: '页码', example: 1)
 */
#[Attribute(Attribute::TARGET_ALL)]
final class OpenApiParameter
{
    /**
     * @param string $name        参数名（如 page / Authorization）
     * @param string $in          query | header | cookie | path
     * @param string $type        OpenAPI schema 类型（string / integer / number / boolean）
     * @param bool   $required    是否必填（in=path 时强制为 true）
     * @param string|null $description 说明
     * @param mixed  $example     示例值
     */
    public function __construct(
        public readonly string $name,
        public readonly string $in = 'query',
        public readonly string $type = 'string',
        public readonly bool $required = false,
        public readonly ?string $description = null,
        public readonly mixed $example = null,
    ) {
    }

    /**
     * 转为 OpenAPI Parameter Object 数组。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $param = [
            'name' => $this->name,
            'in' => $this->in,
            'required' => $this->in === 'path' ? true : $this->required,
            'schema' => ['type' => $this->type],
        ];

        if ($this->description !== null) {
            $param['description'] = $this->description;
        }
        if ($this->example !== null) {
            $param['example'] = $this->example;
        }

        return $param;
    }
}
