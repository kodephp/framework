<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\HttpClient\HttpClient;

/**
 * HTTP 客户端门面：Http::get($url) / Http::post($url, $body)。
 */
final class Http extends Facade
{
    protected static function id(): string
    {
        return HttpClient::class;
    }
}
