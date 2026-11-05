<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Services;

/** Integration point for Danh's verified lesson-completion transaction. */
final class LessonReviewHook
{
    public static function afterLessonCompleted(
        \PDO $pdo,
        int $userId,
        array $learnedWordIds,
        \DateTimeImmutable $completedAt,
        array $directions = ['en_vi']
    ): array {
        // IDs and completion time must come from the backend lesson snapshot, never request JSON.
        // Caller locks users first, verifies lesson completion, calls this hook, then commits once.
        return (new ReviewStateInitializer($pdo))->withinTransaction($userId, $learnedWordIds, $directions, $completedAt);
    }
}
