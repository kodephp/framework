# 日志

基于 Monolog：

```php
logger()->info('用户登录', ['uid' => 1]);
logger()->error('下游失败', ['e' => $e->getMessage()]);
Log::warning('...');          // 门面写法
```

日志文件默认在 `storage/logs/app.log`；级别 / 通道在 `config/logging.php` 配置。

---

