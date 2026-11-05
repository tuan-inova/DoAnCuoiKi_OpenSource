<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{method: string, pattern: string, handler: callable|array}> */
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function patch(string $path, callable|array $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, callable|array $handler): void
    {
        $pattern = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path) . '$#';
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        $path = '/' . trim($path, '/');
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $pathMatched = true;
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->invoke($route['handler'], $params);
            return;
        }

        if ($pathMatched) {
            Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
        }
        Response::error('NOT_FOUND', 'Route not found', 404);
    }

    private function invoke(callable|array $handler, array $params): void
    {
        try {
            if (is_array($handler) && !is_callable($handler)) {
                [$class, $action] = $handler;
                if (!is_string($class) || !class_exists($class) || !method_exists($class, $action)) {
                    throw new ApiException('INTERNAL_ERROR', 'Handler not found', 500);
                }
                $handler = [new $class(), $action];
            }

            $handler($params);
        } catch (ApiException $e) {
            if (ob_get_level() > 0) { ob_clean(); }
            Response::error($e->errorCode, $e->getMessage(), $e->status, $e->details);
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) { ob_clean(); }
            error_log((string) $e);
            Response::error('INTERNAL_ERROR', 'Internal server error', 500);
        }
    }
} 