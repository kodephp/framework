<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Framework\Http\Resp;
use Kode\Framework\Http\Support\QueryMasker;
use Kode\Framework\Http\Support\TrustedProxies;
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
 *
 * v1.0.42：
 *  - query 写入日志前经 {@see QueryMasker} 脱敏（H5），防令牌/密码明文落日志；
 *  - client_ip 仅当直连对端为受信代理时采信转发头（H4），否则一律用 REMOTE_ADDR。
 */
final class AccessLogMiddleware implements MiddlewareInterface
{
    /**
     * @param LoggerInterface      $logger 同步退化路径使用的 logger（及离路径 flush 目标）
     * @param bool                 $enabled 是否记录访问日志
     * @param AccessLogSink|null   $sink   离路径导出队列；为 null 时强制同步写 logger
     * @param bool                 $async  是否异步（入队后由 shutdown / 停机钩子离路径落盘）
     * @param array<int, string>   $trusted 受信代理列表（IP / CIDR / '*'），见 config/security.php
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
        private readonly ?AccessLogSink $sink = null,
        private readonly bool $async = true,
        private readonly array $trusted = [],
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
        $query = QueryMasker::maskQuery($uri->getQuery(), $this->maskParams());

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
     * 队列入队已完全移出请求热路径——真实落盘由 LogServiceProvider 注册的运行时
     * Timer / 停机钩子离请求路径执行，热路径只做一次内存入队（µs 级），绝不做任何
     * 文件 I/O。
     *
     * 历史教训（已重构移除）：早期版本曾在队列积压 ≥ 256 条时于请求线程内同步
     * flush 全队列，高并发下把数千条 Monolog 写入（数十 ms）压进单个请求，且写入
     * 越慢积压越多 → 雪崩：能力梯度 L3(+日志) 一档 rps 从 L2 骤降 87%。队列有界性
     * 改由 AccessLogSink::MAX（8192，超限丢新）保障——访问日志在极端过载下可丢弃，
     * 优于 worker OOM / 请求线程阻塞。
     */

    /**
     * 落盘：异步（sink 已注入且开启）时仅内存入队，离请求路径（运行时周期
     * Timer / shutdown 钩子，见 {@see \Kode\Framework\Providers\LogServiceProvider}）
     * 再批量写入；否则（无 sink 或 async=false）直接同步写 logger，保证向后兼容
     * 与审计强一致场景。无论哪条路径，请求线程内都**不执行 flush**。
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
     * 取客户端真实 IP：仅当直连对端为受信代理时才采信 X-Forwarded-For / X-Real-IP，
     * 否则一律用 REMOTE_ADDR——防伪造转发头造成误账 / 隐私失真（H4）。
     */
    private function clientIp(ServerRequestInterface $request): string
    {
        return TrustedProxies::clientIp($request, $this->trusted);
    }

    /**
     * 访问日志脱敏集合（与审计共用默认值，见 config/audit.php mask_params 可覆盖；
     * 访问日志为观测用途，默认即脱敏，不提供关闭开关，防止凭据明文落盘 H5）。
     *
     * @return array<int, string>
     */
    private function maskParams(): array
    {
        return QueryMasker::normalizeMaskParams(null);
    }
}
