# 熔断（保护下游）

限流保护「自身」，熔断保护「下游」——下游错误率过高时快速失败并降级，避免级联雪崩。

```php
$user = breaker()->run(
    'user-service',
    fn () => http()->get('http://user-svc/1'),
    fallback: fn () => Resp::error('用户服务暂不可用', 503),
);
```

`breaker()` 是纯 PHP 状态机，跨运行时通用（多进程 worker / Fiber / 普通 handler / 队列消费都可用）。跨请求共享状态需接 Redis 等共享存储。

---

