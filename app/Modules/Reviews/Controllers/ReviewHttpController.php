<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Controllers;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\Auth;
use App\Modules\Reviews\Services\ReviewModuleFactory;

final class ReviewHttpController
{
    private function csrfToken(): string
    {
        Session::start();

        if (
            !isset($_SESSION['csrf_token'])
            || !is_string($_SESSION['csrf_token'])
            || $_SESSION['csrf_token'] === ''
        ) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    // Hien thi trang on sau khi dang nhap.
    public function index(array $params): void
    {
        Auth::user();
        $this->csrfToken();

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');

        $reviewApiBase = rtrim(
            str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')),
            '/.'
        );

        $projectRoot = dirname(__DIR__, 4);

        require $projectRoot . '/resources/views/reviews/index.php';
    }

    // Chuyen request HTTP sang controller nghiep vu cua Tuan.
    public function api(string $path): void
    {
        $user = Auth::user();
        $csrf = $this->csrfToken();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $body = [];

        if ($method === 'POST') {
            $raw = file_get_contents(
                'php://input',
                false,
                null,
                0,
                16385
            );

            if ($raw === false) {
                throw new ApiException(
                    'INVALID_JSON',
                    'Cannot read request body',
                    400
                );
            }

            if (strlen($raw) > 16384) {
                throw new ApiException(
                    'PAYLOAD_TOO_LARGE',
                    'Request body is too large',
                    413
                );
            }

            try {
                $object = json_decode(
                    $raw,
                    false,
                    64,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $error) {
                throw new ApiException(
                    'INVALID_JSON',
                    'Request body must be valid JSON',
                    400
                );
            }

            if (!$object instanceof \stdClass) {
                throw new ApiException(
                    'VALIDATION_FAILED',
                    'Request body must be a JSON object',
                    422
                );
            }

            $body = json_decode(
                $raw,
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        }

        $services = ReviewModuleFactory::fromPdo(
            Database::connection()
        );

        $controller = new ReviewController($services);

        $reply = $controller->dispatch(
            $method,
            $path,
            $_GET,
            $body,
            (int) $user['id'],
            $csrf,
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''
        );

        foreach ($reply['headers'] ?? [] as $name => $value) {
            header($name . ': ' . $value);
        }

        Response::json($reply['body'], $reply['status']);
    }
}