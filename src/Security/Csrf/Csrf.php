<?php

declare(strict_types=1);

namespace Kode\Framework\Security\Csrf;

/**
 * 声明式 CSRF 保护注解。
 *
 * 标注在控制器类或方法上，使对应路由纳入 CSRF 防护（需会话启用）。
 * 防护规则与令牌机制由 {@see CsrfMiddleware} 统一处理，本注解仅作「标记」。
 *
 * 设计立场：CSRF 是**按需挂载**的企业级中间件——只有被本注解标记的路由才会
 * 触发令牌校验，其余路由（含 /ping、纯 JWT 接口）在全局中间件里走 O(1) 早退，
 * 不产生任何可感知开销，故「加上企业中间件也不影响响应」。
 *
 * @example
 * ```php
 * // 整个控制器纳入防护（cookie-session Web 应用典型用法）
 * #[Csrf]
 * class ProfileController extends Controller { ... }
 *
 * // 仅个别写操作纳入防护
 * #[Csrf]
 * public function update(): Response { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Csrf
{
}
