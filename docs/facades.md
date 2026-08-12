# 门面与全局助手

| 助手 | 说明 |
| --- | --- |
| `app()` `config($k,$d)` `ctx()` `resolve($id)` | 核心（kode/core） |
| `base_path()` `storage_path()` `env()` | 路径 / 环境 |
| `logger()` / `Log::` | 日志 |
| `cache()` / `Cache::` | 缓存 |
| `event($e)` / `Event::` | 事件 |
| `validator()->validate($d,$r)` / `Validator::` | 校验 |
| `jwt()->issue($c)` / `Jwt::` | JWT |
| `rateLimit()->consume($k,$n)` / `RateLimit::` | 限流 |
| `breaker()->run($n,$t,$f)` / `Breaker::` | 熔断 |
| `http()->get($u)` / `Http::` | HTTP 客户端 |
| `messaging()` / `Messaging::` | 消息总线 |
| `lang($k,$p)` / `translator()` / `Translator::` | 国际化 |
| `queue()` / `Queue::` · `db()` / `DB::` · `process()` / `Process::` · `snowflake()` / `Snowflake::` | 队列 / 数据库 / 多进程 / 分布式 ID |
| `exception_manager()` / `Exception::` | 异常管理器 |
| `route($name, $params)` | 反向生成 URL |

门面（`Cache`/`DB`/`Log`/`Jwt`/`RateLimit`/`Breaker`/`Http`/`Queue`/`Event`/`Messaging`/`Translator`/`Snowflake`/`Process`/`Validator`/`Exception`）继承 `Kode\Core\Facade`，用静态语法访问容器里的服务实例。

---

