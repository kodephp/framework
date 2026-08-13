<?php

declare(strict_types=1);

namespace Kode\Framework\Health;

/**
 * 健康巡检完成事件。
 *
 * 由 {@see HealthChecker::check()} 在每一次探测（含 HTTP 就绪探针 / health:check 命令）后派发，
 * 便于接入指标采集 / 告警 / 日志（依赖未就绪时第一时间感知）。
 *
 * 监听器可按 {@see $mode} 区分来源（'ready' = 就绪探针，'aggregate' = 聚合巡检）。
 *
 * @property-read bool $healthy 整体是否健康
 * @property-read array<string, string> $checks 各组件状态（'ok' | 'error: ...' | 'not_configured'）
 * @property-read string $mode 探测模式
 */
final class HealthChecked
{
    /**
     * @param array<string, string> $checks
     */
    public function __construct(
        public readonly bool $healthy,
        public readonly array $checks,
        public readonly string $mode = 'aggregate',
    ) {
    }
}
