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

            // H3：createSimple 仅建 Transport+Driver，不建 MiddlewareStack，导致
            // config/http-client.php 的 retry/circuit_breaker/limiter 等静默失效。
            // create 会按配置完整构建 MiddlewareStack，使重试/熔断/限流真正生效。
            return Factory::create($config);
        });

        $this->container->alias('http', HttpClient::class);
    }
}
