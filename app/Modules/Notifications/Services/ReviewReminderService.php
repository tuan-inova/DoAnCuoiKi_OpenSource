<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Repositories\NotificationRepository;
use App\Modules\Reviews\Repositories\ReviewRepository;
use App\Modules\Reviews\Requests\ReviewRequest;
use App\Modules\Reviews\Services\ReviewSupport;

/** In-app reminders only. sent_at means published to inbox, not an email delivery. */
final class ReviewReminderService
{
    private $pdo;
    private $reviews;
    private $notifications;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->reviews = new ReviewRepository($pdo);
        $this->notifications = new NotificationRepository($pdo);
    }

    public function ensureDaily(int $userId, ?\DateTimeImmutable $at = null): array
    {
        $now = ReviewSupport::utc($at);
        return ReviewSupport::transaction($this->pdo, function () use ($userId, $now): array {
            $user = $this->reviews->user($userId, true);
            $date = ReviewSupport::localDate($user, $now);
            $count = $this->reviews->dueCount($userId, $now);
            if ($count === 0) {
                return ['created' => false, 'due_count' => 0, 'notification_id' => null];
            }
            $key = 'review_due:u' . $userId . ':' . $date;
            $id = $this->notifications->findDaily($userId, $key);
            if ($id !== null) {
                return ['created' => false, 'due_count' => $count, 'notification_id' => $id];
            }
            $message = 'Bạn có ' . $count . ' thẻ đến hạn ôn tại thời điểm nhắc ngày ' . $date
                . '. Mở Ôn tập để xem danh sách hiện tại.';
            $id = $this->notifications->createDue($userId, $key, $message, $now);
            return ['created' => true, 'due_count' => $count, 'notification_id' => $id];
        });
    }

    public function inbox(int $userId, int $limit = 20, ?int $beforeId = null, ?\DateTimeImmutable $at = null): array
    {
        $this->reviews->user($userId);
        ReviewRequest::integer($limit, 1, 100);
        if ($beforeId !== null) {
            ReviewSupport::id($beforeId);
        }
        return $this->notifications->inbox($userId, $limit, $beforeId, ReviewSupport::utc($at));
    }

    public function markRead(int $userId, int $id, ?\DateTimeImmutable $at = null): array
    {
        ReviewSupport::id($id);
        $now = ReviewSupport::utc($at);
        return ReviewSupport::transaction($this->pdo, function () use ($userId, $id, $now): array {
            $this->reviews->user($userId, true);
            return $this->notifications->markRead($userId, $id, $now);
        });
    }
}
