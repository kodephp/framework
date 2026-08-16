<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

use Kode\Exception\ExceptionManager;
use Kode\Framework\Application;
use Kode\Framework\Http\Resp;
use Kode\Http\App as HttpApp;
use Kode\Http\Response;
use Kode\Process\Http\Request as ProcessRequest;
use Kode\Process\Kode;
use Kode\Process\Protocol\HttpProtocol;
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
        ])
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
        ->on('message', static function (ConnectionInterface $conn, $message) use (&$http, &$graceful, $bootWorker): void {
            if (!$message instanceof ProcessRequest) {
                return;
            }

            $bootWorker();
            if ($http === null) {
                HttpBridge::emit($conn, Resp::error('服务尚未就绪', 503));
                return;
            }

            try {
                $psr = HttpBridge::toPsr7($message);
                /** @var HttpApp $http */
                $handler = static fn () => $http->handle($psr);
                $response = $graceful instanceof GracefulShutdown ? $graceful->track($handler) : $handler();
                $gzip = HttpProtocol::acceptsGzip((string) ($message->header('accept-encoding') ?? ''));
                HttpBridge::emit($conn, $response, self::normalizeProtocol($message->protocol()), $gzip);
            } catch (\Throwable $e) {
                $debug = (bool) (Application::getInstance()?->config()->get('app.debug', false) ?? false);
                HttpBridge::emit($conn, $this->errorResponse($e, $debug));
            }
        });

        $runtime->start();
    }

    /**
     * 从请求协议头（如 "HTTP/1.1"）提取版本号（"1.1"）。
     */
    private static function normalizeProtocol(string $protocol): string
    {
        if (preg_match('#HTTP/(\d+\.\d+)#i', $protocol, $m)) {
            return $m[1];
        }

        return '1.1';
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
