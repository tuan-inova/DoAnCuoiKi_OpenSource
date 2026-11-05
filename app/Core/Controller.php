<?php
declare(strict_types=1);

namespace App\Core;

class Controller {
    /**
     * Render một view giao diện kèm theo dữ liệu
     */
    protected function render(string $view, array $data = []): void {
        // Giải nén mảng dữ liệu thành các biến để sử dụng trong View
        extract($data);

        // Đường dẫn tới thư mục chứa views dựa trên cấu trúc dự án
        $viewFile = dirname(__DIR__, 2) . '/resources/views/' . $view . '.php';

        if (!file_exists($viewFile)) {
            throw new \Exception("Không tìm thấy file giao diện (View): {$view}.php tại đường dẫn: {$viewFile}");
        }

        require_once $viewFile;
    }
} 