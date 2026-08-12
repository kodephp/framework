<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;

/**
 * 常驻进程门面：Process::register() / Process::start() / Process::dryRun() 等。
 *
 * 底层服务为 Kode\Framework\Process\ProcessManager，由 ProcessServiceProvider 绑定，
 * 配置见 config/process.php（workers 列表）。无 fork 环境下用 Process::dryRun() 验证逻辑。
 */
final class Process extends Facade
{
    protected static function id(): string
    {
        return \Kode\Framework\Process\ProcessManager::class;
    }
}
