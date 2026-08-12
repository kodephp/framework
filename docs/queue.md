# 队列（kode/queue，内建 Worker）

```php
// 定义一个 job（普通类即可，消费时按构造参数反序列化）
final class SendMail
{
    public function __construct(public array $data) {}
    public function handle(): void { /* 调邮件服务发信 */ }
}

// 投递：推「类名 + 数据」，或推实例
Queue::push(SendMail::class, ['to' => 'a@b.com']);
Queue::push(new SendMail(['to' => 'a@b.com']));
Queue::later(5, SendMail::class, ['to' => 'a@b.com']);   // 5 秒后

// 助手等价
queue()->push(SendMail::class, ['to' => 'a@b.com']);
```

driver 见 `config/queue.php`（redis/database/beanstalkd/amqp/kafka）。消费由 kode/queue 内建
Worker 完成（启动方式见该包文档 / `config/queue.php` 注释）。

