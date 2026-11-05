<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use App\Core\{ApiException, Database};
use App\Modules\Learning\Repositories\LearningRepository;
use App\Modules\Learning\Services\LearningService;

function check(bool $ok, string $name): void {
    if (!$ok) { throw new RuntimeException($name); }
    echo "PASS: $name\n";
}
function errorIs(callable $work, string $code): void {
    try { $work(); throw new RuntimeException("Expected $code"); }
    catch (ApiException $e) { check($e->errorCode === $code, $code); }
}
$db = Database::connection();
$name = $db->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with($name, '_test') || (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    throw new RuntimeException('Use a fresh dedicated *_test database with migration 001 and seed 001.');
}
$hash = password_hash('test-password-123', PASSWORD_DEFAULT);
$stmt = $db->prepare('INSERT INTO users (name,email,password_hash,daily_goal_xp) VALUES (?,?,?,?)');
$stmt->execute(['Learner A', 'a@example.test', $hash, 10]); $user = (int) $db->lastInsertId();
$stmt->execute(['Learner B', 'b@example.test', $hash, 10]); $other = (int) $db->lastInsertId();
$db->exec("INSERT INTO user_quests (user_id,quest_id,quest_date) VALUES ($user,1,DATE(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','+07:00'))),($user,3,DATE(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','+07:00')))");
$service = new LearningService(new LearningRepository($db));
$repo = new LearningRepository($db);
$path = $service->getLearningPathData($repo->lockUser($user));
check(count($path['lessonsByTopic'][1]) === 2 && !$path['lessonsByTopic'][1][1]['unlocked'], 'Database path and predecessor lock');
errorIs(fn () => $service->start($user, 2), 'LESSON_LOCKED');
$db->exec('UPDATE topics SET is_active=0 WHERE id=1');
check($repo->getActiveTopics() === [] && $repo->getActiveLessons() === [] && $repo->getWordsByLessonId(1) === [], 'Hidden topics filter all content');
errorIs(fn () => $service->start($user, 1), 'LESSON_NOT_FOUND');
$db->exec('UPDATE topics SET is_active=1 WHERE id=1');
$db->exec('UPDATE lessons SET is_active=0 WHERE id=1');
errorIs(fn () => $service->getLessonFlashcards(1, $user), 'LESSON_NOT_FOUND');
$db->exec('UPDATE lessons SET is_active=1 WHERE id=1');
$db->exec('UPDATE words SET is_active=0 WHERE id=1');
check(count($repo->getWordsByLessonId(1)) === 4, 'Hidden words filtered');
errorIs(fn () => $service->start($user, 1), 'LESSON_NOT_READY');
$db->exec('UPDATE words SET is_active=1 WHERE id=1');
$s = $service->start($user, 1); $id = $s['id'];
check($service->start($user, 1)['id'] === $id, 'Starting again resumes same session');
check(!str_contains(json_encode($s), 'correct_option_ids') && !str_contains(json_encode($s), 'accepted_answers'), 'Private answers absent from API state');
errorIs(fn () => $service->getSession($other, $id), 'SESSION_NOT_FOUND');
errorIs(fn () => $service->answer($other, $id, ['question_id' => $s['question']['id'], 'request_key' => str_repeat('x', 20), 'answer' => []]), 'SESSION_NOT_FOUND');
$all = $repo->questions($id);
errorIs(fn () => $service->answer($user, $id, ['question_id' => (int) $all[1]['id'], 'request_key' => bin2hex(random_bytes(16)), 'answer' => ['option_id' => 'a', 'text' => 'milk']]), 'QUESTION_OUT_OF_ORDER');
function bodyFor(array $s, PDO $db, bool $wrong = false): array {
    $stmt = $db->prepare('SELECT snapshot_json FROM session_questions WHERE id=?');
    $stmt->execute([$s['question']['id']]); $snapshot = json_decode($stmt->fetchColumn(), true);
    $a = $snapshot['answer']; $type = $snapshot['type'];
    if (isset($a['correct_option_ids'])) {
        $id = $a['correct_option_ids'][0];
        if ($wrong) foreach ($snapshot['content']['options'] as $option) { if ($option['id'] !== $id) { $id = $option['id']; break; } }
        $answer = ['option_id' => $id];
    } elseif (isset($a['accepted_answers'])) { $answer = ['text' => $wrong ? 'WRONG' : $a['accepted_answers'][0]]; }
    elseif ($type === 'token_order') { $answer = ['token_ids' => $a['accepted_sequences'][0]]; }
    else { $answer = ['pairs' => array_map(fn ($pair) => ['left_id' => $pair['left_id'], 'right_id' => $pair['right_id']], $a['pairs'])]; }
    return ['question_id' => $s['question']['id'], 'request_key' => bin2hex(random_bytes(16)), 'answer' => $answer];
}
$wrongBody = bodyFor($s, $db, true);
$r = $service->answer($user, $id, $wrongBody); $s = $r['session'];
check(!$r['is_correct'] && $s['current_hp'] === 2 && $s['answered_main'] === 1, 'Wrong answer persisted and one heart spent');
check($service->getSession($user, $id)['current_hp'] === 2, 'Reload preserves account hearts');
check($service->answer($user, $id, $wrongBody)['replayed'] && $service->getSession($user, $id)['current_hp'] === 2, 'Duplicate wrong answer spends no second heart');
$conflict = $wrongBody; $conflict['answer'] = bodyFor(['question' => ['id' => $wrongBody['question_id']]], $db)['answer'];
errorIs(fn () => $service->answer($user, $id, $conflict), 'REQUEST_KEY_CONFLICT');
while ($s['status'] === 'in_progress' && $s['question']['phase'] === 'main') { $s = $service->answer($user, $id, bodyFor($s, $db))['session']; }
check($s['question']['phase'] === 'mistake_review' && $s['correct_count'] === 9, 'Main score preserved; review before matching');
$r = $service->answer($user, $id, bodyFor($s, $db, true)); $s = $r['session'];
check($s['current_hp'] === 2 && $s['question']['phase'] === 'mistake_review', 'Wrong correction does not spend a heart');
$s = $service->answer($user, $id, bodyFor($s, $db))['session'];
check($s['question']['phase'] === 'matching' && $s['correct_count'] === 9, 'Corrected answers do not change main score');
$lastBody = bodyFor($s, $db); $s = $service->answer($user, $id, $lastBody)['session'];
check($s['status'] === 'completed' && $s['xp_earned'] === 10 && $s['stats']['streak'] === 1, 'Completed result, XP and local-day streak committed');
$service->answer($user, $id, $lastBody);
check((int) $db->query('SELECT COUNT(*) FROM xp_transactions')->fetchColumn() === 1
    && (int) $db->query('SELECT completed_lessons FROM user_daily_activity')->fetchColumn() === 1, 'Retry final answer does not duplicate rewards or activity');
