<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__, 2) . '/bootstrap/app.php';

use App\Modules\Reviews\Requests\ReviewRequest;
use App\Modules\Reviews\Requests\ReviewException;
use App\Modules\Reviews\Services\ReviewSupport;

$checks = 0;

function requestCheck(bool $condition, string $message): void
{
    global $checks;

    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }

    $checks++;
}

function requestError(callable $operation, string $expectedCode): void
{
    try {
        $operation();
    } catch (ReviewException $error) {
        requestCheck(
            $error->errorCode === $expectedCode,
            'Expected error: ' . $expectedCode
        );
        return;
    }

    throw new RuntimeException(
        'Expected exception: ' . $expectedCode
    );
}

// 1. Query HTTP duoc phep truyen so nguyen dang chuoi.
requestCheck(
    ReviewRequest::integer('20', 1, 100) === 20,
    'Accept integer query string'
);

// 2. Tu choi sai kieu hoac ngoai khoang cho phep.
foreach ([true, false, 1.0, 1.5, [], '1e2', '5.0', 0, 101] as $bad) {
    requestError(
        function () use ($bad): void {
            ReviewRequest::integer($bad, 1, 100);
        },
        'VALIDATION_ERROR'
    );
}

// 3. Tu choi CSRF token rong hoac khong khop.
foreach (['', 'wrong'] as $token) {
    requestError(
        function () use ($token): void {
            ReviewRequest::csrf('correct', $token);
        },
        'CSRF_INVALID'
    );
}

// 4. Chap nhan CSRF token khop.
ReviewRequest::csrf('correct', 'correct');
requestCheck(true, 'Accept matching CSRF token');

// 5. Bo loc rong duoc xem la khong loc.
requestCheck(
    ReviewRequest::optionalString(
        ['direction' => ''],
        'direction'
    ) === null,
    'Empty filter becomes null'
);

// 6. Bo loc phai la chuoi, khong duoc la mang.
requestError(
    function (): void {
        ReviewRequest::optionalString(
            ['direction' => ['en_vi']],
            'direction'
        );
    },
    'VALIDATION_ERROR'
);

// 7. UUID hop le duoc chuan hoa thanh chu thuong.
$upper = 'ABCDEF01-1234-4567-89AB-123456789ABC';

requestCheck(
    ReviewSupport::uuid($upper) === strtolower($upper),
    'Normalize UUID to lowercase'
);

// 8. Tu choi request_key khong phai UUID hop le.
foreach (['', 'not-a-key', 7, true] as $key) {
    requestError(
        function () use ($key): void {
            ReviewSupport::uuid($key);
        },
        'VALIDATION_ERROR'
    );
}

// 9. Tu choi truong ngoai hop dong.
// User ID phai lay tu phien dang nhap, khong lay tu client.
requestError(
    function (): void {
        ReviewSupport::fields(
            ['quality' => 4, 'user_id' => 99],
            ['quality']
        );
    },
    'VALIDATION_ERROR'
);

echo 'PASS: ' . $checks . ' request checks.' . PHP_EOL;