<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 跨域（CORS）全局中间件。
 *
 * 职责：
 *  1. OPTIONS 预检请求直接短路返回 204（带 CORS 头），不再进入路由。
 *  2. 其它请求的响应统一补全 Access-Control-* 头。
 *
 * 行为完全由 config/cors.php 驱动，不写死来源，便于多环境切换。
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->config['enabled'])) {
            return $handler->handle($request);
        }

        $origin = $this->resolveOrigin($request);

        // 预检请求：直接返回 204，避免进入业务路由。
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->apply($origin, Response::make('', 204));
        }

        $response = $handler->handle($request);

        return $this->apply($origin, $response);
    }

    /**
     * 根据配置解析本次响应允许的 Origin（携带凭证时禁止通配）。
     */
    private function resolveOrigin(ServerRequestInterface $request): string
    {
        $allowed = $this->config['allowed_origins'] ?? '*';
        $credentials = !empty($this->config['allow_credentials']);

        // 数组：按具体来源白名单匹配（命中才回显，否则不给来源头）。
        if (is_array($allowed)) {
            $reqOrigin = $request->getHeaderLine('Origin');
            return in_array($reqOrigin, $allowed, true) ? $reqOrigin : '';
        }

        // 字符串：'*' 表示通配；携带凭证时通配不安全，回退为请求来源。
        if ($allowed === '*') {
            return $credentials ? $request->getHeaderLine('Origin') : '*';
        }

        // 单字符串具体来源：原样回显。
        return $allowed;
    }

    private function apply(string $origin, Response $response): Response
    {
        if ($origin === '') {
            return $response;
        }

        $response = $response
            ->header('Access-Control-Allow-Origin', $origin)
            ->header('Access-Control-Allow-Methods', implode(', ', $this->config['allowed_methods'] ?? ['GET', 'POST']))
            ->header('Access-Control-Allow-Headers', implode(', ', $this->config['allowed_headers'] ?? ['Content-Type']))
            ->header('Access-Control-Max-Age', (string) ($this->config['max_age'] ?? 86400));

        if (!empty($this->config['exposed_headers'])) {
            $response = $response->header('Access-Control-Expose-Headers', implode(', ', $this->config['exposed_headers']));
        }

        if (!empty($this->config['allow_credentials'])) {
            $response = $response
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Vary', 'Origin');
        }

        return $response;
    }
}
