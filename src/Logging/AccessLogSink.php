<?php

declare(strict_types=1);

namespace Kode\Framework\Logging;

use Psr\Log\LoggerInterface;

/**
 * 访问日志离路径导出队列（与 Tracer 的进程级 outbox 同范式）。
 *
 * 设计立场：访问日志是「事后分析」型观测数据，无需在请求路径内同步落盘。本 Sink 让
 * 中间件在热路径上只做**一次内存入队**（µs 级、零 I/O），真实格式化 + 文件写入由
 * {@see flush()} 在响应发出后的 shutdown / 优雅停机钩子里批量执行——绝不阻塞客户端响应，
 * 与 v0.8.23 把追踪导出移出请求路径的做法完全对称。
 *
 *  - 进程级静态队列：Swoole / Workerman 每个 worker 独立隔离，天然支持常驻运行时。
 *  - 不持有 Logger：解耦录制与导出，flush 时由调用方注入，便于测试替换为内存 logger。
 *  - {@see reset()}：清空队列（测试隔离 / 压测每请求重置，模拟离路径 drain 已发生，
 *    避免单进程 CLI 循环里队列无限累积的伪影）。
 */
final class AccessLogSink
{
    /**
     * 进程级待导出队列（离请求路径批量落盘）。
     *
     * @var array<int, array{level: string, message: string, context: array}>
     */
    private static array $queue = [];

    /**
     * 队列硬上限（防御性）：即使下游 flush 因异常未触发，也保证内存有界，
     * 不会在持续高并发下无限累积直至 worker OOM。达上限时丢弃最新条目
     * （访问日志在极端过载下可丢弃，优于 OOM 致 worker 崩溃、整体不可用）。
     */
    private const MAX = 8192;

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
     *  - FPM / CLI 的 register_shutdown_function（响应已发出之后）；
     *  - 常驻进程的**周期定时器**（限量 {limit} 条，分摊写盘 I/O，避免单次全量
     *    flush 在事件循环线程内长时间阻塞——高并发下队列随时可能积压到
     *    {@see MAX}，一次性写完会让延迟出现秒级尖峰，见日志模块压测复盘）。
     *
     * @param int $limit 本次最多导出的条数（≤0 视为不限量=全量排空；周期定时器应
     *                  传一批适中数量，如 1024，让余量留给下一次 tick，既不丢日志
     *                  也不在单次 tick 内长阻塞）
     *
     * @return int 本次写入的日志条数（0 = 无数据）
     */
    public function flush(LoggerInterface $logger, int $limit = PHP_INT_MAX): int
    {
        if (self::$queue === [] || $limit === 0) {
            return 0;
        }

        $batch = self::$queue;
        if ($limit > 0 && $limit < count($batch)) {
            // 限量分支：array_splice 同时完成「取前 N 条」与「队列前移」，剩余留给下一批。
            $batch = array_splice(self::$queue, 0, $limit);
        } else {
            // 不限量 / 一次取尽：清空原队列，避免双份引用。
            self::$queue = [];
        }
        if ($batch === []) {
            return 0;
        }

        foreach ($batch as $entry) {
            match ($entry['level']) {
                'error'   => $logger->error($entry['message'], $entry['context']),
                'warning' => $logger->warning($entry['message'], $entry['context']),
                default   => $logger->info($entry['message'], $entry['context']),
            };
        }

        return count($batch);
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
