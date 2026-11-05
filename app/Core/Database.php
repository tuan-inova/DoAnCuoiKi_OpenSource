<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $config = require dirname(__DIR__, 2) . '/config/database.php';
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'], $config['port'], $config['database'], $config['charset']);
            $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            $pdo->exec("SET time_zone = '+00:00'");
            self::$connection = $pdo;
        }
        return self::$connection;
    }
}
