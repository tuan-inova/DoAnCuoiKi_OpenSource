<?php
declare(strict_types=1);

namespace App\Modules\Learning\Services;

use App\Core\ApiException;
use App\Modules\Learning\Repositories\LearningRepository;
use App\Shared\HeartRules;

final class LearningService
{
    public function __construct(private LearningRepository $repo, private ExerciseService $grader = new ExerciseService()) {}

    public function getLearningPathData(array $user): array
    {
        $completed = $this->repo->completedLessons((int) $user['id']);
        $lessonsByTopic = [];
        foreach ($this->repo->getActiveLessons() as $lesson) {
            [$main] = $this->bank($lesson);
            $lesson['ready'] = count($main) >= (int) $lesson['question_count'];
            $lesson['completed'] = in_array((int) $lesson['id'], $completed, true);
            $lesson['unlocked'] = $this->repo->unlocked((int) $user['id'], $lesson);
            $lessonsByTopic[$lesson['topic_id']][] = $lesson;
        }
        return ['topics' => $this->repo->getActiveTopics(), 'lessonsByTopic' => $lessonsByTopic,
            'stats' => $this->stats($user)];
    }

    public function getLessonFlashcards(int $lessonId, int $userId): array
    {
        $lesson = $this->requireLesson($lessonId, $userId);
        [$main] = $this->bank($lesson);
        $words = $this->repo->getWordsByLessonId($lessonId);
        foreach ($words as &$word) {
            $word['image'] = ExerciseService::media($word['image']);
            $word['audio'] = ExerciseService::media($word['audio']);
        }
        return ['lesson' => $lesson, 'words' => $words,
            'ready' => count($main) >= (int) $lesson['question_count'],
            'activeSession' => $this->repo->activeSession($userId, $lessonId)];
    }

    private function requireLesson(int $id, int $userId): array
    {
        $lesson = $this->repo->lesson($id);
        if ($lesson === null) { throw new ApiException('LESSON_NOT_FOUND', 'Không tìm thấy bài học.', 404); }
        if (!$this->repo->unlocked($userId, $lesson)) {
            throw new ApiException('LESSON_LOCKED', 'Hãy hoàn thành bài trước trong chủ đề.', 403);
        }
        return $lesson;
    }

    private function bank(array $lesson): array
    {
        $words = $this->repo->getWordsByLessonId((int) $lesson['id']);
        $main = $matching = [];
        foreach ($this->repo->exercises((int) $lesson['id']) as $row) {
            $snapshot = $this->grader->snapshot($row, $words);
            if ($snapshot === null) { continue; }
            $item = ['exercise_id' => (int) $row['id'], 'snapshot' => $snapshot];
            if ($row['type'] === 'match_pairs') { $matching[] = $item; } else { $main[] = $item; }
        }
        return [$main, $matching];
    }

    public function start(int $userId, int $lessonId): array
    {
        return $this->repo->transaction(function () use ($userId, $lessonId): array {
            $user = $this->userWithHearts($userId);
            $lesson = $this->requireLesson($lessonId, $userId);
            $existing = $this->repo->activeSession($userId, $lessonId);
            if ($existing !== null) { return $this->state($user, $existing); }
            if ((int) $user['current_hp'] === 0) { throw new ApiException('NO_HEARTS', 'Bạn đã hết tim. Hãy chờ hồi tim.', 409); }
            [$main, $matching] = $this->bank($lesson);
            $total = (int) $lesson['question_count'];
            if (count($main) < $total) {
                throw new ApiException('LESSON_NOT_READY', 'Bài học chưa đủ câu hỏi hoặc tài nguyên để bắt đầu.', 409);
            }
            shuffle($main); shuffle($matching);
            $rules = ['pass_percent' => (float) $lesson['pass_percent'], 'xp_reward' => (int) $lesson['xp_reward'],
                'max_hp' => HeartRules::MAX_HP, 'mistake_review' => true, 'matching_required' => $matching !== []];
            $id = $this->repo->createSession($userId, $lessonId, $total, $rules);
            foreach (array_slice($main, 0, $total) as $position => $item) {
                // Shuffle display order once and keep it stable on refresh/retry.
                foreach (['options', 'tokens'] as $key) {
                    if (isset($item['snapshot']['content'][$key])) { shuffle($item['snapshot']['content'][$key]); }
                }
                $this->repo->addQuestion($id, $item['exercise_id'], 'main', $position + 1,
                    json_encode($item['snapshot'], JSON_THROW_ON_ERROR));
            }
            if ($matching !== []) {
                $item = $matching[0]; shuffle($item['snapshot']['content']['right']);
                $this->repo->addQuestion($id, $item['exercise_id'], 'matching', 1,
                    json_encode($item['snapshot'], JSON_THROW_ON_ERROR));
            }
            return $this->state($user, $this->requireSession($userId, $id));
        });
    }

