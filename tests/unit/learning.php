<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use App\Core\ApiException;
use App\Modules\Learning\Services\ExerciseService;
use App\Modules\Learning\Services\LearningService;
use App\Shared\HeartRules;

function check(bool $ok, string $name): void {
    if (!$ok) { throw new RuntimeException($name); }
    echo "PASS: $name\n";
}
$now = new DateTimeImmutable('2026-11-04 12:00:00', new DateTimeZone('UTC'));
check(HeartRules::refresh(['current_hp' => 1, 'next_hp_at' => '2026-11-04 11:00:00'], $now)
    === ['current_hp' => 3, 'next_hp_at' => null], 'Regeneration caps at three');
$hp = HeartRules::lose(['current_hp' => 3, 'next_hp_at' => null], $now);
check($hp === ['current_hp' => 2, 'next_hp_at' => '2026-11-04 12:30:00'], 'Wrong answer schedules account regeneration');
check(HeartRules::refresh($hp, $now) === $hp, 'Refresh does not refill hearts');
check(!LearningService::passed(['total_questions' => 10, 'correct_count' => 7], ['pass_percent' => 70]), 'Exactly 70 percent does not pass');
check(LearningService::passed(['total_questions' => 10, 'correct_count' => 8], ['pass_percent' => 70]), 'Eight of ten passes');
$grader = new ExerciseService();
$row = ['type' => 'listen_type', 'word_id' => 1, 'content_json' => json_encode(['target_word_ids' => [1], 'audio' => '/assets/audio/test.mp3']),
    'answer_json' => json_encode(['accepted_answers' => ['SỮA'], 'normalization' => ['trim' => true, 'case_sensitive' => false]])];
$snapshot = $grader->snapshot($row, [['id' => 1]]);
check($snapshot !== null && $grader->grade($snapshot, $grader->normalize($snapshot, ['text' => ' sữa '])), 'Unicode answer normalized on backend');
check($grader->snapshot($row, []) === null, 'Hidden target cannot enter a new question');
$row['content_json'] = '{"target_word_ids":[1],"audio":"javascript:alert(1)"}';
check($grader->snapshot($row, [['id' => 1]]) === null, 'Unsafe audio excluded');
$row['type'] = 'choose_image';
$row['content_json'] = '{"target_word_ids":[1],"options":[{"id":"a","image":"/assets/a.png"},{"id":"b","text":"missing image"}]}';
$row['answer_json'] = '{"correct_option_ids":["a"]}';
check($grader->snapshot($row, [['id' => 1]]) === null, 'Image exercise requires every image');
try { $grader->normalize($snapshot, ['text' => []]); throw new RuntimeException('Expected validation error'); }
catch (ApiException $e) { check($e->status === 422, 'Malformed answer rejected'); }
check(ExerciseService::media('//evil.example/payload') === null && ExerciseService::media('data:text/html,x') === null, 'Unsafe media schemes excluded');
