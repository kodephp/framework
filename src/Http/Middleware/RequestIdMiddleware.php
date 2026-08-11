<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求追踪中间件。
 *
 * 为每个入站请求生成（或透传客户端）唯一的 X-Request-Id，并回写到响应头，
 * 便于在网关、日志、链路追踪系统中串联一次完整调用。
 *
 * 优先级应最高（最先 pipe），以保证后续中间件与业务日志都能取到该 ID。
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $this->resolveId($request);

        // 把 ID 放进请求属性，下游日志/Handler 可取用。
        $request = $request->withAttribute('request_id', $id);

        /** @var Response $response */
        $response = $handler->handle($request);

        if (!empty($this->config['enabled'])) {
            $response = $response->header('X-Request-Id', $id);
        }

        return $response;
    }

    private function resolveId(ServerRequestInterface $request): string
    {
        if (!empty($this->config['request_id_allow_client'])) {
            $client = $request->getHeaderLine('X-Request-Id');
            if ($client !== '' && preg_match('/^[A-Za-z0-9_\-.:]{1,64}$/', $client) === 1) {
                return $client;
            }
        }

        return $this->uuid4();
    }

    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
