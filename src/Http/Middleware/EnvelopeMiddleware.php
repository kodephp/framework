<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Framework\Http\Resp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 统一信封中间件（http.envelope = true 时挂载，默认开）。
 *
 * 职责：把「裸数组 / 标量返回值」经 kode/http resolve 出的 JSON 响应体，统一包成
 * {code, msg, data} 信封（code 取 HTTP 状态码，msg 默认 'ok'），让控制器里
 * `return ['id' => 1];` 也能拿到信封，与 Resp::json 一致。
 *
 * 边界（不包装，原样透传）：
 *  - 已打 X-Kode-Envelope 标记的响应（Resp::json / Resp::error 产出，含 'passthrough'
 *    直出标记的探针 / Resp::make 自由构造）。
 *  - 非 JSON Content-Type（HTML / 流式 / 文件下载等）。
 *  - 探针路径（/health* /ping /metrics，默认可配）。
 *  - 无 body 的响应（204 等）。
 *
 * 解析 + 重编码有成本，仅对「未打标且声明 JSON」的响应生效；健康探针走 Resp::rawJson
 * 已打 passthrough，热路径零解析开销。
 */
final class EnvelopeMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $skipPaths 跳过信封化的路径前缀（探针 / 指标）
     */
    public function __construct(
        private readonly array $skipPaths = ['/health', '/metrics', '/ping'],
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        foreach ($this->skipPaths as $skip) {
            if (str_starts_with($path, (string) $skip)) {
                return $handler->handle($request);
            }
        }

        $response = $handler->handle($request);

        return $this->wrap($response);
    }

    /**
     * 把未打信封标的 JSON 响应体包成 {code, msg, data}。
     */
    private function wrap(ResponseInterface $response): ResponseInterface
    {
        // Resp::json / Resp::error / Resp::make 已处理信封或显式直出，直接透传。
        if ($response->hasHeader('X-Kode-Envelope')) {
            return $response;
        }

        // 无 body 的响应（204 等）不处理。
        $body = (string) $response->getBody();
        if ($body === '') {
            return $response;
        }

        // 仅处理声明 JSON 的响应（控制器 return [...] 经 resolve 为 application/json）。
        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
            return $response;
        }

        // 尝试解析 JSON；解析失败（非 JSON 体）原样透传。
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $response;
        }

        // 已是信封形态（含 code + msg 顶层键）：视为上游已自行组装，只补标记不重包，
        // 避免熔断/重试等中间件短路响应的 msg 被覆盖。
        if (is_array($decoded) && array_key_exists('code', $decoded) && array_key_exists('msg', $decoded)) {
            if ($response instanceof \Kode\Http\Response) {
                $response->header('X-Kode-Envelope', '1');
                return $response;
            }
            return $response->withHeader('X-Kode-Envelope', '1');
        }

        // 标量 / 列表也归一进 data（列表常见于批量返回）。
        $status = $response->getStatusCode();
        $payload = [
            'code' => $status,
            'msg'  => $status >= 400 ? 'error' : 'ok',
            'data' => $decoded,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // 替换 body（保留原响应的状态 / 头）。
        // 优先走 Kode\Http\Response::body() 快路径（缓存 rawBody，Emitter 直写免 Stream 物化）。
        if ($response instanceof \Kode\Http\Response) {
            $response->body($encoded);
            if (!$response->hasHeader('Content-Type')) {
                $response->header('Content-Type', 'application/json; charset=utf-8');
            }
            $response->header('X-Kode-Envelope', '1');
            return $response;
        }

        $new = $response->withBody(\Kode\Http\create_stream($encoded));
        if (!$new->hasHeader('Content-Type')) {
            $new = $new->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        return $new->withHeader('X-Kode-Envelope', '1');
    }
}
