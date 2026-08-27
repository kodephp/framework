<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

use Kode\Exception\ExceptionManager;
use Kode\Framework\Application;
use Kode\Framework\Http\Resp;
use Kode\Http\App as HttpApp;
use Kode\Http\Psr7\Stream;
use Kode\Http\Response;
use Kode\Http\Routing\RouteResult;
use Kode\Http\Routing\RouteRunner;
use Kode\Process\Http\Request as ProcessRequest;
use Kode\Process\Kode;
use Kode\Process\Runtime\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use function Kode\Process\cpu_count;

/**
 * 基于 kode/process 的多进程 HTTP 服务。
 *
 * 设计立场：
 *  - **复用 kode/process 成熟的 master-worker 多进程运行时**（纯 PHP Native 实现，
 *    零扩展依赖；亦可在 composer 中接入 Swoole/Workerman 而不动业务），不再自写 prefork。
 *  - **每个 worker 独立重建 Application**：fork 后父子进程共享的是写时复制的类定义，
 *    但数据库连接、缓存句柄、JWT 密钥等可变状态必须按进程隔离，否则会串号/损坏。
 *    master 仅做一次预热启动用于「启动即失败」式的配置校验与类预加载。
 *  - 请求处理走框架内核 {@see HttpApp::handle()}（PSR-7 入、PSR-7 出），由
 *    {@see HttpBridge} 在 kode/process 的统一请求/响应与本内核之间互转。
 *
 * 工厂入口见 {@see serve()}；命令行由 bin/kode 调用。
 */
final class HttpServer
{
    /**
     * @param array<string, mixed> $config 见 config/server.php
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * 便捷工厂。
     *
     * @param array<string, mixed> $config
     */
    public static function make(array $config = []): self
    {
        return new self($config);
    }

