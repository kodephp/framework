<?php

declare(strict_types=1);

namespace Kode\Framework\Plugin;

/**
 * 插件依赖引擎（框架级，可继承）：requires() 的唯一消费方。
 *
 * 能力：依赖图解析 / 版本约束判定 / 安装门禁 / 环检测 / 下游级联闭包。
 *
 * 为什么需要它：插件契约只把 requires() 声明为「未安装 → pending」，
 * 安装/恢复/升级/暂停四个动作若都不校验依赖，插件 A 声明依赖 B，却能在 B 缺失
 * 或未启用时照常安装/启用，运行期才炸（resolve 不到 B 注册的服务、事件无人接收）。
 * 这是又一处「声明⟺实现漂移」：声明了依赖却没人消费。
 *
 * 门禁语义：
 *   install / resume / upgrade —— 硬门禁：任一依赖缺失/未启用/版本不满足 → 拒绝
 *   pause                     —— 级联：自动暂停所有下游依赖者（transitive），
 *                                避免下游仍在运行却调不到已暂停的依赖
 *
 * 框架/应用分工（继承即生效，不要在框架引擎里写业务）：
 *   框架只提供图算法与版本约束解析；「有哪些插件」「谁启用了」「装的是什么版本」
 *   这三个应用相关事实经静态钩子注入——
 *     static::collection()         默认 PluginDiscoverer::discover()
 *     static::statusOf($name)      默认 null（视为已启用）
 *     static::installedVersion($n) 默认 ''（视为未记录版本）
 *   应用想接入自己的插件状态表，继承本类覆盖这三个钩子即可，
 *   graph / validate / overview 等全部逻辑零改动复用。
 *
 * 设计取舍：不引入 composer 的 SemVer 解析器（vendor 里也没有），自实现
 * 最小约束集（>=、^、~、精确、*），足够覆盖插件间依赖的真实需求；
 * 闭包用 BFS + visited 集做环检测，不自抛异常（环记为 pending，交由审计报告）。
 *
 * 进程内静态缓存依赖图：插件集合在进程生命周期内稳定
 * （与路由/插件加载一致），新增插件需 reload 才生效。
 */
class PluginDependencyResolver
{
    /** 插件名格式（与 PluginDiscoverer::NAME_PATTERN 同源）。 */
    public const NAME_PATTERN = PluginDiscoverer::NAME_PATTERN;

    /** 视为「已启用」的状态值（应用的状态机需与之对齐）。 */
    public const STATUS_ENABLED = 'enabled';

    /**
     * 版本号格式：1-4 段数字，容忍 v 前缀，可带 -预发布 与 +构建元数据。
     *
     * 与 version_compare() 的容忍度对齐（它接受 1-4 段），但拒绝非版本文本——
     * 'abc'、'1.0.x'、'@stable' 这类值进了 satisfies() 只会落到精确匹配兜底、
     * 永久误拒安装，且报错信息无法解释根因。
     */
    public const VERSION_PATTERN = '#^v?\d+(\.\d+){0,3}(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$#';

    /**
     * 版本约束语法说明（审计报错时内联展示，与 satisfies() 的分支严格对应）。
     *
     * @return list<string>
     */
    public static function constraintSyntax(): array
    {
        return [
            '*（任意版本）',
            '>=1.0 / <=1.0 / >1.0 / <1.0 / ==1.0 / !=1.0（比较）',
            '^1.0（>=1.0,<2.0；^0.2 为 >=0.2,<0.3）',
            '~1.2（>=1.2,<1.3）',
            '1.2.3（精确匹配，容忍 v 前缀）',
        ];
    }

    /** 进程内依赖图缓存：name => list<array{name,version}> */
    private static ?array $graph = null;

    /** 反向图缓存：name => list<string>（谁依赖我） */
    private static ?array $reverse = null;

    // ---- 应用侧事实注入点（静态钩子，继承覆盖即生效）----

    /**
     * 插件清单：name => class。
     *
     * 默认取框架发现器的结果（唯一真值源）；应用若另有插件登记表，覆盖此钩子。
     *
     * @return array<string, class-string>
     */
    protected static function collection(): array
    {
        return PluginDiscoverer::discover();
    }

