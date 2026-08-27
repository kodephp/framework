<?php

declare(strict_types=1);

namespace Kode\Framework\Queue;

use Kode\Queue\Attribute\AsJob;

/**
 * 扫描磁盘目录，收集标注了 {@see AsJob} 的任务类。
 *
 * 约定：调用方传入「命名空间前缀 => 绝对目录」映射，扫描按目录层级推导 FQCN，
 * 与 PSR-4 一致。例如：
 *
 *   ['\app\\jobs\\' => '/base/app/jobs', '\app\\tasks\\' => '/base/app/tasks']
 *
 * 则 `app/jobs/mail/SendMail.php` 推导为 `\app\jobs\mail\SendMail`（含子目录层级），
 * 业务侧只需 `queue()->push(SendMail::class, $data)` 即可投递，无需手工登记。
 *
 * 注意：必须以「命名空间前缀」为 key（而非把 app/jobs 整体当命名空间根），否则
 * `app/jobs/SendMail.php` 会被错推成 `\app\SendMail`，class_exists 失败、发现静默失效。
 */
final class JobDiscovery
{
    /**
     * 扫描目录列表，返回其中标注了 #[AsJob] 的任务类名。
     *
     * @param array<string, string> $dirs  key=命名空间前缀（须以 \\ 结尾），value=绝对目录
     *
     * @return list<class-string>
     */
    public static function scan(array $dirs): array
    {
        $found = [];

        foreach ($dirs as $namespace => $dir) {
            $root = rtrim((string) $dir, '/');
            if (!is_dir($root)) {
                continue;
            }

            $ns = rtrim((string) $namespace, '\\') . '\\';

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);
                // 先统一分隔符为 '/' 再转命名空间：Windows 下 getPathname() 返回 '\'，
                // 直接 str_replace('/', '\\', ...) 不生效，FQCN 推导失败导致任务静默漏发现。
                $relative = str_replace('\\', '/', $relative);
                $class = $ns . str_replace('/', '\\', substr($relative, 0, -4));

                if (!class_exists($class)) {
                    continue;
                }

                if (AsJob::of($class) !== null) {
                    $found[] = $class;
                }
            }
        }

        return array_values(array_unique($found));
    }
}
