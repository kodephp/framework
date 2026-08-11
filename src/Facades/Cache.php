<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Cache\CacheManager;
use Kode\Core\Facade;

/**
 * 缓存门面：Cache::get('k') / Cache::set('k', 'v', ttl)。
 */
final class Cache extends Facade
{
    protected static function id(): string
    {
        return CacheManager::class;
    }
}
