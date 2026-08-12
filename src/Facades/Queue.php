<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;

/**
 * 队列门面：Queue::push($job, $data) / later() / pop() / size()。
 *
 * 解析目标为 Kode\Queue\Queue（经容器单例）。
 */
final class Queue extends Facade
{
    protected static function id(): string
    {
        return \Kode\Queue\Queue::class;
    }
}
