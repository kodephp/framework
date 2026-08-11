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
    }
}
