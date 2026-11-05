<?php

declare(strict_types=1);

/**
 * Ket noi database kiem thu rieng.
 * Doc cau hinh tu bien moi truong.
 */
final class ReviewTestDatabase
{
    public static function configuration(): array
    {
        return [
            'database' => [
                'host' => getenv('SM2_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('SM2_TEST_DB_PORT') ?: '3306',
                'name' => getenv('SM2_TEST_DB_NAME') ?: 'doulingo_sm2_test',
                'username' => getenv('SM2_TEST_DB_USER') ?: 'root',
                'password' => getenv('SM2_TEST_DB_PASSWORD') ?: '',
            ],
        ];
    }

    public static function connect(array $config): PDO
    {
        $db = $config['database'] ?? [];

        // Kiem tra cau hinh truoc khi mo ket noi.
        // Ten database bat buoc ket thuc bang _sm2_test.
        if (
            !preg_match(
                '/^[a-zA-Z0-9_]+_sm2_test$/D',
                $db['name'] ?? ''
            )
            || !preg_match(
                '/^[a-zA-Z0-9_.:-]+$/D',
                $db['host'] ?? ''
            )
            || filter_var(
                $db['port'] ?? null,
                FILTER_VALIDATE_INT
            ) === false
            || (int)$db['port'] < 1
            || (int)$db['port'] > 65535
        ) {
            throw new RuntimeException(
                'Refuse to run: use a separate database ending '
                . '_sm2_test and valid host/port.'
            );
        }

        $dsn = 'mysql:host=' . $db['host']
            . ';port=' . $db['port']
            . ';dbname=' . $db['name']
            . ';charset=utf8mb4';

        $pdo = new PDO(
            $dsn,
            $db['username'],
            $db['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );

        // Thong nhat thoi gian luu trong database la UTC.
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}