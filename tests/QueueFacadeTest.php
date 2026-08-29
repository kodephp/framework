<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Queue\Queue;
use PHPUnit\Framework\TestCase;

/**
 * 队列门面冒烟测试。
 *
 * 验证 Queue 门面能正常加载并解析到 kode/queue 的 Queue 实例。
 * 此前 Queue 门面用 `use Kode\Queue\Queue;` 导入，与 `final class Queue`
 * 同名冲突，文件无法被 PHP 加载（与 DB 门面同类问题），初学者一用即 fatal。
 * 本测试守护该回归：门面类必须可加载、id 指向正确的目标类。
 *
 * 注意：全局助手 queue() 大小写不敏感会占用 QUEUE 这个名字，故直接用完全限定名引用。
 */
final class QueueFacadeTest extends TestCase
{
    public function testFacadeLoadsAndResolves(): void
    {
        Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);

        // 门面背后实例是 kode/queue 的 Queue。
        self::assertInstanceOf(Queue::class, \Kode\Framework\Facades\Queue::getInstance());

        // 门面 id 必须指向 \Kode\Queue\Queue::class（不能用 use 导入导致同名冲突）。
        self::assertSame(\Kode\Queue\Queue::class, \Kode\Framework\Facades\Queue::getServiceId());
    }
}
