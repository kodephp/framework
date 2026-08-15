<?php

declare(strict_types=1);

namespace Kode\Framework\Feature\Middleware;

use Kode\Framework\Feature\FeatureManager;
use Kode\Framework\Feature\FeatureRegistry;
use Kode\Framework\Http\Resp;
use Kode\Framework\Http\RouteMatchTrait;
use Kode\Framework\Http\RouteResolver;
use Kode\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 功能开关中间件（薄编排，判定逻辑全在 FeatureManager）。
 *
 * 作为**全局中间件**运行：对每条命中 #[Feature] 的路由做开关校验，
 * 关闭时返回 fallback（默认 404，可声明 403）。未声明 flag 的路由直接放行。
 *
 * 分桶键：X-User-Id → X-Tenant-Id → 客户端 IP，保证灰度稳定。
 * 总开关见 config/feature.enabled（关闭即全放行）。
 */
final class FeatureMiddleware implements MiddlewareInterface
{
    use RouteMatchTrait;

    /**
     * @param array<string, mixed> $config 框架 config/feature.php 全量配置
     */
    public function __construct(
        private readonly Router $router,
        private readonly FeatureRegistry $registry,
        private readonly FeatureManager $manager,
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
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // 匹配由 RouteResolver 在单次请求内缓存（首个中间件 match 一次，后续命中）。
        $entry = null;
        if ($matched !== null && $matched->isFound() && $matched->route !== null) {
            $entry = $this->registry->flagOf($matched->route);
        }

        if ($entry === null) {
            return $handler->handle($request);
        }

        $key = $this->bucketKey($request);
        if ($this->manager->isEnabled($entry['flag'], $key)) {
            return $handler->handle($request);
        }

        return $this->denied($entry['fallback'], $entry['flag']);
    }

    /**
     * 稳定分桶键：优先用户、其次租户、再次 IP。
     */
    private function bucketKey(ServerRequestInterface $request): ?string
    {
        $userId = $request->getHeaderLine('X-User-Id');
        if ($userId !== '') {
            return 'user:' . $userId;
        }

        $tenant = $request->getHeaderLine('X-Tenant-Id');
        if ($tenant !== '') {
            return 'tenant:' . $tenant;
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return $ip === null ? null : 'ip:' . $ip;
    }

    private function denied(int $status, string $flag): ResponseInterface
    {
        return Resp::error($status === 403 ? 'Forbidden' : 'Not Found', $status, [
            'feature' => $flag,
        ]);
    }
}
