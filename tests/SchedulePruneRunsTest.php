<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Db\Db;
use Kode\Framework\Scheduling\ScheduleDispatcher;

/**
 * `pruneRunHistory()` 是一次**按天数删行**的破坏性操作，因此它的两条口径都必须钉住：
 *  1. 天数越界不许被静默夹取 —— 旧写法 `NOW() - INTERVAL '-5 days'` 等于 `NOW() + 5 天`，
 *     也就是「把整张执行历史删光」，而调用方只看到返回值。
 *  2. 删除失败不许回 0 —— 旧写法 `catch (\Throwable) { return 0; }` 把「一条都没删成」
 *     和「没有过期数据可删」压成同一个数字，而管理端把它印成「已清理 0 条（超过 N 天）」并记一条 info 日志。
 *     这个接口是定时清理任务与后台按钮共同的落点，回 0 就是让所有人以为保留期在正常工作。
 *
 * 另外天数以前是**拼进 SQL** 的（`INTERVAL '{$days} days'`）。这里改成绑定参数，
 * 正向对照（真库跑一次，只删超期那一行）同时证明绑定真的生效、cutoff 仍是服务端算的。
 */
final class SchedulePruneRunsTest extends TestCase
{
    private const DEAD_DSN_PORT = 1;

    /** @var list<string> 本测试自己建的临时库，tearDown 逐个删（只认 kode_zz_ 前缀）。 */
    private array $scratchDbs = [];

    protected function tearDown(): void
    {
        // 顺序要紧：先断开 Db 的连接，再 DROP DATABASE。反了会撞
        // "database ... is being accessed by other users"，而那句被 catch 吞掉之后
        // 每跑一轮就留一座临时库（本机实测攒下三个）。
        try {
            Db::disconnect();
        } catch (\Throwable) {
            // 忽略。
        }

        $left = [];
        foreach ($this->scratchDbs as $name) {
            try {
                $pdo = $this->adminPdo();
                $pdo->exec('DROP DATABASE IF EXISTS ' . $name);
                if (in_array($name, $this->existingScratchDbs(), true)) {
                    $left[] = $name;
                }
            } catch (\Throwable $e) {
                $left[] = $name . '（' . $e->getMessage() . '）';
            }
        }
        $this->scratchDbs = [];

        parent::tearDown();

        self::assertSame([], $left, '临时库没清掉，下一轮的 CREATE DATABASE 会撞名或攒垃圾');
    }

