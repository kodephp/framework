# 消息总线（kode/messaging）

```php
// 发布到主题
Messaging::publish('order.created', ['id' => 123]);

// 订阅（通常在常驻消费进程中）
messaging()->subscribe('order.created', function (array $payload) {
    // 处理订单
});

// 指定总线（默认 memory，可切 redis/nats/mqtt…）
messaging()->bus('redis')->publish('order.created', $payload);
```

