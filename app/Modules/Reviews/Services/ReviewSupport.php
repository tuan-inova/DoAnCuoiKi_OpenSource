<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Services;

use App\Modules\Reviews\Requests\ReviewException;

final class ReviewSupport
{
    public const DIRECTIONS = ['en_vi', 'vi_en', 'listening', 'spelling'];
    public static function utc(?\DateTimeImmutable $at = null): \DateTimeImmutable
    {
        return ($at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
    }
    public static function sql(\DateTimeImmutable $at): string
    {
        return self::utc($at)->format('Y-m-d H:i:s');
    }
    public static function iso(?string $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    public static function decode(string $value): array
    {
        if (substr(ltrim($value), 0, 1) !== '{') {
            throw new ReviewException('CORRUPT_SNAPSHOT', 'Snapshot phải là JSON object.', 500);
        }
        $result = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new ReviewException('CORRUPT_SNAPSHOT', 'Dữ liệu lịch sử không hợp lệ.', 500);
        }
        return $result;
    }
    public static function id($value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new ReviewException('VALIDATION_ERROR', 'ID phải là số nguyên dương.');
        }
        return $value;
    }
    public static function uuid($value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value)) {
            throw new ReviewException('VALIDATION_ERROR', 'request_key phải là UUID hợp lệ.');
        }
        return strtolower($value);
    }
    public static function direction(string $value): string
    {
        if (!in_array($value, self::DIRECTIONS, true)) {
            throw new ReviewException('VALIDATION_ERROR', 'Chiều ôn không hợp lệ.');
        }
        return $value;
    }
    public static function fields(array $value, array $names): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($names);
        if ($actual !== $names) {
            throw new ReviewException('VALIDATION_ERROR', 'Dữ liệu thiếu trường hoặc có trường ngoài hợp đồng.');
        }
    }
    public static function localDate(array $user, \DateTimeImmutable $at): string
    {
        try {
            $zone = new \DateTimeZone($user['timezone']);
        } catch (\Throwable $error) {
            throw new ReviewException('TIMEZONE_INVALID', 'Múi giờ của tài khoản chưa hợp lệ.');
        }
        return $at->setTimezone($zone)->format('Y-m-d');
    }
    public static function transaction(\PDO $pdo, callable $operation)
    {
        if ($pdo->inTransaction()) {
            throw new \LogicException('This operation owns its transaction; use the lesson hook inside an existing transaction.');
        }
        $pdo->beginTransaction();
        try {
            $result = $operation();
            $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
    public static function fingerprint(array $row, int $eventId): string
    {
        $values = [(int)$row['id'], (int)$row['user_id'], (int)$row['word_id'], $row['direction'],
            $row['first_learned_at'], number_format((float)$row['ease_factor'], 3, '.', ''),
            (int)$row['interval_days'], (int)$row['repetitions'], $row['last_reviewed_at'], $row['next_review_at'], $eventId];
        return hash('sha256', self::json($values));
    }
    public static function publicState(array $row): array
    {
        return ['id' => (int)$row['id'], 'word_id' => (int)$row['word_id'], 'direction' => $row['direction'],
            'ease_factor' => (float)$row['ease_factor'], 'interval_days' => (int)$row['interval_days'],
            'repetitions' => (int)$row['repetitions'], 'first_learned_at' => self::iso($row['first_learned_at']),
            'last_reviewed_at' => self::iso($row['last_reviewed_at']), 'next_review_at' => self::iso($row['next_review_at'])];
    }
    public static function quality($quality): int
    {
        if (!is_int($quality) || $quality < 0 || $quality > 5) {
            throw new ReviewException('VALIDATION_ERROR', 'quality phải là số nguyên 0 đến 5.');
        }
        return $quality;
    }
}
