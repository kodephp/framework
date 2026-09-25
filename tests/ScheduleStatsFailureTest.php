<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Db\Db;
use Kode\Framework\Scheduling\ScheduleDispatcher;

/**
 * `stats()` 是「任务健康度」的唯一数据源（后台 `/api/schedules/health` 与 `schedule:list` 都读它），
 * 而它以前把两件不同的事压成同一份载荷：
 *  1. **读不到**：`catch (\Throwable) { logger()->warning(...); return [] / 一条全 0 的行; }`。
 *     于是断链、缺列、无权限全部渲染成「这台系统没有任务跑过」。更糟的是 catch 里先调 `logger()`，
 *     在容器之外（定时清理任务、CLI）它自己就抛「服务容器尚未启动」，把真正的数据库原因顶掉。
 *  2. **没有记录**：单任务分支在 `total = 0` 时回 `success_rate = 100.0` —— 「一次都没跑过」被
 *     印成「成功率 100%」，而管理端那张卡片是照着它决策的。
 *
 * 现在的口径：**只有「表都还没建」算「没有历史」**（全新环境从没跑过任务，那是事实），其余失败一律抛，
 * 并且 `total = 0` 时成功率是 `null`（没有分母的比率不存在），不是 100。
 *
 * 注意本文件不用 `kode_app`：正向对照要往 `kode_schedule_runs` 插行，跑在真库上等于污染别人的执行历史。
 */
final class ScheduleStatsFailureTest extends TestCase
{
    private const DEAD_DSN_PORT = 1;

    /** @var list<string> 本测试自己建的临时库，tearDown 逐个删（只认 kode_zz_ 前缀）。 */
    private array $scratchDbs = [];

    private string $defaultConnection = '';

    /** @var list<string> 进本测试前已注册的连接名（用于把自己加的那几个摘掉）。 */
    private array $existingConnections = [];

    /** @var string 探库失败原因（写进 skip 文案，别让「没跑」和「跑过且绿」长得一样）。 */
    private string $skipReason = '';