    public function getSession(int $userId, int $id): array
    {
        return $this->repo->transaction(function () use ($userId, $id): array {
            $user = $this->userWithHearts($userId);
            return $this->state($user, $this->requireSession($userId, $id));
        });
    }

    private function requireSession(int $userId, int $id): array
    {
        $session = $this->repo->session($userId, $id, true);
        if ($session === null) { throw new ApiException('SESSION_NOT_FOUND', 'Không tìm thấy lượt học.', 404); }
        return $session;
    }

    private function userWithHearts(int $userId): array
    {
        $user = $this->repo->lockUser($userId);
        if ($user['status'] !== 'active') { throw new ApiException('FORBIDDEN', 'Tài khoản không hoạt động.', 403); }
        $hp = HeartRules::refresh($user, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->repo->saveHearts($userId, $hp);
        return array_replace($user, $hp);
    }

    public function answer(int $userId, int $id, array $body): array
    {
        $key = $body['request_key'] ?? null;
        $questionId = $body['question_id'] ?? null;
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9:_-]{16,100}$/D', $key)
            || !is_int($questionId) || $questionId < 1) {
            throw new ApiException('VALIDATION_FAILED', 'Cần question_id và request_key hợp lệ.', 422);
        }
        return $this->repo->transaction(function () use ($userId, $id, $key, $questionId, $body): array {
            $user = $this->userWithHearts($userId);
            $session = $this->requireSession($userId, $id);
            $questions = $this->repo->questions($id);
            $selected = null;
            foreach ($questions as $question) {
                if ((int) $question['id'] === $questionId) { $selected = $question; break; }
            }
            if ($selected === null) { throw new ApiException('QUESTION_NOT_FOUND', 'Câu hỏi không thuộc lượt học.', 404); }
            $snapshot = json_decode($selected['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            $answer = $this->grader->normalize($snapshot, $body['answer'] ?? null);
            $old = $this->repo->submission($key);
            if ($old !== null) {
                if ((int) $old['session_id'] !== $id || (int) $old['session_question_id'] !== $questionId
                    || json_decode($old['answer_json'], true) !== $answer) {
                    throw new ApiException('REQUEST_KEY_CONFLICT', 'request_key đã dùng cho thao tác khác.', 409);
                }
                return ['is_correct' => (bool) $old['is_correct'], 'replayed' => true, 'session' => $this->state($user, $session)];
            }
            $current = $this->current($questions);
            if ($session['status'] !== 'in_progress' || $current === null || (int) $current['id'] !== $questionId) {
                throw new ApiException('QUESTION_OUT_OF_ORDER', 'Câu hỏi đã trả lời hoặc chưa đến lượt.', 409);
            }
            if ($selected['phase'] !== 'mistake_review' && (int) $user['current_hp'] === 0) {
                throw new ApiException('NO_HEARTS', 'Bạn đã hết tim. Hãy chờ hồi tim.', 409);
            }
            $correct = $this->grader->grade($snapshot, $answer);
            $this->repo->recordAnswer($questionId, $key, $answer, $correct);
            if ($correct || $selected['phase'] === 'main') { $this->repo->resolveQuestion($questionId); }
            if ($selected['phase'] === 'main' && $correct) { $this->repo->addCorrect($id); }
            if (!$correct && $selected['phase'] !== 'mistake_review') {
                $hp = HeartRules::lose($user, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                $this->repo->saveHearts($userId, $hp); $user = array_replace($user, $hp);
            }
            $session = $this->requireSession($userId, $id);
            $questions = $this->repo->questions($id);
            $rules = json_decode($session['rules_json'], true, 512, JSON_THROW_ON_ERROR);
            $mainDone = !array_filter($questions, static fn ($q) => $q['phase'] === 'main' && $q['resolved_at'] === null);
            if ((int) $user['current_hp'] === 0 || ($mainDone && !self::passed($session, $rules))) {
                $this->repo->finish($id, 'failed');
            } elseif ($mainDone) {
                if (!array_filter($questions, static fn ($q) => $q['phase'] === 'mistake_review')) {
                    foreach ($this->repo->wrongMainQuestions($id) as $position => $question) {
                        $this->repo->addQuestion($id, (int) $question['exercise_id'], 'mistake_review', $position + 1,
                            $question['snapshot_json'], (int) $question['id']);
                    }
                }
                if ($this->current($this->repo->questions($id)) === null) {
                    $this->repo->finish($id, 'completed');
                    $this->repo->reward($user, $session, (int) $rules['xp_reward']);
                }
            }
            return ['is_correct' => $correct, 'replayed' => false,
                'session' => $this->state($user, $this->requireSession($userId, $id))];
        });
    }

    public static function passed(array $session, array $rules): bool
    {
        return (int) $session['total_questions'] > 0
            && (int) $session['correct_count'] * 100 > (int) $session['total_questions'] * (float) $rules['pass_percent'];
    }

    private function current(array $questions): ?array
    {
        foreach ($questions as $question) {
            if ($question['resolved_at'] === null) { return $question; }
        }
        return null;
    }

    private function state(array $user, array $session): array
    {
        $questions = $this->repo->questions((int) $session['id']);
        $question = $session['status'] === 'in_progress' ? $this->current($questions) : null;
        $public = null;
        if ($question !== null) {
            $snapshot = json_decode($question['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            $public = ['id' => (int) $question['id'], 'phase' => $question['phase'], 'position' => (int) $question['position'],
                'type' => $snapshot['type'], 'content' => $snapshot['content']];
        }
        $rules = json_decode($session['rules_json'], true, 512, JSON_THROW_ON_ERROR);
        return ['id' => (int) $session['id'], 'lesson_id' => (int) $session['lesson_id'], 'status' => $session['status'],
            'total_questions' => (int) $session['total_questions'], 'correct_count' => (int) $session['correct_count'],
            'answered_main' => count(array_filter($questions, static fn ($q) => $q['phase'] === 'main' && $q['resolved_at'] !== null)),
            'current_hp' => (int) $user['current_hp'], 'max_hp' => HeartRules::MAX_HP, 'next_hp_at' => $user['next_hp_at'],
            'xp_earned' => $session['status'] === 'completed' ? (int) $rules['xp_reward'] : 0,
            'stats' => $this->stats($user), 'question' => $public];
    }

    private function stats(array $user): array
    {
        $stats = $this->repo->stats((int) $user['id']);
        $date = new \DateTimeImmutable('today', new \DateTimeZone($user['timezone']));
        $days = array_fill_keys($stats['qualified_dates'], true);
        if (!isset($days[$date->format('Y-m-d')])) { $date = $date->modify('-1 day'); }
        $streak = 0;
        while (isset($days[$date->format('Y-m-d')])) { $streak++; $date = $date->modify('-1 day'); }
        return ['xp' => $stats['xp'], 'streak' => $streak];
    }
}
