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

        echo "正在启动 Kode 多进程服务：http://{$host}:{$port}（worker={$workers}）\n";
        echo "项目根目录：{$root}\n";

        // 2) 按 worker 隔离的 HTTP 内核（fork 后每份独立重建）。
        $http = null;

        $bootWorker = function () use ($root, &$http): void {
            if ($http === null) {
                $http = Application::make($root)->http();
            }
        };

        $runtime = Kode::serve("http://{$host}:{$port}", [
            'workers'     => $workers,
            'maxRequest'  => $maxRequest,
            'reusePort'   => $reusePort,
            'name'        => $name,
        ])
        ->on('workerStart', static function (int $workerId) use ($bootWorker): void {
            $bootWorker();
        })
        ->on('message', static function (ConnectionInterface $conn, $message) use (&$http, $bootWorker): void {
            if (!$message instanceof ProcessRequest) {
                return;
            }

            $bootWorker();
            if ($http === null) {
                $conn->send(HttpBridge::toRaw(Resp::error('服务尚未就绪', 503)), true);
                return;
            }

            try {
                $psr = HttpBridge::toPsr7($message);
                /** @var HttpApp $http */
                $response = $http->handle($psr);
                $conn->send(HttpBridge::toRaw($response, self::normalizeProtocol($message->protocol())), true);
            } catch (\Throwable $e) {
                $debug = (bool) (Application::getInstance()?->config()->get('app.debug', false) ?? false);
                $conn->send(HttpBridge::toRaw($this->errorResponse($e, $debug)), true);
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
