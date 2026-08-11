<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\HttpClient\Factory;
use Kode\HttpClient\HttpClient;

/**
 * HTTP 客户端服务提供者（kode/http-client）。
 */
final class HttpClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(HttpClient::class, function (): HttpClient {
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('http-client', []);

            return Factory::createSimple($config);
        });

        $this->container->alias('http', HttpClient::class);
    }
}
