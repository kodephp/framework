# Kode Framework

一个以 [kode](https://github.com/kodephp) 生态组件为基座、组合 Monolog / Symfony Validator 等成熟包的**现代化 PHP API 框架**。最低 PHP 8.3+，开箱即多进程常驻服务，错误默认返回可追踪的结构化 JSON。

> 设计立场和 webman / Hyperf 一致：**薄内核 + 复用 Composer 生态**。框架只做「启动、容器、路由、统一响应、异常、中间件、韧性层」等地基，其余能力（JWT、限流、缓存、队列、数据库、事件、HTTP 客户端、消息、国际化、Snowflake、定时任务、多进程……）全部来自 kode 生态包，业务代码不变即可切换运行时（Fiber 协程 / 多进程 / 多线程 / Swoole / 分布式）。

---


## 版本自述

本包版本可由类常量核对：`Kode\Framework\Application::VERSION`，或调用 `Application::version()`（当前 `1.15.0`）。`composer.json` 的 `version` 是 composer 侧权威值，类常量是它的交叉核对副本——`tests/VersionGuardTest.php` 在两者不一致时直接失败。

## 5 分钟跑起来

```bash
# 1. 安装：下载骨架 + composer install + 初始化（项目名 myapp 写在包名后）
#    骨架仓库 = kode/skeleton；本仓库（kode/framework）自 v1.2.0 起为纯内核，作为依赖被引入
composer create-project kode/skeleton myapp \
  --repository='{"type":"vcs","url":"https://github.com/kodephp/skeleton.git"}' \
  --stability=dev
cd myapp

# 2. 启动多进程 HTTP 服务（默认 http://127.0.0.1:9527）
php kode start

# 3. 验证
curl http://127.0.0.1:9527/health
# {"status":"ok","service":"kode-app","version":"1.15.0","php":"8.3.33","env":"local","time":0.52,"uptime":3.4,"components":{"app":"ok"}}
# time = health check 方法执行耗时（毫秒）；status 随探针（任一 error 即 degraded），HTTP 恒 200
```

> **为什么多了 `--repository`**：`kode/skeleton` 与 `kode/framework` 目前都**未提交到 Packagist**，
> 直接 `composer create-project kode/skeleton myapp` 会报 `Could not find package ... with stability stable`。
> 显式指定 VCS 仓库即可安装；骨架的 `composer.json` 已声明 framework 的 VCS 仓库，依赖可正常解析。

> 安装时 `composer create-project` 会自动执行 `php kode init`，生成 `.env`（含强随机 `JWT_SECRET`，权限 0600）与 `storage/` 目录。
> 若把框架作为依赖引入已有项目：在 `composer.json` 声明 framework 的 VCS 仓库后 `composer require kode/framework`，再从
> [kode/skeleton](https://github.com/kodephp/skeleton) 复制 `app/`、`config/`、`lang/`、
> `database/`、`kode` 到项目根，然后 `php kode init`（控制台命令走 `php kode console ...`）。（本仓库不再自带这些骨架目录。）

第一个接口：

```php
// app/http/controllers/HelloController.php
namespace app\http\controllers;

use Kode\Framework\Http\Controller;

final class HelloController extends Controller
{
    public function say(): array
    {
        $name = $this->input('name', '世界');
        return ['hello' => $name];          // 直接返回数组 → 自动 JSON 化
    }
}
```

```php
// app/routes.php
use Kode\Http\App;
use app\http\controllers\HelloController;

return function (App $app): void {
    $app->get('/hello', fn() => resolve(HelloController::class)->say());
};
```

```bash
curl "http://127.0.0.1:9527/hello?name=Kode"   # {"hello":"Kode"}
```

---

## 服务运维命令（对标 workerman）

启动时会打印进程表横幅，一眼看到**协议 / 用户 / worker 名 / 监听地址与端口 / 进程数 / 状态**：

```text
Kode[kode] start in PRODUCTION mode
--- KODE ---------------------------------------------------------------------
Kode Framework version:1.15.0          PHP version:8.3.33
Runtime:native                   Event-Loop:event
--- WORKERS ------------------------------------------------------------------
proto    user       worker           listen                       processes  status
http     Zhuanz     kode-http        http://127.0.0.1:9527        8          [OK]
------------------------------------------------------------------------------
项目根目录：/srv/myapp
Press Ctrl+C to stop. Start success.
```

| 命令 | 作用 |
| --- | --- |
| `php kode start` | 前台启动（非 production 默认热重载，`--no-watch` 关闭；`serve` 为别名） |
| `php kode start -d` | **守护进程模式**（脱离终端，写 PID 文件；横幅给出 `php kode stop --port <端口>`，多实例按端口分片，不带端口会作用到默认实例） |
| `php kode status` | workerman 风格状态表：GLOBAL STATUS + 逐进程 PROCESS STATUS |
| `php kode status [--port P] [--pid=N]` | 查看服务状态（`--port` 定位多实例，`--pid` 看单进程） |

> 多实例（v1.3.3）：不同端口实例的 PID/日志/状态隔离在 `storage/runtime/<port>/`（如 `kode start --port 9599`），
> 用 `kode stop/status --port 9599` 精确操作该实例；老版本单文件（`storage/runtime/kode.pid`）自动兼容一次。
| `php kode stop [--port P] [-g]` | 停止服务（默认优雅停机，`-g` 强杀；`--port` 定位多实例） |
| `php kode reload [-d]` | 全量重载（等价 stop 后 start；默认前台，`-d` 进守护） |
| `php kode restart` | 运行中平滑滚动 worker；未运行按 `start` 拉起 |

> 命令约定（v1.3.3 起）：`reload`＝重载所有（stop＋start），`restart`＝只平滑滚动
> worker。注意这与 workerman 的命名相反（那边 restart 是全量、reload 是平滑），
> 为统一记忆：**带 e 的 reload 做“全套”（rEload＝Everything），短小的 restart 做“滚动”（rolling）**。

`status` 输出示例（`connections` / `total_request` / `qps` 来自各 worker 的 1Hz 心跳）：

```text
----------------------------------------------GLOBAL STATUS----------------------------------------------
Kode Framework version:1.15.0        PHP version:8.3.33
start time:2026-08-30 12:36:36    run 0 days 0 hours 1 minutes
master pid:81664      runtime:native     event-loop:event    load average:0.35, 0.31, 0.28
1 workers       3 processes
worker_name      processes  status
kode-http        3          [OK]
----------------------------------------------PROCESS STATUS---------------------------------------------
pid      memory    listening                      worker_name    connections  total_request  qps    status
81667    12.00M    http://127.0.0.1:9527          kode-http#0    0            128            3      [idle]
```

与 workerman 的**已知差异（如实标注，不做伪装）**：不输出 `exit_status` / `exit_count` 两列——
workerman 在 master 里收割子进程并记录退出码，而本框架 master 循环位于 `kode/process` 内部，
业务层观测不到子进程退出码；与其填 0 假装「零退出」误导排障，不如不列。

进程表数据写在 `storage/runtime/`（可用 `config/server.php` 的 `runtime_path` 改），
纯运行时产物，随时可删，下一心跳自动重建。

> **Ctrl+C 为什么能立刻退出**：`kode/process` 收到停机信号后固定空等一整个
> `graceful_shutdown_timeout`（骨架默认 30s）——空闲服务按 Ctrl+C 也要等满 30s。
> 框架在 v1.2.0 补了「快速排空」看门狗：收到信号后一旦在途请求归零就立即结束事件循环，
> 空闲退出从「等满宽限」压到 ≤0.5s；真有在途请求时仍走完整宽限，不丢请求。

---

## 命令的「选项面」：未知选项、错取值、空格写法（v1.8.0 → v1.10.0）

kode/console 对不认识的名字一律照收（记进 `flags()`/`options()`）却不执行，
于是 `php kode migrate:reset --pretend` 会「以为传了 dry-run、实际把全库回滚了」，退出码还是 0。
基类给了自查门禁：

```php
#[AsCommand(name: 'migrate', description: '执行待运行的数据库迁移', usage: 'migrate {--step=} {--pretend}')]
final class MigrateCommand extends Command
{
    private const OPTS = ['step', 'pretend'];

    protected function handle(): int
    {
        if (($bad = $this->rejectUnknownOptions(self::OPTS)) !== null) {
            return $bad;                       // 未识别的选项 → 打印用法行 + 退出 1
        }

        // 整数用 checkIntOptions，允许小数的秒数用 checkNumOptions
        if (($bad = $this->checkIntOptions(['step'], 1)) !== null) {
            return $bad;                       // --step=abc / 1.5 / 0 / -2 → 报错退出 1
        }
        // ...
    }
}
```

- `usage` 要写成 console 的签名 DSL（`{--step=}`），不是 `[--step=N]`：方括号形式解析不出任何选项，
  `--step 2` 的空格写法会把 `2` 泄成位置参数，`kode help migrate` 也列不出选项。
- 名单**逐字**比对：console 只把短别名归一成长名，`--dry_run` 与 `--dry-run` 是两个键，
  命令读不到前者，所以它算未知（替用户「顺手容错」等于把同一类误会再放行一次）。
- 内核自己消费的全局标志（`-q` / `-v` / `-vvv` / `--no-ansi` / `--ansi`）自动放行，
  名单取自 `Kernel::globalFlagNames()`（kode/console ≥ 4.1），命令侧不抄表。
- `--help` / `-h` 出帮助页并返回 0：问「怎么写」的词绝不该被执行。走 `Kode\Console\Kernel` 时
  内核在 `fire()` **之前**就按 `flags('help')` 打了帮助页（命令体根本不运行），基类这条分支
  只服务直接调 `fire()` 的嵌入方（单测、进程内调用）。
- 需要自己判断时用 `unknownOptions(self::OPTS)`，返回形如 `['--dry-run']` 的未知项。
- 取值规则要写 `'integer'`，不能只写 `'numeric'`：`--step=1.5` 过得了 `'numeric'`，
  再被 `(int)` 截成 1，动的就不是用户说的那几个批次（v1.8.1 修正，实测踩到）。
- 判据是 `Input::provided()` 而不是「取到的值是否为 null」：签名里带了默认值的选项
  （`{--limit=20}`）没传也躺在 `options()` 里，两种情况混为一谈就等于永不校验。

**v1.9.0：这条门禁铺满全部内置命令。** 起因是实测 `kode route:list --group api` 只列出 0 条路由
（`--group=api` 才对）——根因不是某个命令写漏了，而是 `usage` 大面积停在惰性的方括号形式：
签名解不出选项 → 守卫无从比对 → 空格写法的值泄成位置参数 → `opt('group')` 读到布尔 `true`。
`src/Console/Commands/` 下 30 个命令逐个补齐 `OPTS` + 守卫，`usage` 全部改写为签名 DSL，
顺带补上一直读却从未声明的 `queue:work --timeout`。

覆盖方式不是给每个命令写一份用例，而是 `tests/CommandLineSurfaceTest.php` 用 glob 自动发现命令类，
把四条不变量钉在所有命令上（新加的命令自动进表）：

1. `usage` 里禁止出现 `[--`；
2. `OPTS` ≡ 签名解析出的选项 ≡ 代码里 `opt()/flag()/provided()/checkXOptions()` 真正读到的名字；
3. 每个带值选项的 `--x value`、`--x=value`、`--flag` 三种写法都必须落到值上；
4. 守卫是 `handle()` 的第一条语句，且所有数值校验排在 `resolve()`（命令开始连库/拉起 worker 的分界）之前。

**v1.10.0：`kode schedule:list` 不再是第二条实现。** 启动器 `kode` 脚本里另有一份
`KodeScheduleListCommand`，`run(array $args)` 从头到尾没读过 `$args` —— 于是
`kode schedule:list --tenant=abc` 退 0、照样列出全部租户，而控制台那份（有门禁、会报错）被同名分支挡在外面。
现在快捷入口一律转发给控制台，那条重复实现删掉了；不变量由 `test_the_launcher_does_not_shadow_console_commands()`
盯着：启动器分发表里凡是不走 `KodeConsoleCommand` 的分支，命令名都不许与控制台命令重名。

同一版把每条 usage 的选项都补上了说明：`kode help route:list` 此前列得出 `--group=GROUP` 却全是空描述，
等于把「这个选项干什么」推回源码。现在 `--compact`、`--columns=`、`queue:work` 的十个数值项都有口径，
并由 `test_every_token_is_documented()` 保证不再退化成空白。

自己加命令时照着抄那一段就够了；漏掉的形态由上面四条替你兜住。

`migrate` / `migrate:rollback` / `migrate:reset` 的 `--step` 现在先校验再动库：没写就是不限步数，
写了就必须是 ≥1 的整数（`(int) 'abc'` 是 0 步，跟字面意思相反；
光秃秃的 `--step` 由 console 自己报「需要一个值」并退 2）。
`queue:work` 同理先验完 `--tries/--max-jobs/--max-time/--memory/--sleep/--timeout` 再进消费循环
（`--tries=abc` 强转是 0 = 不限制重试，`--sleep=abc` 强转是 0 = 空转打满 CPU；`--memory` 允许 `-1` 表示不限）。

---

## 测试基类 `Testing\TestCase`：`independentApp` 现在真的重建（v1.10.1）

`bootApp()` 把「已启动的应用」缓存在 `private static $app`，`tearDown()` 负责清空——
所以 `protected bool $independentApp = true` 的语义是「我要一个按我自己的 `configOverrides`
重新引导的实例」。v1.10.1 之前它只重建 `kode/core` 单例，随后撞上实例缓存就直接把**陈应用**
返回，开关等于没读；更糟的是那份陈应用的地基刚被自己抽掉，下一次 `resolve()` 直接抛
「服务容器尚未启动」。踩中它的条件是跨类顺序（前一个类覆盖了 `tearDown()` 却没回父类），
所以症状是随机的、单跑永远绿——消费方项目真实踩过一次，见其
`tests/TestCaseHygieneGateTest.php` 静态门禁。

现在 `independentApp=true` 会同时丢掉缓存并重建核心单例，两者不再只做一半；
不变量由 `tests/TestingTestCaseIsolationTest.php` 盯住：默认复用、置位必换实例、
且重建后的实例发一次真实请求能解析控制器。

下游写法不变：覆盖 `setUp()/tearDown()` 时**必须调用父类实现**，配置互斥的测试类照旧置
`independentApp = true`。用法详见文档站 `docs/testing.md`。

---

## `/health` 的 `status` 现在随探针（v1.10.2）

同一次探测，框架此前给出三个答案：`/health/ready` 在有依赖 `error` 时返回 503、
`kode console health:check` 以退出码 1 结束、`/health` 的 `status` 却**硬写着 `ok`**——
而它恰是巡检面板与人工 `curl` 读的那一条。于是一个 DB 已经断线的实例，明细里写着
`"db":"error"`，顶层仍然是一片绿。

现在 `status` 与 `HealthChecker::check()['healthy']` 同源（任一 `error` 即 `degraded`）。
HTTP 状态码**刻意保持 200**：`/health` 是报表不是闸门——摘流量看 `/health/ready`，
重启判定看 `/health/live`；把聚合视图也做成 503，会让「拿 `/health` 当 `livenessProbe`」的
应用在一个外部依赖抖动时开始无意义重启，而重启修不好对端的数据库。

消费方需要注意的只有一件事：如果此前有代码 `if ($health['status'] === 'ok')` 当成「永远成立」
在写（例如启动自检脚本以此判断「端点活着」），改成看 HTTP 码或 `/health/live`。
不变量由 `tests/HealthExposureTest.php` 盯住：探针红 → `status:degraded` 且仍 200，
探针绿 → `status:ok`（正对照，防止把 `degraded` 写死）。用法详见文档站 `docs/lifecycle.md`、
`docs/robustness.md`。


---

## `min:` / `max:` 现在按字段声明的类型判，而不是按值碰巧长什么样（v1.11.0）

`Validator` 的 `min:`/`max:` 此前按 `is_numeric($value)` 自适应选比较方式：值是纯数字串就走
**数值**比较。问题是 HTTP 层拿到的 JSON 值常常就是字符串，于是同一份规则会在特定输入下悄悄
换成另一种判据，而且两个方向都错：

- `'password' => '12'` 过 `min:6`（`12 >= 6`）—— 密码长度策略被一枚两位数字绕过；
- `'title' => '20260101'` 过不了 `max:128`（`2.026e7 > 128`）—— 用日期当标题直接 422，
  提示还是「应小于等于 128」；
- 全数字的 64 位 sha256 过不了 `min:64|max:64`（恰好合规的长度被判成超大数值）。

现在比较方式只由**字段自己声明的类型**决定：规则里有 `integer` / `numeric`，或值本来就是
`int` / `float` → 数值比较；其余（含纯数字字符串）一律按**字符数**。新增 `string` 标记，
它同时验类型并把 `min:`/`max:` 定在字符数上；值不是字符串时只报「类型不对」，不会再叠一条
看不懂的长度错。需要按大小判的字段照旧写 `integer|numeric`（`age => 151` 仍被拒）。

不受影响的：`length:`（本来就是字符数）、`in:`/`regex:`/`email`/`url`，以及控制台选项的
`min:`/`max:`（那是 `kode/console` 的 `Input::validate()` 另一套解析器，一直是数值语义）。
消费方唯一要留意的是「以前靠纯数字串走数值分支」的写法：把 `min:0` 这类规则改成
`numeric|min:0` 即可拿回原语义。不变量由 `tests/ValidatorTest.php` 双向盯住（纯数字串按长度 +
声明了数值类型的仍按数值），详见文档站 `docs/getting-started.md` 的校验篇目。


---

## `pruneRunHistory()`：删失败不再回 0，越界天数不再被夹（v1.11.1）

`ScheduleDispatcher::pruneRunHistory($days)` 是「按天数删 `kode_schedule_runs` 行」的破坏性入口
（后台的清理按钮与定时清理任务都落在它上面）。旧实现有两处会把坏消息说成好消息：

- `catch (\Throwable) { return 0; }`：删失败与「没有过期数据」压成同一个 `0`。调用方印的是
  「已清理 0 条（超过 N 天）」并记一条 info 日志——于是保留期早已不工作，而所有人都以为在工作。
  更糟的是这个 `catch` 里还要调 `logger()`，容器未启动的进程（清理任务常在容器之外跑）会先抛
  「服务容器尚未启动」，把真正的数据库原因顶掉，排障方向整个错一位。
- 天数直接拼进 SQL：`NOW() - INTERVAL '-5 days'` 就是 `NOW() + 5 天`，也就是一次「把整张执行
  历史删光」的无告警批量删除，而返回值看着完全正常。

现在的口径：`$days` 必须是 `1..3650` 的整数，越界抛 `InvalidArgumentException`（消息里带着收到的值），
判定在**碰到数据库之前**完成；cutoff 仍由数据库算（`make_interval(days => ?)`，绑定参数），
删除失败原样上抛 `\RuntimeException` 并把数据库异常挂在 `previous` 上。

消费方注意：以前「拿 0 当成功」的调用要显式接异常——清理失败要么让任务失败去报警，要么自己
catch 后记下真实原因，别再退回「已清理 0 条」。可接受区间写死在 `ScheduleDispatcher::MAX_RETENTION_DAYS`
（上限）与 `1`（下限）；应用侧若要更严的用户口径（比如最少 7 天）应在自己那层判完再传进来，
本包不会替你夹。
不变量由 `tests/SchedulePruneRunsTest.php` 盯住：越界值一律在任何摸库之前拒（连接被刻意指向
死地址，摸了就是另一种异常）；删除失败必须带着数据库的原始原因抛出，且异常链里不许出现
「服务容器尚未启动」；正向对照在一次性临时库（`kode_zz_prune_*`，跑完即删）里真建表、真插一行
40 天前的记录，断言「只删超期那一行、返回条数是真的」。


---

## 常驻进程：状态终于读得回来了（v1.12.0）

`ProcessManager` 过去只有写侧没有读侧。它按槽位往 `sys_get_temp_dir()` 写 pid 文件
（`kode-worker-{name}:{slot}.pid`），路径由私有的 `pidFileFor()` 决定，外面没有任何口子能问到「哪几路在跑、pid 是多少」。
于是应用侧自己编了一个路径去判活——`/tmp/kode-daemon.pid`，而那个文件从来没有人写过。
后果不是「面板不准」而是三件事同时坏：状态恒显示「未运行」；「启动」按钮的防重入守卫因此
永远放行，每点一次叠加一整套守护进程；「停止/重载」对着一个不存在的文件，永远失败。
`kode/process` 只提供了写侧的 `Daemon`，读侧本就该由掌握槽位布局的 `ProcessManager` 负责。

现在三个公开口子：

```php
$pm->residentSlots();  // list<{name, slot, pid_file}> —— 纯计算，不碰磁盘
$pm->slotStates();     // + pid:int|null, alive:bool, started_at:int|null
$pm->signalSlots(\Kode\Process\Signal::USR1);
// → ['signalled' => ['heartbeat:0'], 'skipped' => [{label, reason}], 'failed' => [{label, reason}]]
```

四条口径：

- **一次性 worker（`once()`）不在清单里。** 它们启动即退出、从不写 pid 文件，算成常驻槽位
  就让面板多一行永远跑不到的记录，并对它报「发信号失败」。
- **`slotStates()` 与 `start()` 用同一份展开**（私有的 `residentSlotPlan()`）。这里 fork 的
  就是面板上显示的那些路；两份清单各写一遍迟早分叉，而分叉的表现是「状态页有一路、
  实际没人跑」或反过来。
- **`started_at` 取 pid 文件的 mtime**，这是不依赖 `/proc` 的跨平台口径。darwin 根本没有
  `/proc`；而按 `/proc/{pid}/stat` 第 22 字段（clock ticks）算的应用，实测读到的是**宿主机的
  开机时长**——常驻面板把「本机跑了 300 天」当成「这个 worker 跑了 300 天」。
- **读路径绝不删失效的 pid 文件。** 清理责任属于写侧的退出逻辑（`Daemon` 优雅退出时自己
  `unlink`）。读者删文件会把一次「正在启动、尚未落 pid」的正常拉起抹成「没发生过」。
  同理，pid 文件内容不是纯数字时一律判「没在跑」，绝不去猜一个 pid 来发信号。

信号语义必须由调用方明确选对，`Signal` 常量在 macOS 和 Linux 上数字不同（USR1 分别是 30 和 10），
所以只能传 `Kode\Process\Signal::*`，不能传裸数字：

| 信号 | Daemon 的行为 |
| --- | --- |
| `Signal::TERM` / `INT` | 优雅停止：停掉全部 worker、回收子进程、删 pid 文件 |
| `Signal::USR1` | **平滑重载**该路的全部 worker（旧 worker 处理完当前任务再退） |
| `Signal::HUP` | **没有安装处理器** → 系统默认处置 = 直接终止进程 |
| `Signal::USR2` | 把运行时状态打进日志，供排障 |

最后一行是这轮修掉的真缺陷：后台的「重载」按钮发的是 `1`，也就是 SIGHUP。它看起来什么都不发生
（面板照旧、日志照旧），因为那个守护进程已经没了；再点「停止」时它已经不在，于是回「已停止」。
重载请传 `Signal::USR1`。

`signalSlots()` 的返回值分三档（`signalled` / `skipped` / `failed`）而不是布尔，理由是
「一路在跑、一路早就没了」是常态：只回 `true` 会让面板把部分成功报成全成功。标识串与
`slotStates()` 的 `name . ':' . slot` 同源，所以「刚才发给了谁」一定能和状态表逐行对上。
另外 PID 会被操作系统复用，「文件在 + 进程在」理论上是别人的进程；这里不做二次归属判定
（没有跨平台手段），对生产做停机操作前请核对 pid。写侧的归属判定在 `kode/process` >= 5.5.0：
`Daemon` 只认领「不存在 / 空 / 非数字 / 已死 / 是自己」的 pid 文件，且退出时只删写着**自己 pid**
的那一份，所以别人占着的文件不会被覆盖成下一代的。>= 5.5.1 起「判归属 + 落盘」还跑在同一把
非阻塞 `flock` 里（锁加在 pid 文件本身），并发启动会被点名「并发启动」而拒绝。

回归见 `tests/ProcessSlotStateTest.php`：槽位枚举（含 `once()` 被排除、多实例逐槽位独立文件）、
活 pid（用测试自己的 pid 验，`alive` 为真且 `started_at` 等于文件 mtime）、缺文件、
内容不是数字、pid 已失效（断言 `alive` 为假**且文件原地不动**）、`signalSlots()` 只命中存活槽位、
空注册表是 no-op、以及「状态表的标识 == 发信号结果的标识」这条对账。
`start()` 的改动只有一处结构性的（改用 `residentSlotPlan()`），语义不变：所有 `once()` worker
仍在任何 fork 之前同步执行完。


---

## 重复 start() 在派发前就被拒（v1.13.0）

v1.12.0 把状态做成了可读回来的，但「已在跑还再点一次启动」这一条只写在面板的按钮文案里，
`ProcessManager::start()` 自己照样会把第二套守护进程 fork 出来。理由并不充分：pid 文件的互斥
确实存在，可它是 `kode/process` 的 `Daemon::run()` 在**每个守护进程自己的子进程**里判的
（v5.5.0 起会抛异常拒绝启动）。而 `start()` 对多槽位的编排是「fork 完就 `wait()`」——
子进程抛的异常没有任何人接，父进程只是等它退出，命令行照样回「启动成功」。
于是真实的坏法是：一次 `kode start` 之后系统里躺着两套互相看不见的守护进程，
第一套的 pid 文件被第二套覆盖（v5.5.0 之前的行为），而状态表只认得后写的那一份。

现在的口径：**`start()` 在派发之前先问自家 `slotStates()`**，有存活槽位就抛
`\RuntimeException` 并在消息里逐路点名（`name:slot(pid N)`）：

```php
$pm->start();
// RuntimeException: 常驻进程已在运行，拒绝重复启动：heartbeat:0(pid 4812)、
//                   queue:0(pid 4813)、queue:1(pid 4814)。
//                   需要换 worker 注册表请先 stop 再 start；只想重载代码请发 USR1（reload）。
```

三条口径：

- **拦在 `once()` worker 之前。** 一次性 worker 是 `start()` 里唯一同步执行的部分，
  放在它后面就等于「拒绝启动」之前先把定时任务真跑了一遍——有副作用的拒绝不是拒绝。
- **判据只有一份，用 `slotStates()`。** 这里不许再出现第二份「读 pid 文件 + `posix_kill`」，
  那是 `kode/process` 与 `slotStates()` 已经各写一遍的东西；第三份的意思只会和状态表分叉，
  而分叉的表现是「状态页说在跑、启动说没在跑」。这条由源码门禁盯着。
- **失效的 pid 文件不拦启动。** 「文件在」和「在跑」是两件事：上一代被 `kill -9` 之后
  文件原地留着，此时 `slotStates()` 回 `alive: false`，启动照走。把残留文件当成「在跑」
  等于把机器永久锁死在一次失败启动上，而唯一出路是让人手工去 `/tmp` 删文件。

`kode/process` 那一侧的互斥**不是**这道预检的后备，反过来也一样，两道各治一种坏法：
本包的这道管「同一个注册表被重复 `start()`」（面板连点、脚本重跑）；`kode/process` >= 5.5.1 的
`flock` 管「同一个 pid 文件被两个进程同时判+写」（两个不同的 `ProcessManager` 实例并发启动、
两条 `kode process:start` 同时敲）。注意**本包的预检挡不住并发**：两个进程可以同时在
`slotStates()` 里看到「全没跑」，然后各自派发 —— 那时真正拦下来的是 process 侧那把锁。
所以这道预检的价值是「在 fork 之前就给出一条能被 `catch` 到、能回 400 的拒绝」，
而不是「最后一道互斥」（process 侧的异常在已 detach 的子进程里抛出，命令行只看到退出码）。
v5.5.0 之前连 process 侧也挡不住并发（判定与落盘两步之间没有锁），
实测同时敲两次 `php kode process:start` 会真起出两套守护进程 —— 那是 v5.5.1 修的。
两道判据的分工不同，所以要一起留着。

回归见 `tests/ProcessStartGuardTest.php`。`start()` 与 `Daemon::run()` 都会阻塞，
所以用例一律 fork 子进程 + 有界轮询（200×10ms）拿判决，再 `SIGTERM` + `wait()` 收尸：
「被拦时 `once()` worker 没被执行」（flag 文件不存在）、什么都没跑时照走、
残留 pid 文件不拦（正向证据 = `once()` worker 的 flag 文件真出现了）、
以及「不得在框架里重写一份 pid 判定」的源码门禁。
`testStalePidFileDoesNotBlockStart` 是这轮补的第二条腿：只有前两条时，
「无视 `alive` 一律拦」的变异体能全绿——因为「全部不跑」的用例只注册了一个 `once()` worker，
`slotStates()` 天生是空的，那个循环根本没执行。

### v1.13.1：更正上面那句「最后一道」

v1.13.0 发版当天，这个分工被活体复现推翻：`kode/process` v5.5.0 的互斥只罩住了「判」，
没罩住「写」——同时敲两次 `php kode process:start` 会真起出两套互相看不见的守护进程
（两边都在对方落盘前读到「没人占用」）。process 侧已在 **v5.5.1** 用 `flock` 补上，
本包随之做两件事：**依赖下限抬到 `kode/process ^5.5.1`**（代码零改动），
把上面「`kode/process` 那一侧是最后一道」改成如实的分工描述。
本包这道预检的定位不变：它是**唯一能把拒绝变成一次可 `catch` 的异常、从而回 400** 的判定，
而 process 侧那把锁在已 detach 的子进程里抛异常，命令行只看得见退出码。


---

## `ScheduleDispatcher::stats()`：读不到不再回「完美」，以及一处从未跑通的调用（v1.14.0）

`stats()` 是「任务健康度」的唯一数据源（后台 `GET /api/schedules/health`、`schedule:list` 都读它）。
这一轮改掉了两件事，第二件才是第一件的真因。

**① 有一处调用从来就没跑通过。** 单任务那条腿写的是 `Db::selectOne(...)`，而 `kode/database`
的 facade **从来没有**那个方法：`Db::__callStatic` 把它当成 Model 的静态方法转发，实测每次调用
都抛 `BadMethodCallException: 请创建 Model 类后使用静态方法调用`。因为外面正好套着 ② 那个
`catch (\Throwable)`，这个 `\Error` 被吞成一条合成行，于是**「单任务执行统计」从来没工作过**，
而页面上一直是绿的。现在取首行走 `select()`（`$rows === [] ? null : $rows[0]`）。

这类病的通用形态是：facade 方法名写错 → 运行时 `\Error` → 被 `catch (\Throwable)` 吞掉 →
「API 用错」在页面上长成「这台系统没有这项数据」。`class_exists` 型门禁全抓不住（类名是对的）。
所以本包新增 `tests/DbFacadeCallGateTest.php`：扫 `src/` 里对 `Db` 的**全部静态调用**
（`Db::x(` 与 `$db::x(` 两种写法都要认，后者是本包 `$db = Db::class` 的既有风格），
逐个 `method_exists`；先剥注释（文档里那句「旧写法长什么样」不是调用点）；
带一条「把幻影方法名喂给同一个抽取器，它必须点名」的正对照，以及「扫到的调用点数不得少于 10」
的防空转断言。

**② 读不到不再压成「没有历史、而且完美」。** 旧写法：

```php
} catch (\Throwable $e) {
    logger()->warning('查询调度统计失败：' . $e->getMessage());

    return $taskName !== null ? ['total' => 0, …, 'success_rate' => 100.0] : [];
}
```

三处坏法叠在一起：断链 / 无权限 / 列缺失全被压成「这台系统没有任务跑过」；`catch` 里先调
`logger()`，未引导的进程（CLI、定时清理任务）会自己抛「服务容器尚未启动」，把真正的数据库原因
顶掉；而回的那条合成行里 `success_rate = 100.0` —— 于是「一座库没读到」在页面上是一个**绿色的
完美数字**，比空表更容易被照着做决策。`consecutiveFailures()` 同族：`catch → return 0`
把「读不到」写成「没有连续失败」，而那正是「这条任务健康」的判据之一。

现在的口径：

- **读不到一律抛 `\RuntimeException`**，数据库原始异常挂在 `previous` 上（异常链里不许出现
  「服务容器尚未启动」，这条是被断言的）。
- **唯一的例外是历史表还没建**（全新环境从没跑过任务，那是事实）：只认 pgsql `42P01` 与
  sqlite `no such table`，回空汇总。列缺失（42703）、无权限（42501）、断链（08xxx/HY000）
  一律不在豁免内 —— 用例里真 `ALTER TABLE … DROP COLUMN duration_ms` 来钉住这条判断没被放宽。
- **`total === 0` 时 `success_rate` 是 `null`**，不是 `100.0`：没有分母的比率不存在。
  消费方（后台卡片）按 `null` 渲染 `—`。

`schedule:list` 的另一半：`stats()` 现在会抛，命令不能因此打断整张表，也不能顺手回 0
（`$stats['total'] > 0` 一判就变成「没有记录」）。那一列现在有三种显示，且互相分得开 ——
`—` = 没有执行记录，`!` = 这一行读不出来，其余 = 真状态；表尾另出一行
「其中 N 条任务的执行统计读不到」。

消费方注意（**破坏性**）：以前把 `stats()` 当「恒不抛」的调用要显式接异常，别把它又吞成空数组。
`success_rate` 的键恒在，但值可能是 `null`。

回归见 `tests/ScheduleStatsFailureTest.php`（汇总腿与单任务腿各自验「必须抛且原因在链里」、
缺表是唯一不抛的失败、42703 仍必须抛、`total=0` 时 `success_rate` 是 null，
以及正向对照：一次性临时库 `kode_zz_stats_*` 里插 1 成功 + 1 失败，断言比率真的是 50.0
且全任务汇总里有这一行）+ `tests/DbFacadeCallGateTest.php`。


---

## 执行历史那两条读腿同病同治（v1.15.0）

v1.14.0 只收了汇总腿。`runHistory()` 与 `tenantRunHistory()` 是同一张 `kode_schedule_runs` 上
另外两条读腿（后台 `GET /api/schedules/{name}/history` 与「执行历史」抽屉的数据源），
catch 长得一模一样：

```php
} catch (\Throwable $e) {
    logger()->warning("查询调度执行历史失败（{$taskName}）：" . $e->getMessage());

    return [];
}
```

这里的后果比 `stats()` 更直接：抽屉是**有人来查故障时才点开**的那个界面，而断链 / 缺列 /
无权限被压成一个空数组后，页面渲染的是「暂无数据」—— 一张干净的空白表格，看不出任何事发生过。
（`error_message` 也在它的 SELECT 列表里，所以一次没跑完的迁移同样落在这条腿上。）

现在的口径与 `stats()` 逐字一致（同一张表、同一个豁免、同一种抛法）：

- **只有历史表还没建**算「没有历史」，回 `[]`；其余读失败抛 `\RuntimeException`，
  数据库原始异常挂在 `previous` 上，且**这条腿也不调 `logger()`**（未引导的进程里它自己就抛，
  会把真因顶掉）。
- 消息里带任务名 / 租户号（`读取调度执行历史失败（{$taskName}）：…`），因为这两条腿是按归属键
  收窄的，定位故障时那串键就是坐标。

消费方注意（**破坏性**，同上一条）：以前把这两个方法当「恒不抛」的调用要显式接异常。
管理端的正确接法不是再吞一次，而是把「读不到」如实说出去（HTTP 仍 200 +
`available:false` + 结论性 `reason`），否则这条修就在下一层又被抹平了。

三条读腿现在共用一份夹具（`tests/Support/ScheduleRunDbFixture.php`）：伪造「这座库读不到」
在本仓库有一个不小的坑 —— 只把默认连接换成拒连配置是不够的，`Db::select()` 落到连接池
当前那个名字上，而 `addConnection()` 会把池子的驱动名改成**最后注册**的那个；所以恢复默认连接
必须排在所有 add/remove 之后。回归见 `tests/ScheduleHistoryFailureTest.php`
（两条腿各验「必须抛且原因在链里」、缺表是唯一不抛的失败、真 `DROP COLUMN error_message`
仍必须抛、插进去的行真读得回来且 `limit` 真的落在 SQL 上）。


---

## 为什么选它

| 痛点 | 本框架的做法 |
| --- | --- |
| 错误排查难 | 异常默认返回结构化 JSON，含 `location`（出错文件/行/方法）与 `chain`（完整调用链），开发期直接定位源码 |
| 重复造轮子 | 能力全部委托 kode 生态包，框架只做薄适配；包升级即能力升级 |
| 性能 / 常驻 | `kode/process` 多进程常驻内存（零扩展依赖，不锁 Swoole/Workerman） |
| 多运行时 | 一套业务代码，Fiber / 多进程 / 多线程 / Swoole / 分布式通吃 |
| 约定清晰 | 路由双模型（属性 + 闭包）、`app/routes/*.php` 即插即用、插件自动发现 |

---

## 内置能力一览

| 能力 | 怎么用 | 底层包 |
| --- | --- | --- |
| 路由 | 属性 `#[Get]` / 闭包 `app/routes.php` / `app/routes/*.php` | kode/router + kode/attributes |
| 请求 / 响应 | 控制器短方法 `input/query/post/param`；`Resp::json/error` | kode/http (PSR-7) |
| 参数校验 | `$this->validate($data, $rules)` | Symfony Validator |
| 异常处理 | 全局结构化 JSON（location/chain/trace_id） | kode/exception |
| 鉴权 / JWT | `jwt()->issue()`、`AuthMiddleware` | kode/jwt |
| 限流 | `#[RateLimit]` 声明式 + 全局默认，分布式用 Redis | kode/limiting |
| 熔断 | `breaker()->run($name, $task, $fallback)` | kode/fibers (CircuitBreaker) |
| HTTP 熔断中间件 | `CircuitBreakerMiddleware`（边缘保护下游，5xx/传输异常计入，OPEN 短路 503） | 框架内置（PSR-15 薄壳层，复用 `Breaker` 注册表） |
| 重试 | `retry($op, attempts: 3)` + `BackoffStrategy` | 框架内置（固定/指数/去相关抖动，零依赖） |
| 超时 | `timeout($op, seconds: 2.0)` + `fallback` | 框架内置（fiber 真实抢占 / pcntl / sync 退化，零依赖） |
| HTTP 重试中间件 | `RetryMiddleware`（安全方法 502/503/504 自动重试，复用 retry 段退避） | 框架内置（PSR-15 薄壳层，复用 `Retry`） |
| 定时任务 | 约定式 `#[Cron]` 自动发现 + 命令式 `PluginManager::addCron()`（插件 `boot()` 内登记，支持 `[类, 方法]` / `'类::方法'` / 闭包）；`kode cron`、`schedule:list` 统一支持 `schedule.discover_plugins` 发现插件任务（来源标记 `plugin:<name>`）；运行时生命周期 `setEnabled()` / `setEnabledBySource()` / `unregister()` / `unregisterBySource()` 供插件暂停/卸载即时下线其任务 | kode/scheduling |
| 多进程服务 | `kode start`（--watch 热重载） | kode/process |
| 缓存 / 队列 / 数据库 / 事件 / HTTP 客户端 / 消息 | `cache()/queue()/db()/event()/http()/messaging()` | kode/cache · queue · database · event · http-client · messaging |
| 国际化 | `lang()` / `LocaleMiddleware` | Symfony Translation |
| 分布式 ID | `snowflake()` | kode/process |
| 配置 / 日志 / 门面 / DI | `config()` / `logger()` / 门面 / `resolve()` | kode/core · Monolog · kode/di |
| 可观测性 | `/metrics`(Prometheus) + W3C 链路追踪 + `Metrics` 门面；span 导出离请求路径（worker 周期 tick / shutdown / 停机钩子），OTLP/HTTP JSON 与文件导出器，Collector 不可达时指数退避 + 告警去重 | kode/context + 框架本地薄实现 |
| 运维与生命周期 | `/health` `/health/ready` `/ping` 探针 + 启动/停机事件 | kode/event + 框架本地薄实现 |
| 安全与合规 | 安全响应头(CSP/COOP/CORP) + 审计日志(脱敏/业务事件/取证) + CSRF 防护(按需挂载·csrf.failed 安全事件·csrf_token_rotate 会话固定防护) + API 版本化 | 框架本地薄实现 |
| API 文档自动化 | `/docs/openapi.json` + Swagger UI + `#[OpenApi]` | 框架本地薄实现 |

---

## 文档导航

| 文档 | 看什么 |
| --- | --- |
| [入门指南](https://github.com/kodephp/docs/getting-started.md) | 环境、安装、第一个接口、请求/响应、校验、错误、运行与排错 |
| [开发文档总览](https://github.com/kodephp/docs/README.md) | 路由全解、中间件编写、鉴权、限流、熔断、定时任务、多进程、缓存/队列/数据库/事件/HTTP、配置、日志、门面与助手、控制台、DI 与服务提供者、AOP、插件、部署、测试（docs/ 文档地图） |

> 建议顺序：先照「入门指南」把第一个接口跑通，再按需查阅「进阶用法」。

---

## 版本

- 当前版本：**[v1.15.0](https://github.com/kodephp/framework/releases)**
- 包名：`kode/framework`（Composer）
- 仓库：<https://github.com/kodephp/framework>

## 许可证

MIT（兼容 `kode/jwt` 的 Apache-2.0；重新分发请保留其第三方许可说明）。
