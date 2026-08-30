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
use Kode\Process\Reactor\LoopFactory;
use Kode\Process\Runtime\ConnectionInterface;
use Kode\Process\Runtime\RuntimeInterface;
use Kode\Process\Runtime\RuntimeType;
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
 *  - **可观测性由框架补齐、不改 vendor**：kode/process 不暴露子进程退出码与逐进程计数，
 *    故本类在 worker 内以 1Hz 心跳写状态文件（{@see ServerStatusStore}），
 *    供 `bin/kode status` 渲染 workerman 风格的进程表。
 *
 * 工厂入口见 {@see serve()}；命令行由 bin/kode 调用。
 */
final class HttpServer
{
    /**
     * 优雅停机宽限的默认值（秒）。
     *
     * 取 3 与 kode/process 内置默认一致：交互式开发（Ctrl+C）下「空转等满宽限」是最
     * 伤体验的延迟来源，长事务场景应由使用者按 P99 显式调大，而不是让所有人陪着等。
     */
    public const DEFAULT_GRACEFUL_TIMEOUT = 3;

    /** worker 心跳周期（秒）：同时也是停机排空的检查粒度。 */
    private const HEARTBEAT_INTERVAL = 0.5;

    /** 状态文件写入限流（秒）：排空检查 0.5s 一次，但文件 1s 才落一次盘。 */
    private const STATUS_WRITE_INTERVAL = 1.0;

