<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Db\Db;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Tests\Support\ScheduleRunDbFixture;

/**
 * `runHistory()` / `tenantRunHistory()` 是后台「执行历史」抽屉与 `GET /api/schedules/{name}/history`
 * 的唯一数据源。它们与 {@see ScheduleStatsFailureTest} 治的是同一个病，只是另一条腿：
 *
 *     catch (\Throwable $e) { logger()->warning(...); return []; }
 *
 * 「一座库没读到」和「这个任务从没跑过」在出参上是同一个空数组，而前端拿到空数组渲染的是
 * 「暂无数据」。于是断链、缺列、无权限全部变成一张干净的空白表格 —— 而抽屉恰恰是有人来查
 * 故障时才点开的那个界面，它最不该在故障时撒谎。
 *
 * 口径与 `stats()` 完全一致（这也是它们共用一份夹具的原因）：
 *  - **只有「历史表还没建」算「没有历史」**，那是全新环境的事实，回空数组；
 *  - 其余读失败一律抛 {@see \RuntimeException}，且异常链里必须留着数据库的原始原因
 *    （catch 里那句 `logger()` 在未引导的容器里自己就抛，会把真因顶掉）；
 *  - 列缺失（42703）不许混进上一条的豁免里 —— 它和 42P01 只差一个状态码。
 *
 * 本文件不碰 `kode_app`：正向对照要往 `kode_schedule_runs` 插行，跑在真库上等于污染别人的执行历史。
 */
final class ScheduleHistoryFailureTest extends TestCase
{
    use ScheduleRunDbFixture;

    protected function scratchDbPrefix(): string
    {
        return 'kode_zz_hist';
    }

    /* ==================== 1. 读不到必须抛，而不是「这个任务没跑过」 ==================== */

    public function test_a_failing_task_history_read_propagates_instead_of_looking_like_no_runs(): void
    {
        $this->useUnreachableDb();

        $thrown = $this->capture(static fn () => (new ScheduleDispatcher())->runHistory('zz-hist-task'));

        self::assertNotNull($thrown, '单任务历史读失败被吞成空数组：页面会渲染成「这个任务从没跑过」');
        $this->assertReasonSurvived($thrown);
    }

    public function test_a_failing_tenant_history_read_propagates_instead_of_looking_like_no_runs(): void
    {
        $this->useUnreachableDb();

        $thrown = $this->capture(static fn () => (new ScheduleDispatcher())->tenantRunHistory(7));

        self::assertNotNull($thrown, '租户历史读失败被吞成空数组：与上一条同形，只是换了归属键');
        $this->assertReasonSurvived($thrown);
    }

    /* ==================== 2. 「没有历史」仍然不是错误 ==================== */

    public function test_a_missing_history_table_is_no_history_rather_than_an_error(): void
    {
        if ($this->scratchPgsql() === null) {
            $this->skipWithoutPgsql();
        }

        self::assertTrue($this->historyTableMissing(), '前提：这座临时库里还没有执行历史表');

        $dispatcher = new ScheduleDispatcher();

        $byTask = $this->capture(static fn () => $dispatcher->runHistory('zz-hist-never-run', 10));
        self::assertNull($byTask, '全新环境的历史抽屉不该报错：' . ($byTask?->getMessage() ?? ''));
        self::assertSame([], $dispatcher->runHistory('zz-hist-never-run', 10));

        $byTenant = $this->capture(static fn () => $dispatcher->tenantRunHistory(7, 10));
        self::assertNull($byTenant, '全新环境的租户历史不该报错：' . ($byTenant?->getMessage() ?? ''));
        self::assertSame([], $dispatcher->tenantRunHistory(7, 10));
    }

    /**
     * 反向对照（防止「缺表豁免」被写成「什么失败都豁免」）：表在、列被改坏（一次没跑完的迁移）
     * 必须抛。这一条对历史腿尤其重要 —— 它 SELECT 的列比 stats() 多（error_message / node_id），
     * 迁移漏跑其中任何一列都是真实场景。
     */
    public function test_a_broken_column_still_propagates_after_the_missing_table_exemption(): void
    {
        if ($this->scratchPgsql() === null) {
            $this->skipWithoutPgsql();
        }

        $dispatcher = new ScheduleDispatcher();
        self::assertTrue($dispatcher->logRun('zz-hist-fixture', 'success'), '建表/写入失败，前提不成立');
        Db::statement('ALTER TABLE kode_schedule_runs DROP COLUMN error_message');

        $thrown = $this->capture(static fn () => $dispatcher->runHistory('zz-hist-fixture'));

        self::assertNotNull($thrown, '列缺失被当成「没有历史」放行了：那就是把 42703 渲染成空白表格');
        self::assertStringNotContainsString('服务容器尚未启动', $thrown->getMessage());
    }

    /* ==================== 3. 正向对照：真的有记录时读得出真的行 ==================== */

    public function test_real_runs_come_back_through_both_history_reads(): void
    {
        if ($this->scratchPgsql() === null) {
            $this->skipWithoutPgsql();
        }

        $dispatcher = new ScheduleDispatcher();
        self::assertTrue($dispatcher->logRun('zz-hist-mixed', 'success', null, 12, 7));
        self::assertTrue($dispatcher->logRun('zz-hist-mixed', 'failed', null, 34, 7));
        // 另一归属：租户 8 的一条，用来证明 tenant 那条腿真的带了 WHERE 条件
        self::assertTrue($dispatcher->logRun('zz-hist-other', 'success', null, 5, 8));

        $rows = $dispatcher->runHistory('zz-hist-mixed', 10);
        self::assertCount(2, $rows, '任务历史读不到刚写进去的两行');
        self::assertSame('failed', $rows[0]['status'], '历史不是按时间倒序（最近一条在前）');
        self::assertSame(34, (int) $rows[0]['duration_ms']);

        $tenantRows = $dispatcher->tenantRunHistory(7, 10);
        self::assertCount(2, $tenantRows, '租户 7 的历史没读到刚写进去的两行');
        foreach ($tenantRows as $row) {
            self::assertSame(7, (int) $row['tenant_id'], '租户历史串到了别的归属：' . $row['task_name']);
        }
    }

    /** limit 是真的生效，而不是「读全表再让调用方自己截」。 */
    public function test_the_limit_actually_bounds_the_rows_returned(): void
    {
        if ($this->scratchPgsql() === null) {
            $this->skipWithoutPgsql();
        }

        $dispatcher = new ScheduleDispatcher();
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($dispatcher->logRun('zz-hist-limit', 'success', null, $i, 0));
        }

        self::assertCount(2, $dispatcher->runHistory('zz-hist-limit', 2), 'limit 没落到 SQL 上');
    }
}
