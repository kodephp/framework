<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Queue\Queue;

/**
 * 队列门面：Queue::push($job, $data) / later() / pop() / size()。
 */
final class Queue extends Facade
{
    protected static function id(): string
    {
        return Queue::class;
    }
}