    /** @return list<string> 库里现存的 kode_zz_prune_ 前缀库（本测试专属，别扩到别人的 scratch）。 */
    private function existingScratchDbs(): array
    {
        $rows = $this->adminPdo()->query(
            "SELECT datname FROM pg_database WHERE datname LIKE 'kode_zz_prune\\_%'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        return is_array($rows) ? $rows : [];
    }

    /* ==================== 1. 越界天数：拒，而不是夹 ==================== */

    public function test_prune_refuses_a_non_positive_retention(): void
    {
        $this->useUnreachableDb();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/保留天数/');

        (new ScheduleDispatcher())->pruneRunHistory(0);
    }

    /**
     * 负数是这条线上最危险的一个输入：旧 SQL 拼出来是 `started_at < NOW() + 5 days`，
     * 于是整张执行历史被删光，而返回值是一个「看着像正常清理」的行数。
     */
    public function test_prune_refuses_a_negative_retention_that_would_wipe_the_table(): void
    {
        $this->useUnreachableDb();

        $this->expectException(\InvalidArgumentException::class);

        (new ScheduleDispatcher())->pruneRunHistory(-5);
    }

    /** 上限挡的是「一次误传把保留期改成几十年」——同样不许静默接受。 */
    public function test_prune_refuses_an_absurd_retention(): void
    {
        $this->useUnreachableDb();

        $this->expectException(\InvalidArgumentException::class);
        // 报错里必须点名那个上限，否则「越界」和「非法类型」在响应上分不开。
        $this->expectExceptionMessageMatches('/3650/');

        (new ScheduleDispatcher())->pruneRunHistory(3651);
    }

    /**
     * 边界反对照：刚好在上限内的值必须**放行**（连接是死的，所以它抛的是数据库异常而不是入参异常）。
     * 少了这一条，把判据写成 `> 30` 也能让上面那三条「拒」的用例全绿。
     */
    public function test_the_upper_bound_itself_is_accepted(): void
    {
        $this->useUnreachableDb();

        try {
            (new ScheduleDispatcher())->pruneRunHistory(ScheduleDispatcher::MAX_RETENTION_DAYS);
            self::fail('死连接上居然返回成功了');
        } catch (\InvalidArgumentException $e) {
            self::fail('上限内的值被判成越界：' . $e->getMessage());
        } catch (\Throwable $e) {
            // 走到这里说明判据放行了、真的去摸库了 —— 死连接报的就是该抛的东西。
            self::assertStringNotContainsString('保留天数', $e->getMessage());
        }
    }

    /**
     * 「越界一律在碰到数据库之前拒」——连接是死的，所以只要实现先去摸库，
     * 这里抛出的就是连接异常而不是 InvalidArgumentException。
     */
    public function test_out_of_range_days_never_reach_the_database(): void
    {
        $this->useUnreachableDb();

        foreach ([0, -1, 100000, PHP_INT_MIN] as $days) {
            try {
                (new ScheduleDispatcher())->pruneRunHistory($days);
                self::fail("days={$days} 没有被拒");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString((string) $days, $e->getMessage());
            }
        }
    }

    /* ==================== 2. 删除失败：回传错误，而不是 0 ==================== */

    public function test_a_failing_delete_propagates_instead_of_reporting_zero_rows(): void
    {
        $this->useUnreachableDb();

        // 注意「必须抛」这一条与返回 0 的区别才是重点：旧写法永远回 0，
        // 于是管理端拿到的是「成功清理了 0 条」。
        $thrown = null;
        try {
            (new ScheduleDispatcher())->pruneRunHistory(30);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, '删除失败被吞掉了：调用方会把「一条没删成」渲染成「清理了 0 条」');
        self::assertNotInstanceOf(\InvalidArgumentException::class, $thrown, '连接失败不该被说成入参非法');

        // 「抛了」还不够：抛的那条必须能追到真因。旧写法在 catch 里先调 logger()，
        // 而未引导的进程里 logger() 自己就抛「服务容器尚未启动」——那会把真正的连接失败
        // 顶替掉，排障方向整个错一位（清理任务恰恰常在容器之外跑）。
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

    /* ==================== 3. 正向对照：真删，且只删超期的那行 ==================== */

    public function test_prune_deletes_only_the_expired_rows_and_returns_the_real_count(): void
    {
        $db = $this->scratchPgsql();
        if ($db === null) {
            self::markTestSkipped('本机 pgsql 不可达，跳过真库对照（原因：' . $this->skipReason . '）');
        }

        $dispatcher = new ScheduleDispatcher();
        // 走真实写入口：logRun 建表 + 插一行「刚刚执行」。
        self::assertTrue($dispatcher->logRun('zz-prune-fresh', 'success'), '写入执行历史失败，后面的断言是空的');
        // 另一行手工倒拨到 40 天前（logRun 只能写 NOW()）。
        Db::statement(
            "INSERT INTO kode_schedule_runs (task_name, status, started_at) VALUES (?, ?, NOW() - INTERVAL '40 days')",
            ['zz-prune-stale', 'failed']
        );

        $deleted = $dispatcher->pruneRunHistory(7);

        self::assertSame(1, $deleted, '删除条数不是真的（超期那行该删掉，新那行不该动）');
        $left = Db::select('SELECT task_name FROM kode_schedule_runs');
        self::assertSame(
            ['zz-prune-fresh'],
            array_column($left, 'task_name'),
            '保留窗口内的行被删了，或该删的行还在'
        );
    }

    /* ==================== 工具 ==================== */

    /** 把默认连接指向一个必然连不上的地址：任何真的摸库都会立刻抛。 */
    private function useUnreachableDb(): void
    {
        Db::addConnection('zz_prune_dead', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => self::DEAD_DSN_PORT,
            'database' => 'zz_none',
            'username' => 'zz',
            'password' => '',
        ]);
        Db::setDefaultConnection('zz_prune_dead');
    }

    private function adminPdo(): \PDO
    {
        return new \PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres', 'root', '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * 建一个 `kode_zz_` 前缀的一次性库并把默认连接指过去。
     *
     * 为什么不用 kode_app：这条清理是**全表按时间删行**，跑在真库上等于删掉别人的执行历史。
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

        $name = 'kode_zz_prune_' . substr(md5((string) microtime(true)), 0, 8);
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

    /** @var string 探库失败原因（写进 skip 文案，别让「没跑」和「跑过且绿」长得一样）。 */
    private string $skipReason = '';
}
