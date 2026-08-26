<?php

declare(strict_types=1);

namespace Kode\Framework\Process;

/**
 * 槽位绑定装饰器：把一个 worker 绑定到固定槽位，作为独立 Daemon 运行。
 *
 * 背景：kode/process 的 Daemon 对每个 worker fork 出的子进程不暴露「我是第几个」，
 * task 回调拿不到槽位号。为支持 workerman 式「仅第 N 个进程执行」语义，
 * ProcessManager 把一个多实例 worker 按生效槽位拆成多个「单槽位 Daemon」：
 * 每个 SlotWorker 固定 slot、由 Daemon workers(1) 托管（fork / Timer 周期 / 重生 /
 * 优雅退出全部沿用底层），task 闭包捕获 slot 后转发给内层 worker。
 *
 * 行为对齐原语义：
 *   - onStart()/onStop() 在各自子进程首个 tick / 退出前执行（与原多实例一致）；
 *   - 崩溃隔离更彻底：每个槽位拥有独立的监督进程与重生预算。
 */
final class SlotWorker extends Worker
{
    public function __construct(
        private Worker $inner,
        private int $slot
    ) {
    }

    public function name(): string
    {
        return $this->inner->name() . ':' . $this->slot;
    }

    public function handle(int $slot = 0): void
    {
        // 内层未感知槽位（handle() 零参）时，多实例本会各自执行一遍——保持该行为。
        $params = (new \ReflectionMethod($this->inner, 'handle'))->getNumberOfParameters();
        if ($params > 0) {
            $this->inner->handle($this->slot);
        } else {
            $this->inner->handle();
        }
    }

    public function interval(): float
    {
        return $this->inner->interval();
    }

    public function instances(): int
    {
        return 1;
    }

    public function slots(): array
    {
        return [$this->slot];
    }

    public function once(): bool
    {
        return false;
    }

    public function onStart(): void
    {
        $this->inner->onStart();
    }

    public function onStop(): void
    {
        $this->inner->onStop();
    }
}