<?php

declare(strict_types=1);

namespace Kode\Framework\Security\Csrf;

use Kode\Framework\Http\Resp;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF 防护中间件（薄适配，按需挂载）。
 *
 * 作为**全局中间件**运行，但采用「按需命中」策略：
 *  - 若当前路由被 #[Csrf] 标记（或 config 的 auto_apply_unsafe 命中且不在排除路径），
 *    才执行令牌校验 / 派发；
 *  - 其余路由（含 /ping、纯 JWT 接口）仅做一次路由匹配 + 一次哈希查表即早退，
 *    无可感知开销 —— 即「企业级中间件加上也不影响响应」的保证。
 *
 * 令牌机制（标准 double-submit，会话承载）：
 *  - 安全方法（GET/HEAD/OPTIONS）在该路由上首次访问时于会话中签发一次性令牌，
 *    并通过响应头 X-CSRF-Token 回传，供 SPA / 表单引导；
 *  - 非安全方法（POST/PUT/PATCH/DELETE）校验请求头 X-CSRF-Token（或 X-XSRF-Token /
 *    表单字段 _token）与会话令牌一致，否则 419。
 *
 * 前置依赖：会话（LazySessionMiddleware）。无会话（如纯 JWT 无 cookie 应用）的路由
 * 即便被标记也会安全跳过——CSRF 仅对「cookie-session」形态的会话劫持有效。
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param array<string, mixed> $config 框架 config/csrf.php 全量配置
     */
    public function __construct(
        private readonly Router $router,
        private readonly RouteRegistry $registry,
        private readonly array $config = [],
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->config['enabled'] ?? true)) {
            return $handler->handle($request);
        }

        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // 路由匹配（kode 路由表内已缓存，O(1)）。
        $matched = $this->router->match($method, $path);
        if (!$matched->isFound() || $matched->route === null) {
            return $handler->handle($request);
        }

        $tagged = $this->registry->csrfOf($matched->route);
        $autoApply = !empty($this->config['auto_apply_unsafe'] ?? false)
            && !in_array($path, (array) ($this->config['exclude_paths'] ?? []), true);
        $applicable = $tagged || ($autoApply && !in_array($method, self::SAFE_METHODS, true));

        // 未命中任何 CSRF 标记：O(1) 早退，零副作用。
        if (!$applicable) {
            return $handler->handle($request);
        }

        // 无会话承载：CSRF 对本形态无意义（如纯 JWT 接口），安全跳过。
        $session = $this->session();
        if ($session === null) {
            return $handler->handle($request);
        }

        $tokenKey = (string) ($this->config['token_key'] ?? '_csrf_token');
        $header = (string) ($this->config['header'] ?? 'X-CSRF-Token');

        // 安全方法：确保令牌存在并随响应回传，供客户端引导。
        if (in_array($method, self::SAFE_METHODS, true)) {
            $token = $this->ensureToken($session, $tokenKey);
            $response = $handler->handle($request);

            return $response->withHeader($header, $token);
        }

        // 非安全方法：校验双提交令牌。
        $stored = $session->get($tokenKey);
        $submitted = $this->submittedToken($request);
        if (!is_string($stored) || $stored === '' || $submitted === null || !hash_equals($stored, $submitted)) {
            return Resp::error(
                $this->config['error_message'] ?? 'CSRF token mismatch',
                (int) ($this->config['error_status'] ?? 419),
            );
        }

        return $handler->handle($request);
    }

    /**
     * 确保会话中已有 CSRF 令牌，缺失则签发一个新令牌。
     */
    private function ensureToken(object $session, string $tokenKey): string
    {
        $existing = $session->get($tokenKey);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $session->set($tokenKey, $token);

        return $token;
    }

    /**
     * 按优先级解析客户端提交的令牌：请求头 X-CSRF-Token → X-XSRF-Token →
     * 表单/JSON 字段 _token → 查询参数 _token。
     */
    private function submittedToken(ServerRequestInterface $request): ?string
    {
        $primary = (string) ($this->config['header'] ?? 'X-CSRF-Token');
        $xsrf = (string) ($this->config['xsrf_header'] ?? 'X-XSRF-Token');
        $param = (string) ($this->config['token_param'] ?? '_token');

        $fromHeader = $request->getHeaderLine($primary);
        if ($fromHeader !== '') {
            return $fromHeader;
        }

        $fromXsrf = $request->getHeaderLine($xsrf);
        if ($fromXsrf !== '') {
            return $fromXsrf;
        }

        $body = $request->getParsedBody();
        if (is_array($body) && isset($body[$param]) && is_string($body[$param])) {
            return $body[$param];
        }

        $query = $request->getQueryParams();
        if (isset($query[$param]) && is_string($query[$param])) {
            return $query[$param];
        }

        return null;
    }

    /**
     * 防御式取会话（会话未启用 / SessionManager 未绑定时返回 null，不抛异常）。
     *
     * session() 助手定义于全局命名空间，命名空间内未限定调用会自动回退到全局。
     */
    private function session(): ?object
    {
        try {
            return session();
        } catch (\Throwable) {
            return null;
        }
    }
}