    /** 控制台表格宽度（对齐 workerman 的 78 列观感）。 */
    private const TABLE_WIDTH = 78;

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
     * 解析运行时路径（PID 文件 / 日志文件 / 状态目录）。
     *
     * `serve` 与 CLI 的 `stop` / `reload` / `status` 共用同一套解析规则，
     * 避免「启停两处各拼一次路径」导致的漂移（改了配置却发现 stop 找不到 pid 文件）。
     *
     * 优先级：config/server.php 显式配置 > 默认值（状态目录下的 kode.pid / kode.log）。
     * 相对路径按项目根解析，绝对路径原样使用。
     *
     * @param array<string, mixed> $config config/server.php 内容
     * @return array{pid_file: string, log_file: string, runtime_dir: string}
     */
    public static function resolveRuntimePaths(string $root, array $config = []): array
    {
        $root   = rtrim($root, '/');
        $store  = ServerStatusStore::forRoot($root, isset($config['runtime_path']) ? (string) $config['runtime_path'] : null);
        $dir    = $store->dir();

        $pidFile = isset($config['pid_file']) ? (string) $config['pid_file'] : '';
        $logFile = isset($config['log_file']) ? (string) $config['log_file'] : '';

        return [
            'pid_file'    => self::absolutePath($root, $pidFile !== '' ? $pidFile : $dir . '/kode.pid'),
            'log_file'    => self::absolutePath($root, $logFile !== '' ? $logFile : $dir . '/kode.log'),
            'runtime_dir' => $dir,
        ];
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
        $gracefulTimeout = max(0, (int) ($this->config['graceful_shutdown_timeout'] ?? self::DEFAULT_GRACEFUL_TIMEOUT));
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

        // 守护化与运行时路径（PID/日志/状态目录）。
        $daemonize = (bool) ($this->config['daemonize'] ?? false);
        $paths     = self::resolveRuntimePaths($root, $this->config);
        $statusStore = ServerStatusStore::forRoot($root, isset($this->config['runtime_path']) ? (string) $this->config['runtime_path'] : null);
        $statusStore->ensureDir();
        // 只清失效记录：重复 serve 不抹掉另一个仍在运行的实例的状态。
        $statusStore->prune();
        $startedAt = microtime(true);

        // 2) 按 worker 隔离的 HTTP 内核（fork 后每份独立重建）。
        $http = null;
        $app  = null;
        $graceful = null;

        // 逐进程遥测计数器：master 里的实例随 fork 被复制到每个 worker，
        // 各子进程此后独立递增（写时复制，互不干扰），无需任何跨进程同步。
        $telemetry = new WorkerTelemetry();

        $bootWorker = function () use ($root, &$http, &$app, &$graceful): void {
            if ($http === null) {
                $app = Application::make($root);
                $http = $app->http();
                // 优雅停机管理器：每 worker 一个实例，请求路径上用于计入/计出在途请求。
                $graceful = $app->core()->container->get(GracefulShutdown::class);
            }
        };

        $listen = "http://{$host}:{$port}";

        $runtime = Kode::serve($listen, [
            'workers'     => $workers,
            'maxRequest'  => $maxRequest,
            'reusePort'   => $reusePort,
            'name'        => $name,
            // 优雅停机宽限：kode/process 收到 SIGTERM 后停收新连接，并等待在途连接
            // 在此时间内自然关闭，超时则强制退出（应小于 k8s terminationGracePeriodSeconds）。
            'gracefulShutdownTimeout' => $gracefulTimeout,
            // 守护化 / PID 文件 / 日志：kode/process 在 start() 内自行两次 fork + setsid，
            // 并在脱离终端之后写 PID，故守护模式下的 pid 文件里才是真正的 master pid。
            'daemonize'   => $daemonize,
            'logFile'     => $paths['log_file'],
            'pidFile'     => $paths['pid_file'],
        ], $runtimeType)
        ->on('workerStart', function (int $workerId) use (
            $bootWorker,
            &$runtime,
            &$graceful,
            $telemetry,
            $statusStore,
            $root,
            $name,
            $listen,
            $host,
            $port,
            $workers,
            $gracefulTimeout,
            $daemonize,
            $startedAt,
            $runtimeType
        ): void {
            $bootWorker();
            // worker 级启动钩子：应用已就绪，可建立独立连接池 / 启动周期任务。
            try {
                event(new \Kode\Framework\Lifecycle\WorkerStarting($workerId));
            } catch (\Throwable) {
                // 事件系统未就绪：不阻断启动。
            }

            $this->startWorkerTelemetry(
                runtime:     $runtime,
                graceful:    $graceful,
                telemetry:   $telemetry,
                store:       $statusStore,
                workerId:    $workerId,
                pid:         getmypid(),
                masterPid:   function_exists('posix_getppid') ? posix_getppid() : 0,
                context:     [
                    'root'             => $root,
                    'name'             => $name,
                    'listen'           => $listen,
                    'host'             => $host,
                    'port'             => $port,
                    'workers'          => $workers,
                    'graceful_timeout' => $gracefulTimeout,
                    'daemon'           => $daemonize,
                    'started_at'       => $startedAt,
                    'runtime'          => $runtimeType,
                ],
            );
        })
        ->on('workerStop', function (int $workerId) use ($statusStore): void {
            // 优雅停机钩子：刷新指标 / 关闭连接 / 落盘 / 注册中心下线，应在宽限期内完成。
            try {
                event(new \Kode\Framework\Lifecycle\WorkerStopping($workerId));
            } catch (\Throwable) {
                // 忽略。
            }
            $statusStore->removeWorker(getmypid());
        })
        // 连接计数：keep-alive 下一条连接会承载多个请求，故单独由 connect/close 维护，
        // 不能拿请求数代替（否则状态表里的 connections 会虚高到累计请求量）。
        ->on('connect', static function () use ($telemetry): void {
            $telemetry->onConnect();
        })
        ->on('close', static function () use ($telemetry): void {
            $telemetry->onClose();
        })
        // 注意：不可声明为 static closure——兜底 errorResponse 依赖 $this，
        // static 闭包不绑定对象，异常路径会二次抛「Using $this when not in object context」。
        ->on('message', function (ConnectionInterface $conn, $message) use (&$http, &$graceful, $bootWorker, $telemetry, $lean, $debug): void {
            if (!$message instanceof ProcessRequest) {
                return;
            }

            $telemetry->onRequest();
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

        // 横幅必须在 start() 之前输出：守护化会把 STDOUT/STDERR 重定向到日志文件，
        // 之后 echo 的内容只会进日志，终端上什么都看不到。
        echo $this->renderBanner($runtime, [
            'listen'  => $listen,
            'host'    => $host,
            'port'    => $port,
            'workers' => $workers,
            'root'    => $root,
            'name'    => $name,
            'daemon'  => $daemonize,
            'debug'   => $debug,
        ]);

        $runtime->start();

        // 走到这里说明 master 已收到停止信号并收割完所有 worker（非守护模式下是正常退出路径）。
        // 自删 + prune：先清掉本进程的 master 记录，再剔除其它失效记录；
        // 用 prune 而非 clear，避免误删同目录下另一个仍在运行的实例的状态。
        $statusStore->removeMasterFileIfSelf();
        $statusStore->prune();
    }

    // ------------------------------------------------------------ 可观测性

    /**
     * 在 worker 内启动遥测心跳 + 快速排空看门狗。
     *
     * 心跳（1Hz 落盘）：把本进程的内存 / 连接数 / 累计请求 / QPS 写进状态文件，
     * 这是 `bin/kode status` 唯一的真实数据来源——kode/process 不向外暴露逐进程计数，
     * 与其在 CLI 侧猜，不如让每个进程自报。
     *
     * 快速排空（0.5s 粒度）：kode/process 收到停机信号后固定空等一整个宽限期，
     * 只有在「某条连接被关闭」时才会提前结束循环——于是**空闲服务按 Ctrl+C 也要等满
     * graceful_shutdown_timeout**（配置文件里是 30s，实测退出耗时 30s）。
     * 这里补一个看门狗：收到停机信号后，一旦在途请求归零就立刻结束事件循环，
     * 把「空闲退出」从「等满宽限」压缩到 ≤0.5s；真有在途请求时仍走完整宽限，不丢请求。
     *
     * @param RuntimeInterface|null $runtime  用于注册定时器与主动结束循环（注册时尚未赋值）
     * @param GracefulShutdown|null $graceful 在途请求计数器
     * @param array<string, mixed>  $context  master 级信息（listen / workers / 版本 …）
     */
    private function startWorkerTelemetry(
        ?RuntimeInterface $runtime,
        ?GracefulShutdown $graceful,
        WorkerTelemetry $telemetry,
        ServerStatusStore $store,
        int $workerId,
        int $pid,
        int $masterPid,
        array $context,
    ): void {
        $runtimeLabel = $this->runtimeLabel($runtime, $context['runtime'] ?? null);

        // master 记录由 worker 代写：守护化后真正的 master pid 只有子进程能通过 ppid 观测到，
        // 而 start() 之前（守护化发生前）拿到的 pid 会指向即将退出的中间进程。
        if ($masterPid > 1) {
            $store->writeMaster([
                'pid'              => $masterPid,
                'name'             => $context['name'],
                'proto'            => 'http',
                'listen'           => $context['listen'],
                'host'             => $context['host'],
                'port'             => $context['port'],
                'workers'          => $context['workers'],
                'runtime'          => $runtimeLabel,
                'loop'             => $this->loopLabel(),
                'version'          => Application::VERSION,
                'php_version'      => PHP_VERSION,
                'started_at'       => $context['started_at'],
                'root'             => $context['root'],
                'graceful_timeout' => $context['graceful_timeout'],
                'daemon'           => $context['daemon'],
            ]);
        }

        if ($runtime === null) {
            return;
        }

        // 链式接管停机信号：置排空标志后，仍把信号交给 kode/process 原处理器。
        $this->chainStopSignals(static function () use ($telemetry): void {
            $telemetry->markStopping();
        });

        $lastWrite = 0.0;
        $lastReq   = 0;
        $lastStamp = microtime(true);

        $runtime->addTimer(self::HEARTBEAT_INTERVAL, function () use (
            $runtime,
            $graceful,
            $telemetry,
            $store,
            $workerId,
            $pid,
            $context,
            $runtimeLabel,
            &$lastWrite,
            &$lastReq,
            &$lastStamp
        ): void {
            $requests  = $telemetry->requests();
            $now       = microtime(true);
            $qps       = (int) round(max(0, $requests - $lastReq) / max(0.001, $now - $lastStamp));
            $lastReq   = $requests;
            $lastStamp = $now;

            if ($now - $lastWrite >= self::STATUS_WRITE_INTERVAL) {
                $lastWrite = $now;
                $store->writeWorker($pid, [
                    'pid'         => $pid,
                    'worker_id'   => $workerId,
                    'worker_name' => $context['name'] . '#' . $workerId,
                    'name'        => $context['name'],
                    'listen'      => $context['listen'],
                    'runtime'     => $runtimeLabel,
                    'memory'      => memory_get_usage(true),
                    'peak_memory' => memory_get_peak_usage(true),
                    'connections' => $telemetry->connections(),
                    'requests'    => $requests,
                    'qps'         => $qps,
                    'inflight'    => $graceful instanceof GracefulShutdown ? $graceful->inFlight() : 0,
                    'started_at'  => (float) $context['started_at'],
                    'updated_at'  => $now,
                ]);
            }

            // 快速排空：已停机且无在途请求 → 立即结束事件循环（idle 退出不必等满宽限）。
            $inFlight = $graceful instanceof GracefulShutdown ? $graceful->inFlight() : 0;
            if ($telemetry->isStopping() && $inFlight === 0) {
                $runtime->stop();
            }
        }, true);
    }

    /**
     * 链式接管 worker 的停机信号（SIGINT / SIGTERM / SIGUSR1）。
     *
     * 关键点：**不覆盖** kode/process 已注册的处理器，而是先取回原处理器再包一层——
     * 直接 `pcntl_signal()` 覆盖会废掉 vendor 的「停收新连接 → HTTP/2 GOAWAY → 排空宽限」
     * 整条优雅停机链路。取回后在新处理器里先跑我们的标志位，再原样透传。
     *
     * ext-ev / ext-event 驱动下信号不走 pcntl（走各自 watcher），此时取回的是 SIG_DFL，
     * 我们的处理器作为附加监听器并存，两条链路各触发一次，行为仍然正确。
     */
    private function chainStopSignals(callable $onStop): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_signal_get_handler')) {
            return;
        }

        foreach ([SIGINT, SIGTERM, SIGUSR1] as $signal) {
            $previous = pcntl_signal_get_handler($signal);

            pcntl_signal($signal, static function (int $signo) use ($onStop, $previous): void {
                $onStop();

                if (is_callable($previous)) {
                    $previous($signo);
                }
            });
        }
    }

