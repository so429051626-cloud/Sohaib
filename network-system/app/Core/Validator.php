<?php
declare(strict_types=1);

namespace App\Core;

/**
 * التحقق من المدخلات برسائل عربية.
 * القواعد: required, nullable, int, numeric, min:n, max:n, between:a,b, in:a,b,
 *          ip, mac, email, regex:/x/, username, rate, date, datetime, confirmed, host
 */
final class Validator
{
    private array $errors = [];
    private array $clean = [];

    public function __construct(private array $data, private array $rules, private array $labels = [])
    {
        $this->run();
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return (bool) $this->errors;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function first(): ?string
    {
        return $this->errors ? (string) reset($this->errors) : null;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function validated(): array
    {
        return $this->clean;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $label = $this->labels[$field] ?? $field;
            $isNumeric = in_array('int', $rules, true) || in_array('numeric', $rules, true);
            $empty = $value === null || $value === '' || (is_array($value) && !$value);

            if ($isNumeric && is_string($value)) {
                $value = latin_digits($value);
            }

            if ($empty) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = "حقل {$label} مطلوب.";
                }
                $this->clean[$field] = null;
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $msg = $this->check($name, $arg, $value, $label, $isNumeric, $field);
                if ($msg !== null) {
                    $this->errors[$field] = $msg;
                    break;
                }
            }

            if (in_array('int', $rules, true) && is_numeric($value)) {
                $value = (int) $value;
            } elseif (in_array('numeric', $rules, true) && is_numeric($value)) {
                $value = (float) $value;
            } elseif (in_array('mac', $rules, true)) {
                $value = normalize_mac((string) $value);
            }
            $this->clean[$field] = $value;
        }
    }

    private function check(string $name, ?string $arg, mixed $value, string $label, bool $isNumeric, string $field): ?string
    {
        $str = is_scalar($value) ? (string) $value : '';
        switch ($name) {
            case 'required':
            case 'nullable':
            case 'string':
                return null;
            case 'int':
                return preg_match('/^-?\d+$/', $str) ? null : "{$label} يجب أن يكون عدداً صحيحاً.";
            case 'numeric':
                return is_numeric($str) ? null : "{$label} يجب أن يكون رقماً.";
            case 'min':
                if ($isNumeric) {
                    return (float) $str >= (float) $arg ? null : "{$label} يجب ألا يقل عن {$arg}.";
                }
                return mb_strlen($str) >= (int) $arg ? null : "{$label} يجب ألا يقل عن {$arg} أحرف.";
            case 'max':
                if ($isNumeric) {
                    return (float) $str <= (float) $arg ? null : "{$label} يجب ألا يزيد على {$arg}.";
                }
                return mb_strlen($str) <= (int) $arg ? null : "{$label} يجب ألا يزيد على {$arg} حرفاً.";
            case 'between':
                [$a, $b] = explode(',', (string) $arg);
                return ((float) $str >= (float) $a && (float) $str <= (float) $b) ? null : "{$label} يجب أن يكون بين {$a} و{$b}.";
            case 'in':
                return in_array($str, explode(',', (string) $arg), true) ? null : "قيمة {$label} غير صالحة.";
            case 'ip':
                return filter_var($str, FILTER_VALIDATE_IP) !== false ? null : "{$label} ليس عنوان IP صالحاً.";
            case 'ipv4':
                return filter_var($str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? null : "{$label} ليس عنوان IPv4 صالحاً.";
            case 'host':
                $ok = filter_var($str, FILTER_VALIDATE_IP) !== false
                    || preg_match('/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $str);
                return $ok ? null : "{$label} ليس عنواناً صالحاً.";
            case 'mac':
                return normalize_mac($str) !== null ? null : "{$label} ليس عنوان MAC صالحاً.";
            case 'email':
                return filter_var($str, FILTER_VALIDATE_EMAIL) !== false ? null : "{$label} ليس بريداً إلكترونياً صالحاً.";
            case 'regex':
                return preg_match((string) $arg, $str) ? null : "صيغة {$label} غير صحيحة.";
            case 'username':
                return preg_match('/^[A-Za-z0-9._@-]+$/', $str) ? null : "{$label} يقبل الأحرف الإنجليزية والأرقام والرموز . _ - @ فقط.";
            case 'login':
                return preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $str) ? null : "{$label} يقبل الأحرف الإنجليزية والأرقام والرموز . _ - @ فقط.";
            case 'rate':
                return preg_match('/^\d+(\.\d+)?[kKmMgG]?$/', $str) ? null : "صيغة {$label} غير صحيحة (مثال: 512k أو 4M).";
            case 'date':
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) && strtotime($str) !== false ? null : "{$label} ليس تاريخاً صالحاً.";
            case 'datetime':
                return strtotime($str) !== false ? null : "{$label} ليس تاريخاً صالحاً.";
            case 'color':
                return preg_match('/^#[0-9a-fA-F]{6}$/', $str) ? null : "{$label} ليس لوناً صالحاً.";
            case 'confirmed':
                return ($this->data[$field . '_confirmation'] ?? null) === $value ? null : "تأكيد {$label} غير مطابق.";
            default:
                return null;
        }
    }
}
