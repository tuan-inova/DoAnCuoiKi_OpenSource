<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

use App\Modules\Notifications\Services\ReviewReminderService;
use App\Modules\Reviews\Requests\ReviewRequest;
use App\Modules\Reviews\Requests\ReviewException;
use App\Modules\Reviews\Services\ReviewSupport;

/** Called by ReviewController after session authentication, HTTP method and CSRF checks. */
final class NotificationController
{
    private $reminders;

    public function __construct(ReviewReminderService $reminders)
    {
        $this->reminders = $reminders;
    }

    public function handle(string $path, array $query, array $body, int $userId, ?int $readId): array
    {
        if ($path === '/api/v1/notifications') {
            return $this->reminders->inbox(
                $userId,
                ReviewRequest::integer($query['limit'] ?? 20, 1, 100),
                isset($query['before_id']) ? ReviewRequest::integer($query['before_id'], 1, PHP_INT_MAX) : null
            );
        }
        ReviewSupport::fields($body, []);
        if ($path === '/api/v1/notifications/review-due') {
            return $this->reminders->ensureDaily($userId);
        }
        if ($readId !== null) {
            return $this->reminders->markRead($userId, $readId);
        }
        throw new ReviewException('NOT_FOUND', 'Không tìm thấy route.', 404);
    }
}
