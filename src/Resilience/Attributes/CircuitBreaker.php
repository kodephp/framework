<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Attributes;

/**
 * 声明式 HTTP 熔断保护注解。
 *
 * 标注在控制器类或方法上，使对应路由纳入边缘熔断保护（保护下游依赖不因故障级联雪崩）。
 * 熔断参数（阈值 / 恢复 / 隔离维度）由 config/resilience.php 的 `breaker.http` 段统一配置，
 * 本注解仅作「标记」——只有被标记的路由才会触发 {@see CircuitBreakerMiddleware} 的
 * 熔断逻辑，其余路由在全局中间件里走 O(1) 早退，不产生可感知开销。
 *
 * @example
 * ```php
 * // 整个下游调用控制器纳入熔断
 * #[CircuitBreaker]
 * class PaymentsController extends Controller { ... }
 *
 * // 仅个别高风险路由
 * #[CircuitBreaker]
 * public function settle(): Response { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class CircuitBreaker
{
}
