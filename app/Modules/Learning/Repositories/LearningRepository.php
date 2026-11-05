<?php
declare(strict_types=1);

namespace App\Modules\Learning\Repositories;

use PDO;

final class LearningRepository
{
    public function __construct(private PDO $db) {}

    public function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function write(string $sql, array $params): void
    {
        $this->db->prepare($sql)->execute($params);
    }

    public function getActiveTopics(): array
    {
        return $this->rows('SELECT * FROM topics WHERE is_active = 1 ORDER BY position, id');
    }

    public function getActiveLessons(): array
    {
        return $this->rows('SELECT l.* FROM lessons l JOIN topics t ON t.id = l.topic_id
            WHERE l.is_active = 1 AND t.is_active = 1 ORDER BY t.position, l.position, l.id');
    }

    public function lesson(int $id): ?array
    {
        return $this->rows('SELECT l.* FROM lessons l JOIN topics t ON t.id = l.topic_id
            WHERE l.id = ? AND l.is_active = 1 AND t.is_active = 1', [$id])[0] ?? null;
    }

    public function getWordsByLessonId(int $lessonId): array
    {
        return $this->rows('SELECT w.* FROM words w
            JOIN lesson_words lw ON lw.word_id = w.id JOIN lessons l ON l.id = lw.lesson_id
            JOIN topics t ON t.id = l.topic_id JOIN topics wt ON wt.id = w.topic_id
            WHERE lw.lesson_id = ? AND w.is_active = 1 AND l.is_active = 1
            AND t.is_active = 1 AND wt.is_active = 1 ORDER BY w.id', [$lessonId]);
    }

    public function exercises(int $lessonId): array
    {
        return $this->rows('SELECT e.* FROM exercises e JOIN lessons l ON l.id = e.lesson_id
            JOIN topics t ON t.id = l.topic_id LEFT JOIN words w ON w.id = e.word_id
            LEFT JOIN topics wt ON wt.id = w.topic_id
            WHERE e.lesson_id = ? AND e.is_active = 1 AND l.is_active = 1 AND t.is_active = 1
            AND (e.word_id IS NULL OR (w.is_active = 1 AND wt.is_active = 1)) ORDER BY e.id', [$lessonId]);
    }

    public function completedLessons(int $userId): array
    {
        return array_map('intval', array_column($this->rows("SELECT DISTINCT lesson_id FROM learning_sessions
            WHERE user_id = ? AND mode = 'lesson' AND status = 'completed'", [$userId]), 'lesson_id'));
    }

    public function unlocked(int $userId, array $lesson): bool
    {
        return $this->rows("SELECT l.id FROM lessons l JOIN topics t ON t.id = l.topic_id
            WHERE l.topic_id = ? AND l.position < ? AND l.is_active = 1 AND t.is_active = 1
            AND NOT EXISTS (SELECT 1 FROM learning_sessions s WHERE s.user_id = ?
                AND s.lesson_id = l.id AND s.mode = 'lesson' AND s.status = 'completed') LIMIT 1",
            [$lesson['topic_id'], $lesson['position'], $userId]) === [];
    }

    public function lockUser(int $id): array
    {
        return $this->rows('SELECT * FROM users WHERE id = ? FOR UPDATE', [$id])[0];
    }

    public function saveHearts(int $id, array $state): void
    {
        $this->write('UPDATE users SET current_hp = ?, next_hp_at = ? WHERE id = ?',
            [$state['current_hp'], $state['next_hp_at'], $id]);
    }

    public function activeSession(int $userId, int $lessonId): ?array
    {
        return $this->rows("SELECT * FROM learning_sessions WHERE user_id = ? AND lesson_id = ?
            AND mode = 'lesson' AND status = 'in_progress' ORDER BY id DESC LIMIT 1", [$userId, $lessonId])[0] ?? null;
    }

    public function session(int $userId, int $id, bool $lock = false): ?array
    {
        return $this->rows('SELECT * FROM learning_sessions WHERE id = ? AND user_id = ?'
            . ($lock ? ' FOR UPDATE' : ''), [$id, $userId])[0] ?? null;
    }

    public function createSession(int $userId, int $lessonId, int $total, array $rules): int
    {
        $this->write("INSERT INTO learning_sessions (user_id,lesson_id,mode,total_questions,rules_json)
            VALUES (?,?,'lesson',?,?)", [$userId, $lessonId, $total, json_encode($rules, JSON_THROW_ON_ERROR)]);
        return (int) $this->db->lastInsertId();
    }

    public function addQuestion(int $sessionId, int $exerciseId, string $phase, int $position, string $snapshot, ?int $source = null): void
    {
        $this->write('INSERT INTO session_questions (session_id,exercise_id,phase,position,snapshot_json,source_question_id)
            VALUES (?,?,?,?,?,?)', [$sessionId, $exerciseId, $phase, $position, $snapshot, $source]);
    }

    public function questions(int $sessionId): array
    {
        return $this->rows("SELECT * FROM session_questions WHERE session_id = ?
            ORDER BY FIELD(phase,'main','mistake_review','matching'), position", [$sessionId]);
    }

    public function submission(string $key): ?array
    {
        return $this->rows('SELECT a.*, q.session_id FROM answer_submissions a
            JOIN session_questions q ON q.id = a.session_question_id WHERE a.request_key = ?', [$key])[0] ?? null;
    }

    public function recordAnswer(int $questionId, string $key, array $answer, bool $correct): void
    {
        // The owning user/session lock serializes attempts across tabs and devices.
        $attempt = 1 + (int) $this->rows('SELECT COUNT(*) AS n FROM answer_submissions WHERE session_question_id = ?', [$questionId])[0]['n'];
        $this->write('INSERT INTO answer_submissions (session_question_id,request_key,attempt_no,answer_json,is_correct)
            VALUES (?,?,?,?,?)', [$questionId, $key, $attempt, json_encode($answer, JSON_THROW_ON_ERROR), (int) $correct]);
    }

    public function resolveQuestion(int $id): void
    {
        $this->write('UPDATE session_questions SET resolved_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }

    public function addCorrect(int $id): void
    {
        $this->write('UPDATE learning_sessions SET correct_count = correct_count + 1 WHERE id = ?', [$id]);
    }

    public function wrongMainQuestions(int $id): array
    {
        return $this->rows("SELECT q.* FROM session_questions q JOIN answer_submissions a ON a.session_question_id = q.id
            WHERE q.session_id = ? AND q.phase = 'main' AND a.attempt_no = 1 AND a.is_correct = 0 ORDER BY q.position", [$id]);
    }

    public function finish(int $id, string $status): void
    {
        $this->write("UPDATE learning_sessions SET status = ?, finished_at = UTC_TIMESTAMP()
            WHERE id = ? AND status = 'in_progress'", [$status, $id]);
    }

    public function reward(array $user, array $session, int $xp): void
    {
        if ($xp > 0) {
            $this->write("INSERT INTO xp_transactions (user_id,session_id,amount,reason,event_key)
                VALUES (?,?,?,'lesson_completed',?)", [$user['id'], $session['id'], $xp, 'lesson:' . $session['id'] . ':complete']);
        }
        $date = (new \DateTimeImmutable('now', new \DateTimeZone($user['timezone'])))->format('Y-m-d');
        // Only advance already assigned quests; claiming their rewards belongs to Notifications/Quests.
        $this->write("UPDATE user_quests uq JOIN quest_definitions q ON q.id = uq.quest_id
            SET uq.progress = LEAST(q.target, uq.progress + CASE q.metric WHEN 'xp_earned' THEN ? ELSE 1 END)
            WHERE uq.user_id = ? AND uq.quest_date = ? AND uq.reward_claimed_at IS NULL
            AND q.metric IN ('xp_earned','lessons_completed')", [$xp, $user['id'], $date]);
        $this->write('INSERT INTO user_daily_activity (user_id,activity_date,xp_total,completed_lessons,streak_qualified)
            VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE xp_total = xp_total + VALUES(xp_total),
            completed_lessons = completed_lessons + 1, streak_qualified = (xp_total >= ?)',
            [$user['id'], $date, $xp, (int) ($xp >= (int) $user['daily_goal_xp']), $user['daily_goal_xp']]);
    }

    public function stats(int $userId): array
    {
        $xp = (int) $this->rows('SELECT COALESCE(SUM(amount),0) AS n FROM xp_transactions WHERE user_id = ?', [$userId])[0]['n'];
        $days = array_column($this->rows('SELECT activity_date FROM user_daily_activity
            WHERE user_id = ? AND streak_qualified = 1 ORDER BY activity_date DESC', [$userId]), 'activity_date');
        return ['xp' => $xp, 'qualified_dates' => $days];
    }
}
