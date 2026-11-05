<?php
declare(strict_types=1);

use App\Core\Response;
use App\Core\Router;

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
require dirname(__DIR__) . '/bootstrap/app.php';

// Buffer output so a failed render cannot send a partial success page.
ob_start();
try {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $relative = substr((string) $path, strlen($base));
    if ($relative === '' || $relative === '/') {
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__) . '/resources/views/home.php';
    } else {
        $router = new Router();
        (require dirname(__DIR__) . '/routes/web.php')($router);
        (require dirname(__DIR__) . '/routes/api.php')($router);
        $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $relative);
    }
    ob_end_flush();
} catch (\Throwable $e) {
    ob_end_clean();
    error_log((string) $e);
    Response::error('INTERNAL_ERROR', 'Internal server error', 500);
}
