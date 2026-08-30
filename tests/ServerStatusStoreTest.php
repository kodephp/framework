<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Server\ServerStatusStore;
use PHPUnit\Framework\TestCase;

/**
 * 运行状态文件仓库验证（不启动服务、不碰进程，纯文件 IO 层）。
 *
 * 覆盖点：
 *  - 目录解析：默认 storage/runtime，相对路径按项目根拼、绝对路径原样用；
 *  - master 记录写入与合并（worker 多次代写不应互相覆盖字段）；
 *  - worker 记录按 pid 各写各的，互不干扰；
 *  - 读者剔除「PID 已死」的僵尸记录（进程被 SIGKILL 时来不及自删）；
 *  - 文件名解析能挡住伪造/异常文件名（worker.abc.json 不应被当成进程记录）。
 */
final class ServerStatusStoreTest extends TestCase
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

    public function testForRootUsesDefaultStorageRuntime(): void
    {
        $store = ServerStatusStore::forRoot('/srv/app');

        $this->assertSame('/srv/app/storage/runtime', $store->dir());
    }

    public function testForRootResolvesRelativeSubDir(): void
    {
        $store = ServerStatusStore::forRoot('/srv/app', 'var/run');

        $this->assertSame('/srv/app/var/run', $store->dir());
    }

    public function testForRootKeepsAbsoluteSubDir(): void
    {
        $store = ServerStatusStore::forRoot('/srv/app', '/tmp/kode-run');

        $this->assertSame('/tmp/kode-run', $store->dir());
    }

    public function testWriteAndReadMaster(): void
    {
        $store = $this->store();

        $this->assertNull($store->master());

        $store->writeMaster(['pid' => 1234, 'listen' => 'http://127.0.0.1:9527']);
        $master = $store->master();

        $this->assertIsArray($master);
        $this->assertSame(1234, $master['pid']);
        $this->assertSame('http://127.0.0.1:9527', $master['listen']);
    }

    public function testWriteMasterMergesInsteadOfOverwriting(): void
    {
        $store = $this->store();

        // 模拟多个 worker 代写同一份 master 记录：各自只补自己观测到的字段。
        $store->writeMaster(['pid' => 100, 'version' => '1.1.1']);
        $store->writeMaster(['loop' => 'select']);

        $master = $store->master();
        $this->assertIsArray($master);
        $this->assertSame(100, $master['pid']);
        $this->assertSame('1.1.1', $master['version']);
        $this->assertSame('select', $master['loop']);
    }

    public function testWorkersAreKeyedByPidAndIndependent(): void
    {
        $store = $this->store();

        $store->writeWorker(2001, ['worker_id' => 0, 'requests' => 7]);
        $store->writeWorker(2002, ['worker_id' => 1, 'requests' => 9]);
        $store->writeWorker(2001, ['worker_id' => 0, 'requests' => 8]);

        // 直接读文件，验证同 pid 覆写、不同 pid 互不影响。
        // 必须**先于** workers()：workers() 承担「读者清理」职责，会把 PID 已死的记录删掉。
        $first  = $this->readRaw($store, 'worker.2001.json');
        $second = $this->readRaw($store, 'worker.2002.json');

        $this->assertIsArray($first);
        $this->assertSame(8, $first['requests'], '同 pid 重复写入应为覆写');
        $this->assertIsArray($second);
        $this->assertSame(9, $second['requests'], '不同 pid 的记录互不影响');

        // 两个 pid 都是「本机不存在的进程」，会被存活探测剔除。
        $this->assertSame([], $store->workers());
    }

    public function testPruneDropsDeadMasterRecord(): void
    {
        $store = $this->store();
        $store->writeMaster(['pid' => 999999, 'listen' => 'http://127.0.0.1:9527']);

        $store->prune();

        $this->assertNull($store->master(), '失效的 master 记录应被 prune 清除');
    }

    public function testRemoveMasterFileIfSelfOnlyDeletesOwnRecord(): void
    {
        $store = $this->store();

        // 别人的记录：不应被删
        $store->writeMaster(['pid' => 999999]);
        $store->removeMasterFileIfSelf();
        $this->assertNotNull($store->master());

        // 自己的记录：应被删
        $store->writeMaster(['pid' => getmypid()]);
        $store->removeMasterFileIfSelf();
        $this->assertNull($store->master());
    }

    public function testMalformedWorkerFileNameIsIgnored(): void
    {
        $store = $this->store();

        // 伪造/损坏的文件不应被当成进程记录解析。
        @file_put_contents($store->dir() . '/worker.abc.json', '{"requests":1}');
        @file_put_contents($store->dir() . '/worker..json', '{}');
        @file_put_contents($store->dir() . '/not-a-worker.1.json', '{}');

        $this->assertSame([], $store->workers());
        $this->assertSame([], $store->workerFileNames());
    }

    public function testIsAliveRejectsInvalidPids(): void
    {
        $this->assertFalse(ServerStatusStore::isAlive(0));
        $this->assertFalse(ServerStatusStore::isAlive(1));
        $this->assertFalse(ServerStatusStore::isAlive(-1));
    }

    private function store(): ServerStatusStore
    {
        $dir = sys_get_temp_dir() . '/kode-status-test-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0o755, true);
        $this->tmpDirs[] = $dir;

        return new ServerStatusStore($dir);
    }

    /** @return array<string, mixed>|null */
    private function readRaw(ServerStatusStore $store, string $name): ?array
    {
        $raw = @file_get_contents($store->dir() . '/' . $name);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
