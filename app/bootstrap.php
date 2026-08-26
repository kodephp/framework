<?php

/*
 * 应用引导（在 ServiceProvider 全部启动后加载）
 *
 * 适合在此注册：全局事件监听器、定时任务、第三方 SDK 初始化等。
 *
 * AOP 切面不再需要在此手动注册——AopServiceProvider 已按 config/aop.php 的 paths
 * 自动发现 app/Aop（及约定目录 app/Aspects）下标注 #[Aspect] 的类并织入。
 */

use Kode\Framework\Facades\Event;

// 事件监听：收到 PingEvent 时记录日志
Event::listen(\app\events\PingEvent::class, function (\app\events\PingEvent $e): void {
    logger()->info('收到 PingEvent: ' . $e->message);
});
