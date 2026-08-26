<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Framework\Queue\JobDiscovery;
use Kode\Queue\HandlerResolver;
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

        // 消费端：自动发现 app/jobs、app/tasks 下标注 #[AsJob] 的任务类，
        // 并合并 config/queue.php 的 workers 显式映射，注册为单例供 queue:work 复用。
        $this->container->singleton(HandlerResolver::class, function (): HandlerResolver {
            $resolver = new HandlerResolver($this->container);

            try {
                foreach (JobDiscovery::scan($this->jobDirs()) as $class) {
                    $resolver->registerJobClass($class);
                }
            } catch (\Throwable) {
                // 目录扫描失败（如 app 尚未初始化）不应阻断启动
            }

            /** @var array<string, callable|class-string> $workers */
            $workers = (array) $this->config('queue.workers', []);
            foreach ($workers as $name => $handler) {
                $resolver->register((string) $name, $handler);
            }

            return $resolver;
        });

        $this->container->alias('queue.resolver', HandlerResolver::class);
    }

    /**
     * 返回「命名空间前缀 => 绝对目录」映射，供 JobDiscovery 按 PSR-4 推导 FQCN。
     *
     * @return array<string, string>
     */
    private function jobDirs(): array
    {
        $base = rtrim((string) $this->config('path.base', (string) getcwd()), '/');
        /** @var list<string> $dirs */
        $dirs = (array) $this->config('queue.jobs_dir', ['app/jobs', 'app/tasks']);

        $map = [];
        foreach ($dirs as $rel) {
            $abs = str_starts_with($rel, '/') ? $rel : $base . '/' . ltrim($rel, '/');
            // app/jobs -> \app\jobs\；app/tasks -> \app\tasks\
            $ns = '\app\\' . str_replace('/', '\\', trim($rel, '/')) . '\\';
            $map[$ns] = $abs;
        }

        return $map;
    }
}
