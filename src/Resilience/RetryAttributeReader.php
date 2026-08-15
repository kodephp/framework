<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

use Kode\Framework\Resilience\Attributes\Retry;
use ReflectionClass;
use ReflectionMethod;

/**
 * 从控制器类 + 方法上读取 #[Retry] 声明。
 *
 * 类级标注对全部方法生效；方法级标注覆盖（精确定位个别读接口）。
 * 仅做反射读取，不触碰 Route 对象，可在路由注册阶段调用。
 */
final class RetryAttributeReader
{
    public function isPresent(string $controllerClass, ?string $method = null): bool
    {
        if (!class_exists($controllerClass)) {
            return false;
        }

        if ((new ReflectionClass($controllerClass))->getAttributes(Retry::class) !== []) {
            return true;
        }

        if ($method !== null && method_exists($controllerClass, $method)
            && (new ReflectionMethod($controllerClass, $method))->getAttributes(Retry::class) !== []) {
            return true;
        }

        return false;
    }
}
