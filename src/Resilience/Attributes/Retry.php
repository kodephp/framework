<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Attributes;

/**
 * 声明式 HTTP 重试保护注解。
 *
 * 标注在控制器类或方法上，使对应路由的「安全方法」请求在遭遇上游瞬态故障
 * （默认 502/503/504 或指定异常）时按退避自动重试，把抖动对调用方屏蔽。
 * 重试参数（次数 / 退避 / 重试集）由 config/resilience.php 的 `retry.http` 段统一配置，
 * 本注解仅作「标记」——只有被标记的路由才会触发 {@see RetryMiddleware} 的重试包裹，
 * 其余路由（如 /ping）在全局中间件里走 O(1) 早退，不产生可感知开销。
 *
 * @example
 * ```php
 * // 整个下游查询控制器纳入重试
 * #[Retry]
 * class CatalogController extends Controller { ... }
 *
 * // 仅个别读接口
 * #[Retry]
 * public function search(): Response { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Retry
{
}
