<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once __DIR__ . '/ReviewTestDatabase.php';

use App\Modules\Reviews\Services\ReviewModuleFactory;
use App\Modules\Reviews\Requests\ReviewException;
use App\Modules\Reviews\Controllers\ReviewController;

$pdo = ReviewTestDatabase::connect(
    ReviewTestDatabase::configuration()
);

$services = ReviewModuleFactory::fromPdo($pdo);
$users = [];
$checks = 0;
$failed = false;

function noticeCheck(bool $condition, string $message): void
{
    global $checks;

    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }

    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}

try {
    // Tao hai tai khoan thu rieng.
    foreach (['Asia/Ho_Chi_Minh', 'UTC'] as $zone) {
        $query = $pdo->prepare(
            'INSERT INTO users (name,email,password_hash,timezone)
             VALUES (?,?,?,?)'
        );

        $query->execute([
            'Reminder integration',
            'notice-' . bin2hex(random_bytes(10)) . '@example.invalid',
            password_hash(
                bin2hex(random_bytes(16)),
                PASSWORD_DEFAULT
            ),
            $zone,
        ]);

        $users[] = (int) $pdo->lastInsertId();
    }

    [$u1, $u2] = $users;

    // Thoi gian gia lap, giu nguyen khi chay test.
    $now = new DateTimeImmutable('2030-01-02T17:01:00Z');

    // 1. Khong nhac khi chua co the den han.
    $none = $services['reminders']->ensureDaily($u1, $now);

    noticeCheck(
        !$none['created'] && $none['notification_id'] === null,
        'No reminder without due cards'
    );

    // Tao mot the da hoc va da den han.
    $services['initializer']->initialize(
        $u1,
        [1],
        ['en_vi'],
        $now->sub(new DateInterval('P2D'))
    );

    // 2. Cung ngay chi tao mot thong bao.
    $first = $services['reminders']->ensureDaily($u1, $now);
    $retry = $services['reminders']->ensureDaily($u1, $now);

    noticeCheck(
        $first['created']
            && !$retry['created']
            && $first['notification_id'] === $retry['notification_id'],
        'One reminder per local day'
    );

    // 3. Ngay nhac dua theo mui gio tai khoan.
    $query = $pdo->prepare(
        'SELECT dedupe_key FROM notifications WHERE id=?'
    );
    $query->execute([$first['notification_id']]);

    noticeCheck(
        $query->fetchColumn()
            === 'review_due:u' . $u1 . ':2030-01-03',
        'Reminder uses account timezone'
    );

    // 4. Sang ngay moi co the nhac tiep.
    $nextDay = $services['reminders']->ensureDaily(
        $u1,
        $now->add(new DateInterval('P1D'))
    );

    noticeCheck(
        $nextDay['created']
            && $nextDay['notification_id'] !== $first['notification_id'],
        'New local day may remind again'
    );

    // 5. Thong bao ngay mai chua hien o thoi diem hom nay.
    $inbox = $services['reminders']->inbox(
        $u1, 20, null, $now
    );

    noticeCheck(
        count($inbox['items']) === 1,
        'Future scheduled notification is hidden'
    );

    // 6. User khac khong thay thong bao cua user thu nhat.
    $otherInbox = $services['reminders']->inbox(
        $u2, 20, null, $now
    );

    noticeCheck(
        $otherInbox['items'] === [],
        'Inbox belongs to signed-in user'
    );

    // 7. Khong danh dau da doc thong bao cua nguoi khac.
    try {
        $services['reminders']->markRead(
            $u2,
            $first['notification_id'],
            $now
        );

        throw new RuntimeException('Cross-user read was accepted.');
    } catch (ReviewException $error) {
        noticeCheck(
            $error->errorCode === 'NOT_FOUND',
            'Cannot mark another user notification read'
        );
    }

    // 8. Danh dau da doc nhieu lan giu nguyen moc dau tien.
    $read = $services['reminders']->markRead(
        $u1,
        $first['notification_id'],
        $now
    );

    $readAgain = $services['reminders']->markRead(
        $u1,
        $first['notification_id'],
        $now->add(new DateInterval('PT1M'))
    );

    noticeCheck(
        $read === $readAgain,
        'Mark read preserves first timestamp'
    );

    $controller = new ReviewController($services);

    // 9. Tao thong bao qua API can CSRF hop le.
    $response = $controller->dispatch(
        'POST',
        '/api/v1/notifications/review-due',
        [],
        [],
        $u1,
        'expected',
        'wrong'
    );

    noticeCheck(
        $response['status'] === 403,
        'Notification mutations require CSRF'
    );

    // 10. Client khong duoc tu chon user_id.
    $response = $controller->dispatch(
        'GET',
        '/api/v1/notifications',
        ['user_id' => $u1],
        [],
        $u2,
        'expected',
        ''
    );

    noticeCheck(
        $response['status'] === 422,
        'Client cannot select notification owner'
    );

    // 11. Tai khoan bi khoa khong duoc doc thong bao.
    $pdo->prepare(
        "UPDATE users SET status='locked' WHERE id=?"
    )->execute([$u2]);

    $response = $controller->dispatch(
        'GET',
        '/api/v1/notifications',
        [],
        [],
        $u2,
        'expected',
        ''
    );

    noticeCheck(
        $response['status'] === 403,
        'Locked account cannot read inbox'
    );
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
} finally {
    // Chi xoa du lieu cua hai tai khoan thu vua tao.
    foreach ($users as $userId) {
        foreach (['notifications', 'user_review_states'] as $table) {
            $pdo->prepare(
                'DELETE FROM ' . $table . ' WHERE user_id=?'
            )->execute([$userId]);
        }

        $pdo->prepare(
            'DELETE FROM users WHERE id=?'
        )->execute([$userId]);
    }
}

echo 'Notification checks=' . $checks
    . ' result=' . ($failed ? 'FAIL' : 'PASS')
    . PHP_EOL;

exit($failed ? 1 : 0);