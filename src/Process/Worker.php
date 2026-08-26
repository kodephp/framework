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
 *   - instances() ：并行实例数（fork 的子进程数），默认 1。
 *   - slots()     ：声明「执行槽位」列表（0 起）。默认 [] 表示全部实例都执行；
 *                 返回 [0] 时仅实例 0（主进程槽位）执行，典型用于「只让一个进程干活
 *                 的递归扫描/清理/发布任务」，避免多实例重复执行。
 *   - once()       ：声明该 worker 为一次性任务（启动时同步执行一遍即完成，不常驻）。
 *   - onStart()/onStop()：生命周期钩子（建连/清理等；分别在 worker 子进程启动首 tick 与退出前各一次）。
 *
 * 槽位感知：若子类把 handle 声明为 handle(int $slot = 0)（多一个可选 int 参数），
 * 运行器会把当前槽位传进来；不声明则维持旧签名 handle()，多实例下每实例各执行一次。
 *
 * 运行器对「真实 fork 启动」与「无 fork 逻辑验证（dryRun）」做了分离：
 * 单元测试与环境无 pcntl 时，用 ProcessManager::dryRun() 同步跑一遍 handle()
 * 验证逻辑；生产 CLI 用 ProcessManager::start() 委托 Daemon 真正 fork 出常驻进程。
 *
 * 示例：
 *   final class HeartbeatWorker extends Worker
 *   {
 *       public function name(): string { return 'heartbeat'; }
 *       public function interval(): float { return 5.0; }
 *       // 只有实例 0 干活：handle(int $slot = 0) { if ($slot > 0) return; /* 递归扫描 *\/ }
 *       public function slots(): array { return [0]; }
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
     * 执行槽位列表（从 0 开始的实例编号）。
     *
     * 返回 [] 表示全部实例都执行（默认，等价 instances() 个实例同时跑）。
     * 返回 [0] 表示仅实例 0（常称「主进程槽位」）执行——用于需要避免多实例
     * 重复执行的任务（递归扫描、定时发布、清理等），其余实例保持存活占位。
     */
    public function slots(): array
    {
        return [];
    }

    /**
     * 是否一次性任务（启动时同步执行一遍即完成，不 fork 常驻）。
     *
     * 默认 false。设为 true 的 worker 在 ProcessManager::start() 时以
     * onStart() → handle(slot) → onStop() 顺序执行每个生效槽位各一次后结束，
     * 适用于「进程启动时跑一次的迁移 / 预热 / 清点任务」。
     */
    public function once(): bool
    {
        return false;
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
