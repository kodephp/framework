# 缓存（kode/cache，PSR-16）

```php
// 助手
cache()->set('user:1', $profile, 3600);     // 写，TTL 秒
$profile = cache()->get('user:1');          // 读，缺失返回 null
cache()->forget('user:1');                  // 删

// 一次计算并缓存（回调结果缓存到命中）
$hot = cache()->remember('hot_posts', 60, fn () => Post::topN(10));

// 门面等价写法
Cache::put('k', $v, 60);
$v = Cache::get('k');
```

driver 见 `config/cache.php`（默认 redis，可切 file/memory）。

