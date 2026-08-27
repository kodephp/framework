<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Lock\LockManager;
use Kode\Framework\Lock\LockWatchdog;
use Kode\Framework\Lock\StaticLockManager;

/**
 * 分布式锁子系统服务提供者（薄壳层）。
 *
 * 始终接线：框架内置锁契约 + 内置静态后端（memory / file），依赖 {@see LockManager} 单例，
 * 故本 Provider 无条件绑定（不引入独立开关）。
 *
 * 绑定：
 *  - LockManager：按 config/lock.php 的 `driver`（memory | file）选择内置后端；
 *    file 模式落盘到 storage_path('framework/locks')（app() 就绪时）或系统临时目录；
 *    注入事件派发闭包，使每次 acquire / release 派发 {@see \Kode\Framework\Lock\LockAcquired} /
 *    {@see \Kode\Framework\Lock\LockReleased}（可用于指标 / 审计）。
 *  - LockWatchdog：装饰 LockManager，提供 `protect()` 自动续期（看门狗），按 config/lock.php 的
 *    `watchdog`（enabled / driver / renew_ratio）配置；无独立开关时随 LockManager 一并接线。
 *
 * 跨主机分布式锁（Redis / etcd / DB）不在此实现：在应用层实现 {@see LockManager} 并经
 * `config/app.php` 的 providers 绑定即可零改动替换，API 完全一致（薄壳哲学：契约 + 钩子）。
 */
final class LockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(LockManager::class, function (): LockManager {
            $config = (array) $this->config('lock', []);
            $driver = (string) ($config['driver'] ?? 'memory');
            $dispatcher = static fn (object $event): object => event($event);

            // H4：memory 驱动在多 worker 下每进程独立内存，分布式互斥/去重语义被破坏（仅 production 告警）。
            if ($driver === 'memory' && $this->config('app.env') === 'production') {
                $workers = (int) ($this->config('server.workers', 0) ?: 0);
                if ($workers === 0 && function_exists('Kode\Process\cpu_count')) {
                    $workers = max(1, (int) \Kode\Process\cpu_count());
                }
                if ($workers > 1) {
                    error_log('[kode] 警告：lock.driver=memory 但 server.workers=' . $workers . '，多进程下锁不互斥。生产多副本请替换为 Redis 实现 LockManager。');
                }
            }

            $dir = $driver === 'file' ? $this->lockDir() : null;

            return new StaticLockManager($config, $dir, $dispatcher);
        });
        $this->container->alias('lock', LockManager::class);

        $this->container->singleton(LockWatchdog::class, function (): LockWatchdog {
            $config = (array) $this->config('lock', []);
            $wd = (array) ($config['watchdog'] ?? []);
            $dispatcher = static fn (object $event): object => event($event);

            return new LockWatchdog(
                manager: $this->container->make(LockManager::class),
                renewRatio: (float) ($wd['renew_ratio'] ?? 0.34),
                driver: (string) ($wd['driver'] ?? 'auto'),
                dispatcher: $dispatcher,
            );
        });
        $this->container->alias('watchdog', LockWatchdog::class);
    }

    private function lockDir(): ?string
    {
        $config = (array) $this->config('lock', []);

        if (isset($config['path']) && is_string($config['path']) && $config['path'] !== '') {
            return $config['path'];
        }

        // app() 就绪时优先放 storage_path，否则退化到系统临时目录（CLI / 早期阶段）。
        if (app() !== null) {
            try {
                return storage_path('framework/locks');
            } catch (\Throwable) {
                // 忽略，落到系统临时目录
            }
        }

        return sys_get_temp_dir() . '/kode-locks';
    }
}
