<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Framework\Observability\Metrics\MetricRegistry;

/**
 * 指标门面：Metrics::counter(...)->with([...])->inc() 等。
 */
final class Metrics extends Facade
{
    protected static function id(): string
    {
        return MetricRegistry::class;
    }
}
