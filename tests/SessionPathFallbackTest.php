<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Session\SessionManager;

/**
 * 会话文件路径回退：.env 模板常含 `SESSION_FILE_PATH=` 空值，空串必须与 null
 * 同等视为“未配置”，由 Provider 解析为 storage/sessions；否则驱动拿到空路径，
 * 首个请求即报无意义的 LockException（曾导致 SESSION_ENABLED=true 时全站 500）。
 */
final class SessionPathFallbackTest extends TestCase
{
    protected bool $independentApp = true;

    public function testEmptyFilePathFallsBackToStorageSessions(): void
    {
        $this->configOverrides = ['session' => [
            'enabled' => true,
            'default' => 'file',
            'name' => 'KODE_SESSION',
            'lifetime' => 120,
            'secure' => false,
            'http_only' => true,
            'samesite' => 'Lax',
            'id_sources' => ['cookie'],
            'gc_probability' => 1,
            'gc_divisor' => 100,
            'gc_lifetime' => 7200,
            'drivers' => ['file' => ['path' => '']],
        ]];
        $this->bootApp();

        /** @var SessionManager $manager */
        $manager = resolve(SessionManager::class);
        $drivers = (array) $manager->getConfig('drivers');
        $path = (string) ($drivers['file']['path'] ?? '');

        $this->assertStringEndsWith(
            'storage/sessions',
            rtrim($path, '/'),
            '空路径须回退到 storage/sessions，而非透传空串给驱动'
        );
        $this->assertDirectoryExists($path);
    }
}
