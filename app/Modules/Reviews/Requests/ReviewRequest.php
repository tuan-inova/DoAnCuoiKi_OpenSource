<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Requests;

final class ReviewRequest
{
    public static function integer($value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ReviewException('VALIDATION_ERROR', 'Tham số phải là số nguyên.');
        }
        $number = (int)$value;
        if ($number < $min || $number > $max) {
            throw new ReviewException('VALIDATION_ERROR', 'Tham số nằm ngoài khoảng cho phép.');
        }
        return $number;
    }

    public static function optionalString(array $query, string $key): ?string
    {
        if (!isset($query[$key]) || $query[$key] === '') {
            return null;
        }
        if (!is_string($query[$key])) {
            throw new ReviewException('VALIDATION_ERROR', 'Bộ lọc không hợp lệ.');
        }
        return $query[$key];
    }

    public static function csrf(string $expected, string $received): void
    {
        if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
            throw new ReviewException('CSRF_INVALID', 'CSRF token không hợp lệ. Hãy tải lại trang.', 403);
        }
    }
}
