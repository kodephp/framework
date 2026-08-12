<?php

declare(strict_types=1);

namespace Kode\Framework\Lifecycle;

/**
 * 应用启动完成事件（每个进程 boot 一次）。
 *
 * 用于「进程级」一次性初始化：预热缓存、注册信号、启动后台协程等。
 * 注意：多进程下 master 预热与每个 worker 各触发一次。
 */
final class ApplicationBooted
{
    public function __construct(
        public readonly string $basePath,
        public readonly string $env,
    ) {
    }
}
