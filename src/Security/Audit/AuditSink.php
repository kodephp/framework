<?php

declare(strict_types=1);

namespace Kode\Framework\Security\Audit;

use Psr\Log\LoggerInterface;

/**
 * 审计日志离路径导出队列（与 AccessLogSink / Tracer outbox 同范式）。
 *
 * 设计立场：审计是「合规记录」型观测数据，无需在请求路径内同步落盘。本 Sink 让
 * {@see AuditService::record()} 在热路径上只做**一次内存入队**（µs 级、零 I/O），
 * 真实格式化 + 写入由 {@see flush()} 在响应发出后的 shutdown / 优雅停机钩子里批量执行——
 * 绝不阻塞客户端响应，与 v0.8.23 把追踪导出、v0.8.25 把访问日志移出请求路径完全对称。
 *
 *  - 进程级静态队列：Swoole / Workerman 每个 worker 独立隔离，天然支持常驻运行时。
 *  - 不持有 Logger：解耦录制与导出，flush 时由调用方注入，便于测试替换为内存 logger。
 *  - {@see reset()}：清空队列（测试隔离 / 压测每请求重置，模拟离路径 drain 已发生，
 *    避免单进程 CLI 循环里队列无限累积的伪影）。
 */
final class AuditSink
{
    /**
     * 队列硬上限（防御性，v0.8.42 对齐 AccessLogSink）：即使下游 flush 因异常未触发，
     * 也保证内存有界，不会在持续高并发下无限累积直至 worker OOM。达上限时丢弃最新条目
     * （审计在极端过载下可丢弃，优于 OOM 致 worker 崩溃、整体不可用）。
     */
    private const MAX = 8192;

    /**
     * 进程级待导出队列（离请求路径批量落盘）。
     *
     * @var array<int, array{level: string, message: string, context: array}>
     */
    private static array $queue = [];

    /**
     * 热路径入队：仅一次数组 push，无任何 I/O 与格式化开销。
     */
    public function emit(string $level, string $message, array $context): void
    {
        if (count(self::$queue) >= self::MAX) {
            return;
        }
        self::$queue[] = [
            'level'   => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * 离请求路径批量落盘：把队列一次性写入 logger 并清空。
     *
     * 由以下时机调用（均不阻塞客户端响应）：
     *  - Swoole / Workerman 的优雅停机钩子（GracefulShutdown）；
     *  - FPM / CLI 的 register_shutdown_function（响应已发出之后）。
     *
     * @return int 本次写入的日志条数（0 = 无数据）
     */
    public function flush(LoggerInterface $logger): int
    {
        if (self::$queue === []) {
            return 0;
        }

        $count = count(self::$queue);
        foreach (self::$queue as $entry) {
            match ($entry['level']) {
                'error'   => $logger->error($entry['message'], $entry['context']),
                'warning' => $logger->warning($entry['message'], $entry['context']),
                default   => $logger->info($entry['message'], $entry['context']),
            };
        }
        self::$queue = [];

        return $count;
    }

    /**
     * 清空队列（测试隔离 / 压测每请求重置，消除累积伪影）。
     */
    public static function reset(): void
    {
        self::$queue = [];
    }

    /**
     * 当前队列中待导出条数。
     */
    public function pending(): int
    {
        return count(self::$queue);
    }
}
