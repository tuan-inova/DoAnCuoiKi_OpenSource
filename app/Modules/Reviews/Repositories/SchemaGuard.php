<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Repositories;

use App\Modules\Reviews\Requests\ReviewException;

final class SchemaGuard
{
    public static function check(\PDO $pdo): void
    {
        $required = [
            'users' => ['id','name','status','role','timezone'],
            'topics' => ['id','is_active'],
            'words' => ['id','topic_id','english','vietnamese','ipa','example','example_translation','image','audio','is_active'],
            'lessons' => ['id','topic_id','is_active'], 'lesson_words' => ['lesson_id','word_id'],
            'exercises' => ['id','lesson_id','word_id','content_json','is_active'],
            'learning_sessions' => ['id','user_id','lesson_id','mode','status','rules_json','started_at','finished_at','total_questions','correct_count'],
            'session_questions' => ['id','session_id','exercise_id','phase','position','snapshot_json','resolved_at'],
            'answer_submissions' => ['id','session_question_id','request_key','attempt_no','answer_json','is_correct','hint_used','answered_at'],
            'user_review_states' => ['id','user_id','word_id','direction','first_learned_at','ease_factor','interval_days','repetitions','last_reviewed_at','next_review_at'],
            'review_events' => ['id','review_state_id','submission_id','quality','before_json','after_json','reviewed_at'],
            'user_daily_activity' => ['user_id','activity_date','xp_total','completed_lessons','streak_qualified'],
            'notifications' => ['id','user_id','type','message','scheduled_at','sent_at','read_at','dedupe_key'],
        ];
        $columns = $pdo->query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(\PDO::FETCH_NUM);
        $found = [];
        foreach ($columns as $column) {
            $found[$column[0]][] = $column[1];
        }
        foreach ($required as $table => $names) {
            if (array_diff($names, $found[$table] ?? [])) {
                throw new ReviewException('SCHEMA_MISMATCH', 'Database chưa khớp schema 18 bảng của nhóm trưởng; kiểm tra bảng ' . $table . '.', 500);
            }
        }
        $rows = $pdo->query('SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0 ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(\PDO::FETCH_NUM);
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row[0]][$row[1]][] = $row[2];
        }
        $unique = ['user_review_states' => ['user_id','word_id','direction'],'answer_submissions' => ['request_key'],
            'review_events' => ['review_state_id','submission_id'],'notifications' => ['dedupe_key']];
        foreach ($unique as $table => $columns) {
            $ok = false;
            foreach ($indexes[$table] ?? [] as $index) {
                if ($index === $columns) {
                    $ok = true;
                }
            }
            if (!$ok) {
                throw new ReviewException('SCHEMA_MISMATCH', 'Thiếu khóa unique cần thiết ở bảng ' . $table . '.', 500);
            }
        }
    }
}
