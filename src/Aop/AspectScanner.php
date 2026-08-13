<?php

declare(strict_types=1);

namespace Kode\Framework\Aop;

use Kode\Aop\Attribute\Aspect;
use Kode\Attributes\Reader;

/**
 * 切面扫描器：在约定目录中自动发现 #[Aspect] 切面类。
 *
 * 与框架既有的 TaskScanner / ControllerScanner 同理念——约定优于配置，零侵入扫描，
 * 无需在配置里逐条登记切面。AopServiceProvider 据此把发现的切面注册进 AOP 内核。
 *
 * 扫描到的是「标注了 #[Aspect] 的类」；类里方法上再标 #[Before]/#[After]/#[Around] 等
 * 并声明 Pointcut 表达式（如 execution(* App\Service\*->*(..))），即完成织入声明。
 */
final class AspectScanner
{
    public function __construct(
        private readonly Reader $reader,
    ) {
    }

    /**
     * 扫描一批目录，返回所有标注 #[Aspect] 的切面类。
     *
     * @param array<string, string> $dirs  key=来源标签（app / plugin:<name>），value=目录绝对路径
     * @return list<class-string>
     */
    public function scan(array $dirs): array
    {
        $found = [];

        foreach ($dirs as $source => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $found = [...$found, ...$this->scanDir($dir, (string) $source)];
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<class-string>
     */
    private function scanDir(string $dir, string $source): array
    {
        $found = [];

        // 复用 kode/attributes 的 Scanner 遍历目录下所有类。
        $scanner = new \Kode\Attributes\Scanner($this->reader, $dir);

        foreach ($scanner->classes($dir) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }

            // 仅收集类级标注 #[Aspect] 的类（抽象切面基类跳过）。
            if ($this->reader->getClassAttrs($class)->get(Aspect::class) !== null) {
                $found[] = $class;
            }
        }

        return $found;
    }
}
