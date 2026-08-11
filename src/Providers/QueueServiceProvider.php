<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Queue\Queue;
use Kode\Queue\QueueManager;

/**
 * 队列服务提供者（kode/queue，PHP 8.3+）
 *
 * 绑定 QueueManager（按 config/queue.php 构建）与默认连接 Queue。
 * 门面 Queue / 助手 queue() 复用；业务侧用 queue()->push($job) / ->later(...)。
 */
final class QueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(QueueManager::class, function (): QueueManager {
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('queue', []);

            return QueueManager::make($config);
        });

        $this->container->singleton(Queue::class, static function (): Queue {
            return resolve(QueueManager::class)->default();
        });

        $this->container->alias('queue', Queue::class);
    }
}
