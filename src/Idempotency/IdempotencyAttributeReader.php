<?php

declare(strict_types=1);

namespace Kode\Framework\Idempotency;

use Kode\Framework\Idempotency\Attributes\Idempotency;
use ReflectionClass;
use ReflectionMethod;

/**
 * 从控制器类 + 方法上读取 #[Idempotency] 声明。
 *
 * 类级标注对全部方法生效；方法级标注覆盖（精确定位个别写接口）。
 * 仅做反射读取，不触碰 Route 对象，可在路由注册阶段调用。
 */
final class IdempotencyAttributeReader
{
    public function isPresent(string $controllerClass, ?string $method = null): bool
    {
        if (!class_exists($controllerClass)) {
            return false;
        }

        if ((new ReflectionClass($controllerClass))->getAttributes(Idempotency::class) !== []) {
            return true;
        }

        if ($method !== null && method_exists($controllerClass, $method)
            && (new ReflectionMethod($controllerClass, $method))->getAttributes(Idempotency::class) !== []) {
            return true;
        }

        return false;
    }
}
