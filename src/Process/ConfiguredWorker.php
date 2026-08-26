<?php

declare(strict_types=1);

namespace Kode\Framework\Process;

use ReflectionMethod;

/**
 * 声明式配置装饰器：把 config/process.php 条目里的声明键覆盖到 worker 行为上。
 *
 * 兼容原有两种写法（类名 / class+config 构造参数），并在条目上新增可选声明键：
 *
 *   'workers' => [
 *       \app\process\CleanupWorker::class,                          // 写法 1：无参
 *       ['class' => \app\process\FooWorker::class, 'config' => [...]], // 写法 2：构造参数
 *       [                                                          // 写法 3：声明式增强
 *           'class'    => \app\process\BarWorker::class,
 *           'config'   => [...],        // 可选：构造参数
 *           'count'    => 3,            // 可选：并行实例数（等价 instances()）
 *           'interval' => 5.0,          // 可选：轮询间隔秒
 *           'once'     => false,        // 可选：仅启动时执行一次
 *           'slots'    => [0],          // 可选：仅这些实例执行（[0] = 仅主进程槽位）
 *       ],
 *   ]
 *
 * 优先级约定：类内对 interval()/instances() 等方法的重写优先于声明键；
 * 声明键只在 worker 未重写对应方法（使用基类默认值）时生效。
 */
final class ConfiguredWorker extends Worker
{
    public function __construct(
        private Worker $inner,
        private array $declared = []
    ) {
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function handle(int $slot = 0): void
    {
        // 内层 worker 若声明了 handle(int $slot = 0)（感知槽位），转发槽位；否则维持 handle()。
        $params = (new ReflectionMethod($this->inner, 'handle'))->getNumberOfParameters();
        if ($params > 0) {
            $this->inner->handle($slot);
        } else {
            $this->inner->handle();
        }
    }

    public function interval(): float
    {
        if (isset($this->declared['interval'])) {
            return (float) $this->declared['interval'];
        }

        return $this->inner->interval();
    }

    public function instances(): int
    {
        $base = $this->inner->instances();
        if (isset($this->declared['count'])) {
            $base = max(1, (int) $this->declared['count']);
        }

        return max(1, $base);
    }

    public function slots(): array
    {
        if (isset($this->declared['slots'])) {
            return array_values(array_unique(array_map('intval', (array) $this->declared['slots'])));
        }

        return $this->inner->slots();
    }

    public function once(): bool
    {
        if (isset($this->declared['once'])) {
            return (bool) $this->declared['once'];
        }

        return $this->inner->once();
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