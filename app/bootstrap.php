<?php

/*
 * 应用引导（在 ServiceProvider 全部启动后加载）
 *
 * 适合在此注册：AOP 切面、全局事件监听器、定时任务、第三方 SDK 初始化等。
 */

use Kode\Aop\Aop;
use Kode\Framework\Facades\Event;

// 事件监听：收到 PingEvent 时记录日志
Event::listen(\App\Events\PingEvent::class, function (\App\Events\PingEvent $e): void {
    logger()->info('收到 PingEvent: ' . $e->message);
});

// 注册 AOP 切面（需配合 Aop::proxy() 使用）
Aop::register(new \App\Aop\LogAspect());