    /**
     * 启动服务（阻塞，直到收到停止信号）。
     *
     * @param string $root 项目根目录（config/app/ 所在目录）
     */
    public function run(string $root): void
    {
        // 1) master 预热：提前校验配置、预加载类定义（失败则立即退出，避免 fork 后才崩）。
        try {
            Application::make($root);
        } catch (\Throwable $e) {
            fwrite(STDERR, "启动前校验失败：{$e->getMessage()}\n");
            exit(1);
        }

        $host    = (string) ($this->config['host'] ?? '127.0.0.1');
        $port    = (int) ($this->config['port'] ?? 8000);
        $configuredWorkers = (int) ($this->config['workers'] ?? 0);
        $workers = $configuredWorkers > 0 ? $configuredWorkers : $this->defaultWorkers();
        $maxRequest = max(0, (int) ($this->config['max_request'] ?? 0));
        $reusePort  = (bool) ($this->config['reuse_port'] ?? false);
        $name    = (string) ($this->config['name'] ?? 'kode-http');
        $gracefulTimeout = max(0, (int) ($this->config['graceful_shutdown_timeout'] ?? 30));
        $k8sGrace = max(0, (int) ($_SERVER['K8S_TERMINATION_GRACE_PERIOD'] ?? getenv('K8S_TERMINATION_GRACE_PERIOD') ?: 30));
        if ($gracefulTimeout >= $k8sGrace - 5 && $k8sGrace > 0) {
            fwrite(STDERR, "[kode] 警告：graceful_shutdown_timeout({$gracefulTimeout}s) >= K8s terminationGracePeriodSeconds({$k8sGrace}s)-5s，留给 LB 摘流与进程退出的余量不足，滚动更新可能丢在途请求（建议 graceful < termination-5，且配置 preStop sleep 5s）。\n");
        }
        $debug   = (bool) ($this->config['debug'] ?? false);

        // 运行时选择：CLI --runtime > config/server.php[runtime] > 环境变量 KODE_RUNTIME > 自动择优。
        // 允许值：native（自研多进程，零扩展）/ swoole（单进程协程，kode/fibers）/ workerman。
        $runtimeType = $this->config['runtime']
            ?? ($_SERVER['KODE_RUNTIME'] ?? getenv('KODE_RUNTIME') ?: null);
        if ($runtimeType !== null && !in_array($runtimeType, ['native', 'swoole', 'workerman'], true)) {
            fwrite(STDERR, "未知运行时：{$runtimeType}（可选 native|swoole|workerman）\n");
            exit(1);
        }

        // Lean opt-out（默认关）：跳过 toPsr7 + App::handle(全局中间件管道) + emit 整段，
        // 对「无路由级中间件的热路径」直发 raw，追平 webman 同构 handler 的吞吐天花板。
        // 通过 server.lean 配置或 KODE_LEAN=1 环境变量开启；带中间件 / 404 / 405 的
        // 请求自动退回完整 PSR-7 路径，默认行为零影响。
        $leanEnv = $_SERVER['KODE_LEAN'] ?? getenv('KODE_LEAN');
        $lean    = !empty($this->config['lean']) || $leanEnv === '1' || $leanEnv === 'true';

        echo "正在启动 Kode 多进程服务：http://{$host}:{$port}（worker={$workers}）\n";
        echo "项目根目录：{$root}\n";

        // 2) 按 worker 隔离的 HTTP 内核（fork 后每份独立重建）。
        $http = null;
        $app  = null;
        $graceful = null;

        $bootWorker = function () use ($root, &$http, &$app, &$graceful): void {
            if ($http === null) {
                $app = Application::make($root);
                $http = $app->http();
                // 优雅停机管理器：每 worker 一个实例，请求路径上用于计入/计出在途请求。
                $graceful = $app->core()->container->get(GracefulShutdown::class);
            }
        };

        $runtime = Kode::serve("http://{$host}:{$port}", [
            'workers'     => $workers,
            'maxRequest'  => $maxRequest,
            'reusePort'   => $reusePort,
            'name'        => $name,
            // 优雅停机宽限：kode/process 收到 SIGTERM 后停收新连接，并等待在途连接
            // 在此时间内自然关闭，超时则强制退出（应小于 k8s terminationGracePeriodSeconds）。
            'gracefulShutdownTimeout' => $gracefulTimeout,
        ], $runtimeType)
        ->on('workerStart', static function (int $workerId) use ($bootWorker): void {
            $bootWorker();
            // worker 级启动钩子：应用已就绪，可建立独立连接池 / 启动周期任务。
            try {
                event(new \Kode\Framework\Lifecycle\WorkerStarting($workerId));
            } catch (\Throwable) {
                // 事件系统未就绪：不阻断启动。
            }
        })
        ->on('workerStop', static function (int $workerId): void {
            // 优雅停机钩子：刷新指标 / 关闭连接 / 落盘 / 注册中心下线，应在宽限期内完成。
            try {
                event(new \Kode\Framework\Lifecycle\WorkerStopping($workerId));
            } catch (\Throwable) {
                // 忽略。
            }
        })
        // 注意：不可声明为 static closure——兜底 errorResponse 依赖 $this，
        // static 闭包不绑定对象，异常路径会二次抛「Using $this when not in object context」。
        ->on('message', function (ConnectionInterface $conn, $message) use (&$http, &$graceful, $bootWorker, $lean, $debug): void {
            if (!$message instanceof ProcessRequest) {
                return;
            }

            $bootWorker();
            if ($http === null) {
                HttpBridge::emit($conn, Resp::error('服务尚未就绪', 503));
                return;
            }

            // ── Lean opt-out ──────────────────────────────────────────────
            // 对「无路由级中间件的命中路由」跳过 App::handle(全局中间件管道)，
            // 直接 RouteRunner::invoke + Response::resolve + 原生 raw 直发。
            // 含中间件 / 404 / 405 / 非 HTTP/1.x 的请求自动退回下方完整 PSR-7 路径。
            if ($lean) {
                /** @var HttpApp $http */
                $router = $http->getRouter();
                $result = $router->match($message->method(), $message->path());
                // 快路径守卫：HTTP/2 连接必须走 emit/sendResponse——对 h2 流 send(raw=true)
                // 会把整份 1.1 报文当作单个 DATA 帧体写出（响应损坏），故 h2 一律落入完整路径。
                $protocol = $message->protocol();
                $isHttp1 = $protocol === 'HTTP/1.1' || $protocol === 'HTTP/1.0';
                if ($result->status === RouteResult::FOUND && $isHttp1) {
                    $route = $result->route;
                    if ($route->getMiddlewares() === []) {
                        // 复用 LazyServerRequest（懒解析、零急切成本）：修复旧快路径自拼
                        // 「method+path 空壳」导致 query/header/body/cookie 全部丢失的缺陷；
                        // 并写入 kode/context 请求上下文，使 Request 门面在快路径下与完整
                        // 路径语义一致（App::handle / RouteRunner::handle 均如此约定）。
                        $leanReq = HttpBridge::toPsr7($message);
                        // 有参数路由才写入 attribute（_route 无消费方；_route_params 对空参
                        // 恒等于 Request::param()/att('_route_params', []) 的默认值 []，语义一致）
                        if ($result->params !== []) {
                            $leanReq = $leanReq
                                ->withAttribute('_route', $route)
                                ->withAttribute('_route_params', $result->params);
                            foreach ($result->params as $k => $v) {
                                $leanReq = $leanReq->withAttribute($k, $v);
                            }
                        }
                        $invoke = static fn(): ResponseInterface => Response::resolve(
                            RouteRunner::invoke($route->getHandler(), $leanReq, $result->params)
                        );
                        try {
                            \Kode\Http\Request::setRequest($leanReq);
                            try {
                                // 在途计数：排空期清理回调（flush 日志/队列/追踪）必须等快路径请求完成。
                                $response = $graceful instanceof GracefulShutdown ? $graceful->track($invoke) : $invoke();
                            } finally {
                                \Kode\Http\Request::clear();
                            }
                        } catch (\Throwable $e) {
                            $response = $this->errorResponse($e, $debug);
                        }
                        // HEAD 响应不带实体（与完整路径 App::handle 行为一致）：
                        // 否则 keep-alive 下客户端会把下一响应前 N 字节当 HEAD 的 body 解析，连接级错位。
                        if (strtoupper($message->method()) === 'HEAD') {
                            $response = $response->withBody(Stream::create(''));
                        }
                        $conn->send(HttpBridge::toRaw($response, $protocol === 'HTTP/1.0' ? '1.0' : '1.1'), true);
                        return;
                    }
                }
                // 命中带中间件路由 / 未命中 / HTTP/2：落入完整 PSR-7 路径
            }

            try {
                $psr = HttpBridge::toPsr7($message);
                /** @var HttpApp $http */
                $handler = static fn () => $http->handle($psr);
                $response = $graceful instanceof GracefulShutdown ? $graceful->track($handler) : $handler();
                HttpBridge::emit($conn, $response);
            } catch (\Throwable $e) {
                HttpBridge::emit($conn, $this->errorResponse($e, $debug));
            }
        });

        $runtime->start();
    }

    private function defaultWorkers(): int
    {
        if (function_exists('Kode\\Process\\cpu_count')) {
            return max(1, (int) cpu_count());
        }

        return 4;
    }

    /**
     * worker 内兜底错误响应：复用 kode/exception 的 ExceptionManager 产出结构化 JSON，
     * 与框架正常请求路径的错误形态保持一致（含 trace_id / location / chain）。
     * 仅在中间件链路之外的极端异常（如桥接阶段）才会走到这里。
     */
    private function errorResponse(\Throwable $e, bool $debug): ResponseInterface
    {
        if (class_exists(ExceptionManager::class)) {
            try {
                $manager = exception_manager();
                $result = $manager->respond($e);
                $body = $result['body'];

                $response = Response::json($body)->status($result['status']);
                if (!empty($body['trace_id'])) {
                    $response = $response->header('X-Trace-Id', (string) $body['trace_id']);
                }
                if (!empty($body['span_id'])) {
                    $response = $response->header('X-Span-Id', (string) $body['span_id']);
                }

                return $response;
            } catch (\Throwable) {
                // 落到下面的兜底，避免错误响应自身再崩溃。
            }
        }

        return Resp::error($debug ? $e->getMessage() : '服务器内部错误', 500);
    }
}
