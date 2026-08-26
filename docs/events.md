# 事件（kode/event，PSR-14）

```php
// 监听（config/event.php 的 listeners 也可声明）
Event::listen(\app\Events\UserRegistered::class, function (\app\Events\UserRegistered $e) {
    // 发欢迎邮件、打点……
});

// 触发
event(new \app\Events\UserRegistered($uid));
```

**订阅者**（一个类批量注册多个监听器，对齐 webman/hyperf 的 subscribe 风格）：

```php
use Kode\Event\Dispatcher;
use Kode\Event\SubscriberInterface;

final class UserEventSubscriber implements SubscriberInterface
{
    public function subscribe(Dispatcher $dispatcher): void
    {
        $dispatcher->listen('user.registered', fn () => /* ... */);
        $dispatcher->listen('user.deleted',   fn () => /* ... */);
    }
}
```

在 `config/event.php` 的 `subscribe` 数组里声明类名即可启用：

```php
return ['subscribe' => [\app\Listeners\UserEventSubscriber::class]];
```

