<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Field-spec driven validation used by generic CRUD controllers.
 * Spec keys: name,label,type(text|email|number|int|select|checkbox|textarea|password|prefix|ip|hidden),
 * required,max,min,options(array value=>label),pattern,default,help,list(bool),readonly_on_edit
 */
final class Validator
{
    /**
     * @param list<array<string,mixed>> $fields
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:array<string,string>} [clean data, errors]
     */
    public static function validate(array $fields, array $input, bool $isUpdate = false): array
    {
        $data = [];
        $errors = [];
        foreach ($fields as $f) {
            $name = (string) $f['name'];
            $type = (string) ($f['type'] ?? 'text');
            $label = (string) ($f['label'] ?? $name);
            if (!empty($f['readonly_on_edit']) && $isUpdate) {
                continue;
            }
            if ($type === 'checkbox') {
                $data[$name] = !empty($input[$name]) ? 1 : 0;
                continue;
            }
            $raw = $input[$name] ?? null;
            $val = is_string($raw) ? trim($raw) : $raw;
            if ($val === null || $val === '') {
                if (!empty($f['required']) && !($type === 'password' && $isUpdate)) {
                    $errors[$name] = "{$label} is required.";
                } elseif ($type !== 'password') {
                    $data[$name] = $f['default_empty'] ?? null; // empty optional => NULL
                }
                continue;
            }
            if (!is_string($val) && !is_int($val)) {
                $errors[$name] = "{$label} is invalid.";
                continue;
            }
            $val = (string) $val;
            if (isset($f['max']) && $type !== 'number' && $type !== 'int' && mb_strlen($val) > (int) $f['max']) {
                $errors[$name] = "{$label} must be at most {$f['max']} characters.";
                continue;
            }
            switch ($type) {
                case 'email':
                    if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        $errors[$name] = "{$label} must be a valid email.";
                        continue 2;
                    }
                    break;
                case 'number':
                    if (!preg_match('/^\d+(\.\d{1,8})?$/', $val)) {
                        $errors[$name] = "{$label} must be a non-negative decimal number (max 8 decimals).";
                        continue 2;
                    }
                    if (isset($f['min']) && bccomp($val, (string) $f['min'], 8) < 0) {
                        $errors[$name] = "{$label} must be at least {$f['min']}.";
                        continue 2;
                    }
                    if (isset($f['max']) && bccomp($val, (string) $f['max'], 8) > 0) {
                        $errors[$name] = "{$label} must be at most {$f['max']}.";
                        continue 2;
                    }
                    break;
                case 'int':
                    if (!preg_match('/^\d{1,10}$/', $val)) {
                        $errors[$name] = "{$label} must be a whole number.";
                        continue 2;
                    }
                    if ((isset($f['min']) && (int) $val < (int) $f['min']) || (isset($f['max']) && (int) $val > (int) $f['max'])) {
                        $errors[$name] = "{$label} must be between " . ($f['min'] ?? 0) . ' and ' . ($f['max'] ?? 'max') . '.';
                        continue 2;
                    }
                    break;
                case 'select':
                    if (!in_array($val, array_map('strval', array_keys($f['options'] ?? [])), true)) {
                        $errors[$name] = "{$label} has an invalid selection.";
                        continue 2;
                    }
                    break;
                case 'prefix':
                    if (!preg_match('/^\d{1,20}$/', $val)) {
                        $errors[$name] = "{$label} must be digits only.";
                        continue 2;
                    }
                    break;
                case 'ip':
                    if (!filter_var($val, FILTER_VALIDATE_IP)) {
                        $errors[$name] = "{$label} must be a valid IPv4/IPv6 address.";
                        continue 2;
                    }
                    break;
                case 'password':
                    break;
            }
            if (!empty($f['pattern']) && !preg_match((string) $f['pattern'], $val)) {
                $errors[$name] = "{$label} has an invalid format.";
                continue;
            }
            $data[$name] = $val;
        }
        return [$data, $errors];
    }
}
