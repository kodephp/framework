<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Server\ServerStatus;
use Kode\Framework\Server\ServerStatusStore;
use PHPUnit\Framework\TestCase;

/**
 * 状态渲染验证（纯逻辑，不启进程、不依赖真实服务）。
 *
 * 「进程是否存活」由 {@see ServerStatusStore::isAlive()} 按 PID 探测，
 * 因此本测试借**测试进程自身的 PID**充当「活着的 master / worker」，
 * 无需 fork 即可覆盖有记录的渲染路径。
 *
 * 不覆盖的部分（诚实标注）：GLOBAL STATUS 的 load average、run 时长来自
 * 运行环境实时取值，属于展示层格式化，断言无意义，故不断言整行文本。
 */
final class ServerStatusTest extends TestCase
{
    /** @var list<string> 测试期创建的临时目录，tearDown 统一回收 */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach ((array) @glob($dir . '/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    public function testNotRunningWhenStoreIsEmpty(): void
    {
        $status = $this->makeStatus();

        $this->assertFalse($status->isRunning());

        $out = $status->render();
        $this->assertStringContainsString('服务未运行', $out);
        $this->assertStringContainsString($this->dir(), $out, '未运行时应给出状态目录，便于人工排查');
    }

    public function testDeadMasterRecordIsTreatedAsStopped(): void
    {
        $store = $this->store();
        // 999999 是「几乎必然不存在」的 PID：模拟服务被 SIGKILL 后来不及清理的残留记录。
        $store->writeMaster(['pid' => 999999, 'listen' => 'http://127.0.0.1:9527']);

        $status = new ServerStatus($store);

        $this->assertFalse($status->isRunning(), 'master 进程已死时不应报告为运行中');
    }

    public function testGlobalStatusCountsProcessesFromLiveWorkerRecords(): void
    {
        $store = $this->store();
        $store->writeMaster(['pid' => getmypid(), 'version' => '1.1.0', 'runtime' => 'native']);
        $store->writeWorker(getmypid(), [
            'worker_id'   => 0,
            'worker_name' => 'kode-http#0',
            'name'        => 'kode-http',
            'listen'      => 'http://127.0.0.1:9527',
            'memory'      => 12 * 1048576,
            'connections' => 3,
            'requests'    => 42,
            'qps'         => 7,
            'inflight'    => 0,
            'updated_at'  => microtime(true),
        ]);

        $status = new ServerStatus($store);

        $this->assertTrue($status->isRunning());

        $out = $status->render();
        $this->assertStringContainsString('GLOBAL STATUS', $out);
        $this->assertStringContainsString('PROCESS STATUS', $out);
        $this->assertStringContainsString('1 workers       1 processes', $out);
        $this->assertStringContainsString('kode-http', $out);
        $this->assertStringContainsString('12.00M', $out);
        $this->assertStringContainsString('[idle]', $out, '无在途请求的新鲜心跳应为 idle');
    }

    public function testBusyWhenInflightRequestsExist(): void
    {
        $store = $this->store();
        $store->writeWorker(getmypid(), [
            'worker_name' => 'kode-http#0',
            'inflight'    => 2,
            'updated_at'  => microtime(true),
        ]);

        $this->assertStringContainsString('[busy]', (new ServerStatus($store))->render());
    }

    public function testStaleHeartbeatIsMarkedUnknown(): void
    {
        $store = $this->store();
        $store->writeWorker(getmypid(), [
            'worker_name' => 'kode-http#0',
            'updated_at'  => microtime(true) - ServerStatus::STALE_AFTER - 1,
        ]);

        $this->assertStringContainsString('[unknown]', (new ServerStatus($store))->render());
    }

    public function testRenderSingleShowsProcessDetail(): void
    {
        $store = $this->store();
        $store->writeWorker(getmypid(), [
            'worker_id'   => 1,
            'worker_name' => 'kode-http#1',
            'listen'      => 'http://127.0.0.1:9527',
            'requests'    => 9,
            'inflight'    => 0,
            'updated_at'  => microtime(true),
        ]);

        $out = (new ServerStatus($store))->render(getmypid());

        $this->assertStringContainsString('PROCESS DETAIL', $out);
        $this->assertStringContainsString('kode-http#1', $out);
        $this->assertStringContainsString('total_request', $out);
    }

    public function testRenderSingleReportsUnknownPid(): void
    {
        $store = $this->store();
        $store->writeWorker(getmypid(), ['worker_name' => 'kode-http#0', 'updated_at' => microtime(true)]);

        $out = (new ServerStatus($store))->render(999999);

        $this->assertStringContainsString('未找到进程 999999', $out);
    }

    public function testFormatMemory(): void
    {
        $this->assertSame('0M', ServerStatus::formatMemory(0));
        $this->assertSame('0M', ServerStatus::formatMemory(-1));
        $this->assertSame('1.00M', ServerStatus::formatMemory(1048576));
        $this->assertSame('12.50M', ServerStatus::formatMemory((int) (12.5 * 1048576)));
    }

    public function testFormatDuration(): void
    {
        $this->assertSame('0 days 0 hours 0 minutes', ServerStatus::formatDuration(0));
        $this->assertSame('0 days 0 hours 0 minutes', ServerStatus::formatDuration(-5));
        $this->assertSame('0 days 0 hours 5 minutes', ServerStatus::formatDuration(300));
        $this->assertSame('1 days 2 hours 3 minutes', ServerStatus::formatDuration(86400 + 7380));
    }

    /** 不可命名为 status()——TestCase::status() 是 final 方法。 */
    private function makeStatus(): ServerStatus
    {
        return new ServerStatus($this->store());
    }

    private function store(): ServerStatusStore
    {
        $dir = sys_get_temp_dir() . '/kode-status-render-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0o755, true);
        $this->tmpDirs[] = $dir;

        return new ServerStatusStore($dir);
    }

    private function dir(): string
    {
        return $this->tmpDirs[count($this->tmpDirs) - 1];
    }
}