$questProgress = $db->query("SELECT quest_id,progress FROM user_quests WHERE user_id=$user ORDER BY quest_id")->fetchAll();
check((int) $questProgress[0]['progress'] === 1 && (int) $questProgress[1]['progress'] === 10, 'Assigned quest progress committed once with lesson XP');
check($service->getLessonFlashcards(2, $user)['lesson']['id'] == 2, 'Next real lesson unlocked');
$db->exec("UPDATE users SET status='locked' WHERE id=$other");
errorIs(fn () => $service->start($other, 1), 'FORBIDDEN');
$db->exec("UPDATE users SET status='active', current_hp=1 WHERE id=$other");
$s2 = $service->start($other, 1); $s2 = $service->answer($other, $s2['id'], bodyFor($s2, $db, true))['session'];
check($s2['status'] === 'failed' && $s2['current_hp'] === 0, 'Zero hearts fails session');
errorIs(fn () => $service->start($other, 1), 'NO_HEARTS');
$db->exec("UPDATE users SET next_hp_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 MINUTE) WHERE id=$other");
check($service->start($other, 1)['current_hp'] === 2, 'Time-based regeneration shared by new sessions');
check($repo->completedLessons($other) === [], 'Progress isolated per user');
// Exercise the three requested UI families with database fixtures, separate from shipped content.
$db->exec("INSERT INTO topics (name,position) VALUES ('Media test',2)");
$topic = (int) $db->lastInsertId();
$db->exec("INSERT INTO lessons (topic_id,name,position,question_count,pass_percent,xp_reward) VALUES ($topic,'Media test',1,3,50,5)");
$mediaLesson = (int) $db->lastInsertId();
$db->exec("INSERT INTO lesson_words (lesson_id,word_id) VALUES ($mediaLesson,1)");
$fixture = $db->prepare('INSERT INTO exercises (lesson_id,word_id,type,content_json,answer_json) VALUES (?,1,?,?,?)');
$fixture->execute([$mediaLesson,'choose_image',json_encode(['instruction'=>'Chọn hình','prompt'=>'milk','target_word_ids'=>[1],
    'options'=>[['id'=>'a','image'=>'/assets/images/learning-test.svg'],['id'=>'b','image'=>'/assets/images/learning-test.svg']]]),'{"correct_option_ids":["a"]}']);
$fixture->execute([$mediaLesson,'listen_select',json_encode(['instruction'=>'Nghe rồi chọn','target_word_ids'=>[1],'audio'=>'/assets/audio/learning-test.wav',
    'options'=>[['id'=>'a','text'=>'milk'],['id'=>'b','text'=>'tea']]]),'{"correct_option_ids":["a"]}']);
$fixture->execute([$mediaLesson,'listen_type',json_encode(['instruction'=>'Nghe rồi nhập','target_word_ids'=>[1],'audio'=>'/assets/audio/learning-test.wav']),'{"accepted_answers":["milk"]}']);
$mediaSession = $service->start($user, $mediaLesson); $types = [];
while ($mediaSession['status'] === 'in_progress') {
    $types[] = $mediaSession['question']['type'];
    $mediaSession = $service->answer($user, $mediaSession['id'], bodyFor($mediaSession, $db))['session'];
}
sort($types);
check($types === ['choose_image','listen_select','listen_type'] && $mediaSession['status'] === 'completed', 'Image, listen-select and listen-type graded from database');
$db->exec("UPDATE users SET status='locked' WHERE id=$other");
$stmt = $db->prepare('UPDATE words SET example=?,example_translation=NULL WHERE id=1');
$stmt->execute(['</script><img src=x onerror="window.__learningXss=1">']);
$db->exec('UPDATE words SET example=NULL,example_translation=NULL WHERE id=2');
echo "Integration complete. Test accounts: a@example.test and b@example.test.\n";
