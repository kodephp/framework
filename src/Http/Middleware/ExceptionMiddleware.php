<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Exception\ExceptionManager;
use Kode\Framework\Http\Resp;
use Kode\Framework\Observability\Trace\TraceContext;
use Kode\Framework\Validation\ValidationException;
use Kode\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP 异常中间件（薄适配 kode/exception）
 *
 * 作为全局最外层中间件：捕获下游抛出的未处理异常，交给 kode/exception 的
 * {@see ExceptionManager::respond()} 统一格式化为结构化 JSON（默认含 file / line /
 * chain，便于直接定位出错的文件与行号），并透传 X-Trace-Id / X-Span-Id 链路头。
 *
 * 框架不再自研错误渲染 / 友好调试页——错误响应形态 100% 由 kode/exception 决定。
 * 校验异常（{@see ValidationException}）按 API 约定转 422（含字段级错误明细）。
 */
final class ExceptionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ExceptionManager $manager,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ValidationException $e) {
            return Resp::error($e->getMessage() ?: '参数校验失败', 422, ['errors' => $e->errors()]);
        } catch (\Throwable $e) {
            return $this->toResponse($e);
        }
    }

    private function toResponse(\Throwable $e): ResponseInterface
    {
        try {
            $result = $this->manager->respond($e);
            $body = $result['body'];

            $response = Response::json($body)->status($result['status']);

            // 透传分布式链路标识，便于网关 / 日志串联。
            if (!empty($body['trace_id'])) {
                $response = $response->header('X-Trace-Id', (string) $body['trace_id']);
            }
            if (!empty($body['span_id'])) {
                $response = $response->header('X-Span-Id', (string) $body['span_id']);
            }

            return $response;
        } catch (\Throwable $renderError) {
            // 错误处理器自身出错（如 kode/exception 内部异常、循环依赖）时，绝不让请求
            // 以「裸 PHP 错误 / 空白 500」结束——返回最小安全 JSON，并记录原始错误。
            return $this->fallback($e, $renderError);
        }
    }

    private function fallback(\Throwable $e, \Throwable $renderError): ResponseInterface
    {
        try {
            $traceId = TraceContext::traceId();
        } catch (\Throwable) {
            $traceId = '';
        }

        try {
            logger()->error('异常响应渲染失败，已回退最小安全响应', [
                'exception' => $renderError,
                'original' => $e,
            ]);
        } catch (\Throwable) {
            // logger 也可能不可用，尽力而为，不二次抛错。
        }

        return Response::json([
            'message' => 'Internal Server Error',
            'trace_id' => $traceId,
        ])->status(500);
    }
}
