<?php

declare(strict_types=1);

namespace App\Plugins;

final class DemoService
{
    public function greet(): string
    {
        return 'hello from demo plugin';
    }
}
