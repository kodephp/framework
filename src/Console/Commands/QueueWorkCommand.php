<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Queue\JobDiscovery;
use Kode\Queue\Config\WorkerOptions;
use Kode\Queue\Contract\FailedJobStoreInterface;
use Kode\Queue\Failed\ArrayFailedJobStore;
use Kode\Queue\Failed\FileFailedJobStore;
use Kode\DI\Contract\ContainerInterface as DiContainer;
use Kode\Queue\HandlerResolver;
use Kode\Queue\QueueManager;
use Kode\Queue\Worker;
use Psr\Container\ContainerInterface;

/**
 * 启动队列消费进程（修复「框架最大坑」：此前只有生产者 QueueManager/Queue 被绑定，
 * ConsoleServiceProvider 也未注册任何消费命令，导致异步任务/事件在生产环境根本无法消费）。
 *
 *   bin/kode console queue:work                 # 常驻守护，监听默认队列
 *   bin/kode console queue:work --queue=mail,default --tries=3
 *   bin/kode console queue:work --once          # 把队列跑空就退出（CI / 一次性补数）
 *
 * 处理器来源（三者合并，前者优先）：
 *   1. QueueServiceProvider 绑定、自动扫描 #[AsJob] 得到的 HandlerResolver 单例；
 *   2. config/queue.php 的 `workers`（任务名 => 处理器类/闭包）；
 *   3. app/Jobs、app/Tasks 下标注 #[AsJob] 的任务类（自动发现）。
 */
#[AsCommand(
    name: 'queue:work',
    description: '启动队列消费进程（常驻；--once 跑空即退）',
    usage: 'queue:work [--connection=] [--queue=default] [--name=worker] [--tries=0] [--sleep=1] [--max-jobs=0] [--max-time=0] [--memory=128] [--once]',
)]
final class QueueWorkCommand extends Command
{
    protected function handle(): int
    {
        /** @var QueueManager $manager */
        $manager = resolve(QueueManager::class);

        $connection = $this->opt('connection');
        $queue = ($connection !== null && $connection !== '')
            ? $manager->connection((string) $connection)
            : $manager->default();

        $resolver = $this->resolveResolver();
        $options = $this->buildOptions();
        $failedStore = $this->buildFailedStore();

        $worker = new Worker($queue, $resolver, $options, $failedStore);

        $this->info(sprintf(
            '队列消费进程启动：连接=%s 队列=[%s] 已注册处理器=%d（Ctrl+C / SIGTERM 优雅退出）',
            $connection !== null && $connection !== '' ? $connection : 'default',
            implode(', ', $options->queues),
            count($resolver->registered()),
        ));

        try {
            $summary = $worker->run($options);
        } catch (\Throwable $e) {
            $this->error('消费进程异常退出：' . $e->getMessage());

            return 1;
        }

        $this->line(sprintf(
            '运行结束：处理 %d 个，成功 %d 个，最终失败 %d 个，耗时 %.2fs。',
            $summary->processed,
            $summary->succeeded(),
            $summary->failed,
            $summary->uptimeSeconds,
        ));

        return $summary->isClean() ? 0 : 1;
    }

    /**
     * 取得任务处理器解析器：优先复用 QueueServiceProvider 绑定的单例（已自动发现），
     * 否则现场构建一个（容器注入 + 自动发现 + 显式 workers）。
     */
    private function resolveResolver(): HandlerResolver
    {
        try {
            $container = $this->container();
            if ($container->has(HandlerResolver::class)) {
                /** @var HandlerResolver $resolver */
                $resolver = $container->make(HandlerResolver::class);

                return $resolver;
            }
        } catch (\Throwable) {
            // 容器尚未就绪，走现场构建
        }

        $resolver = new HandlerResolver($this->psrContainer());

        foreach (JobDiscovery::scan($this->jobDirs()) as $class) {
            $resolver->registerJobClass($class);
        }

        /** @var array<string, callable|class-string> $workers */
        $workers = (array) config('queue.workers', []);
        foreach ($workers as $name => $handler) {
            $resolver->register((string) $name, $handler);
        }

        return $resolver;
    }

    /**
     * 合并 config/queue.php 的 worker 段与 CLI 覆盖项，构造 WorkerOptions。
     */
    private function buildOptions(): WorkerOptions
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('queue.worker', []);

        if ($queue = $this->opt('queue')) {
            $config['queues'] = $queue;
        }
        if ($name = $this->opt('name')) {
            $config['name'] = $name;
        }
        if ($v = $this->opt('tries')) {
            $config['max_attempts'] = (int) $v;
        }
        if ($v = $this->opt('sleep')) {
            $config['sleep'] = (float) $v;
        }
        if ($v = $this->opt('timeout')) {
            $config['block_timeout'] = (float) $v;
        }
        if ($v = $this->opt('max-jobs')) {
            $config['max_jobs'] = (int) $v;
        }
        if ($v = $this->opt('max-time')) {
            $config['max_time'] = (int) $v;
        }
        if ($v = $this->opt('memory')) {
            $config['memory_limit'] = (int) $v;
        }

        if ($this->flag('once')) {
            $config['stop_when_empty'] = true;
            $config['block_timeout'] = 0;
            $config['sleep'] = 0;
            $config['reclaim_every'] = 0;
        }

        return WorkerOptions::fromArray($config);
    }

    /**
     * 死信存储：默认文件存储（无需数据库依赖），失败回退内存存储。
     */
    private function buildFailedStore(): ?FailedJobStoreInterface
    {
        try {
            /** @var string $path */
            $path = (string) config('queue.failed.path', '');
            if ($path === '') {
                $path = $this->basePath('storage/queue/failed');
            }

            return new FileFailedJobStore($path);
        } catch (\Throwable $e) {
            $this->warn('死信文件存储不可用：' . $e->getMessage() . '，回退内存存储（不持久化）。');

            try {
                return new ArrayFailedJobStore();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    /**
     * 返回「命名空间前缀 => 绝对目录」映射，供 JobDiscovery 按 PSR-4 推导 FQCN。
     *
     * @return array<string, string>
     */
    private function jobDirs(): array
    {
        $base = rtrim((string) config('path.base', (string) getcwd()), '/');
        /** @var list<string> $dirs */
        $dirs = (array) config('queue.jobs_dir', ['app/Jobs', 'app/Tasks']);

        $map = [];
        foreach ($dirs as $rel) {
            $abs = str_starts_with($rel, '/') ? $rel : $base . '/' . ltrim($rel, '/');
            $ns = 'App\\' . str_replace('/', '\\', trim($rel, '/')) . '\\';
            $map[$ns] = $abs;
        }

        return $map;
    }

    private function basePath(string $path = ''): string
    {
        $base = rtrim((string) config('path.base', (string) getcwd()), '/');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }

    private function container(): DiContainer
    {
        /** @var DiContainer $container */
        $container = resolve(DiContainer::class);

        return $container;
    }

    private function psrContainer(): ?ContainerInterface
    {
        try {
            return $this->container();
        } catch (\Throwable) {
            return null;
        }
    }
}
