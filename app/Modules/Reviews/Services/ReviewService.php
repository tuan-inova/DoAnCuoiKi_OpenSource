<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Services;

use App\Modules\Reviews\Repositories\ReviewRepository;
use App\Modules\Reviews\Requests\ReviewException;

final class ReviewService
{
    private $pdo;
    private $repository;
    private $scheduler;
    public function __construct(\PDO $pdo, ?Sm2Scheduler $scheduler = null)
    {
        $this->pdo = $pdo;
        $this->repository = new ReviewRepository($pdo);
        $this->scheduler = $scheduler ?? new Sm2Scheduler();
    }
    public function profile(int $userId): array
    {
        $user = $this->repository->user($userId);
        ReviewSupport::localDate($user, ReviewSupport::utc());
        $user['id'] = (int)$user['id'];
        return $user;
    }
    public function due(int $userId, int $limit = 20, ?string $cursor = null, ?string $direction = null, ?\DateTimeImmutable $at = null): array
    {
        $this->repository->user($userId);
        return $this->repository->due($userId, ReviewSupport::utc($at), $limit, $cursor, $direction);
    }
    public function history(int $userId, int $limit = 20, ?int $beforeId = null): array
    {
        $this->repository->user($userId);
        if ($beforeId !== null) {
            ReviewSupport::id($beforeId);
        }
        return $this->repository->history($userId, $limit, $beforeId);
    }

    public function start(int $userId, array $body, ?\DateTimeImmutable $at = null): array
    {
        ReviewSupport::fields($body, ['review_state_id','request_key']);
        $stateId = ReviewSupport::id($body['review_state_id']);
        $key = ReviewSupport::uuid($body['request_key']);
        $now = ReviewSupport::utc($at);
        return ReviewSupport::transaction($this->pdo, function () use ($userId, $stateId, $key, $now): array {
            $this->repository->user($userId, true);
            $session = $this->repository->findStart($userId, $key);
            if ($session) {
                $rules = ReviewSupport::decode($session['rules_json']);
                if ((int)$rules['review_state_id'] !== $stateId) {
                    throw new ReviewException('REQUEST_KEY_REUSED', 'request_key đã được dùng cho thẻ khác.', 409);
                }
                $this->assertOpen($session, $rules, $now);
                $question = $this->repository->startQuestion((int)$session['id']);
                return $this->startDto($session, $question, $rules);
            }
            $state = $this->repository->lockState($userId, $stateId);
            if ($state['first_learned_at'] === null || $state['next_review_at'] === null) {
                throw new ReviewException('NOT_LEARNED', 'Từ chưa được ghi nhận đã học.', 409);
            }
            if ($state['next_review_at'] > ReviewSupport::sql($now)) {
                throw new ReviewException('NOT_DUE', 'Thẻ chưa đến hạn ôn.', 409);
            }
            $word = $this->repository->word((int)$state['word_id']);
            if ($state['direction'] === 'listening' && trim((string)$word['audio']) === '') {
                throw new ReviewException('MEDIA_UNAVAILABLE', 'Chưa có âm thanh cho chiều ôn nghe.', 409);
            }
            $exerciseId = $this->repository->sourceExercise((int)$state['word_id']);
            $version = $this->repository->version($stateId);
            $stamp = ReviewSupport::fingerprint($state, $version);
            $rules = ['schema_version' => 1,'module' => 'tuan_reviews_v1','format' => 'flashcard_self_assessment',
                'start_request_key' => $key,'review_state_id' => $stateId,'direction' => $state['direction'],
                'expires_at' => ReviewSupport::sql($now->add(new \DateInterval('PT30M'))),'xp_policy' => 'none'];
            $sessionId = $this->repository->createSession($userId, $rules, $now);
            $card = ['word_id' => (int)$word['id'],'english' => $word['english'],'vietnamese' => $word['vietnamese'],
                'ipa' => $word['ipa'],'example' => $word['example'],'example_translation' => $word['example_translation'],'image' => $word['image'],'audio' => $word['audio']];
            $snapshot = ['schema_version' => 1,'module' => 'tuan_reviews_v1','format' => 'flashcard_self_assessment',
                'review_state_id' => $stateId,'direction' => $state['direction'],'state_version' => $version,'state_stamp' => $stamp,
                'source_exercise_id' => $exerciseId,'card' => $card];
            $questionId = $this->repository->createQuestion($sessionId, $exerciseId, $snapshot);
            return $this->startDto(['id' => $sessionId,'status' => 'in_progress'], ['id' => $questionId,'snapshot_json' => ReviewSupport::json($snapshot)], $rules);
        });
    }

