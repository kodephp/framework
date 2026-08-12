# 参数校验

两种写法：

**A. 控制器里快捷校验**（失败自动转 422）：

```php
$data = $this->validate($this->params(), [
    'name'  => 'required|min:2|max:50',
    'email' => 'required|email',
    'age'   => 'int|min:0',
]);
```

**B. 用 Symfony Attribute 声明在 DTO 上**（推荐复杂表单）：

```php
use Symfony\Component\Validator\Constraints as Assert;

final class CreateUser
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 50)]
    public string $name;

    #[Assert\Email]
    public string $email;
}
```

规则串参考：`required` `email` `int` `numeric` `min:2` `max:50` `in:a,b` 等。

---

