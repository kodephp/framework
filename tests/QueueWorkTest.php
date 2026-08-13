<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Queue\JobDiscovery;
use Kode\Framework\Tests\Fixtures\Jobs\EchoJob;
use Kode\Queue\Config\WorkerOptions;
use Kode\Queue\HandlerResolver;
use Kode\Queue\QueueManager;
use Kode\Queue\Worker;
use PHPUnit\Framework\TestCase;

/**
 * 队列消费端（P0-1）端到端验证：
 *  - JobDiscovery 自动发现 #[AsJob] 任务类（框架薄壳价值，复刻 kode/queue 约定优于配置）；
 *  - QueueServiceProvider 的接线产物（QueueManager + HandlerResolver）可真实消费一条投递的任务。
 *
 * 用 memory 驱动，无需 redis/数据库，证明「投递 → 消费」链路在框架层真正闭合。
 */
final class QueueWorkTest extends TestCase
{
    private const FIXTURE_NS = 'Kode\\Framework\\Tests\\Fixtures\\';

    private string $jobsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobsDir = __DIR__ . '/Fixtures/Jobs';
        EchoJob::$handled = false;
        EchoJob::$payload = [];
    }

    public function testJobDiscoveryFindsAsJobClasses(): void
    {
        $key = 'Kode\\Framework\\Tests\\Fixtures\\Jobs\\';
        $found = JobDiscovery::scan([$key => $this->jobsDir]);

        self::assertContains(EchoJob::class, $found);
    }

    public function testWorkerConsumesPushedJobOverMemoryQueue(): void
    {
        $manager = QueueManager::make([
            'default' => 'memory',
            'connections' => ['memory' => ['driver' => 'memory', 'queue' => 'default']],
        ]);
        $queue = $manager->default();

        // 复刻 QueueServiceProvider 的处理器接线。
        $resolver = new HandlerResolver();
        foreach (JobDiscovery::scan([self::FIXTURE_NS . 'Jobs\\' => $this->jobsDir]) as $class) {
            $resolver->registerJobClass($class);
        }
        self::assertContains(EchoJob::class, $resolver->registered());

        // 投递（与业务侧 queue()->push(EchoJob::class, $data) 同形）。
        $queue->push(EchoJob::class, ['msg' => 'hello']);

        // 一次性跑空队列（对应 queue:work --once）。
        $worker = new Worker($queue, $resolver, WorkerOptions::batch('default'));
        $summary = $worker->run(WorkerOptions::batch('default'));

        self::assertGreaterThanOrEqual(1, $summary->processed);
        self::assertTrue(EchoJob::$handled, '任务处理器未被调用');
        self::assertSame(['msg' => 'hello'], EchoJob::$payload);
    }
}