    // ---------------------------------------------------------------- 横幅

    /**
     * 渲染启动横幅（对标 workerman 的 WORKERS 表：进程数 / 监听地址 / 状态一目了然）。
     *
     * @param array<string, mixed> $ctx listen/host/port/workers/root/name/daemon/debug
     */
    private function renderBanner(?RuntimeInterface $runtime, array $ctx): string
    {
        $mode         = !empty($ctx['debug']) ? 'DEBUG' : 'PRODUCTION';
        $runtimeLabel = $this->runtimeLabel($runtime, null);
        $loop         = $this->loopLabel();
        $user         = $this->currentUser();

        $out  = "Kode[bin/kode] start in {$mode} mode\n";
        $out .= self::separator('KODE') . "\n";
        $out .= sprintf(
            "Kode Framework version:%-14s PHP version:%s\n",
            Application::VERSION,
            PHP_VERSION
        );
        $out .= sprintf("Runtime:%-24s Event-Loop:%s\n", $runtimeLabel, $loop);
        $out .= self::separator('WORKERS') . "\n";
        $out .= sprintf(
            "%-8s %-10s %-16s %-28s %-10s %s\n",
            'proto',
            'user',
            'worker',
            'listen',
            'processes',
            'status'
        );
        $out .= sprintf(
            "%-8s %-10s %-16s %-28s %-10d %s\n",
            'http',
            $user,
            (string) $ctx['name'],
            (string) $ctx['listen'],
            (int) $ctx['workers'],
            '[OK]'
        );
        $out .= self::separator() . "\n";
        $out .= "项目根目录：" . (string) $ctx['root'] . "\n";

        // 守护模式下终端已经脱离，Ctrl+C 不再可达，必须提示用 stop 命令停止。
        $out .= !empty($ctx['daemon'])
            ? "守护模式已启动（PID 文件：" . ($this->config['pid_file'] ?? 'storage/runtime/kode.pid') . "）。\n"
              . "Input \"php bin/kode stop\" to stop. Start success.\n"
            : "Press Ctrl+C to stop. Start success.\n";

        return $out;
    }

