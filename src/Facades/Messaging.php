<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Framework\Messaging\Messenger;

/**
 * 消息门面：Messaging::publish($channel, $data) / Messaging::subscribe(...)。
 */
final class Messaging extends Facade
{
    protected static function id(): string
    {
        return Messenger::class;
    }
}
