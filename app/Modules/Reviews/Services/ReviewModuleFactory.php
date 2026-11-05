<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Services;

use App\Modules\Reviews\Repositories\SchemaGuard;
use App\Modules\Notifications\Services\ReviewReminderService;

final class ReviewModuleFactory
{
    /** Use the PDO supplied by Toan's shared database service. Does not read or create .env. */
    public static function fromPdo(\PDO $pdo, bool $checkSchema = true): array
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->inTransaction()) {
            throw new \LogicException('Supply a MySQL/MariaDB PDO outside a transaction.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec("SET time_zone = '+00:00'");
        if ($checkSchema) {
            SchemaGuard::check($pdo);
        }
        return [
            'reviews' => new ReviewService($pdo),
            'initializer' => new ReviewStateInitializer($pdo),
            'reminders' => new ReviewReminderService($pdo),
        ];
    }
}
