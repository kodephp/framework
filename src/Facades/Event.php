<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Event\Dispatcher;
use Kode\Core\Facade;

/**
 * 事件门面：Event::listen('event', fn) / Event::dispatch($event)。
 */
final class Event extends Facade
{
    protected static function id(): string
    {
        return Dispatcher::class;
    }
}
