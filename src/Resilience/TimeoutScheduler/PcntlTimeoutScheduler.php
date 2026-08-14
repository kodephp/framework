<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\TimeoutScheduler;

use Kode\Framework\Resilience\TimeoutExceeded;
use Kode\Framework\Resilience\TimeoutScheduler;

/**
 * 基于 pcntl_alarm 的 CLI 硬中断超时后端（opt-in）。
 *
 * 适用于常驻 CLI 进程（workerman / webman / 消费 worker）中「CPU 密集型、不挂起」的任务：
 * 借助 SIGALRM 在超时点抛 {@see TimeoutExceeded}，对阻塞调用做真实中断。
 *
 * 注意：
 *  - 仅当 extension_loaded('pcntl') 且 SAPI 为 cli 时可用；否则 {@see run} 直接抛 {@see \RuntimeException}；
 *  - 与事件循环（Swoole/Workerman EventLoop）的信号处理可能冲突，故默认不启用，
 *    仅在 config('resilience.timeout.scheduler') === 'pcntl' 时显式选用；
 *  - 进入前保存并恢复既有 SIGALRM 处理器与 alarm 计时，尽量不污染宿主环境。
 */
final class PcntlTimeoutScheduler implements TimeoutScheduler
{
    public function run(callable $op, float $seconds): mixed
    {
        if (!extension_loaded('pcntl') || PHP_SAPI !== 'cli') {
            throw new \RuntimeException(sprintf(
                '%s requires the pcntl extension and CLI SAPI; current SAPI=%s, pcntl=%s',
                self::class,
                PHP_SAPI,
                extension_loaded('pcntl') ? 'loaded' : 'missing',
            ));
        }

        $secondsInt = max(1, (int) ceil($seconds));
        $previous = pcntl_signal_get_handler(SIGALRM);
        pcntl_async_signals(true);

        $thrown = null;
        pcntl_signal(SIGALRM, static function () use (&$thrown, $seconds): void {
            $thrown = new TimeoutExceeded($seconds, 'anonymous');
            throw $thrown;
        });

        try {
            $result = $op();
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous);

            return $result;
        } catch (\Throwable $e) {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous);

            throw $e;
        }
    }
}
