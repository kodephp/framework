<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Backoff;

/**
 * 退避策略契约（运行时无关）。
 *
 * 给定「即将进行第 $attempt 次重试」（attempt 从 1 开始，即第 1 次重试 = 在上一次失败后的等待），
 * 返回应等待的秒数（可为小数，0 表示立即重试）。
 *
 * 框架内置 Fixed / Exponential / DecorrelatedJitter 三种实现，业务也可实现本契约自定义
 * （如接指数退避 + 全抖动、或按上游 Retry-After 头等待）。
 */
interface BackoffStrategy
{
    /**
     * 第 $attempt 次重试前应等待的秒数。
     *
     * @param int $attempt 第几次重试（>=1）
     */
    public function delay(int $attempt): float;
}
