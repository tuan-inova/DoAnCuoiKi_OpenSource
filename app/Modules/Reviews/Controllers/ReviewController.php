<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Controllers;

use App\Modules\Notifications\Controllers\NotificationController;
use App\Modules\Reviews\Requests\ReviewException;
use App\Modules\Reviews\Requests\ReviewRequest;
use App\Modules\Reviews\Services\ReviewSupport;

/** Returns an HTTP response array; Toan's router supplies authenticated session identity. */
final class ReviewController
{
    private $services;

    public function __construct(array $services)
    {
        $this->services = $services;
    }

    public function dispatch(
        string $method,
        string $path,
        array $query,
        array $body,
        int $userId,
        string $expectedCsrf,
        string $receivedCsrf
    ): array {
        try {
            if ($userId < 1) {
                throw new ReviewException('UNAUTHENTICATED', 'Bạn cần đăng nhập bằng tài khoản của dự án.', 401);
            }
            $profile = $this->services['reviews']->profile($userId);
            if (array_key_exists('user_id', $query) || array_key_exists('user_id', $body)) {
                throw new ReviewException('VALIDATION_ERROR', 'Không nhận user_id từ client.');
            }
            $paths = [
                '/api/v1/reviews/bootstrap' => 'GET',
                '/api/v1/reviews/due' => 'GET',
                '/api/v1/reviews/history' => 'GET',
                '/api/v1/reviews/start' => 'POST',
                '/api/v1/reviews/submit' => 'POST',
                '/api/v1/notifications' => 'GET',
                '/api/v1/notifications/review-due' => 'POST',
            ];
            $readId = null;
            if (preg_match('~^/api/v1/notifications/([1-9][0-9]*)/read$~D', $path, $match)) {
                $readId = ReviewRequest::integer($match[1], 1, PHP_INT_MAX);
                $requiredMethod = 'POST';
            } else {
                $requiredMethod = $paths[$path] ?? null;
            }
            if ($requiredMethod === null) {
                throw new ReviewException('NOT_FOUND', 'Không tìm thấy route.', 404);
            }
            if ($method !== $requiredMethod) {
                $response = self::error(new ReviewException('METHOD_NOT_ALLOWED', 'Sai phương thức HTTP.', 405));
                $response['headers']['Allow'] = $requiredMethod;
                return $response;
            }
            if ($method === 'POST') {
                ReviewRequest::csrf($expectedCsrf, $receivedCsrf);
            }
            if (strpos($path, '/api/v1/notifications') === 0) {
                $value = (new NotificationController($this->services['reminders']))->handle($path, $query, $body, $userId, $readId);
            } else {
                switch ($path) {
                    case '/api/v1/reviews/bootstrap':
                        $value = [
                            'user' => $profile,
                            'csrf_token' => $expectedCsrf,
                            'directions' => ReviewSupport::DIRECTIONS,
                            'server_now' => ReviewSupport::utc()->format('Y-m-d\TH:i:s\Z'),
                        ];
                        break;
                    case '/api/v1/reviews/due':
                        $value = $this->services['reviews']->due(
                            $userId,
                            ReviewRequest::integer($query['limit'] ?? 20, 1, 100),
                            ReviewRequest::optionalString($query, 'cursor'),
                            ReviewRequest::optionalString($query, 'direction')
                        );
                        break;
                    case '/api/v1/reviews/history':
                        $value = $this->services['reviews']->history(
                            $userId,
                            ReviewRequest::integer($query['limit'] ?? 20, 1, 100),
                            isset($query['before_id']) ? ReviewRequest::integer($query['before_id'], 1, PHP_INT_MAX) : null
                        );
                        break;
                    case '/api/v1/reviews/start':
                        $value = $this->services['reviews']->start($userId, $body);
                        break;
                    default:
                        $value = $this->services['reviews']->submit($userId, $body);
                        break;
                }
            }
            return ['status' => 200, 'headers' => ['Cache-Control' => 'no-store'], 'body' => ['data' => $value]];
        } catch (ReviewException $error) {
            return self::error($error);
        } catch (\PDOException $error) {
            if (in_array((int)($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
                return self::error(new ReviewException('BUSY_RETRY', 'Dữ liệu đang bận. Thử lại cùng request_key.', 503));
            }
            // The application's exception handler logs details and sends a generic 500 response.
            throw $error;
        }
    }

    public static function error(ReviewException $error): array
    {
        return [
            'status' => $error->httpStatus,
            'headers' => ['Cache-Control' => 'no-store'],
            'body' => ['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]],
        ];
    }
}
