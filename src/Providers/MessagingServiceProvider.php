<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Messaging\Messenger;

/**
 * 消息服务提供者（kode/messaging）。
 */
final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Messenger::class, function (): Messenger {
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('messaging', []);

            return new Messenger($config);
        });

        $this->container->alias('messaging', Messenger::class);
    }
}
