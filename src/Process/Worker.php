<?php

declare(strict_types=1);

namespace Kode\Framework\Process;

/**
 * 常驻进程 Worker 抽象基类（kode/process Daemon 的薄适配）。
 *
 * 框架不再自研底层运行器——真正的「fork 多进程 + Timer 周期 + 监督重生 + 优雅退出」
 * 由 kode/process 的 Daemon（v5.2.31+ 内置）提供。本类只定义业务契约，用户只需实现：
 *
 *   - name()  ：worker 唯一名称（用于日志 / 状态 / pid 文件）。
 *   - handle()：单次工作量（由 Daemon 按 interval() 周期调用）。
 *
 * 可选覆盖：
 *   - interval()   ：轮询间隔（秒，浮点），默认 1.0。
 *   - instances()  ：并行实例数（Daemon fork 的子进程数），默认 1。
 *   - onStart()/onStop()：生命周期钩子（建连/清理等；分别在 worker 子进程启动首 tick 与退出前各一次）。
 *
 * 运行器对「真实 fork 启动」与「无 fork 逻辑验证（dryRun）」做了分离：
 * 单元测试与环境无 pcntl 时，用 ProcessManager::dryRun() 同步跑一遍 handle()
 * 验证逻辑；生产 CLI 用 ProcessManager::start() 委托 Daemon 真正 fork 出常驻进程。
 *
 * 示例：
 *   final class HeartbeatWorker extends Worker
 *   {
 *       public function name(): string { return 'heartbeat'; }
 *       public function handle(): void { /* 周期心跳 *\/ }
 *       public function interval(): float { return 5.0; }
 *   }
 */
abstract class Worker
{
    /**
     * worker 唯一名称。
     */
    abstract public function name(): string;

    /**
     * 单次工作量（按 interval() 周期被运行器调用）。
     *
     * 实现里请自行吞掉内部异常或记录日志，避免单次失败拖垮整个 worker 循环。
     */
    abstract public function handle(): void;

    /**
     * 轮询间隔（秒）。浮点，最小 0.001。
     */
    public function interval(): float
    {
        return 1.0;
    }

    /**
     * 并行实例数（fork 出的子进程数量）。>=1。
     */
    public function instances(): int
    {
        return 1;
    }

    /**
     * 子进程启动后、进入循环前的钩子（建连、预热等）。
     */
    public function onStart(): void
    {
    }

    /**
     * 收到停止信号、退出循环后的钩子（释放资源等）。
     */
    public function onStop(): void
    {
    }
}
