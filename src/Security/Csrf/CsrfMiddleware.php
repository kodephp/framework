<?php

declare(strict_types=1);

namespace Kode\Framework\Security\Csrf;

use Kode\Framework\Http\Resp;
use Kode\Framework\Http\RouteMatchTrait;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Http\RouteResolver;
use Kode\Framework\Security\Audit\AuditService;
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
 * 前置依赖：会话（LazySessionMiddleware）。无会话承载时：安全方法照常放行；
 * 非安全方法 fail-closed 拒绝（419）——静默跳过等于「标了 #[Csrf] 却零防护」，
 * 纯 JWT 无 cookie 应用不应给路由标 #[Csrf]。
 *
 * 安全可观测性：校验失败时经离路径异步审计管线记录 csrf.failed 事件（与 auth.failed 同源，
 * SOC 可统一监测），仅在失败时触发、不污染正常热路径，且审计未启用则静默跳过。
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    use RouteMatchTrait;

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param array<string, mixed> $config 框架 config/csrf.php 全量配置
     */
    public function __construct(
        private readonly Router $router,
        private readonly RouteRegistry $registry,
        private readonly array $config = [],
        private readonly ?RouteResolver $resolver = null,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->config['enabled'] ?? true)) {
            return $handler->handle($request);
        }

        [$request, $matched] = $this->resolveRoute($request);
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // 路由匹配（由 RouteResolver 在单次请求内缓存，首个中间件 match 一次，后续命中）。
        if ($matched === null || !$matched->isFound() || $matched->route === null) {
            return $handler->handle($request);
        }

        $tagged = $this->registry->csrfOf($matched->route);
        $autoApply = !empty($this->config['auto_apply_unsafe'] ?? false)
            && !in_array($path, (array) ($this->config['exclude_paths'] ?? []), true);
        $applicable = $tagged || ($autoApply && !in_array($method, self::SAFE_METHODS, true));

        // 显式豁免路径（config csrf.skip_paths）：即便被 #[Csrf] 标记也放行，
        // 用于需跨站调用的 Webhook 等（修复配置项声明与实现脱节的「虚假安全感」）。
        if ($applicable && in_array($path, (array) ($this->config['skip_paths'] ?? []), true)) {
            return $handler->handle($request);
        }

        // 未命中任何 CSRF 标记：O(1) 早退，零副作用。
        if (!$applicable) {
            return $handler->handle($request);
        }

        // 无会话承载（session 未启用 / SessionManager 未绑定）：
        // 安全方法照常放行；非安全方法 fail-closed 拒绝——旧实现静默跳过等于
        // 「标了 #[Csrf] 却零防护」，攻击者视角与未开启 CSRF 无异且无任何告警。
        $session = $this->session();
        if ($session === null) {
            if (!in_array($method, self::SAFE_METHODS, true)) {
                return Resp::error(
                    'CSRF 会话未启用：请开启 session（SESSION_ENABLED=true）或从该路由移除 #[Csrf]',
                    (int) ($this->config['error_status'] ?? 419),
                );
            }

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
            // 联动审计：把「潜在的 CSRF 攻击」作为安全事件离路径异步入审计流，
            // 与 AuthMiddleware 的 auth.failed 同源（SOC 可统一监测）。仅失败时触发，
            // 不污染正常请求热路径；审计未启用 / 解析失败一律静默跳过，绝不阻塞 419 主流程。
            $this->auditFailure($request, $submitted === null ? 'missing_token' : 'token_mismatch');

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
     * 按优先级解析客户端提交的令牌：请求头 X-CSRF-Token → X-XSRF-Token → 表单/JSON 字段 _token。
     *
     * 安全说明（v0.8.52）：不再接受查询参数载体——URL 中的令牌会进入访问日志、
     * 浏览器历史与 Referer 头，削弱会话绑定令牌的保密性。
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

    /**
     * 经离路径异步审计管线记录 CSRF 失败安全事件（审计未启用 / 容器未绑定时静默跳过）。
     *
     * 失败时不可影响 CSRF 主流程（返回 419），故全程吞掉异常。事件名默认 csrf.failed，
     * 可被 config/csrf.php 的 audit_action 覆盖；仅当 config audit_on_failure 为真时记录。
     *
     * @param ServerRequestInterface $request
     * @param 'missing_token'|'token_mismatch' $reason
     */
    private function auditFailure(ServerRequestInterface $request, string $reason): void
    {
        if (empty($this->config['audit_on_failure'] ?? true)) {
            return;
        }

        try {
            /** @var AuditService $audit */
            $audit = resolve(AuditService::class);
            $audit->event(
                (string) ($this->config['audit_action'] ?? 'csrf.failed'),
                [
                    'reason' => $reason,
                    'method' => $request->getMethod(),
                    'path'   => $request->getUri()->getPath(),
                ],
                $request,
            );
        } catch (\Throwable) {
            // 审计未配置或失败：绝不影响 CSRF 主流程。
        }
    }
}
