<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Framework\Http\Resp;
use Kode\Framework\Logging\AccessLogSink;
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
    /**
     * @param LoggerInterface      $logger 同步退化路径使用的 logger（及离路径 flush 目标）
     * @param bool                 $enabled 是否记录访问日志
     * @param AccessLogSink|null   $sink   离路径导出队列；为 null 时强制同步写 logger
     * @param bool                 $async  是否异步（入队后由 shutdown / 停机钩子离路径落盘）
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
        private readonly ?AccessLogSink $sink = null,
        private readonly bool $async = true,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        // 用单调时钟 hrtime 测延迟，避免 wall-clock 被 NTP/系统时间回拨影响精度。
        $start = hrtime(true) / 1e6;
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
        $latency = round((hrtime(true) / 1e6 - $start) * 1000, 2);

        // 热路径优化：uri 直接取 path(+query) 拼装，避免 getUri()->withQuery('') 每次请求克隆
        // 一个 Uri 对象（原实现每请求多一次对象分配 + GC 压力）；client_ip 仅在无代理头时回退到
        // server params，正常反代场景只做两次廉价头查找。
        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        $context = [
            'method'      => $request->getMethod(),
            'uri'         => $query === '' ? $path : $path . '?' . $query,
            'query'       => $query,
            'status'      => $status,
            'latency_ms'  => $latency,
            'request_id'  => $requestId !== '' ? $requestId : null,
            'client_ip'   => $this->clientIp($request),
            'route'       => $request->getAttribute('route_name'),
        ];

        if ($error !== null) {
            $context['error'] = $error->getMessage();
            $this->write('error', $context);
            return;
        }

        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');
        $this->write($level, $context);
    }

    /**
     * 落盘：异步（sink 已注入且开启）时仅内存入队，离请求路径再批量写入；
     * 否则（无 sink 或 async=false）直接同步写 logger，保证向后兼容与审计强一致场景。
     */
    private function write(string $level, array $context): void
    {
        if ($this->sink !== null && $this->async) {
            $this->sink->emit($level, 'access', $context);
            return;
        }

        match ($level) {
            'error'   => $this->logger->error('access', $context),
            'warning' => $this->logger->warning('access', $context),
            default   => $this->logger->info('access', $context),
        };
    }

    /**
     * 取客户端真实 IP（尊重 X-Forwarded-For / X-Real-IP，优先前者首段）。
     *
     * 用 strstr(..., ',', true) 取首段，避免 strtok 的全局解析状态副作用。
     */
    private function clientIp(ServerRequestInterface $request): string
    {
        $fwd = $request->getHeaderLine('X-Forwarded-For');
        if ($fwd !== '') {
            return strstr($fwd, ',', true) ?: $fwd;
        }
        $real = $request->getHeaderLine('X-Real-IP');
        if ($real !== '') {
            return $real;
        }
        $server = $request->getServerParams();

        return $server['REMOTE_ADDR'] ?? 'unknown';
    }
}
