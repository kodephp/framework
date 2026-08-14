<?php

declare(strict_types=1);

namespace Kode\Framework\Idempotency;

/**
 * 幂等键重复命中事件（重放检测）。
 *
 * 由 {@see IdempotencyManager::once()} / {@see IdempotencyManager::seen()} 在检测到已处理 key 时派发，
 * 便于接入指标（重复请求率）/ 审计，或驱动 HTTP 幂等中间件返回 409 / 缓存响应。
 *
 * @property-read string $key 幂等键
 */
final class IdempotencyHit
{
    public function __construct(
        public readonly string $key,
    ) {
    }
}
