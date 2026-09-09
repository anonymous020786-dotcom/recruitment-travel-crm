<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/**
 * Small rule-based validator. Rules are `field => 'rule|rule:arg|...'`.
 *
 * Supported: required, nullable, sometimes, string, email, integer, numeric,
 * boolean, array, in:a,b,c, min:n, max:n, between:a,b, size:n, regex:/.../,
 * confirmed, same:field, different:field, digits:n, date, url, alpha, alpha_num,
 * alpha_dash, ulid.
 *
 * Extend with `->rule('name', fn ($value, $args, $all) => bool|string)`.
 */
class Validator
{
    /** @var array<string,string> */
    private array $messages = [];

    /** @var array<string,callable> */
    private array $custom = [];

    /** @var array<string,list<string>> */
    private array $errors = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $messages field.rule => message
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        array $messages = [],
    ) {
        $this->messages = $messages;
    }

    public static function make(array $data, array $rules, array $messages = []): static
    {
        return new static($data, $rules, $messages);
    }

    public function rule(string $name, callable $callback): static
    {
        $this->custom[$name] = $callback;

        return $this;
    }

    public function fails(): bool
    {
        return $this->validate() !== [];
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->validate();
    }

    /** @return array<string,mixed> validated subset; throws on failure */
    public function validated(): array
    {
        $errors = $this->validate();
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $out = [];
        foreach (array_keys($this->rules) as $field) {
            if ($this->has($field)) {
                $out[$field] = $this->value($field);
            }
        }

        return $out;
    }

    /** @return array<string,list<string>> */
    private function validate(): array
    {
        if ($this->errors !== [] || $this->validated) {
            return $this->errors;
        }
        $this->validated = true;

        foreach ($this->rules as $field => $ruleset) {
            $rules = is_array($ruleset) ? $ruleset : explode('|', $ruleset);
            $rules = array_map('trim', $rules);

            $isNullable = in_array('nullable', $rules, true);
            $isSometimes = in_array('sometimes', $rules, true);
            $present = $this->has($field);
            $value = $this->value($field);
            $empty = $value === null || $value === '' || $value === [];

            if ($isSometimes && !$present) {
                continue;
            }
            if ($empty && ($isNullable || !in_array('required', $rules, true))) {
                if (!in_array('required', $rules, true)) {
                    continue;
                }
            }

            foreach ($rules as $rule) {
                if (in_array($rule, ['nullable', 'sometimes'], true)) {
                    continue;
                }
                [$name, $args] = $this->parseRule($rule);
                if (!$this->check($name, $field, $value, $args)) {
                    $this->addError($field, $name, $args);
                    break; // one message per field
                }
            }
        }

        return $this->errors;
    }

    private bool $validated = false;

    /** @return array{0:string,1:list<string>} */
    private function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) {
            return [$rule, []];
        }
        [$name, $argString] = explode(':', $rule, 2);
        if ($name === 'regex') {
            return [$name, [$argString]];
        }

        return [$name, array_map('trim', explode(',', $argString))];
    }

    private function check(string $rule, string $field, mixed $value, array $args): bool
    {
        if (isset($this->custom[$rule])) {
            $result = ($this->custom[$rule])($value, $args, $this->data);
            if (is_string($result)) {
                $this->errors[$field][] = $result;

                return false;
            }

            return (bool) $result;
        }

        return match ($rule) {
            'required'  => !($value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '')),
            'string'    => is_string($value),
            'integer'   => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'numeric'   => is_numeric($value),
            'boolean'   => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off'], true),
            'array'     => is_array($value),
            'email'     => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'       => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'in'        => in_array((string) $value, $args, true),
            'min'       => $this->size($value) >= (float) $args[0],
            'max'       => $this->size($value) <= (float) $args[0],
            'between'   => $this->size($value) >= (float) $args[0] && $this->size($value) <= (float) $args[1],
            'size'      => $this->size($value) === (float) $args[0],
            'digits'    => is_string($value) && preg_match('/^\d{' . (int) $args[0] . '}$/', $value) === 1,
            'regex'     => is_string($value) && @preg_match($args[0], $value) === 1,
            'same'      => $value === $this->value($args[0]),
            'different' => $value !== $this->value($args[0]),
            'confirmed' => $value === $this->value($field . '_confirmation'),
            'date'      => is_string($value) && strtotime($value) !== false,
            'alpha'     => is_string($value) && preg_match('/^[\pL]+$/u', $value) === 1,
            'alpha_num' => is_string($value) && preg_match('/^[\pL\pN]+$/u', $value) === 1,
            'alpha_dash' => is_string($value) && preg_match('/^[\pL\pN_-]+$/u', $value) === 1,
            'ulid'      => is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) === 1,
            default     => true, // unknown rule: ignore rather than block
        };
    }

    private function size(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            return (float) count($value);
        }

        return (float) mb_strlen((string) $value);
    }

    private function addError(string $field, string $rule, array $args): void
    {
        $key = "{$field}.{$rule}";
        $label = ucfirst(str_replace(['_', '-'], ' ', $field));

        $message = $this->messages[$key]
            ?? $this->messages[$field]
            ?? match ($rule) {
                'required'  => "{$label} is required.",
                'email'     => "{$label} must be a valid email address.",
                'min'       => "{$label} must be at least {$args[0]} characters.",
                'max'       => "{$label} may not be longer than {$args[0]} characters.",
                'confirmed' => "{$label} confirmation does not match.",
                'same'      => "{$label} must match {$args[0]}.",
                'in'        => "{$label} is not a valid choice.",
                'integer', 'numeric' => "{$label} must be a number.",
                'boolean'   => "{$label} must be true or false.",
                'date'      => "{$label} must be a valid date.",
                'ulid'      => "{$label} is not a valid identifier.",
                default     => "{$label} is invalid.",
            };

        $this->errors[$field][] = $message;
    }

    private function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    private function value(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }
}