    protected function setUp(): void
    {
        parent::setUp();

        // 记下原状再动手：本文件会 addConnection / setDefaultConnection，
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

    /* ==================== 1. 读不到必须抛，而不是「没有任务跑过」 ==================== */

    /**
     * 全任务汇总那条腿：断链时旧写法 `catch (\Throwable)` 回 `[]`，
     * 而后台把 `[]` 渲染成「执行成功率 100%」（因为没有一行 total>0）。
     */
    public function test_a_failing_aggregate_read_propagates_instead_of_looking_like_no_tasks(): void
    {
        $this->useUnreachableDb();

        $thrown = $this->capture(static fn () => (new ScheduleDispatcher())->stats());

        self::assertNotNull($thrown, '汇总读失败被吞了：调用方会把「一座库没读到」渲染成「这台系统没有任务跑过」');
        self::assertNotInstanceOf(\InvalidArgumentException::class, $thrown, '连接失败不该被说成入参非法');
        $this->assertReasonSurvived($thrown);
    }

    /**
     * 单任务那条腿更贵：旧写法在 catch 里回一条**合成行**，其中 `success_rate = 100.0`。
     * 于是「读不到」在页面上是一个绿色的 100%，比空表更容易被照着做决策。
     */
    public function test_a_failing_single_task_read_propagates_instead_of_reporting_a_perfect_rate(): void
    {
        $this->useUnreachableDb();

        $thrown = $this->capture(static fn () => (new ScheduleDispatcher())->stats('zz-stats-task'));

        self::assertNotNull($thrown, '单任务读失败被吞成了一条合成行');
        $this->assertReasonSurvived($thrown);
    }

    /* ==================== 2. 「没有记录」不等于「完美」 ==================== */

    /**
     * 表存在、该任务一行记录都没有：`total` 是 0（事实），成功率必须是 **null**（没有分母），
     * 而不是旧写法的 100.0。
     */
    public function test_a_task_without_runs_has_no_rate_rather_than_a_perfect_one(): void
    {
        if ($this->scratchPgsql() === null) {
            self::markTestSkipped('本机 pgsql 不可达，跳过真库对照（原因：' . $this->skipReason . '）');
        }

        $dispatcher = new ScheduleDispatcher();
        // 先建表（logRun 会顺手建），再查一个从没跑过的任务名。
        self::assertTrue($dispatcher->logRun('zz-stats-fixture', 'success'), '建表/写入失败，后面的断言是空的');

        $stats = $dispatcher->stats('zz-stats-never-run');

        self::assertArrayHasKey('total', $stats, 'total 这一维必须存在，否则前端会各自兜数');
        self::assertSame(0, (int) $stats['total'], '没有执行记录的任务 total 应为 0');
        self::assertArrayHasKey('success_rate', $stats, '成功率这一维必须存在，否则前端会兜成 0/100');
        self::assertNull($stats['success_rate'], '一次都没跑过的任务被回成 100% 成功率');
    }

    /**
     * 表都还没建（全新环境从没跑过任务）算「没有历史」，这是**唯一**允许不抛的失败：
     * 回空汇总而不是 500。
     */
    public function test_a_missing_history_table_is_no_history_rather_than_an_error(): void
    {
        if ($this->scratchPgsql() === null) {
            self::markTestSkipped('本机 pgsql 不可达，跳过真库对照（原因：' . $this->skipReason . '）');
        }

        self::assertTrue($this->historyTableMissing(), '前提：这座临时库里还没有执行历史表');

        $thrown = $this->capture(static fn () => (new ScheduleDispatcher())->stats());
        if ($thrown !== null) {
            self::fail('全新环境不该报错：' . $thrown->getMessage());
        }

        // 「没有历史」与「读到过行」在页面上必须是两件事，所以空汇总要能被判出来。
        $stats = (new ScheduleDispatcher())->stats();
        self::assertSame([], $stats, '缺表应当回空汇总（前端据此出「—」）');
    }

    /**
     * 反向对照（防上面那条「缺表放行」被写成「什么失败都放行」）：
     * 表在、但列被改坏（模拟一次没跑完的迁移）必须抛，不能被同一句 SQLSTATE 判断放过。
     */
    public function test_a_broken_column_still_propagates_after_the_missing_table_exemption(): void
    {
        if ($this->scratchPgsql() === null) {
            self::markTestSkipped('本机 pgsql 不可达，跳过真库对照（原因：' . $this->skipReason . '）');
        }

        $dispatcher = new ScheduleDispatcher();
        self::assertTrue($dispatcher->logRun('zz-stats-fixture', 'success'), '建表失败，前提不成立');
        Db::statement('ALTER TABLE kode_schedule_runs DROP COLUMN duration_ms');

        $thrown = $this->capture(static fn () => $dispatcher->stats());

        self::assertNotNull($thrown, '列缺失被当成「没有历史」放行了：那就是把 42703 渲染成空表');
        self::assertStringNotContainsString('服务容器尚未启动', $thrown->getMessage());
    }

    /* ==================== 3. 正向对照：真的有记录时算得出真的比率 ==================== */

    public function test_the_rate_is_the_real_ratio_when_there_are_runs(): void
    {
        if ($this->scratchPgsql() === null) {
            self::markTestSkipped('本机 pgsql 不可达，跳过真库对照（原因：' . $this->skipReason . '）');
        }

        $dispatcher = new ScheduleDispatcher();
        self::assertTrue($dispatcher->logRun('zz-stats-mixed', 'success'));
        self::assertTrue($dispatcher->logRun('zz-stats-mixed', 'failed'));

        $single = $dispatcher->stats('zz-stats-mixed');
        self::assertSame(2, (int) $single['total']);
        self::assertSame(1, (int) $single['succeeded']);
        self::assertSame(50.0, (float) $single['success_rate'], '成功率不是真比率');

        $all = $dispatcher->stats();
        self::assertArrayHasKey('zz-stats-mixed', $all, '全任务汇总里没有这一行，说明它被读丢了');
        self::assertSame(2, (int) $all['zz-stats-mixed']['total']);
    }

    /* ==================== 工具 ==================== */

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
     * 而本测试要的是「表在不在」这个事实，所以自己去摸一次并只认 42P01。
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
        Db::addConnection('zz_stats_dead', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => self::DEAD_DSN_PORT,
            'database' => 'zz_none',
            'username' => 'zz',
            'password' => '',
        ]);
        Db::setDefaultConnection('zz_stats_dead');
    }

    private function adminPdo(): \PDO
    {
        return new \PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres', 'root', '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** @return list<string> 库里现存的 kode_zz_stats_ 前缀库（本测试专属，别扩到别人的 scratch）。 */
    private function existingScratchDbs(): array
    {
        $rows = $this->adminPdo()->query(
            "SELECT datname FROM pg_database WHERE datname LIKE 'kode_zz_stats\\_%'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        return is_array($rows) ? $rows : [];
    }

    /**
     * 建一个 `kode_zz_stats_` 前缀的一次性库并把默认连接指过去。
     *
     * @return null|string 成功返回库名；不可达返回 null（调用方 skip）
     */
    private function scratchPgsql(): ?string
    {
        try {
            $pdo = $this->adminPdo();
        } catch (\Throwable $e) {
            $this->skipReason = $e->getMessage();

            return null;
        }

        $name = 'kode_zz_stats_' . substr(md5((string) microtime(true)), 0, 8);
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
}