    public function submit(int $userId, array $body, ?\DateTimeImmutable $at = null): array
    {
        ReviewSupport::fields($body, ['session_question_id','request_key','quality']);
        $questionId = ReviewSupport::id($body['session_question_id']);
        $key = ReviewSupport::uuid($body['request_key']);
        $quality = ReviewSupport::quality($body['quality']);
        $now = ReviewSupport::utc($at);
        try {
            return ReviewSupport::transaction($this->pdo, function () use ($userId, $questionId, $key, $quality, $now): array {
                $user = $this->repository->user($userId, true);
                // Retry lookup precedes session deadline and stale-state checks.
                $prior = $this->repository->priorSubmission($key);
                if ($prior) {
                    $answer = ReviewSupport::decode($prior['answer_json']);
                    $rules = ReviewSupport::decode($prior['rules_json']);
                    if (
                        (int)$prior['user_id'] !== $userId || (int)$prior['session_question_id'] !== $questionId
                        || ($rules['module'] ?? '') !== 'tuan_reviews_v1' || ($answer['quality'] ?? null) !== $quality || !$prior['event_id']
                    ) {
                        throw new ReviewException('REQUEST_KEY_REUSED', 'request_key đã được dùng cho một thao tác khác.', 409);
                    }
                    return $this->result((int)$prior['event_id'], (int)$prior['id'], ReviewSupport::decode($prior['after_json']), $prior['event_time']);
                }
                $question = $this->repository->lockQuestion($userId, $questionId);
                $rules = ReviewSupport::decode($question['rules_json']);
                $snapshot = ReviewSupport::decode($question['snapshot_json']);
                if (($rules['module'] ?? '') !== 'tuan_reviews_v1' || ($snapshot['module'] ?? '') !== 'tuan_reviews_v1') {
                    throw new ReviewException('NOT_FOUND', 'Không tìm thấy lượt ôn.', 404);
                }
                $this->assertOpen($question, $rules, $now);
                $state = $this->repository->lockState($userId, (int)$snapshot['review_state_id']);
                $version = $this->repository->version((int)$state['id']);
                if (!hash_equals($snapshot['state_stamp'], ReviewSupport::fingerprint($state, $version))) {
                    throw new ReviewException('STALE_REVIEW', 'Thẻ đã được cập nhật ở lượt hoặc thiết bị khác. Hãy tải lại danh sách.', 409);
                }
                if ($state['first_learned_at'] === null || $state['next_review_at'] === null || $state['next_review_at'] > ReviewSupport::sql($now)) {
                    throw new ReviewException('NOT_DUE', 'Thẻ chưa đủ điều kiện ôn.', 409);
                }
                if (
                    $state['first_learned_at'] > ReviewSupport::sql($now) || ($state['last_reviewed_at'] !== null && $state['last_reviewed_at'] > ReviewSupport::sql($now))
                    || $question['started_at'] > ReviewSupport::sql($now)
                ) {
                    throw new ReviewException('CLOCK_MISMATCH', 'Giờ máy chủ đang sớm hơn lịch sử đã lưu.', 409);
                }
                $date = ReviewSupport::localDate($user, $now);
                $next = $this->scheduler->schedule(['easiness_factor' => $state['ease_factor'],'interval_days' => (int)$state['interval_days'],
                    'repetitions' => (int)$state['repetitions']], $quality, $now);
                if ($next['easiness_factor'] > 99999.999 || $next['repetitions'] > 4294967295) {
                    throw new ReviewException('STATE_OUT_OF_RANGE', 'Giá trị lịch vượt giới hạn cột database.');
                }
                $after = $state;
                $after['ease_factor'] = $next['easiness_factor'];
                $after['interval_days'] = $next['interval_days'];
                $after['repetitions'] = $next['repetitions'];
                $after['last_reviewed_at'] = ReviewSupport::sql($now);
                $after['next_review_at'] = ReviewSupport::sql(new \DateTimeImmutable($next['due_at']));
                $answer = ['schema_version' => 1,'module' => 'tuan_reviews_v1','self_assessment' => true,'quality' => $quality,
                    'review_state_id' => (int)$state['id'],'direction' => $state['direction']];
                $submissionId = $this->repository->createSubmission($questionId, $key, $answer, $quality, $now);
                $this->repository->updateState($userId, $after);
                $afterData = ['schema_version' => 1,'state' => ReviewSupport::publicState($after),'activity_date' => $date,'timezone' => $user['timezone'],
                    'session_id' => (int)$question['learning_session_id'],'session_question_id' => $questionId,'quality' => $quality,'xp_awarded' => 0];
                $eventId = $this->repository->createEvent(
                    (int)$state['id'],
                    $submissionId,
                    $quality,
                    ['schema_version' => 1, 'state' => ReviewSupport::publicState($state), 'state_version' => $version],
                    $afterData,
                    $now
                );
                $this->repository->finish((int)$question['learning_session_id'], $questionId, $quality, $now);
                $this->repository->recordPresence($userId, $date);
                return $this->result($eventId, $submissionId, $afterData, ReviewSupport::sql($now));
            });
        } catch (\PDOException $error) {
            $number = (int)($error->errorInfo[1] ?? 0);
            if ($number === 1062) {
                throw new ReviewException('REQUEST_KEY_REUSED', 'request_key hoặc bằng chứng ôn đã được ghi. Hãy tải lại.', 409);
            }
            if (in_array($number, [1205,1213], true)) {
                throw new ReviewException('BUSY_RETRY', 'Dữ liệu đang bận. Thử lại cùng request_key.', 503);
            }
            throw $error;
        }
    }
    private function assertOpen(array $session, array $rules, \DateTimeImmutable $now): void
    {
        if ($session['status'] !== 'in_progress') {
            throw new ReviewException('REVIEW_FINISHED', 'Lượt ôn đã kết thúc.', 409);
        }
        if (($rules['expires_at'] ?? '') <= ReviewSupport::sql($now)) {
            throw new ReviewException('REVIEW_EXPIRED', 'Lượt ôn đã hết hạn 30 phút.', 410);
        }
    }
    private function startDto(array $session, array $question, array $rules): array
    {
        $snapshot = ReviewSupport::decode($question['snapshot_json']);
        return ['session_id' => (int)$session['id'],'session_question_id' => (int)$question['id'],'review_state_id' => (int)$snapshot['review_state_id'],
            'direction' => $snapshot['direction'],'format' => 'flashcard_self_assessment','expires_at' => ReviewSupport::iso($rules['expires_at']),'card' => $snapshot['card']];
    }
    private function result(int $eventId, int $submissionId, array $stored, string $reviewedAt): array
    {
        return ['review_event_id' => $eventId,'submission_id' => $submissionId,'session_id' => $stored['session_id'],
            'session_question_id' => $stored['session_question_id'],'quality' => $stored['quality'],'state' => $stored['state'],
            'state_version' => $eventId,'activity_date' => $stored['activity_date'],'reviewed_at' => ReviewSupport::iso($reviewedAt),'xp_awarded' => 0];
    }
}
