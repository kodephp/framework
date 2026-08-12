<?php

/*
 * 事件监听配置（kode/event）
 *
 * listeners ：事件 => 监听器（闭包 / 类名 / [类, 方法]）。
 * subscribe ：订阅者类名列表（实现 Kode\Event\SubscriberInterface，
 *             一个类通过 subscribe(Dispatcher) 批量注册多个监听器）。
 */

return [
    'listeners' => [
        // 示例：
        // \App\Events\UserRegistered::class => [\App\Listeners\SendWelcomeEmail::class],
    ],

    'subscribe' => [
        // 示例：
        // \App\Listeners\UserEventSubscriber::class,
    ],
];
