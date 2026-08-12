<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\SubscriberInterface;
use PHPUnit\Framework\TestCase;

/**
 * 事件订阅者（config/event.php 的 subscribe 数组）机制验证。
 *
 * 框架 EventServiceProvider 在 boot 阶段读取 event.subscribe，对每个类
 * 实例化并调用 kode/event 的 Dispatcher::subscribe()，一个订阅者可批量注册多个监听器。
 * 本测试验证 kode/event 的订阅机制本身（框架仅做配置驱动的薄转发）。
 */
final class EventSubscriberTest extends TestCase
{
    public function testSubscriberRegistersMultipleListeners(): void
    {
        $dispatcher = new Dispatcher();

        $subscriber = new class extends \stdClass implements SubscriberInterface
        {
            /** @var list<string> */
            public array $log = [];

            public function subscribe(Dispatcher $dispatcher): void
            {
                $dispatcher->listen('app.ping', function (): void {
                    $this->log[] = 'ping';
                });
                $dispatcher->listen('app.pong', function (): void {
                    $this->log[] = 'pong';
                });
            }
        };

        $dispatcher->subscribe($subscriber);

        $dispatcher->dispatch('app.ping');
        $dispatcher->dispatch('app.pong');

        self::assertSame(['ping', 'pong'], $subscriber->log);
    }
}
