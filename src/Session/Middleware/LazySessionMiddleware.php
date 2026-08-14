<?php

declare(strict_types=1);

namespace Kode\Framework\Session\Middleware;

use Kode\Session\Middleware\SessionMiddleware as BaseSessionMiddleware;
use Kode\Session\SessionManager;
use Kode\Session\Support\SessionId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 惰性会话中间件（性能修复核心）。
 *
 * 原 vendor SessionMiddleware 在 {@see BaseSessionMiddleware::fromRequest()} 中**无条件**调用
 * `$session->start()`（开文件锁 + 读取负载），并在响应时**无条件** `saveSession()`（写文件 +
 * 下发 Set-Cookie）。这意味着每个请求——哪怕 /ping 根本不用会话——都至少一次文件锁 + 一次
 * 读 + 一次写，是 kode 全栈 /ping 仅 ~150 req/s 的头号灾难（单测隔离约 154 req/s）。
 *
 * 本中间件改为「惰性」：
 *  - 入站：仅用 {@see SessionManager::make()} 创建一个**未启动**的 Session 对象（零 I/O，仅拼装），
 *    挂到 manager 与请求属性上；不读盘、不加锁。
 *  - 业务侧通过 session() 助手首次访问会话时才由助手触发 `start()`（按需读盘）。
 *  - 出站：仅当会话**确实被启动过**才 `saveSession()`（写盘 + Set-Cookie）；从未被触碰的请求
 *    （如 /ping）全程零会话 I/O。
 *
 * 原则：会话能力完全保持（读 / 写 / 闪存 / CSRF / GC / Set-Cookie 一样不缺），只是把 I/O 从
 * 「每个请求必付」收敛为「用到才付」。继承 vendor 中间件以复用其 saveSession / GC 逻辑，
 * 并兼容既有 `instanceof SessionMiddleware` 接线校验。
 */
final class LazySessionMiddleware extends BaseSessionMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // ① 仅创建未启动的会话对象（零 I/O）。
        $id = SessionId::sanitize($this->idFromRequest($request));
        $session = $this->manager->make($id, $this->config);
        $this->manager->setSession($session);
        $request = $request->withAttribute('session', $session);

        $response = $handler->handle($request);

        // ② 仅当本次请求真正启动过会话，才落盘并下发 Set-Cookie。
        //    /ping 这类不碰会话的请求：isStarted() 恒为 false → 全程零会话 I/O。
        if ($session->isStarted()) {
            $this->maybeGarbageCollect();

            // saveSession() 内部已处理脏数据 / 闪存 / 释放锁（调用 $session->save()）。
            $response = $this->saveSession($session, $response);
        }

        return $response;
    }

    /**
     * 从请求解析 Session ID（cookie / query / body / 头），非法或缺失则交由
     * {@see SessionId::sanitize()} 生成新 ID（防会话固定 / 路径穿越）。
     *
     * 优先走 PSR-7 请求对象（而非 $_COOKIE 等超全局），以适配非 CGI 运行环境。
     */
    private function idFromRequest(ServerRequestInterface $request): ?string
    {
        $name = $this->config['name'] ?? 'KODE_SESSION';
        $idParam = $this->config['id_param'] ?? 'session_id';

        $candidate = $request->getCookieParams()[$name] ?? null;

        if ($candidate === null) {
            $candidate = $request->getQueryParams()[$idParam] ?? null;
        }

        if ($candidate === null) {
            $body = $request->getParsedBody();
            $candidate = is_array($body) ? ($body[$idParam] ?? null) : null;
        }

        if ($candidate === null) {
            $header = $request->getHeaderLine('X-Session-Id');
            $candidate = $header !== '' ? $header : null;
        }

        return is_string($candidate) ? $candidate : null;
    }
}
