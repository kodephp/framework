<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Database\Db\Db;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求级数据库事务（原子化写请求）
 *
 * 对写方法（POST / PUT / PATCH / DELETE）在请求开始时开启一个数据库事务，
 * 控制器内所有写操作都在同一事务上下文中；请求正常返回则提交，抛异常则回滚。
 * 这把「一次 HTTP 请求 = 一个原子工作单元」这一企业级不变量落到框架层，
 * 避免「写了 A 成功、写 B 失败、数据半成品」的脏状态。
 *
 * 设计立场：
 *  - **默认关闭**，需 config('database.auto_transaction') = true 才生效——避免对只读/
 *    GET 请求、以及不触碰数据库的心跳/健康检查无谓开事务。
 *  - **仅作用于写方法**，读请求（GET/HEAD/OPTIONS）直接放行，零开销。
 *  - **跳过路径**：transaction_skip_paths 中的路径（如 /health、/metrics）不开启，
 *    这些探针本就不写库。
 *  - **异常透传**：回滚后仍将异常重新抛出，交由最外层 ExceptionMiddleware 产出
 *    统一的结构化错误响应（含 trace_id），错误形态与无事务时完全一致。
 *  - **真正原子**：kode/database 1.15.5+ 缓存同一连接名的 PDO 连接，begin/insert/commit
 *    落在同一连接上，事务原子性成立；事务进行中读操作强制走主库（写连接），避免读到
 *    未提交数据被读写分离路由到从库。
 *  - 嵌套事务由 kode/database 的 transactionDepth 跟踪 + PDO savepoint 自然处理
 *    （控制器内再调 db()->transaction() 不会破坏外层事务边界）。
 */
class TransactionMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $writeMethods   视为「写」的 HTTP 方法
     * @param list<string> $skipPaths       跳过事务的路径前缀
     */
    public function __construct(
        private readonly bool $enabled = false,
        private readonly array $writeMethods = ['POST', 'PUT', 'PATCH', 'DELETE'],
        private readonly array $skipPaths = ['/health', '/metrics', '/ping'],
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->shouldWrap($request)) {
            return $handler->handle($request);
        }

        $this->begin();
        try {
            $response = $handler->handle($request);
            $this->commit();

            return $response;
        } catch (\Throwable $e) {
            // 回滚后透传，交由 ExceptionMiddleware 统一格式化错误响应。
            try {
                $this->rollback();
            } catch (\Throwable) {
                // 连接已断开等极端情况下回滚可能再失败，忽略以免掩盖原始异常。
            }
            throw $e;
        }
    }

    /**
     * 事务控制（委托 kode/database 的静态代理；抽出为受保护方法便于测试 spy）。
     */
    protected function begin(): void
    {
        Db::beginTransaction();
    }

    protected function commit(): void
    {
        Db::commit();
    }

    protected function rollback(): void
    {
        Db::rollback();
    }

    private function shouldWrap(ServerRequestInterface $request): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $method = strtoupper((string) $request->getMethod());
        if (!in_array($method, $this->writeMethods, true)) {
            return false;
        }

        $path = $request->getUri()->getPath();
        foreach ($this->skipPaths as $skip) {
            if (str_starts_with($path, (string) $skip)) {
                return false;
            }
        }

        return true;
    }
}
