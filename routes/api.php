<?php
declare(strict_types=1);

use App\Core\Response;
use App\Core\Router;
use App\Modules\Vocabulary\Controllers\DictionaryController;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Users\Controllers\UsersController;
use App\Modules\Learning\Controllers\LearningController;

return static function (Router $router): void {
    $router->get('/api/v1/health', static function (array $params): void {
        Response::data(['status' => 'ok', 'database' => 'not_configured']);
    });

    $router->post('/api/v1/learning/lessons/{id}/sessions', [LearningController::class, 'start']);
    $router->get('/api/v1/learning/sessions/{id}', [LearningController::class, 'session']);
    $router->post('/api/v1/learning/sessions/{id}/answers', [LearningController::class, 'answer']);

    // Auth 
    $router->post('/api/v1/auth/register', [AuthController::class, 'register']);
    $router->post('/api/v1/auth/login', [AuthController::class, 'login']);
    $router->post('/api/v1/auth/logout', [AuthController::class, 'logout']);

    // Users
    $router->get('/api/v1/users/me', [UsersController::class, 'me']);
    $router->patch('/api/v1/users/me', [UsersController::class, 'updateMe']);
    $router->patch('/api/v1/users/{id}/status', [UsersController::class, 'updateStatus']);

    // TOÀN: tra từ để Admin chuẩn bị nội dung học.
    $router->get('/api/v1/admin/dictionary',[DictionaryController::class, 'lookup']);

        // API on tap va nhac hoc.
    $reviewRoutes = [
        'GET' => [
            '/api/v1/reviews/bootstrap',
            '/api/v1/reviews/due',
            '/api/v1/reviews/history',
            '/api/v1/notifications',
        ],
        'POST' => [
            '/api/v1/reviews/start',
            '/api/v1/reviews/submit',
            '/api/v1/notifications/review-due',
            '/api/v1/notifications/{id}/read',
        ],
    ];

    foreach ($reviewRoutes as $method => $paths) {
        foreach ($paths as $path) {
            $register = strtolower($method);

            $router->$register(
                $path,
                static function (array $params) use ($path): void {
                    $resolvedPath = str_replace(
                        '{id}',
                        $params['id'] ?? '',
                        $path
                    );

                    $controller = new
                        \App\Modules\Reviews\Controllers\ReviewHttpController();

                    $controller->api($resolvedPath);
                }
            );
        }
    }
};