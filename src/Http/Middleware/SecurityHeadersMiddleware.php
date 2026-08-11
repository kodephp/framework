<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 安全响应头全局中间件。
 *
 * 对所有响应统一追加工业级安全头（config/security.php 驱动）：
 *   - X-Content-Type-Options: nosniff
 *   - X-Frame-Options: DENY
 *   - Referrer-Policy
 *   - Strict-Transport-Security（HSTS）
 *   - X-XSS-Protection（兼容旧浏览器）
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
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

        /** @var Response $response */
        $response = $handler->handle($request);

        if (!empty($this->config['nosniff'])) {
            $response = $response->header('X-Content-Type-Options', 'nosniff');
        }

        $frame = (string) ($this->config['frame_options'] ?? '');
        if ($frame !== '') {
            $response = $response->header('X-Frame-Options', $frame);
        }

        $referrer = (string) ($this->config['referrer_policy'] ?? '');
        if ($referrer !== '') {
            $response = $response->header('Referrer-Policy', $referrer);
        }

        $hsts = (string) ($this->config['hsts'] ?? '');
        if ($hsts !== '') {
            $response = $response->header('Strict-Transport-Security', $hsts);
        }

        $xss = (string) ($this->config['xss_protection'] ?? '');
        if ($xss !== '') {
            $response = $response->header('X-XSS-Protection', $xss);
        }

        return $response;
    }
}