    /**
     * 取插件的启用状态；null 表示「无状态记录，视为已启用」。
     *
     * 应用侧通常接自己的状态表（如 kode_plugins.status）。
     */
    protected static function statusOf(string $name): ?string
    {
        return null;
    }

    /**
     * 取插件已安装版本；'' 表示「未记录版本」（不参与版本约束判定）。
     */
    protected static function installedVersion(string $name): string
    {
        return '';
    }

    /**
     * 解析单个插件的 requires() 声明，归一为 list<array{name:string, version:?string}>。
     *
     * 兼容两种写法：
     *   - `'storage_driver'`                              → 无版本约束
     *   - `['name' => 'storage_driver', 'version' => '^1.0']` → 带版本约束
     *
     * 非法元素（空串、非法名、缺 name 的数组）被静默丢弃——
     * 格式合法性由契约审计判为漂移，此处不抛错。
     *
     * @param object $plugin 插件实例
     * @return list<array{name: string, version: ?string}>
     */
    public static function requirements(object $plugin): array
    {
        if (!method_exists($plugin, 'requires')) {
            return [];
        }
        $out = [];
        foreach ((array) $plugin->requires() as $req) {
            $normalized = self::normalizeRequirement($req);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * 归一单个依赖项；非法返回 null。
     *
     * @param mixed $req
     * @return array{name: string, version: ?string}|null
     */
    private static function normalizeRequirement(mixed $req): ?array
    {
        if (is_string($req)) {
            $name = strtolower(trim($req));
            return preg_match(self::NAME_PATTERN, $name) ? ['name' => $name, 'version' => null] : null;
        }
        if (is_array($req)) {
            $name = is_string($req['name'] ?? null) ? strtolower(trim($req['name'])) : '';
            if (!preg_match(self::NAME_PATTERN, $name)) {
                return null;
            }
            $version = is_string($req['version'] ?? null) ? trim($req['version']) : null;

            return ['name' => $name, 'version' => $version === '' ? null : $version];
        }

        return null;
    }

    /**
     * 完整依赖图（name => 其直接依赖列表）。进程内静态缓存。
     *
     * @return array<string, list<array{name: string, version: ?string}>>
     */
    public static function graph(): array
    {
        if (static::$graph !== null) {
            return static::$graph;
        }
        $graph = [];
        foreach (static::collection() as $name => $class) {
            try {
                $plugin = new $class();
            } catch (\Throwable) {
                continue;
            }
            $graph[$name] = self::requirements($plugin);
        }

        return static::$graph = $graph;
    }

    /**
     * 反向依赖图：name => 直接依赖它的插件列表。
     *
     * @return array<string, list<string>>
     */
    public static function reverse(): array
    {
        if (static::$reverse !== null) {
            return static::$reverse;
        }
        $reverse = [];
        foreach (static::graph() as $name => $deps) {
            foreach ($deps as $dep) {
                $reverse[$dep['name']][] = $name;
            }
        }
        foreach ($reverse as &$names) {
            $names = array_values(array_unique($names));
        }

        return static::$reverse = $reverse;
    }

    /**
     * 传递依赖闭包（name 的所有直接+间接依赖）。
     *
     * 环检测：visited 集已包含当前节点时跳过，不重复展开——
     * A→B→A 只返回 ['B']，不无限递归。
     *
     * @return list<string> 依赖插件名列表（去重、按 BFS 发现顺序）
     */
    public static function closure(string $name): array
    {
        $graph = static::graph();
        $queue = [$name];
        $visited = [$name => true];
        $out = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($graph[$current] ?? [] as $dep) {
                $depName = $dep['name'];
                if (isset($visited[$depName])) {
                    continue;
                }
                $visited[$depName] = true;
                $out[] = $depName;
                $queue[] = $depName;
            }
        }

        return $out;
    }

    /**
     * 下游依赖者（transitive）：所有直接或间接依赖 name 的插件。
     *
     * @return list<string>
     */
    public static function dependents(string $name): array
    {
        $reverse = self::reverse();
        $queue = [$name];
        $visited = [$name => true];
        $out = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($reverse[$current] ?? [] as $parent) {
                if (isset($visited[$parent])) {
                    continue;
                }
                $visited[$parent] = true;
                $out[] = $parent;
                $queue[] = $parent;
            }
        }

        return $out;
    }

