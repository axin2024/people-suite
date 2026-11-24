<?php

declare(strict_types=1);

namespace PeopleSuite;

use DateTimeImmutable;

final class Input
{
    public static function text(mixed $value, string $field, int $limit): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new BusinessError(422, 'invalid_field', ['field' => $field]);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $limit) {
            throw new BusinessError(422, 'invalid_field', ['field' => $field]);
        }
        return $value;
    }

    public static function code(mixed $value, string $field): string
    {
        $value = self::text($value, $field, 48);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,47}$/D', $value)) {
            throw new BusinessError(422, 'invalid_code', ['field' => $field]);
        }
        return $value;
    }

    public static function date(mixed $value, string $field): string
    {
        $value = self::text($value, $field, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01') {
            throw new BusinessError(422, 'invalid_date', ['field' => $field]);
        }
        return $value;
    }

    public static function integer(mixed $value, string $field, int $minimum = 1, int $maximum = PHP_INT_MAX): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => $minimum,
            'max_range' => $maximum,
        ]]);
        if ((!is_int($value) && !is_string($value)) || $number === false) {
            throw new BusinessError(422, 'invalid_integer', ['field' => $field]);
        }
        return $number;
    }

    public static function instant(mixed $value, string $field): int
    {
        $value = self::text($value, $field, 25);
        if (str_ends_with($value, 'Z')) {
            $value = substr($value, 0, -1) . '+00:00';
        }
        $pattern = '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-](?:(?:0[0-9]|1[0-3]):[0-5][0-9]|14:00)$/D';
        $date = preg_match($pattern, $value) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value) : false;
        if (!$date || $date->format('Y-m-d\TH:i:sP') !== $value) {
            throw new BusinessError(422, 'invalid_instant', ['field' => $field]);
        }
        return $date->getTimestamp();
    }
}
