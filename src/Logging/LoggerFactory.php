<?php

declare(strict_types=1);

namespace Kode\Framework\Logging;

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

        $logger->pushHandler($handler);

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
