<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Learning\Controllers\LearningController;

return static function (Router $router): void {
    $router->get('/learning', [LearningController::class, 'index']);
    $router->get('/learning/lesson/{id}', [LearningController::class, 'lesson']);
}; 