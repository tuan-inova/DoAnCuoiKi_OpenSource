<?php
declare(strict_types=1);

namespace App\Modules\Learning\Controllers;

use App\Core\{ApiException, Controller, Database, Request, Response, Session};
use App\Middleware\Auth;
use App\Modules\Learning\Repositories\LearningRepository;
use App\Modules\Learning\Services\LearningService;

final class LearningController extends Controller
{
    private function service(): LearningService
    {
        return new LearningService(new LearningRepository(Database::connection()));
    }

    private function id(array $params): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) { throw new ApiException('VALIDATION_FAILED', 'ID không hợp lệ.', 422); }
        return $id;
    }

    private function viewData(): array
    {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return ['basePath' => $base, 'csrfToken' => Session::csrfToken()];
    }

    public function index(array $params = []): void
    {
        $user = Auth::user();
        $this->render('learning/index', $this->service()->getLearningPathData($user) + $this->viewData());
    }

    public function lesson(array $params): void
    {
        $user = Auth::user();
        $this->render('learning/flashcard', $this->service()->getLessonFlashcards($this->id($params), (int) $user['id']) + $this->viewData());
    }

    public function start(array $params): void
    {
        $user = Auth::user(); Session::verifyCsrf();
        Response::data($this->service()->start((int) $user['id'], $this->id($params)));
    }

    public function session(array $params): void
    {
        $user = Auth::user();
        Response::data($this->service()->getSession((int) $user['id'], $this->id($params)));
    }

    public function answer(array $params): void
    {
        $user = Auth::user(); Session::verifyCsrf();
        Response::data($this->service()->answer((int) $user['id'], $this->id($params), Request::json()));
    }
}
