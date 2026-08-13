<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 租户解析器接口（框架级原语，不绑定任何存储策略）。
 *
 * 应用实现本接口，决定「如何从一次 HTTP 请求识别出当前租户」。
 * 框架只负责：在请求入口调用 resolve() → 把结果写入请求级 Context（kode/context）→
 * 暴露 tenant() 助手。至于「租户对应哪个库 / 哪个 schema / 哪行记录」属于应用层，
 * 框架不越界（薄壳原则）。
 *
 * 内置了三种常见策略（Header / Subdomain / Query），应用也可直接提供自定义类名。
 */
interface TenantResolver
{
    /**
     * 从请求解析租户标识。
     *
     * @return string|null 租户标识（字符串）；无法识别时返回 null（框架回退到 default 或留空）。
     */
    public function resolve(ServerRequestInterface $request): ?string;
}
