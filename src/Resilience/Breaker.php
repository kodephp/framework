<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

/**
 * 熔断器管理器（框架韧性层）
 *
 * ── 关于「是否限制于 kode/fibers」 ──
 * 框架自带的中性契约 {@see CircuitBreaker} 与默认引擎 {@see FiberBreaker}
 * （薄适配）仅做**接口对齐**：状态机 / 恢复窗口 / 半开探活等算法完全委托
 * kode/fibers 的 CircuitBreaker，从根上不重复实现，且与协程、多运行时通用。
 *
 * 本管理器刻意**只依赖框架中性契约 CircuitBreaker**，不直接 new 任何具体类；
 * 具体引擎通过可注入的工厂提供（默认工厂由 ResilienceServiceProvider 绑定
 * FiberBreaker）。将来要换成 Redis 分布式熔断，只需替换工厂，
 * 业务代码（breaker()->run(...)）完全无感。
 *
 * 语义边界（与限流区分）：
 *  - 限流：保护自身不被流量打垮（节流，返回 429）；
 *  - 熔断：保护下游依赖不因故障级联雪崩（故障隔离 + fallback 降级）。
 */
final class Breaker
{
    /** @var array<string, CircuitBreaker> */
    private array $breakers = [];

    /**
     * @param array<string, mixed> $config
     * @param callable(string $name, array<string, mixed> $config): CircuitBreaker $factory 引擎工厂（必填）
     */
    public function __construct(
        private readonly array $config = [],
        private readonly mixed $factory = null,
    ) {
    }

    /**
     * 获取（或创建）指定名称的熔断器实例，按服务名隔离。
     */
    public function get(string $name): CircuitBreaker
    {
        return $this->breakers[$name] ??= $this->create($name);
    }

    /**
     * 执行任务；熔断器打开时调用 fallback（无 fallback 则抛 CircuitOpenException）。
     *
     * @param callable           $task
     * @param callable|null      $fallback
     * @return mixed
     */
    public function run(string $name, callable $task, ?callable $fallback = null)
    {
        $breaker = $this->get($name);

        if (!$breaker->allowRequest()) {
            return $fallback !== null ? $fallback() : throw new CircuitOpenException($name);
        }

        try {
            $result = $task();
            $breaker->recordSuccess();

            return $result;
        } catch (\Throwable $e) {
            $breaker->recordFailure();
            throw $e;
        }
    }

    /**
     * 查询熔断器当前状态：closed | open | half_open。
     */
    public function state(string $name): string
    {
        return $this->get($name)->state();
    }

    /**
     * 读取熔断器指标（失败次数、打开时间、恢复窗口等）。
     *
     * @return array<string, mixed>
     */
    public function metrics(string $name): array
    {
        return $this->get($name)->metrics();
    }

    private function create(string $name): CircuitBreaker
    {
        if (!is_callable($this->factory)) {
            throw new \RuntimeException('Circuit breaker factory is not configured');
        }

        return ($this->factory)($name, $this->config);
    }
}
