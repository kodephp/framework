<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Plugin;

final class DemoService
{
    public function greet(): string
    {
        return 'hello from demo plugin';
    }
}
