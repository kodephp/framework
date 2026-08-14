<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Events;

/**
 * 熔断器打开事件。
 *
 * 当 {@see \Kode\Framework\Resilience\CircuitBreakerMiddleware} 探测到熔断器处于 OPEN 状态、
 * 于 HTTP 边缘直接短路返回降级响应时派发。携带服务名 / 状态 / 请求路径，便于接入
 * 可观测性与告警（如 Prometheus 计数器、Slack 通知）。
 *
 * 注意：本事件只在「边缘短路」时发生，是下游已不可用的强信号，值得告警；
 * 半开探活、单次失败等不算「打开」，不派发本事件，避免噪声。
 */
final class CircuitOpened
{
    /**
     * @param string $name   熔断器服务名（按路径 / 主机 / 固定名推导）
     * @param string $state  当前状态（open）
     * @param string $path   触发短路的请求路径
     * @param int    $status 短路返回的 HTTP 状态码
     */
    public function __construct(
        public readonly string $name,
        public readonly string $state,
        public readonly string $path,
        public readonly int $status,
    ) {
    }
}
