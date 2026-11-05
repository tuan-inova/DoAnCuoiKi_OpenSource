<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__, 2) . '/bootstrap/app.php';

use App\Modules\Reviews\Services\ReviewSupport;
use App\Modules\Reviews\Requests\ReviewException;

$checks = 0;

function supportCheck(bool $condition, string $message): void
{
    global $checks;

    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }

    $checks++;
}

// 1. Chap nhan bon chieu on duoc ho tro.
foreach (ReviewSupport::DIRECTIONS as $direction) {
    supportCheck(
        ReviewSupport::direction($direction) === $direction,
        'Valid direction: ' . $direction
    );
}

// 2. Tu choi muc danh gia sai kieu hoac ngoai 0-5.
foreach ([-1, 6, 1.5, '4', null, true] as $quality) {
    try {
        ReviewSupport::quality($quality);
        supportCheck(false, 'Invalid quality was accepted');
    } catch (ReviewException $error) {
        supportCheck(
            $error->httpStatus === 422,
            'Reject invalid quality'
        );
    }
}

// Trang thai gia lap, khong doc/ghi database.
$row = [
    'id' => 1,
    'user_id' => 2,
    'word_id' => 3,
    'direction' => 'en_vi',
    'first_learned_at' => '2030-01-01 00:00:00',
    'ease_factor' => '2.500',
    'interval_days' => 1,
    'repetitions' => 1,
    'last_reviewed_at' => '2030-01-02 00:00:00',
    'next_review_at' => '2030-01-03 00:00:00',
];

// 3. EF dang chuoi tu PDO va dang so phai cho cung fingerprint.
$same = $row;
$same['ease_factor'] = 2.5;

supportCheck(
    ReviewSupport::fingerprint($row, 5)
        === ReviewSupport::fingerprint($same, 5),
    'Normalize decimal string and number'
);

// 4. Event moi phai lam fingerprint thay doi.
supportCheck(
    ReviewSupport::fingerprint($row, 5)
        !== ReviewSupport::fingerprint($row, 6),
    'New review event changes fingerprint'
);

// 5. Doi ngay on phai lam fingerprint thay doi.
$changed = $row;
$changed['next_review_at'] = '2030-01-04 00:00:00';

supportCheck(
    ReviewSupport::fingerprint($row, 5)
        !== ReviewSupport::fingerprint($changed, 5),
    'Schedule change changes fingerprint'
);

// 6. Chuyen trang thai database thanh du lieu tra ve API.
$publicState = ReviewSupport::publicState($row);

supportCheck(
    $publicState['ease_factor'] === 2.5
        && $publicState['next_review_at'] === '2030-01-03T00:00:00Z',
    'Public state has correct types and UTC date'
);

// 7. Gio UTC 17:01 tuong ung ngay hom sau tai Viet Nam.
$localDate = ReviewSupport::localDate(
    ['timezone' => 'Asia/Ho_Chi_Minh'],
    new DateTimeImmutable('2030-01-02T17:01:00Z')
);

supportCheck(
    $localDate === '2030-01-03',
    'Activity date follows user timezone'
);

// 8. Snapshot phai la JSON object, khong phai JSON array.
try {
    ReviewSupport::decode('[]');
    supportCheck(false, 'Array snapshot was accepted');
} catch (ReviewException $error) {
    supportCheck(true, 'Reject array snapshot');
}

// 9. Tu choi truong ngoai hop dong, nhu user_id tu client.
try {
    ReviewSupport::fields(
        ['quality' => 4, 'user_id' => 99],
        ['quality']
    );

    supportCheck(false, 'Extra field was accepted');
} catch (ReviewException $error) {
    supportCheck(true, 'Reject extra fields');
}

echo 'PASS: ' . $checks . ' support checks.' . PHP_EOL;