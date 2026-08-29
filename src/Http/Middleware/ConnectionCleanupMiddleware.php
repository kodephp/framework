<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Context\Context;
use Kode\Database\Db\Db;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * 连接生命周期收口（请求级最外层）。
 *
 * kode/database 1.15.5 起把「同一连接名」的 PDO 连接缓存在 {@see Db} 的静态连接池里，
 * 实现 begin/insert/commit 在同一连接上、**事务真正原子**；
 * 同时新增 {@see Db::disconnect()} 用于「每个请求/协程结束时释放缓存连接」。
 *
 * 缓存连接对常驻进程（Swoole / Workerman / 多进程 prefork worker）是性能关键，
 * 但也带来两个必须框架收口的健壮性风险：
 *  1. **事务泄漏**：若控制器手动 Db::beginTransaction() 后抛异常却未回滚，残留事务会绑在
 *     缓存连接上跨请求延续，下一个请求可能读到未提交数据、或被持久锁阻塞。
 *  2. **跨请求连接复用**：缓存连接属于进程级静态态，单测/CLI 之间若不释放会互相污染。
 *
 * 本中间件挂在全局链最外层，无论请求成功还是异常，**一定会**在响应后执行收口：
 *  - 若 {@see Db::inTransaction()} 仍为真（有泄漏事务）→ 强制回滚 + 记告警，杜绝跨请求续命；
 *  - 若开启 release_per_request → 调用 {@see Db::disconnect()} 释放全部缓存连接
 *    （适合单测 / CLI / 连接易失效场景；常驻 API 服务默认关闭以保留连接复用性能）。
 *
 * 设计立场：
 *  - **零配置默认安全**：leak_rollback 默认开（事务绝不跨请求），release_per_request 默认关
 *    （保留连接池性能，由 kode/database 的缓存承担复用）。
 *  - **只做防御网**：正常路径下 TransactionMiddleware 已 commit/rollback，Db::inTransaction()
 *    为假，本中间件不触碰任何事务；仅捕获「绕过框架事务的手动 begin 残留」。
 *  - **绝不改变响应**：收口失败（连接已断开等）被静默吞掉，原始响应/异常照常向外传递。
 */
class ConnectionCleanupMiddleware implements MiddlewareInterface
{
    /**
     * @param bool $releasePerRequest 响应后是否释放缓存连接（Db::disconnect）；默认 false。
     * @param bool $leakRollback      是否回滚泄漏事务；默认 true。
     * @param LoggerInterface|null $logger 泄漏告警日志（可空，缺失则降级为静默回滚）。
     */
    public function __construct(
        private readonly bool $releasePerRequest = false,
        private readonly bool $leakRollback = true,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 请求级上下文收口（v1.0.0）：在**请求开始前**清除上个请求残留的 auth_user_id，
        // 防跨请求泄漏。AuditService 现改为「读取不清除」（同请求内多次审计可取到一致用户 ID），
        // 因此必须由本全局最外层中间件兜底清理——未鉴权请求若读到上个请求残留的用户身份，
        // 会把审计错误归属到该用户头上。
        Context::delete('auth_user_id');

        // finally 保证无论成功还是异常，响应产出后都执行收口；
        // 异常会在 finally 之后继续向外传播（交给 ExceptionMiddleware 格式化）。
        try {
            return $handler->handle($request);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * 请求收口：回收泄漏事务 + 按需释放连接。
     *
     * 抽出为受保护方法，便于测试 spy 验证「何时回滚 / 何时释放」的编排。
     */
    protected function cleanup(): void
    {
        if ($this->leakRollback && $this->inTransaction()) {
            $this->rollbackLeaked();
            $this->logger?->warning('ConnectionCleanupMiddleware: 请求结束时检测到未提交事务，已强制回滚以避免跨请求泄漏');
        }

        if ($this->releasePerRequest) {
            $this->releaseConnections();
        }
    }

    /** 当前是否处于事务中（委托 kode/database）。 */
    protected function inTransaction(): bool
    {
        return Db::inTransaction();
    }

    /** 回滚泄漏事务（委托 kode/database）。 */
    protected function rollbackLeaked(): void
    {
        try {
            Db::rollback();
        } catch (\Throwable) {
            // 连接已断开等极端情况：忽略，避免掩盖原始流程。
        }
    }

    /** 释放全部缓存连接（委托 kode/database）。 */
    protected function releaseConnections(): void
    {
        try {
            Db::disconnect();
        } catch (\Throwable) {
            // 释放失败不阻断主流程。
        }
    }
}
