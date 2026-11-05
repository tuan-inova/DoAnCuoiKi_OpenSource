<?php
declare(strict_types=1);

$envPath = dirname(__DIR__) . '/.env';
$env = [];

if (is_file($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        } 
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\"'");
    }
}

$get = static fn (string $key, string $default = ''): string => (($value = getenv($key)) !== false ? $value : ($env[$key] ?? $default));

return [
    'host' => $get('DB_HOST', '127.0.0.1'),
    'port' => (int) $get('DB_PORT', '3306'),
    'database' => $get('DB_DATABASE'),
    'username' => $get('DB_USERNAME'),
    'password' => $get('DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
];