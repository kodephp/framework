<?php

declare(strict_types=1);

namespace Kode\Framework\Security\Audit;

use Kode\Context\Context;
use Kode\Framework\Http\Support\QueryMasker;
use Kode\Framework\Http\Support\TrustedProxies;
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
 * 默认**离路径异步导出**（v1.0.27，与 v1.0.25 访问日志同范式）：record() 在热路径上只
 * 做一次内存入队，真实格式化 + 日志写入由响应后的 shutdown / 优雅停机钩子批量执行，绝不
 * 阻塞客户端响应；async=false 时退化为同步写，兼容审计强一致场景。
 */
final class AuditService
{
    /**
     * 默认敏感字段名（统一小写）。命中其一的查询参数 / 事件明细值将被替换为 '***'。
     * 可经 config/audit.php 的 mask_params 覆盖（设为 [] 即显式关闭脱敏）。
     *
     * v1.0.42 起移至 {@see QueryMasker::DEFAULT_MASK_PARAMS}，本常量保留为兼容别名
     * （与访问日志共用同一份默认集合，避免两份拷贝漂移）。
     */
    public const DEFAULT_MASK_PARAMS = QueryMasker::DEFAULT_MASK_PARAMS;

    /**
     * @param array<string, mixed> $config
     * @param array<int, string>   $trusted 受信代理列表（IP / CIDR / '*'），见 config/security.php
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $config = [],
        private readonly ?AuditSink $sink = null,
        private readonly bool $async = true,
        private readonly array $trusted = [],
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
        $forensic = !array_key_exists('forensic', $this->config) || !empty($this->config['forensic']);

        $context = [
            'method'      => $request->getMethod(),
            'path'        => $path,
            'query'       => $this->maskQuery($request->getUri()->getQuery()),
            'status'      => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'client_ip'   => $this->clientIp($request),
            'request_id'  => $request->getHeaderLine('X-Request-Id'),
            'user_id'     => $userId,
        ];

        // 取证元数据（安全审计）：User-Agent / Referer 有助于安全溯源。
        if ($forensic) {
            $context['user_agent'] = $request->getHeaderLine('User-Agent');
            $context['referer']    = $request->getHeaderLine('Referer');
        }

        // 请求体脱敏（默认关）：仅当已解析为数组（form/json）且无副作用读取时记录。
        if (!empty($this->config['mask_body'])) {
            $parsed = $request->getParsedBody();
            if (is_array($parsed)) {
                $context['body'] = $this->maskSensitive($parsed, $this->maskParams());
            }
        }

        // 离路径异步导出：热路径仅内存入队，真实写入由 flush 钩子批量执行。
        if ($this->sink !== null && $this->async) {
            $this->sink->emit($level, 'audit', $context);
            return;
        }

        $this->logger->log($level, 'audit', $context);
    }

    /**
     * 记录一次业务 / 安全审计事件（非 HTTP 请求维度）。
     *
     * 用于把「谁在哪个请求下做了什么业务动作」结构化记录进审计流——例如登录成功 /
     * 权限变更 / 敏感数据导出 / 配置修改等。与 {@see record()} 共用同一离路径异步导出
     * 管线，故热路径同样仅一次内存入队，绝不阻塞业务。
     *
     * @param string                 $action  事件名（建议点分隔语义串，如 'user.login' / 'role.assigned'）
     * @param array<string, mixed>   $detail  结构化明细（敏感键会被脱敏）
     * @param ?ServerRequestInterface $request 可选：提供则补全 client_ip / request_id / 取证头
     * @param ?string                $userId  可选：显式用户标识；传入时不回退到 context 读取，
     *                                        亦不清除 context 中的 auth_user_id（避免抢占请求审计的用户）。
     */
    public function recordEvent(
        string $action,
        array $detail = [],
        ?ServerRequestInterface $request = null,
        ?string $userId = null,
    ): void {
        $level = $this->config['event_log_level'] ?? LogLevel::INFO;
        $userId = $userId ?? $this->resolveUser();

        $context = [
            'event'   => $action,
            'detail'  => $this->maskSensitive($detail, $this->maskParams()),
            'user_id' => $userId,
        ];

        if ($request !== null) {
            $context['client_ip'] = $this->clientIp($request);
            $context['request_id'] = $request->getHeaderLine('X-Request-Id');
            if (!array_key_exists('forensic', $this->config) || !empty($this->config['forensic'])) {
                $context['user_agent'] = $request->getHeaderLine('User-Agent');
                $context['referer']    = $request->getHeaderLine('Referer');
            }
        }

        if ($this->sink !== null && $this->async) {
            $this->sink->emit($level, 'audit', $context);
            return;
        }

        $this->logger->log($level, 'audit', $context);
    }

    /**
     * {@see recordEvent()} 的流式别名：audit()->event('user.login', [...], $request, $uid)。
     */
    public function event(string $action, array $detail = [], ?ServerRequestInterface $request = null, ?string $userId = null): void
    {
        $this->recordEvent($action, $detail, $request, $userId);
    }

    /**
     * 取脱敏字段名集合（统一小写，便于不区分大小写匹配）。
     * 配置 mask_params 为空数组即显式关闭脱敏；缺省使用安全默认集合。
     *
     * @return array<int, string>
     */
    private function maskParams(): array
    {
        $mask = $this->config['mask_params'] ?? self::DEFAULT_MASK_PARAMS;

        return is_array($mask) ? QueryMasker::normalizeMaskParams($mask) : [];
    }

    /**
     * 递归脱敏：键命中 mask 集合的值替换为 '***'（兼容嵌套数组，如 filter[password]=x）。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function maskSensitive(array $data, array $mask): array
    {
        return QueryMasker::maskSensitive($data, $mask);
    }

    /**
     * 脱敏查询串（v1.0.42 起委托 {@see QueryMasker}，与访问日志共用实现）。
     */
    private function maskQuery(string $query): string
    {
        return QueryMasker::maskQuery($query, $this->maskParams());
    }

    /**
     * 从 kode/context 解析当前用户 ID（AuthMiddleware 鉴权时写入）。
     *
     * 修复说明（v1.0.42）：旧实现「读取后立即清除」，导致同一请求内先执行一次审计后，
     * 后续业务审计（recordEvent / record）再也取不到用户 ID（H1）。现在**只读不清除**，
     * 同请求内多次读取结果一致；防跨请求泄漏的清理改由全局最外层
     * {@see \Kode\Framework\Http\Middleware\ConnectionCleanupMiddleware} 在每个请求
     * 开始前执行（`Context::delete('auth_user_id')`），保证未鉴权请求不会读到上一个
     * 请求残留的用户身份。
     */
    private function resolveUser(): ?string
    {
        if (empty($this->config['capture_user'])) {
            return null;
        }

        /** @var mixed $id */
        $id = Context::get('auth_user_id');

        if ($id === null) {
            return null;
        }

        return is_scalar($id) ? (string) $id : json_encode($id);
    }

    /**
     * 真实客户端 IP：仅当直连对端为受信代理时采信转发头（H4），
     * 否则一律用 REMOTE_ADDR——防伪造 XFF/X-Real-IP 造成审计溯源失真。
     */
    private function clientIp(ServerRequestInterface $request): string
    {
        return TrustedProxies::clientIp($request, $this->trusted);
    }
}
