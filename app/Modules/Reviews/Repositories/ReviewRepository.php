<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Repositories;

use App\Modules\Reviews\Services\ReviewSupport;
use App\Modules\Reviews\Requests\ReviewException;

final class ReviewRepository
{
    private $pdo;
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function user(int $id, bool $lock = false): array
    {
        $query = $this->pdo->prepare('SELECT id,name,role,status,timezone FROM users WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        $user = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            throw new ReviewException('UNAUTHENTICATED', 'Tài khoản không còn hợp lệ.', 401);
        }
        if ($user['status'] !== 'active') {
            throw new ReviewException('ACCOUNT_UNAVAILABLE', 'Tài khoản đang bị khóa hoặc ngừng hoạt động.', 403);
        }
        return $user;
    }
    public function lockState(int $userId, int $id): array
    {
        $query = $this->pdo->prepare('SELECT * FROM user_review_states WHERE id=? AND user_id=? FOR UPDATE');
        $query->execute([$id, $userId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ReviewException('NOT_FOUND', 'Không tìm thấy thẻ ôn.', 404);
        }
        return $row;
    }
    public function version(int $stateId): int
    {
        // Services lock the user before their first consistent read; same-user writes serialize.
        // Immutable history needs no gap lock, which would contend across new states.
        $query = $this->pdo->prepare('SELECT id FROM review_events WHERE review_state_id=? ORDER BY id DESC LIMIT 1');
        $query->execute([$stateId]);
        return (int)($query->fetchColumn() ?: 0);
    }
    public function word(int $id): array
    {
        $query = $this->pdo->prepare('SELECT w.*,t.is_active AS topic_active FROM words w JOIN topics t ON t.id=w.topic_id WHERE w.id=?');
        $query->execute([$id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$row || (int)$row['is_active'] !== 1 || (int)$row['topic_active'] !== 1) {
            throw new ReviewException('CONTENT_UNAVAILABLE', 'Từ hoặc chủ đề đang được ẩn.', 409);
        }
        return $row;
    }
    public function sourceExercise(int $wordId): int
    {
        $query = $this->pdo->prepare("SELECT e.id FROM exercises e
            JOIN lessons l ON l.id=e.lesson_id JOIN topics t ON t.id=l.topic_id
            JOIN lesson_words lw ON lw.lesson_id=e.lesson_id AND lw.word_id=?
            WHERE e.is_active=1 AND l.is_active=1 AND t.is_active=1
              AND (e.word_id=? OR (e.word_id IS NULL AND JSON_CONTAINS(e.content_json,?, '$.target_word_ids')))
            ORDER BY (e.word_id IS NULL),e.id LIMIT 1");
        $query->execute([$wordId,$wordId,(string)$wordId]);
        $id = $query->fetchColumn();
        if (!$id) {
            throw new ReviewException('CONTENT_UNAVAILABLE', 'Chưa có bài tập nguồn đang hoạt động cho từ này.', 409);
        }
        return (int)$id;
    }

    public function findStart(int $userId, string $key): ?array
    {
        $query = $this->pdo->prepare("SELECT * FROM learning_sessions WHERE user_id=? AND mode='sm2'
            AND JSON_UNQUOTE(JSON_EXTRACT(rules_json,'$.module'))='tuan_reviews_v1'
            AND JSON_UNQUOTE(JSON_EXTRACT(rules_json,'$.start_request_key'))=? ORDER BY id DESC LIMIT 1");
        $query->execute([$userId, $key]);
        return $query->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function startQuestion(int $sessionId): array
    {
        $query = $this->pdo->prepare("SELECT * FROM session_questions WHERE session_id=? AND phase='main' AND position=1 FOR UPDATE");
        $query->execute([$sessionId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ReviewException('CORRUPT_SNAPSHOT', 'Không tìm thấy snapshot của lượt ôn.', 500);
        }
        return $row;
    }

    public function createSession(int $userId, array $rules, \DateTimeImmutable $now): int
    {
        $insert = $this->pdo->prepare("INSERT INTO learning_sessions
            (user_id,lesson_id,mode,status,total_questions,correct_count,rules_json,started_at)
            VALUES (?,NULL,'sm2','in_progress',1,0,?,?)");
        $insert->execute([$userId, ReviewSupport::json($rules), ReviewSupport::sql($now)]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createQuestion(int $sessionId, int $exerciseId, array $snapshot): int
    {
        $insert = $this->pdo->prepare("INSERT INTO session_questions (session_id,exercise_id,phase,position,snapshot_json)
            VALUES (?,?,'main',1,?)");
        $insert->execute([$sessionId, $exerciseId, ReviewSupport::json($snapshot)]);
        return (int)$this->pdo->lastInsertId();
    }

    public function priorSubmission(string $key): ?array
    {
        // Call only after locking the user in a new transaction. Avoid missing-key gap locks.
        $query = $this->pdo->prepare('SELECT a.*,s.user_id,s.id AS session_id,s.rules_json,
            e.id AS event_id,e.after_json,e.reviewed_at AS event_time
            FROM answer_submissions a JOIN session_questions q ON q.id=a.session_question_id
            JOIN learning_sessions s ON s.id=q.session_id LEFT JOIN review_events e ON e.submission_id=a.id
            WHERE a.request_key=?');
        $query->execute([$key]);
        return $query->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function lockQuestion(int $userId, int $questionId): array
    {
        $query = $this->pdo->prepare("SELECT q.*,s.id AS learning_session_id,s.user_id,s.mode,s.status,s.rules_json,s.started_at
            FROM session_questions q JOIN learning_sessions s ON s.id=q.session_id
            WHERE q.id=? AND s.user_id=? AND s.mode='sm2' FOR UPDATE");
        $query->execute([$questionId, $userId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ReviewException('NOT_FOUND', 'Không tìm thấy lượt ôn.', 404);
        }
        return $row;
    }

    public function createSubmission(int $questionId, string $key, array $answer, int $quality, \DateTimeImmutable $now): int
    {
        $query = $this->pdo->prepare('INSERT INTO answer_submissions
            (session_question_id,request_key,attempt_no,answer_json,is_correct,hint_used,answered_at)
            VALUES (?,?,1,?,?,0,?)');
        $query->execute([$questionId, $key, ReviewSupport::json($answer), $quality >= 3 ? 1 : 0, ReviewSupport::sql($now)]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateState(int $userId, array $after): void
    {
        $query = $this->pdo->prepare('UPDATE user_review_states SET
            ease_factor=?,interval_days=?,repetitions=?,last_reviewed_at=?,next_review_at=? WHERE id=? AND user_id=?');
        $query->execute([$after['ease_factor'], $after['interval_days'], $after['repetitions'],
            $after['last_reviewed_at'], $after['next_review_at'], $after['id'], $userId]);
    }

    public function createEvent(int $stateId, int $submissionId, int $quality, array $before, array $after, \DateTimeImmutable $now): int
    {
        $query = $this->pdo->prepare('INSERT INTO review_events
            (review_state_id,submission_id,quality,before_json,after_json,reviewed_at) VALUES (?,?,?,?,?,?)');
        $query->execute([$stateId, $submissionId, $quality, ReviewSupport::json($before), ReviewSupport::json($after), ReviewSupport::sql($now)]);
        return (int)$this->pdo->lastInsertId();
    }

    public function finish(int $sessionId, int $questionId, int $quality, \DateTimeImmutable $now): void
    {
        $sqlNow = ReviewSupport::sql($now);
        $this->pdo->prepare('UPDATE session_questions SET resolved_at=? WHERE id=?')->execute([$sqlNow, $questionId]);
        $this->pdo->prepare("UPDATE learning_sessions SET status='completed',correct_count=?,finished_at=? WHERE id=?")
            ->execute([$quality >= 3 ? 1 : 0, $sqlNow, $sessionId]);
    }

    public function recordPresence(int $userId, string $date): void
    {
        // Learning owns XP, lesson counters and the streak qualification rule.
        $this->pdo->prepare('INSERT INTO user_daily_activity
            (user_id,activity_date,xp_total,completed_lessons,streak_qualified) VALUES (?,?,0,0,0)
            ON DUPLICATE KEY UPDATE id=id')->execute([$userId, $date]);
    }

    public function initializeState(int $userId, int $wordId, string $direction, string $first, string $due): bool
    {
        $insert = $this->pdo->prepare('INSERT INTO user_review_states (user_id,word_id,direction)
            VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $insert->execute([$userId, $wordId, $direction]);
        $row = $this->lockState($userId, (int)$this->pdo->lastInsertId());
        if ($row['first_learned_at'] !== null) {
            return false;
        }
        $this->pdo->prepare('UPDATE user_review_states SET first_learned_at=?,next_review_at=? WHERE id=? AND first_learned_at IS NULL')
            ->execute([$first, $due, $row['id']]);
        return true;
    }
    public function due(int $userId, \DateTimeImmutable $now, int $limit = 20, ?string $cursor = null, ?string $direction = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new ReviewException('VALIDATION_ERROR', 'limit phải nằm trong 1-100.');
        }
        $where = "s.user_id=? AND s.first_learned_at IS NOT NULL AND s.next_review_at IS NOT NULL AND s.next_review_at<=? AND w.is_active=1 AND t.is_active=1 AND (s.direction<>'listening' OR (w.audio IS NOT NULL AND CHAR_LENGTH(TRIM(w.audio))>0))";
        $params = [$userId,ReviewSupport::sql($now)];
        if ($direction !== null) {
            $where .= ' AND s.direction=?';
            $params[] = ReviewSupport::direction($direction);
        }
        $join = ' FROM user_review_states s JOIN words w ON w.id=s.word_id JOIN topics t ON t.id=w.topic_id WHERE ';
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $join . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        if ($cursor !== null) {
            if (strlen($cursor) > 256) {
                throw new ReviewException('INVALID_CURSOR', 'Cursor không hợp lệ.');
            }
            $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
            if ($raw === false) {
                throw new ReviewException('INVALID_CURSOR', 'Cursor không hợp lệ.');
            }
            try {
                $after = ReviewSupport::decode($raw);
            } catch (\Throwable $error) {
                throw new ReviewException('INVALID_CURSOR', 'Cursor không hợp lệ.');
            }
            if (
                !isset($after['at'], $after['id']) || !is_int($after['id']) || $after['id'] < 1 || !is_string($after['at'])
                || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $after['at'])
            ) {
                throw new ReviewException('INVALID_CURSOR', 'Cursor không hợp lệ.');
            }
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $after['at'], new \DateTimeZone('UTC'));
            if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $after['at']) {
                throw new ReviewException('INVALID_CURSOR', 'Ngày trong cursor không hợp lệ.');
            }
            $where .= ' AND (s.next_review_at>? OR (s.next_review_at=? AND s.id>?))';
            array_push($params, $after['at'], $after['at'], $after['id']);
        }
        $query = $this->pdo->prepare('SELECT s.*,w.english,w.vietnamese,w.ipa,w.example,w.example_translation,w.image,w.audio' . $join . $where
            . ' ORDER BY s.next_review_at ASC,s.id ASC LIMIT ' . ($limit + 1));
        $query->execute($params);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $next = null;
        if ($hasMore && $rows) {
            $last = end($rows);
            $next = rtrim(strtr(base64_encode(ReviewSupport::json(['at' => $last['next_review_at'],'id' => (int)$last['id']])), '+/', '-_'), '=');
        }
        $items = [];
        foreach ($rows as $row) {
            $item = ReviewSupport::publicState($row);
            $item['word'] = array_intersect_key($row, array_flip(['english','vietnamese','ipa','example','example_translation','image','audio']));
            $items[] = $item;
        }
        return ['items' => $items,'meta' => ['total_due' => $total,'limit' => $limit,'next_cursor' => $next,'server_now' => $now->format('Y-m-d\TH:i:s\Z')]];
    }
    public function dueCount(int $userId, \DateTimeImmutable $now): int
    {
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM user_review_states s JOIN words w ON w.id=s.word_id JOIN topics t ON t.id=w.topic_id
            WHERE s.user_id=? AND s.first_learned_at IS NOT NULL AND s.next_review_at IS NOT NULL AND s.next_review_at<=? AND w.is_active=1 AND t.is_active=1
              AND (s.direction<>\'listening\' OR (w.audio IS NOT NULL AND CHAR_LENGTH(TRIM(w.audio))>0))');
        $query->execute([$userId,ReviewSupport::sql($now)]);
        return (int)$query->fetchColumn();
    }
    public function history(int $userId, int $limit = 20, ?int $beforeId = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new ReviewException('VALIDATION_ERROR', 'limit phải nằm trong 1-100.');
        }
        $sql = 'SELECT e.id,e.quality,e.before_json,e.after_json,e.reviewed_at,s.word_id,s.direction,w.english,w.vietnamese
            FROM review_events e JOIN user_review_states s ON s.id=e.review_state_id JOIN words w ON w.id=s.word_id WHERE s.user_id=?';
        $params = [$userId];
        if ($beforeId !== null) {
            $sql .= ' AND e.id<?';
            $params[] = $beforeId;
        }
        $query = $this->pdo->prepare($sql . ' ORDER BY e.id DESC LIMIT ' . ($limit + 1));
        $query->execute($params);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['quality'] = (int)$row['quality'];
            $row['word_id'] = (int)$row['word_id'];
            $row['before'] = ReviewSupport::decode($row['before_json']);
            $row['after'] = ReviewSupport::decode($row['after_json']);
            $row['reviewed_at'] = ReviewSupport::iso($row['reviewed_at']);
            unset($row['before_json'], $row['after_json']);
        }
        unset($row);
        $last = $rows ? end($rows) : null;
        return ['items' => $rows,'next_before_id' => $more ? $last['id'] : null];
    }
}