    /**
     * 是否检测到依赖环（从 name 出发能否回到 name 自身）。
     *
     * 实现：BFS 从 name 的所有直接依赖出发，若途中再次到达 name 则存在环。
     * visited 集从空集开始（不含 name），这样 name 出现在队列中时能被发现。
     */
    public static function hasCycle(string $name): bool
    {
        $graph = static::graph();

        // 自依赖
        foreach ($graph[$name] ?? [] as $dep) {
            if ($dep['name'] === $name) {
                return true;
            }
        }

        // BFS：从 name 的所有直接依赖出发，看能否回到 name
        $queue = [];
        foreach ($graph[$name] ?? [] as $dep) {
            $queue[] = $dep['name'];
        }
        $visited = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $name) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($graph[$current] ?? [] as $dep) {
                if (!isset($visited[$dep['name']])) {
                    $queue[] = $dep['name'];
                }
            }
        }

        return false;
    }

    /**
     * 校验插件 name 的所有依赖是否就绪（用于 install/resume/upgrade 门禁）。
     *
     * @return array{ok: bool, missing: list<string>, notEnabled: list<string>, versionMismatch: list<string>, checked: list<string>}
     *   - missing        : requires 声明但 collection() 中不存在
     *   - notEnabled     : 存在但状态非 enabled（paused/uninstalled）
     *   - versionMismatch: 存在且 enabled，但已安装版本不满足约束
     *   - checked        : 实际传递校验过的依赖名（BFS 展开，便于前端展示）
     */
    public static function validate(string $name): array
    {
        $graph = static::graph();
        $missing = [];
        $notEnabled = [];
        $versionMismatch = [];
        $checked = [];

        foreach (static::closure($name) as $depName) {
            $checked[] = $depName;
            $discovered = static::collection();
            if (!isset($discovered[$depName])) {
                $missing[] = $depName;
                continue;
            }
            $status = static::statusOf($depName) ?? self::STATUS_ENABLED;
            if ($status !== self::STATUS_ENABLED) {
                $notEnabled[] = $depName . '(' . $status . ')';
                continue;
            }
            $constraint = self::constraintOf($name, $depName, $graph);
            if ($constraint !== null) {
                $installed = static::installedVersion($depName);
                if ($installed === '' || !self::satisfies($installed, $constraint)) {
                    $versionMismatch[] = "{$depName}: 已安装 {$installed} 不满足 {$constraint}";
                }
            }
        }

        return [
            'ok'              => $missing === [] && $notEnabled === [] && $versionMismatch === [],
            'missing'         => $missing,
            'notEnabled'      => $notEnabled,
            'versionMismatch' => $versionMismatch,
            'checked'         => $checked,
        ];
    }

    /**
     * 取 name 对 depName 的版本约束（若 name 直接依赖 depName）。
     *
     * 传递依赖不继承约束——只校验直接依赖边上的版本要求，
     * 避免 A→B(>=1.0)→C 时把 B 对 C 的约束错误地施加到 A 上。
     */
    private static function constraintOf(string $name, string $depName, array $graph): ?string
    {
        foreach ($graph[$name] ?? [] as $dep) {
            if ($dep['name'] === $depName) {
                return $dep['version'];
            }
        }

        return null;
    }

    /**
     * 版本号格式判定（自校验，与 version_compare() 的容忍度对齐）。
     *
     * 空串视为合法（表示「未声明版本」，由 describe() 回落默认值），
     * 其余必须匹配 VERSION_PATTERN。
     */
    public static function isValidVersion(string $version): bool
    {
        $version = trim($version);

        return $version === '' || preg_match(self::VERSION_PATTERN, $version) === 1;
    }

    /**
     * 版本约束语法判定。
     *
     * 分支顺序与 satisfies() 严格一致——约束表达式必须能被引擎真正解析，
     * 否则 satisfies() 会静默退化为「精确匹配」兜底。已实测两类静默错误：
     *   - '>=1.0, <2.0'（Composer 惯用的区间写法）：逗号后整段被丢弃，
     *     实测 2.5.0 能通过声明的 <2.0 上界；
     *   - '1.0.x' / '1.0.*' / '@stable'：落精确匹配兜底，任何版本都被误拒。
     *
     * 空串与 null 合法（表示「不约束版本」，与 satisfies() 的通配分支一致）。
     */
    public static function isValidConstraint(?string $constraint): bool
    {
        $c = trim((string) $constraint);

        if ($c === '' || in_array($c, ['*', 'x', 'X'], true)) {
            return true;
        }
        // 比较运算符：与 satisfies() 的 [><=]{1,2} 对应，但只放行有意义的运算符，
        // '=>'、'='、'>>' 之类 version_compare 会当成 != 处理，属拼写错误。
        if (preg_match('/^(?:>=|<=|==|!=|=|>|<)\s*(.+)$/', $c, $m)) {
            return self::isValidVersion($m[1]);
        }
        // caret / tilde：与 satisfies() 的 str_starts_with 分支对应（容忍操作符后空格）。
        if (preg_match('/^[\^~]\s*(.+)$/', $c, $m)) {
            return self::isValidVersion($m[1]);
        }
        // 精确匹配兜底。
        return self::isValidVersion($c);
    }

    /**
     * 版本约束判定（支持 >=、<=、>、<、^、~、精确、*）。
     *
     * 语义（与 npm/SemVer 兼容的最小集）：
     *   ^1.2   → >=1.2, <2.0      （主版本固定，补丁+次版本可升）
     *   ^0.2   → >=0.2, <0.3      （0.x 时次版本固定）
     *   ~1.2   → >=1.2, <1.3      （次版本固定，补丁可升）
     *   >=1.0  → >=1.0
     *   *      → 任意
     *   1.2.3  → ==1.2.3
     */
    public static function satisfies(string $version, ?string $constraint): bool
    {
        if ($constraint === null || $constraint === '' || $constraint === '*' || $constraint === 'x' || $constraint === 'X') {
            return true;
        }
        $version = ltrim($version, 'v');
        $constraint = ltrim($constraint, 'v');

        if (preg_match('/^([><=]{1,2})\s*(.+)$/', $constraint, $m)) {
            return version_compare($version, trim($m[2]), $m[1]);
        }
        if (str_starts_with($constraint, '^')) {
            $base = ltrim($constraint, '^');
            $parts = explode('.', $base);
            $major = (int) ($parts[0] ?? 0);
            if ($major > 0) {
                $upper = ($major + 1) . '.0.0';
            } else {
                $minor = (int) ($parts[1] ?? 0);
                $upper = '0.' . ($minor + 1) . '.0';
            }

            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }
        if (str_starts_with($constraint, '~')) {
            $base = ltrim($constraint, '~');
            $parts = explode('.', $base);
            $major = (int) ($parts[0] ?? 0);
            $minor = (int) ($parts[1] ?? 0);
            $upper = $major . '.' . ($minor + 1) . '.0';

            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }

        // 精确匹配
        return version_compare($version, $constraint, '==');
    }

    /**
     * 依赖概览（供 API / 前端展示）：直接依赖 + 传递闭包 + 下游依赖者 + 是否成环。
     *
     * @return array{dependencies: list<array{name,version,installed,status,ok}>, dependents: list<string>, transitive: list<string>, cycle: bool}
     */
    public static function overview(string $name): array
    {
        $graph = static::graph();
        $collection = static::collection();
        $deps = [];
        foreach ($graph[$name] ?? [] as $dep) {
            $status = static::statusOf($dep['name']) ?? self::STATUS_ENABLED;
            $installed = static::installedVersion($dep['name']);
            $constraint = $dep['version'];
            $ok = isset($collection[$dep['name']])
                && $status === self::STATUS_ENABLED
                && ($constraint === null || $installed === '' || self::satisfies($installed, $constraint));
            $deps[] = [
                'name'      => $dep['name'],
                'version'   => $constraint,
                'installed' => $installed,
                'status'    => $status,
                'ok'        => $ok,
            ];
        }

        return [
            'dependencies' => $deps,
            'dependents'   => static::dependents($name),
            'transitive'   => static::closure($name),
            'cycle'        => static::hasCycle($name),
        ];
    }

    /** 使缓存失效（新增/删除插件文件后调用；通常 reload 会重建进程）。 */
    public static function flush(): void
    {
        static::$graph = null;
        static::$reverse = null;
    }
}
