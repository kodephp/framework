<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Exception\ExceptionManager;
use Kode\Exception\Formatter\UnifiedResponseFormatter;
use Kode\Exception\KodeException;
use Kode\Framework\Providers\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * 异常处理服务提供者（委托 kode/exception）
 *
 * 框架是 API 优先框架，错误响应默认即结构化 JSON（含 file / line / chain 以便
 * 追踪到出错的源文件位置），不再提供「开发者友好 HTML 调试页」。
 *
 * 统一异常处理、链路追踪与响应格式化全部交由 kode/exception 的
 * {@see ExceptionManager} + {@see UnifiedResponseFormatter} 完成；框架只负责：
 *  - 把框架的 Monolog 日志器接进管理器（日志落框架）；
 *  - 用 app.debug 决定生产模式（生产模式自动收敛绝对路径与系统异常细节）；
 *  - setSendHeaders(false)：HTTP 输出由框架的 ExceptionMiddleware 接管（自己拼 Response）。
 *
 * 不调用 ExceptionManager::register()：全局 set_exception_handler 会接管整个进程，
 * 与 kode/process / kode/core 的运行时冲突；框架只在 HTTP 中间件里按需 respond()。
 */
final class ExceptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ExceptionManager::class, function (): ExceptionManager {
            $debug = (bool) $this->config('app.debug', false);
            $isProduction = !$debug;

            /** @var LoggerInterface $logger */
            $logger = $this->container->get(LoggerInterface::class);

            $manager = new ExceptionManager(
                logger: $logger,
                formatter: new UnifiedResponseFormatter($isProduction),
                isProduction: $isProduction,
            );

            // 响应交由框架中间件写出，不在此 echo / 发头。
            $manager->setSendHeaders(false);

            // 配置 kode/exception 的全局链路上下文（trace_id / span_id 生成、实参捕获）。
            KodeException::init(
                isProduction: $isProduction,
                serviceName: (string) $this->config('app.name', 'kode-app'),
            );

            return $manager;
        });

        $this->container->alias('exception.manager', ExceptionManager::class);
    }
}
