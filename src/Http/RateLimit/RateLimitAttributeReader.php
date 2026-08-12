<?php

declare(strict_types=1);

namespace Kode\Framework\Http\RateLimit;

use Kode\Limiting\Attribute\RateLimit;
use ReflectionClass;
use ReflectionMethod;

/**
 * 从控制器类 + 方法上读取 kode/limiting 的 #[RateLimit] 声明式限流规则。
 *
 * 类级规则对所有方法生效；方法级规则叠加（属性 IS_REPEATABLE，可写多条）。
 * 返回顺序：类级规则在前，方法级规则在后——便于同分组下按声明顺序叠加。
 *
 * 规则对象即 kode/limiting 的 {@see RateLimit} 属性（框架不重复定义规则结构），
 * 仅做反射读取，不触碰 kode/http 的 Route 对象，故可在路由注册阶段调用。
 *
 * @return list<RateLimit>
 */
final class RateLimitAttributeReader
{
    /**
     * @return list<RateLimit>
     */
    public function read(string $controllerClass, ?string $method = null): array
    {
        if (!class_exists($controllerClass)) {
            return [];
        }

        /** @var list<RateLimit> $rules */
        $rules = [];

        foreach ((new ReflectionClass($controllerClass))->getAttributes(RateLimit::class) as $attr) {
            /** @var RateLimit $instance */
            $instance = $attr->newInstance();
            $rules[] = $instance;
        }

        if ($method !== null && method_exists($controllerClass, $method)) {
            foreach ((new ReflectionMethod($controllerClass, $method))->getAttributes(RateLimit::class) as $attr) {
                /** @var RateLimit $instance */
                $instance = $attr->newInstance();
                $rules[] = $instance;
            }
        }

        return $rules;
    }
}
