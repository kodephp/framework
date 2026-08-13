<?php

declare(strict_types=1);

namespace Kode\Framework\Feature;

use Kode\Framework\Feature\Attributes\Feature;
use ReflectionClass;
use ReflectionMethod;

/**
 * 从控制器类 + 方法上读取 #[Feature] 声明。
 *
 * 类级作为默认；方法级覆盖（与 #[RateLimit] 读取器同一套路）。
 * 返回 ['flag' => string, 'fallback' => int] 或 null（无声明）。
 *
 * @return array{flag: string, fallback: int}|null
 */
final class FeatureAttributeReader
{
    /**
     * @return array{flag: string, fallback: int}|null
     */
    public function read(string $controllerClass, ?string $method = null): ?array
    {
        if (!class_exists($controllerClass)) {
            return null;
        }

        $flag = null;
        $fallback = 404;

        foreach ((new ReflectionClass($controllerClass))->getAttributes(Feature::class) as $attr) {
            /** @var Feature $instance */
            $instance = $attr->newInstance();
            $flag = $instance->name;
            $fallback = $instance->fallback;
        }

        if ($method !== null && method_exists($controllerClass, $method)) {
            foreach ((new ReflectionMethod($controllerClass, $method))->getAttributes(Feature::class) as $attr) {
                /** @var Feature $instance */
                $instance = $attr->newInstance();
                $flag = $instance->name;
                $fallback = $instance->fallback;
            }
        }

        if ($flag === null) {
            return null;
        }

        return ['flag' => $flag, 'fallback' => $fallback];
    }
}
