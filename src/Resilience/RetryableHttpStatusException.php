<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 内部哨兵异常：把「下游返回可重试状态码（如 502/503/504）」桥接进 Retry 原语的异常模型。
 *
 * HTTP 中间件层面没有异常、只有响应，本异常让「响应状态码」能参与 Retry 的
 * retryOn（可重试判定）编排；并在全部重试耗尽时，让中间件把最后一次上游响应
 * 原样交还调用方（best-effort），既不静默吞错、也不伪造成功。
 */
final class RetryableHttpStatusException extends \RuntimeException
{
    public function __construct(
        private readonly ResponseInterface $response,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Upstream responded with retryable status ' . $response->getStatusCode(),
            0,
            $previous
        );
    }

    public function response(): ResponseInterface
    {
        return $this->response;
    }
}
