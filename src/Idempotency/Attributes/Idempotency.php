<?php

declare(strict_types=1);

namespace Kode\Framework\Idempotency\Attributes;

/**
 * 声明式 HTTP 幂等保护注解。
 *
 * 标注在控制器类或方法上，使对应路由纳入幂等防护：携带 `Idempotency-Key` 的重复请求
 * 在 TTL 内只真正执行业务一次，重放返回首次缓存响应（Stripe 风格）。
 * 幂等参数（键头 / 作用域 / TTL）由 config/idempotency.php 的 `http` 段统一配置，
 * 本注解仅作「标记」——只有被标记的路由才会触发 {@see IdempotencyMiddleware} 的
 * 去重逻辑，其余路由在全局中间件里走 O(1) 早退，不产生可感知开销。
 *
 * @example
 * ```php
 * // 整个写控制器纳入幂等（支付 / 下单 / 写接口防重复提交）
 * #[Idempotency]
 * class CheckoutController extends Controller { ... }
 *
 * // 仅个别写接口
 * #[Idempotency]
 * public function charge(): Response { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Idempotency
{
}
