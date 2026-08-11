<?php

declare(strict_types=1);

namespace App\Events;

/**
 * 演示事件
 */
final class PingEvent
{
    public function __construct(public readonly string $message)
    {
    }
}
