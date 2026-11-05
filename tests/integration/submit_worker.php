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

try {
    // File du lieu cong viec do bai kiem thu chinh truyen vao.
    $job = json_decode(
        (string) file_get_contents($argv[1]),
        true,
        64,
        JSON_THROW_ON_ERROR
    );

    $config = ReviewTestDatabase::configuration();

    if (!preg_match('/_sm2_test$/D', $config['database']['name'])) {
        throw new RuntimeException('Test DB guard.');
    }

    $pdo = ReviewTestDatabase::connect($config);
    $services = ReviewModuleFactory::fromPdo($pdo);

    // Mo phong mot thiet bi gui ket qua on.
    $result = $services['reviews']->submit(
        (int) $job['user_id'],
        $job['body'],
        new DateTimeImmutable($job['at'])
    );

    echo json_encode(
        [
            'status' => 200,
            'data' => $result,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (ReviewException $error) {
    // Loi nghiep vu, vi du luot on da cu tren thiet bi khac.
    echo json_encode([
        'status' => $error->httpStatus,
        'code' => $error->errorCode,
    ]);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);

    echo json_encode([
        'status' => 500,
        'code' => 'WORKER_FAILED',
    ]);

    exit(1);
}