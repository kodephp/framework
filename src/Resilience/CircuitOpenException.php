<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

/**
 * 熔断器打开异常。
 *
 * 当熔断器处于 OPEN 状态且未提供 fallback 时抛出，交由全局异常处理器
 * 统一转译为结构化响应（如 503）。
 */
final class CircuitOpenException extends \RuntimeException
{
    public function __construct(string $name, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Circuit breaker [{$name}] is open", $code, $previous);
    }
}
