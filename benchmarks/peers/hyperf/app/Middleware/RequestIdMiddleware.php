<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * 链路 ID 中间件（对标 kode/config/security.php request_id 开启态 + webman 同构中间件）。
 */
class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = (string) ($request->getHeaderLine('x-request-id') ?: bin2hex(random_bytes(12)));
        $response = $handler->handle($request);

        return $response->withHeader('X-Request-Id', $id);
    }
}
