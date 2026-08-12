<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Framework\Http\Resp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * 访问日志中间件（结构化请求日志）
 *
 * 作为全局中间件链的一环，对每次请求记录一行结构化日志：
 *   method / uri / status / latency_ms / request_id / client_ip / route_name。
 * 便于接入 ELK / Loki 等日志系统做流量观测、慢请求定位、链路追踪关联。
 *
 * 开关见 config/logging.php 的 access_log.enabled；生产建议开启。
 * 自身异常（如日志写入失败）绝不中断请求——观测是辅助，可用性优先。
 */
final class AccessLogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        $start = microtime(true);
        $requestId = $request->getHeaderLine('X-Request-Id');

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            // 异常由下游 ExceptionMiddleware 负责格式化；这里仍补一条失败访问日志。
            $this->log($request, null, $start, $requestId, $e);
            throw $e;
        }

        $this->log($request, $response, $start, $requestId, null);

        return $response;
    }

    /**
     * @param ResponseInterface|null $response 异常分支为 null
     */
    private function log(
        ServerRequestInterface $request,
        ?ResponseInterface $response,
        float $start,
        string $requestId,
        ?\Throwable $error,
    ): void {
        $status = $response?->getStatusCode() ?? 500;
        $latency = round((microtime(true) - $start) * 1000, 2);

        $context = [
            'method'      => $request->getMethod(),
            'uri'         => (string) $request->getUri()->withQuery(''),
            'query'       => $request->getUri()->getQuery(),
            'status'      => $status,
            'latency_ms'  => $latency,
            'request_id'  => $requestId !== '' ? $requestId : null,
            'client_ip'   => $this->clientIp($request),
            'route'       => $request->getAttribute('route_name'),
        ];

        if ($error !== null) {
            $context['error'] = $error->getMessage();
            $this->logger->error('access', $context);
            return;
        }

        if ($status >= 500) {
            $this->logger->error('access', $context);
        } elseif ($status >= 400) {
            $this->logger->warning('access', $context);
        } else {
            $this->logger->info('access', $context);
        }
    }

    /**
     * 取客户端真实 IP（尊重 X-Forwarded-For / X-Real-IP，优先前者首段）。
     */
    private function clientIp(ServerRequestInterface $request): string
    {
        $fwd = $request->getHeaderLine('X-Forwarded-For');
        if ($fwd !== '') {
            return strtok($fwd, ',');
        }
        $real = $request->getHeaderLine('X-Real-IP');
        if ($real !== '') {
            return $real;
        }
        $server = $request->getServerParams();

        return $server['REMOTE_ADDR'] ?? 'unknown';
    }
}
