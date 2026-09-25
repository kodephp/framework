<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Support;

use Kode\Database\Db\Db;

/**
 * `kode_schedule_runs` 读侧测试的共用夹具：一次性 scratch 库、必然连不上的死连接、异常捕获。
 *
 * 为什么要有这一份：`stats()` / `runHistory()` / `tenantRunHistory()` 三条读腿要证的是同一件事
 * （「读不到」不等于「没有数据」），而「伪造一座读不到的库」在这个仓库里有一个不小的坑 ——
 * 只有把**默认连接**换成拒连配置是不够的，`Db::select()` 走的是连接池当前的那个名字，
 * 而 `addConnection()` 会把池子的驱动名改成**最后注册**的那个。所以恢复默认连接必须排在
 * 所有 add/remove 之后（见 tearDown 里的顺序注释），否则下一个测试文件会连到本文件的死连接上。
 *
 * 每个使用方必须给出自己独占的 scratch 库前缀（`kode_zz_<名>_*`）：tearDown 只按自己的前缀删库，
 * 前缀共用会让两个文件互相删掉对方的库，而 `assertSame([], $left)` 那条收尾核对也会互相误伤。
 */
trait ScheduleRunDbFixture
{
    private const DEAD_DSN_PORT = 1;

    /** @var list<string> 本测试自己建的临时库，tearDown 逐个删（只认自己的前缀）。 */
    private array $scratchDbs = [];

    private string $defaultConnection = '';

    /** @var list<string> 进本测试前已注册的连接名（用于把自己加的那几个摘掉）。 */
    private array $existingConnections = [];

    /** @var string 探库失败原因（写进 skip 文案，别让「没跑」和「跑过且绿」长得一样）。 */
    private string $skipReason = '';

    /** 本测试独占的 scratch 库名前缀，必须以 `kode_zz_` 开头。 */
    abstract protected function scratchDbPrefix(): string;

    protected function setUp(): void
    {
        parent::setUp();

        // 记下原状再动手：本夹具会 addConnection / setDefaultConnection，
        // 而那些是进程级静态状态 —— 不还原的话，后面的测试文件会连到我的死连接上。
        $this->defaultConnection = Db::getDefaultConnection();
        $this->existingConnections = array_keys(Db::getConnections());
    }

    protected function tearDown(): void
    {
        try {
            Db::disconnect();
        } catch (\Throwable) {
            // 忽略：断开失败不影响下面的删库。
        }

        $left = [];
        foreach ($this->scratchDbs as $name) {
            try {
                $this->adminPdo()->exec('DROP DATABASE IF EXISTS ' . $name);
                if (in_array($name, $this->existingScratchDbs(), true)) {
                    $left[] = $name;
                }
            } catch (\Throwable $e) {
                $left[] = $name . '（' . $e->getMessage() . '）';
            }
        }
        $this->scratchDbs = [];

        // 摘掉自己注册的连接，再把默认指回原来那个。
        // 顺序有讲究：addConnection() 会把 PoolManager 的 driver 改成**最后注册**的那个名字，
        // 所以恢复默认连接必须排在所有 add/remove 之后，否则驱动名会停在临时连接上。
        foreach (array_keys(Db::getConnections()) as $name) {
            if ($name === $this->defaultConnection || in_array($name, $this->existingConnections, true)) {
                continue;
            }
            Db::removeConnection((string) $name);
        }
        Db::setDefaultConnection($this->defaultConnection);

        parent::tearDown();

        self::assertSame([], $left, '临时库没清掉，下一轮的 CREATE DATABASE 会撞名或攒垃圾');
        self::assertSame(
            $this->defaultConnection,
            Db::getDefaultConnection(),
            '默认连接没还原：本文件的死连接会漏给后面的测试文件'
        );
    }

    /** 跑一遍 $fn，返回抛出的异常（没抛就返回 null），让「必须抛/不许抛」两类断言共用一条路径。 */
    private function capture(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * 「抛了」还不够：抛的那条必须能追到数据库的原始原因。
     * 旧写法在 catch 里先 `logger()->warning()`，未引导的容器里 logger() 自己就抛，
     * 那会把真正的连接失败顶掉 —— 而这条线恰恰常在容器之外跑（清理任务、CLI）。
     */
    private function assertReasonSurvived(\Throwable $thrown): void
    {
        $chain = [];
        for ($e = $thrown; $e !== null; $e = $e->getPrevious()) {
            $chain[] = get_class($e) . ': ' . $e->getMessage();
        }
        $joined = implode(' | ', $chain);

        self::assertMatchesRegularExpression(
            '/SQLSTATE|Connection refused|连接/i',
            $joined,
            '异常链里没有数据库的原始原因：' . $joined
        );
        self::assertStringNotContainsString('服务容器尚未启动', $joined, '日志器把真正的失败原因顶替了');
    }

    /**
     * 执行历史表在这座库里不存在吗？
     *
     * 不能用 `Db::hasTable()` 问：它在 pgsql 上发的是 MySQL 形态的探测语句，
     * 实测直接 `SQLSTATE[42704] 未认可的配置参数 "tables"` —— 那是包侧的缺陷，
     * 而本夹具要的是「表在不在」这个事实，所以自己去摸一次并只认 42P01。
     */
    private function historyTableMissing(): bool
    {
        $thrown = $this->capture(static fn () => Db::select('SELECT 1 FROM kode_schedule_runs LIMIT 1'));
        if ($thrown === null) {
            return false;
        }

        for ($e = $thrown; $e !== null; $e = $e->getPrevious()) {
            if (str_contains($e->getMessage(), '42P01')) {
                return true;
            }
        }

        self::fail('探测执行历史表时抛出的是另一种错误：' . $thrown->getMessage());
    }

    /** 把默认连接指向一个必然连不上的地址：任何真的摸库都会立刻抛。 */
    private function useUnreachableDb(): void
    {
        Db::addConnection($this->scratchDbPrefix() . 'dead', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => self::DEAD_DSN_PORT,
            'database' => 'zz_none',
            'username' => 'zz',
            'password' => '',
        ]);
        Db::setDefaultConnection($this->scratchDbPrefix() . 'dead');
    }

    private function adminPdo(): \PDO
    {
        return new \PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres', 'root', '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** @return list<string> 库里现存的、属于本测试前缀的库（别扩到别人的 scratch）。 */
    private function existingScratchDbs(): array
    {
        $rows = $this->adminPdo()->query(
            "SELECT datname FROM pg_database WHERE datname LIKE '" . $this->scratchDbPrefix() . "\\_%'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        return is_array($rows) ? $rows : [];
    }

    /**
     * 建一个一次性库并把默认连接指过去。
     *
     * @return null|string 成功返回库名；不可达返回 null（调用方 skip，且原因写进 skip 文案）
     */
    private function scratchPgsql(): ?string
    {
        try {
            $pdo = $this->adminPdo();
        } catch (\Throwable $e) {
            $this->skipReason = $e->getMessage();

            return null;
        }

        $name = $this->scratchDbPrefix() . '_' . substr(md5((string) microtime(true)), 0, 8);
        $pdo->exec('CREATE DATABASE ' . $name);
        $this->scratchDbs[] = $name;

        Db::addConnection($name, [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => $name,
            'username' => 'root',
            'password' => '',
        ]);
        Db::setDefaultConnection($name);

        return $name;
    }

    /** scratch 库不可达时的统一 skip（不静默跳过：原因必须印出来）。 */
    private function skipWithoutPgsql(): void
    {
        self::markTestSkipped('本机 pgsql 不可达，跳过真库对照（原因：' . $this->skipReason . '）');
    }
}
