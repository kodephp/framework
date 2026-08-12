<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\ApiDoc\OpenApiGenerator;
use Kode\Framework\Console\Command;

/**
 * 主动生成 OpenAPI 文档文件（开发者显式触发，而非仅运行时自动生成）。
 *
 *   bin/kode apidoc:generate                  # 写入 config('apidoc.output')（默认 storage/apidoc/openapi.json）
 *   bin/kode apidoc:generate --output=docs/openapi.json
 *   bin/kode apidoc:generate --check          # 仅校验完整性（缺 summary/200 响应则退出码 1，供 CI 强制补全）
 *
 * 为什么需要「主动生成」命令？
 *   运行时自动生成只能覆盖 routes / methods / 路径参数，而查询参数、请求体、响应结构
 *   属于业务语义，反射无法可靠推断。开发者用 #[OpenApi] 结构化声明后，本命令把结果
 *   落盘为可提交的 openapi.json（CI 产物 / 喂给 openapi-generator、Redoc、Postman）。
 */
#[AsCommand(
    name: 'apidoc:generate',
    description: '扫描路由与 #[OpenApi] 属性，生成 openapi.json 文档文件',
    usage: 'apidoc:generate {--output=} {--check} {--no-write}',
)]
final class ApiDocGenerateCommand extends Command
{
    /** 项目根（测试可注入）。 */
    protected string $basePath = '';

    public function __construct(string $basePath = '')
    {
        parent::__construct();
        $this->basePath = $basePath;
    }

    protected function handle(): int
    {
        /** @var OpenApiGenerator $generator */
        $generator = resolve(OpenApiGenerator::class);
        $spec = $generator->generate();

        // 仅校验完整性（不写文件）
        if ($this->flag('check')) {
            return $this->runCheck($generator, $spec);
        }

        $json = json_encode(
            $spec,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($this->flag('no-write')) {
            $this->line($json);

            return 0;
        }

        $output = (string) ($this->opt('output') ?: config('apidoc.output', 'storage/apidoc/openapi.json'));
        $root = $this->basePath !== '' ? rtrim($this->basePath, '/') : getcwd();
        $path = $this->isAbsolute($output) ? $output : $root . '/' . ltrim($output, '/');

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $this->error("无法创建目录：{$dir}");

            return 1;
        }

        if (file_put_contents($path, $json) === false) {
            $this->error("写入失败：{$path}");

            return 1;
        }

        $this->success("已生成 OpenAPI 文档：{$path}");
        $this->info(sprintf('paths: %d, operations: %d', count($spec['paths']), $this->countOperations($spec)));

        return 0;
    }

    /**
     * 校验文档完整性。
     *
     * @param array<string, mixed> $spec
     */
    private function runCheck(OpenApiGenerator $generator, array $spec): int
    {
        $issues = $generator->findIncomplete($spec);

        if ($issues === []) {
            $this->success('文档完整：全部操作均有 summary 与 200 响应。');

            return 0;
        }

        $this->warn(sprintf('发现 %d 个操作文档不完整：', count($issues)));
        foreach ($issues as $it) {
            $this->line(sprintf(
                '  %s %s — %s',
                strtoupper((string) $it['method']),
                $it['path'],
                implode(', ', $it['reasons'])
            ));
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function countOperations(array $spec): int
    {
        $n = 0;
        foreach (($spec['paths'] ?? []) as $methods) {
            if (is_array($methods)) {
                $n += count($methods);
            }
        }

        return $n;
    }

    private function isAbsolute(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path));
    }
}
