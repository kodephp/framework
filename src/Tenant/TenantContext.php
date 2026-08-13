<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant;

use Kode\Context\Context;

/**
 * 租户上下文读助手（写入由 TenantMiddleware 在请求级 scope 内完成）。
 *
 * 通过 kode/context 的「每请求隔离 scope」存取当前租户标识，
 * 请求外（CLI / 未解析）一律返回 null，永不跨请求串扰。
 */
final class TenantContext
{
    public const KEY = 'tenant.id';

    /**
     * 当前租户标识；无租户（未解析 / CLI）返回 null。
     */
    public static function id(): ?string
    {
        $value = Context::get(self::KEY);

        return is_string($value) ? $value : null;
    }

    public static function has(): bool
    {
        return self::id() !== null;
    }
}
