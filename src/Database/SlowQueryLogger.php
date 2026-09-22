<?php

declare(strict_types=1);

namespace Kode\Framework\Database;

use Kode\Database\Event\SqlEvent;
use Psr\Log\LoggerInterface;

/**
 * 慢查询 / 失败查询日志器（kode/database 的 SqlEvent 订阅者）。
 *
 * database.slow_log.enabled 打开时由 DatabaseServiceProvider 挂进 EventManager。
 * 上下文刻意不带绑定参数：慢日志的用途是定位语句，把参数原样落盘等于把 PII 抄进日志文件。
 *
 * 日志器用闭包惰性取而不是直接注入：provider 的注册顺序与日志服务无关，
 * 触发时才解析，未绑定 LoggerInterface 的裸应用也不会报错。
 */
final class SlowQueryLogger
{
    public const DEFAULT_THRESHOLD = 1.0;

    private readonly float $threshold;

    /** @param \Closure(): ?LoggerInterface $logger */
    public function __construct(
        private readonly \Closure $logger,
        float $threshold = self::DEFAULT_THRESHOLD,
    ) {
        // 0 / 负数不可能是想要的阈值（那等于全量落盘），回退默认值而不是悄悄关掉功能
        $this->threshold = $threshold > 0 ? $threshold : self::DEFAULT_THRESHOLD;
    }

    public function __invoke(SqlEvent $event): void
    {
        if (!$event->failed() && $event->getDuration() < $this->threshold) {
            return;
        }

        $logger = ($this->logger)();
        if (!$logger instanceof LoggerInterface) {
            return;
        }

        $context = [
            'connection' => $event->getConnection() ?? 'default',
            'seconds' => round($event->getDuration(), 4),
            'sql' => $event->getSql(),
        ];

        if ($event->failed()) {
            $logger->error('SQL 执行失败', $context + ['error' => $event->getError()?->getMessage()]);

            return;
        }

        $logger->warning('慢查询', $context + ['threshold' => $this->threshold]);
    }
}
