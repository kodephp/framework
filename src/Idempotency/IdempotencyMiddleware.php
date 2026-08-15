<?php

declare(strict_types=1);

namespace Kode\Framework\Idempotency;

use Kode\Framework\Http\Resp;
use Kode\Framework\Http\RouteMatchTrait;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Http\RouteResolver;
use Kode\Http\Response;
use Kode\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP 幂等中间件（薄壳层，Stripe 风格）。
 *
 * 把 {@see IdempotencyManager} 接入 HTTP 流量：自动处理 `Idempotency-Key` 头，
 * 让「同一键的重复请求」在 TTL 内只真正执行业务一次，重放返回首次的缓存响应
 * （状态 / Content-Type / 响应体完全一致），而非重复执行业务或仅回 409。
 *
 * 流程：
 *  1) 取请求头（默认 `Idempotency-Key`）；
 *     - 为空且 `enforce=false`（默认）→ 直接放行，本中间件零开销；
 *     - 为空且 `enforce=true` → 400（强制要求携带幂等键，用于写接口防重复提交）；
 *  2) 计算最终键（scope=global 仅用键；scope=route 叠加 `METHOD path` 防跨端点碰撞；
 *     可配置 `prefix` 命名空间隔离）；
 *  3) 原子占位 `seen()`：
 *     - true（首次）→ 跑下游 handler，把响应编码为 envelope 补挂 `attach()`，
 *       响应带 `Idempotency-Recorded: true`；
 *     - false（重放）→ 取 `replay()` 缓存 envelope，原样重建响应并带
 *       `Idempotency-Replay: true`；若并发窗口内尚未落盘响应，降级 409（避免重复执行）。
 *
 * 与分布式锁边界：锁防并发互斥（同时只有一个持有者运行）；本中间件防重试安全
 * （重放返回一致响应，业务不重复跑）。二者常配合用于「支付 / 下单 / 写接口」。
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    use RouteMatchTrait;

    /**
     * @param array<string, mixed> $options 见 config/idempotency.php 的 `http` 段
     */
    public function __construct(
        private readonly IdempotencyManager $manager,
        private readonly array $options = [],
        private readonly ?Router $router = null,
        private readonly ?RouteRegistry $registry = null,
        private readonly ?RouteResolver $resolver = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 路由属性门控：未标记 #[Idempotency] 的路由直接放行（O(1) 早退），
        // 仅保护显式声明路由，避免对无关请求做幂等键查询与存储占位。
        // 匹配由 RouteResolver 在单次请求内缓存（首个中间件 match 一次，后续命中）。
        [$request, $matched] = $this->resolveRoute($request);
        if ($matched !== null && $matched->isFound() && $matched->route !== null
            && $this->registry !== null && !$this->registry->idempotencyOf($matched->route)) {
            return $handler->handle($request);
        }

        $header = (string) ($this->options['header'] ?? 'Idempotency-Key');
        $key = trim($request->getHeaderLine($header));

        if ($key === '') {
            if (!empty($this->options['enforce'])) {
                return Resp::error('缺少幂等键：' . $header, 400);
            }

            return $handler->handle($request);
        }

        $finalKey = $this->resolveKey($request, $key);

        if (!$this->manager->seen($finalKey, (int) ($this->options['ttl'] ?? 3600))) {
            $payload = $this->manager->replay($finalKey);
            if ($payload !== null) {
                return $this->rebuild($payload)
                    ->withHeader((string) ($this->options['replay_header'] ?? 'Idempotency-Replay'), 'true');
            }

            // 占位成功但首次响应尚未落盘（极窄并发窗口）：降级 409，避免重复执行业务。
            return Resp::error('请求正在处理中或已去重', 409);
        }

        $response = $handler->handle($request);
        $this->manager->attach($finalKey, $this->envelope($response));

        return $response->withHeader((string) ($this->options['recorded_header'] ?? 'Idempotency-Recorded'), 'true');
    }

    /**
     * 计算存储键：route 作用域叠加方法 + 路径，避免不同端点复用同一键相互误判。
     */
    private function resolveKey(ServerRequestInterface $request, string $key): string
    {
        $prefix = (string) ($this->options['prefix'] ?? '');
        $scope = (string) ($this->options['scope'] ?? 'global');

        if ($scope === 'route') {
            return $prefix . $request->getMethod() . ' ' . $request->getUri()->getPath() . ':' . $key;
        }

        return $prefix . $key;
    }

    /**
     * 把响应编码为一个可持久化的 envelope（状态 / Content-Type / 体），体经 base64 避免 JSON 破坏。
     */
    private function envelope(ResponseInterface $response): string
    {
        return (string) json_encode([
            's' => $response->getStatusCode(),
            'c' => $response->getHeaderLine('Content-Type'),
            'b' => base64_encode((string) $response->getBody()),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 从重放 envelope 重建响应（保持状态 / Content-Type / 体一致）。
     */
    private function rebuild(string $payload): ResponseInterface
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return Resp::error('幂等记录已损坏', 409);
        }

        $status = (int) ($data['s'] ?? 200);
        $contentType = (string) ($data['c'] ?? 'application/json');
        $raw = base64_decode((string) ($data['b'] ?? ''), true);
        $body = $raw === false ? '' : $raw;

        $headers = $contentType !== '' ? ['Content-Type' => $contentType] : [];

        return Response::make($body, $status, $headers);
    }
}
