<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Services;

use App\Modules\Reviews\Repositories\ReviewRepository;
use App\Modules\Reviews\Requests\ReviewException;

/** Called by the server after learning; never expose word IDs as a public write API. */
final class ReviewStateInitializer
{
    private $pdo;
    private $repository;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->repository = new ReviewRepository($pdo);
    }

    public function initialize(int $userId, array $wordIds, array $directions = ['en_vi'], ?\DateTimeImmutable $completedAt = null): array
    {
        $at = ReviewSupport::utc($completedAt);
        return ReviewSupport::transaction($this->pdo, function () use ($userId, $wordIds, $directions, $at): array {
            return $this->withinTransaction($userId, $wordIds, $directions, $at);
        });
    }

    public function withinTransaction(int $userId, array $wordIds, array $directions, \DateTimeImmutable $completedAt): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Learning must begin its transaction before calling the hook.');
        }
        // All same-user writes use this lock order, including the outer Learning transaction.
        $this->repository->user($userId, true);
        if (!$wordIds || count($wordIds) > 500 || !$directions || count($directions) > 4) {
            throw new ReviewException('VALIDATION_ERROR', 'Cần 1–500 từ và 1–4 chiều ôn.');
        }
        $ids = [];
        foreach ($wordIds as $id) {
            $ids[] = ReviewSupport::id($id);
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        foreach ($directions as $direction) {
            if (!is_string($direction)) {
                throw new ReviewException('VALIDATION_ERROR', 'Chiều ôn phải là chuỗi.');
            }
            ReviewSupport::direction($direction);
        }
        $directions = array_values(array_unique($directions));
        sort($directions);
        $at = ReviewSupport::utc($completedAt);
        $dueAt = $at->add(new \DateInterval('P1D'));
        if ((int)$at->format('Y') < 1000 || (int)$dueAt->format('Y') > 9999) {
            throw new ReviewException('STATE_OUT_OF_RANGE', 'Ngày học nằm ngoài phạm vi DATETIME.');
        }
        $first = ReviewSupport::sql($at);
        $due = ReviewSupport::sql($dueAt);
        $created = 0;
        $existing = 0;
        $skipped = [];
        foreach ($ids as $wordId) {
            try {
                $word = $this->repository->word($wordId);
            } catch (ReviewException $error) {
                if ($error->errorCode !== 'CONTENT_UNAVAILABLE') {
                    throw $error;
                }
                $skipped[] = ['word_id' => $wordId, 'reason' => 'inactive_or_missing'];
                continue;
            }
            foreach ($directions as $direction) {
                if ($direction === 'listening' && trim((string)$word['audio']) === '') {
                    $skipped[] = ['word_id' => $wordId, 'direction' => $direction, 'reason' => 'missing_audio'];
                    continue;
                }
                if ($this->repository->initializeState($userId, $wordId, $direction, $first, $due)) {
                    $created++;
                } else {
                    $existing++;
                }
            }
        }
        return ['initialized' => $created, 'already_learned' => $existing, 'skipped' => $skipped];
    }
}
