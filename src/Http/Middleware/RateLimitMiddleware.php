<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Framework\Facades\RateLimit;
use Kode\Framework\Http\Resp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 限流中间件（kode/limiting）
 *
 * 按「路由 + 客户端 IP」维度限流；超限返回 429 并附带标准限流响应头
 * （X-RateLimit-Limit / Remaining / Reset、Retry-After，遵循 IETF 草案）。
 * 限流总开关见 config/limiting.enabled。
 *
 * 演示 PHP 8.3 新特性：#[\Override] 显式标注对接口方法的重写。
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!config('limiting.enabled', true)) {
            return $handler->handle($request);
        }

        $key = 'rl:' . $this->routeKey($request) . ':' . $this->clientIp($request);
        $result = RateLimit::consume($key, 1);

        if ($result->isDenied()) {
            return $this->tooMany($result->toHeaders());
        }

        $response = $handler->handle($request);
        foreach ($result->toHeaders() as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    private function tooMany(array $headers): ResponseInterface
    {
        $response = Resp::fail('请求过于频繁，请稍后再试', 'E429', 429);

        foreach ($headers as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        return $response;
    }

    private function routeKey(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        // 把路由参数（/users/42）归一为模板（/users/{id}）以共享限额
        return preg_replace('/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/{uuid}', (string) preg_replace('/\/\d+(?=\/|$)/', '/{id}', $path));
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('x-forwarded-for');
        if ($forwarded !== '') {
            return explode(',', $forwarded)[0];
        }

        return $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
    }
}
