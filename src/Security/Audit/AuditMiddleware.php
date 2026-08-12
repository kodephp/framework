<?php

declare(strict_types=1);

namespace Kode\Framework\Security\Audit;

use Kode\Framework\Http\Resp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 审计中间件（合规审计入口）
 *
 * 在全局管线内层包裹请求处理，于响应返回后调用 {@see AuditService::record()} 落审计日志。
 * 自身异常绝不中断请求——审计是辅助，可用性优先。
 */
final class AuditMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            // 异常由下游 ExceptionMiddleware 负责格式化；这里仍以 500 形态补一条审计。
            $this->audit->record($request, Resp::error('服务器内部错误', 500), $start);
            throw $e;
        }

        $this->audit->record($request, $response, $start);

        return $response;
    }
}
