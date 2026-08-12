<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Event\Dispatcher;

/**
 * 事件服务提供者（kode/event）
 *
 * 支持 listen / once / subscribe / until，协程安全，可结合 kode/aop 做切面事件。
 */
final class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Dispatcher::class, fn(): Dispatcher => new Dispatcher());
        $this->container->alias('events', Dispatcher::class);
    }

    public function boot(): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->container->get(Dispatcher::class);

        /** @var array<string, mixed> $listeners */
        $listeners = (array) $this->config('event.listeners', []);

        foreach ($listeners as $event => $handlers) {
            foreach ((array) $handlers as $handler) {
                $dispatcher->listen((string) $event, $handler);
            }
        }

        // 订阅者：一个类批量注册多个监听器（webman/hyperf 的 subscribe 风格）。
        // config/event.php 的 subscribe 数组放「实现了 Kode\Event\SubscriberInterface 的类名」。
        /** @var array<int, class-string<\Kode\Event\SubscriberInterface>> $subscribers */
        $subscribers = (array) $this->config('event.subscribe', []);
        foreach ($subscribers as $subscriber) {
            if (is_string($subscriber) && class_exists($subscriber)) {
                $dispatcher->subscribe(new $subscriber());
            }
        }
    }
}
