<?php

declare(strict_types=1);

namespace Kode\Framework\Security\Audit;

use Kode\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * 审计服务（合规审计记录）
 *
 * 记录「谁（user_id）/ 何时（由日志时间戳承载）/ 对什么资源（method+path）/
 * 做了什么结果（status）/ 耗时 / 来自哪（client_ip）/ 链路（request_id）」。
 *
 * 用户身份取自 kode/context 的 auth_user_id（由 AuthMiddleware 在鉴权时写入）；
 * 读取后立即清除，避免多进程顺序处理时跨请求泄漏。
 *
 * 设计立场：审计是「合规记录」，写入 PSR 日志器（默认 Monolog 落 storage/logs），
 * 不侵入业务；敏感路径可在 config/audit.ignore_paths 屏蔽。
 *
 * 默认**离路径异步导出**（v0.8.27，与 v0.8.25 访问日志同范式）：record() 在热路径上只
 * 做一次内存入队，真实格式化 + 日志写入由响应后的 shutdown / 优雅停机钩子批量执行，绝不
 * 阻塞客户端响应；async=false 时退化为同步写，兼容审计强一致场景。
 */
final class AuditService
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $config = [],
        private readonly ?AuditSink $sink = null,
        private readonly bool $async = true,
    ) {
    }

    /**
     * 记录一次请求审计。
     */
    public function record(
        ServerRequestInterface $request,
        ResponseInterface $response,
        float $start,
        ?string $userId = null,
    ): void {
        $path = $request->getUri()->getPath();
        $ignore = (array) ($this->config['ignore_paths'] ?? []);
        if (in_array($path, $ignore, true)) {
            return;
        }

        $level = $this->config['log_level'] ?? LogLevel::INFO;
        $userId = $userId ?? $this->resolveUser();

        $context = [
            'method'      => $request->getMethod(),
            'path'        => $path,
            'query'       => $request->getUri()->getQuery(),
            'status'      => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'client_ip'   => $this->clientIp($request),
            'request_id'  => $request->getHeaderLine('X-Request-Id'),
            'user_id'     => $userId,
        ];

        // 离路径异步导出：热路径仅内存入队，真实写入由 flush 钩子批量执行。
        if ($this->sink !== null && $this->async) {
            $this->sink->emit($level, 'audit', $context);
            return;
        }

        $this->logger->log($level, 'audit', $context);
    }

    /**
     * 从 kode/context 解析当前用户 ID（AuthMiddleware 鉴权时写入），并清除防泄漏。
     */
    private function resolveUser(): ?string
    {
        if (empty($this->config['capture_user'])) {
            return null;
        }

        /** @var mixed $id */
        $id = Context::get('auth_user_id');
        // 读取后立即清除，避免同一 worker 顺序处理下一请求时泄漏。
        Context::set('auth_user_id', null);

        if ($id === null) {
            return null;
        }

        return is_scalar($id) ? (string) $id : json_encode($id);
    }

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
