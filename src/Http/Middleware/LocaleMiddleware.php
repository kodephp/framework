<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Context\Context;
use Kode\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 国际化中间件（Accept-Language 自动选语种）
 *
 * 解析请求头 Accept-Language，命中 config/locale.php 的 available 后切换语种，
 * 并在响应头写入 Content-Language，便于链路与前端识别。
 * 行为由 config/locale.php 的 enabled 开关控制，默认开启。
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->config['enabled'])) {
            return $handler->handle($request);
        }

        $locale = $this->resolve($request);
        if ($locale !== null) {
            // 写入请求上下文（kode/context，按 fiber/协程隔离），而非改写共享单例，
            // 避免并发请求之间语种互相污染。Translator::getLocale()/trans() 会自动读取。
            Context::set('locale', $locale);
        }

        $response = $handler->handle($request);

        if ($locale !== null) {
            $response = $response->withHeader('Content-Language', $locale);
        }

        return $response;
    }

    /**
     * 从 Accept-Language 解析首选可用语种；无法匹配可用列表则返回 null。
     */
    private function resolve(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Accept-Language');
        if ($header === '') {
            return null;
        }

        $first = trim(explode(';', explode(',', $header)[0])[0]);
        if ($first === '') {
            return null;
        }

        $available = $this->config['available'] ?? [];
        if ($available === []) {
            return $first;
        }

        if (in_array($first, $available, true)) {
            return $first;
        }

        // 退而求其次：语言前缀匹配（如 zh 命中 zh-CN）。
        $base = explode('-', $first)[0];
        foreach ($available as $av) {
            if (str_starts_with($av, $base . '-') || str_starts_with($first, explode('-', (string) $av)[0] . '-')) {
                return $av;
            }
        }

        return null;
    }
}
