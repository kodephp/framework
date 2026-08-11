<?php

declare(strict_types=1);

namespace Kode\Framework\Validation;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * 验证器（基于 Symfony Validator）
 *
 * 提供简洁的「规则字符串」写法，内部翻译为 Symfony Constraint，
 * 既保留了 Symfony 强大的校验能力，又让日常校验足够顺手。
 *
 * 规则示例：
 *   [
 *     'name'  => 'required|min:2|max:50',
 *     'email' => 'required|email',
 *     'age'   => 'nullable|integer|min:0|max:150',
 *     'role'  => 'required|in:admin,user',
 *     'site'  => 'nullable|url',
 *   ]
 *
 * 也支持在 DTO 上使用 Symfony 的 #[Assert\*] 属性，调用 validateObject()。
 */
final class Validator
{
    private ValidatorInterface $sf;

    public function __construct()
    {
        $this->sf = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /**
     * 校验数组数据。
     *
     * @param array<string, mixed>        $data    待校验数据
     * @param array<string, string>       $rules   字段 => 规则串
     * @param array<string, string>       $messages 字段 => 自定义错误提示
     * @return array<int, array{field:string, message:string}>
     */
    public function validate(array $data, array $rules, array $messages = []): array
    {
        $violations = [];

        foreach ($rules as $field => $ruleStr) {
            $value = $data[$field] ?? null;
            $constraints = $this->parseRules($ruleStr, $value);

            foreach ($this->sf->validate($value, $constraints) as $violation) {
                $violations[] = [
                    'field' => $field,
                    'message' => $messages[$field] ?? (string) $violation->getMessage(),
                ];
            }
        }

        return $violations;
    }

    /**
     * 校验对象（支持 Symfony 属性约束）。
     *
     * @return array<int, array{field:string, message:string}>
     */
    public function validateObject(object $object): array
    {
        $violations = [];
        foreach ($this->sf->validate($object) as $violation) {
            $violations[] = [
                'field' => (string) $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $violations;
    }

    /**
     * 将用户友好的规则串翻译为 Symfony Constraint 列表。
     *
     * min:/max: 按值类型自适应：
     *  - 数字（int/float/纯数字串）走数值比较 GreaterThanOrEqual / LessThanOrEqual
     *  - 字符串走 Length 比较，符合「最少/最多几个字符」的直觉
     *
     * @param mixed $value 当前字段值，用于推断 min/max 约束类型
     * @return list<Assert\Constraint>
     */
    private function parseRules(string $ruleStr, mixed $value): array
    {
        $constraints = [];
        $required = false;
        $nullable = false;
        $isNumeric = is_int($value) || is_float($value) || (is_string($value) && $value !== '' && is_numeric($value));

        foreach (explode('|', $ruleStr) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if ($part === 'required') {
                $required = true;
                continue;
            }
            if ($part === 'nullable') {
                $nullable = true;
                continue;
            }
            if ($part === 'email') {
                $constraints[] = new Assert\Email();
                continue;
            }
            if ($part === 'url') {
                $constraints[] = new Assert\Url();
                continue;
            }
            if ($part === 'integer') {
                $constraints[] = new Assert\Type('integer');
                continue;
            }
            if ($part === 'numeric') {
                $constraints[] = new Assert\Type('numeric');
                continue;
            }
            if ($part === 'boolean' || $part === 'bool') {
                $constraints[] = new Assert\Type('bool');
                continue;
            }
            if (str_starts_with($part, 'min:')) {
                $n = (int) substr($part, 4);
                $constraints[] = $isNumeric
                    ? new Assert\GreaterThanOrEqual($n)
                    : new Assert\Length(['min' => $n]);
                continue;
            }
            if (str_starts_with($part, 'max:')) {
                $n = (int) substr($part, 4);
                $constraints[] = $isNumeric
                    ? new Assert\LessThanOrEqual($n)
                    : new Assert\Length(['max' => $n]);
                continue;
            }
            if (str_starts_with($part, 'length:')) {
                $constraints[] = new Assert\Length(['max' => (int) substr($part, 7)]);
                continue;
            }
            if (str_starts_with($part, 'in:')) {
                $constraints[] = new Assert\Choice(['choices' => explode(',', substr($part, 3))]);
                continue;
            }
            if (str_starts_with($part, 'regex:')) {
                $pattern = trim(substr($part, 6), '/');
                $constraints[] = new Assert\Regex('/' . $pattern . '/');
                continue;
            }
        }

        if ($required) {
            $constraints[] = new Assert\NotNull();
            $constraints[] = new Assert\NotBlank();
        } elseif ($nullable) {
            // 允许 null 透传，不强制 NotNull
            $constraints[] = new Assert\AtLeastOneOf([
                new Assert\IsNull(),
                new Assert\NotNull(),
            ]);
        }

        return $constraints;
    }
}
