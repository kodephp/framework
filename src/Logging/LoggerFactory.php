<?php

declare(strict_types=1);

namespace Kode\Framework\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Level;
use Psr\Log\LoggerInterface;

/**
 * Monolog 日志工厂
 *
 * 框架不直接依赖某个日志实现，而是以 Monolog 作为默认实现并遵循 PSR-3
 * （Psr\Log\LoggerInterface），因此可以随时替换为其它 PSR-3 兼容实现。
 */
final class LoggerFactory
{
    /**
     * @param array<string, mixed> $config 允许 keys: name, path, level, rotate(bool)
     */
    public static function create(array $config = []): LoggerInterface
    {
        $name = $config['name'] ?? 'kode';
        $path = $config['path'] ?? storage_path('logs/app.log');
        $level = self::level($config['level'] ?? 'debug');
        $rotate = (bool) ($config['rotate'] ?? true);

        $directory = dirname($path);
        if ($directory !== '' && !is_dir($directory)) {
            @mkdir($directory, 0o755, true);
        }

        $logger = new Logger($name);
        $handler = $rotate
            ? new RotatingFileHandler($path, 7, $level)
            : new StreamHandler($path, $level);

        // 结构化 JSON（H1）：LOG_FORMAT=json 时便于 ELK/Loki 检索，默认 LineFormatter 仅本地调试。
        $format = strtolower((string) ($config['formatter'] ?? env('LOG_FORMAT', 'line')));
        if ($format === 'json') {
            $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true));
        }

        $logger->pushHandler($handler);

        // 链路关联（H2）：每条日志自动注入 trace_id/span_id，实现 日志↔链路↔异常 三端一致。
        $logger->pushProcessor(static function (\Monolog\LogRecord $record): \Monolog\LogRecord {
            try {
                $traceId = \Kode\Context\Context::getString(\Kode\Context\Context::TRACE_ID);
                if ($traceId !== null && $traceId !== '') {
                    $extra = $record->extra;
                    $extra['trace_id'] = $traceId;
                    $spanId = \Kode\Context\Context::getString(\Kode\Context\Context::SPAN_ID);
                    if ($spanId !== null && $spanId !== '') {
                        $extra['span_id'] = $spanId;
                    }
                    return $record->with(extra: $extra);
                }
            } catch (\Throwable) {
            }

            return $record;
        });

        return $logger;
    }

    private static function level(string $level): Level
    {
        try {
            return Level::fromName(strtoupper($level));
        } catch (\Throwable) {
            return Level::Debug;
        }
    }
}
