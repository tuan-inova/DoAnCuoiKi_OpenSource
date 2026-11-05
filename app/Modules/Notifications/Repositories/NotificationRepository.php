<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Repositories;

use App\Modules\Reviews\Requests\ReviewException;
use App\Modules\Reviews\Services\ReviewSupport;

final class NotificationRepository
{
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findDaily(int $userId, string $key): ?int
    {
        // The caller already holds the user row lock in a fresh transaction.
        $query = $this->pdo->prepare('SELECT id FROM notifications WHERE dedupe_key=? AND user_id=?');
        $query->execute([$key, $userId]);
        $id = $query->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function createDue(int $userId, string $key, string $message, \DateTimeImmutable $now): int
    {
        $query = $this->pdo->prepare("INSERT INTO notifications
            (user_id,type,message,scheduled_at,sent_at,dedupe_key) VALUES (?,'review_due',?,?,?,?)");
        $time = ReviewSupport::sql($now);
        $query->execute([$userId, $message, $time, $time, $key]);
        return (int)$this->pdo->lastInsertId();
    }

    public function inbox(int $userId, int $limit, ?int $beforeId, \DateTimeImmutable $now): array
    {
        $params = [$userId, ReviewSupport::sql($now)];
        $sql = 'SELECT id,type,message,scheduled_at,sent_at,read_at FROM notifications
            WHERE user_id=? AND scheduled_at<=? AND sent_at IS NOT NULL';
        if ($beforeId !== null) {
            $sql .= ' AND id<?';
            $params[] = $beforeId;
        }
        $query = $this->pdo->prepare($sql . ' ORDER BY id DESC LIMIT ' . ($limit + 1));
        $query->execute($params);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            foreach (['scheduled_at', 'sent_at', 'read_at'] as $field) {
                $row[$field] = ReviewSupport::iso($row[$field]);
            }
        }
        unset($row);
        $last = $rows ? end($rows) : null;
        return ['items' => $rows, 'next_before_id' => $more ? $last['id'] : null];
    }

    public function markRead(int $userId, int $id, \DateTimeImmutable $now): array
    {
        $query = $this->pdo->prepare('SELECT id,read_at FROM notifications
            WHERE id=? AND user_id=? AND scheduled_at<=? AND sent_at IS NOT NULL FOR UPDATE');
        $query->execute([$id, $userId, ReviewSupport::sql($now)]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ReviewException('NOT_FOUND', 'Không tìm thấy thông báo.', 404);
        }
        $readAt = $row['read_at'] ?? ReviewSupport::sql($now);
        $this->pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,?) WHERE id=? AND user_id=?')
            ->execute([$readAt, $id, $userId]);
        return ['id' => $id, 'read_at' => ReviewSupport::iso($readAt)];
    }
}
