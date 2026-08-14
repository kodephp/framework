<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Idempotency\IdempotencyManager;
use Kode\Framework\Idempotency\StaticIdempotencyManager;
use Kode\Framework\Idempotency\StaticIdempotencyStore;

/**
 * 幂等子系统服务提供者（薄壳层）。
 *
 * 始终接线：框架内置幂等契约 + 内置静态存储（memory / file），依赖 {@see IdempotencyManager} 单例，
 * 故本 Provider 无条件绑定（不引入独立开关）。
 *
 * 绑定：
 *  - IdempotencyManager：按 config/idempotency.php 的 `driver`（memory | file）组合
 *    StaticIdempotencyStore + StaticIdempotencyManager；file 模式落盘到
 *    storage_path('framework/idempotency')（app() 就绪时）或系统临时目录；
 *    注入事件派发闭包，使每次记录 / 命中派发 {@see \Kode\Framework\Idempotency\IdempotencyRecorded} /
 *    {@see \Kode\Framework\Idempotency\IdempotencyHit}（指标 / 审计）。
 *
 * 与分布式锁的边界：锁 = 并发互斥；幂等 = 重试安全。跨主机共享去重（Redis / etcd / DB）不在此实现，
 * 在应用层实现 {@see \Kode\Framework\Idempotency\IdempotencyStore} 并经 config/app.php 的 providers
 * 绑定即可零改动替换，API 完全一致（薄壳哲学：契约 + 钩子）。
 */
final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(IdempotencyManager::class, function (): IdempotencyManager {
            $config = (array) $this->config('idempotency', []);
            $driver = (string) ($config['driver'] ?? 'memory');
            $dispatcher = static fn (object $event): object => event($event);

            $dir = $driver === 'file' ? $this->storeDir() : null;
            $store = new StaticIdempotencyStore($config, $dir);

            return new StaticIdempotencyManager($store, $dispatcher);
        });
        $this->container->alias('idempotency', IdempotencyManager::class);
    }

    private function storeDir(): ?string
    {
        $config = (array) $this->config('idempotency', []);

        if (isset($config['path']) && is_string($config['path']) && $config['path'] !== '') {
            return $config['path'];
        }

        if (app() !== null) {
            try {
                return storage_path('framework/idempotency');
            } catch (\Throwable) {
                // 忽略，落到系统临时目录
            }
        }

        return sys_get_temp_dir() . '/kode-idempotency';
    }
}
