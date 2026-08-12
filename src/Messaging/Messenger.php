<?php

declare(strict_types=1);

namespace Kode\Framework\Messaging;

use Kode\Messaging\Messaging;

/**
 * 消息门面包装（kode/messaging）。
 *
 * kode/messaging 自身是静态门面，此处包一层实例，保证框架门面风格统一
 * （resolve('messaging') 拿到的是实例，而不是静态类）。
 */
final class Messenger
{
    public function __construct(array $config)
    {
        Messaging::configure($config);
    }

    /**
     * 取得一个消息总线（默认 memory 进程内）。
     */
    public function bus(?string $driver = null, array $config = []): object
    {
        return Messaging::pubsub($driver, $config);
    }

    /**
     * 发布消息到频道。
     */
    public function publish(string $channel, mixed $payload, ?string $driver = null): void
    {
        $this->bus($driver)->publish($channel, $payload);
    }

    /**
     * 订阅频道（返回订阅 ID，可 unsubscribe）。
     */
    public function subscribe(string $channel, callable $handler, ?string $driver = null): string
    {
        return $this->bus($driver)->subscribe($channel, $handler);
    }

    /**
     * 取得 kode/messaging 静态门面类名（需要 server/client/websocket 等高级能力时，
     * 直接用 {@see Messaging} 的静态方法：Messaging::server() / Messaging::client() 等）。
     *
     * 注意：Messaging 是静态门面（构造器私有，不可实例化），此处返回其类名供静态调用。
     */
    public function raw(): string
    {
        return Messaging::class;
    }
}