    /** 分隔线：带标签时把标签嵌进虚线中（workerman 观感）。 */
    private static function separator(string $label = ''): string
    {
        if ($label === '') {
            return str_repeat('-', self::TABLE_WIDTH);
        }

        $tag   = ' ' . $label . ' ';
        $left  = 3;
        $right = max(0, self::TABLE_WIDTH - $left - strlen($tag));

        return str_repeat('-', $left) . $tag . str_repeat('-', $right);
    }

    /**
     * 实际生效的运行时名（以运行时实例自报为准，配置只是意图）。
     *
     * 刻意**不调用** `$runtime->stats()`：它会连带加载 `Kode\Process\Version`，
     * 后者在 PHP 8.5 上是致命错误（详见 {@see WorkerTelemetry} 类注释）。
     */
    private function runtimeLabel(?RuntimeInterface $runtime, ?string $fallback): string
    {
        if ($runtime !== null && method_exists($runtime, 'type')) {
            $type = $runtime::type();
            if ($type instanceof RuntimeType) {
                return $type->value;
            }
        }

        return $fallback ?? 'auto';
    }

    /** 事件循环驱动名（ext-event / ext-ev / select）。 */
    private function loopLabel(): string
    {
        return class_exists(LoopFactory::class) ? (string) LoopFactory::preferred() : 'unknown';
    }

    /** 当前进程的系统用户名（不可用时退回 uid）。 */
    private function currentUser(): string
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
            return 'unknown';
        }

        $info = @posix_getpwuid(posix_geteuid());
        if (is_array($info) && isset($info['name'])) {
            return (string) $info['name'];
        }

        return (string) posix_geteuid();
    }

    /** 相对路径按项目根解析，绝对路径原样返回。 */
    private static function absolutePath(string $root, string $path): string
    {
        if ($path === '') {
            return $root;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path) || str_starts_with($path, '\\\\')
                ? $path
                : $root . '/' . ltrim($path, '/');
        }

        return str_starts_with($path, '/') ? $path : $root . '/' . ltrim($path, '/');
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
