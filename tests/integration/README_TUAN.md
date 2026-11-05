# Phần công việc Hà Văn Tuấn - 65134070

- Nhánh làm việc: Tuan.
- Phụ trách: SM-2, ôn tập, nhắc học và kiểm thử liên quan.
- Database: sử dụng schema 18 bảng của nhóm trưởng.
- Database kiểm thử riêng: doulingo_sm2_test.

## 1. Công việc đã viết

| Thư mục | Nội dung |
|---|---|
| app/Modules/Reviews/ | Thuật toán SM-2, trạng thái ôn riêng, danh sách đến hạn, phiên ôn, lưu kết quả, lịch sử |
| app/Modules/Notifications/ | Nhắc học trong ứng dụng, chống nhắc trùng, danh sách thông báo, đánh dấu đã đọc |
| resources/views/reviews/ | Giao diện danh sách ôn, flashcard tự đánh giá, lịch sử và thông báo |
| tests/unit/ | Kiểm thử thuật toán, dữ liệu hỗ trợ và đầu vào |
| tests/integration/ | Kiểm thử database, gửi trùng, nhiều thiết bị và rollback |

Không sửa Core, Auth, Users, bootstrap, config, routes, public hoặc database của nhóm.

## 2. Quy tắc SM-2

- EF ban đầu: 2.500; EF tối thiểu: 1.300.
- quality là số nguyên từ 0 đến 5.
- quality < 3: reset repetitions về 0, khoảng ôn 1 ngày.
- Nhớ đúng lần đầu: khoảng ôn 1 ngày.
- Nhớ đúng lần hai: khoảng ôn 6 ngày.
- Từ lần thứ ba: ceil(khoảng ôn cũ × EF cũ).
- Sau đó cập nhật EF theo công thức SM-2.
- Ngày ôn mới tính từ thời điểm thực sự ôn, kể cả ôn trễ.
- Database lưu thời gian UTC.
- Ngày hoạt động và ngày nhắc lấy theo timezone của tài khoản.

Mỗi user/từ/chiều ôn có trạng thái riêng.
Học lại từ đã học không reset lịch cũ.

## 3. Phạm vi luồng ôn hiện tại

V1 dùng flashcard tự đánh giá mức nhớ 0–5.

Client gửi mức nhớ; backend tự tính EF, khoảng ôn và ngày ôn.
Đây chưa phải chức năng chấm đáp án trò chơi tự động.

Không nhận user_id, EF, ngày ôn hoặc điểm XP do client tự chọn.
Không tự cộng XP, thay HP, tiến độ quest hoặc xét streak.
Giữ nguyên các bộ đếm Learning đã ghi.

Hỗ trợ en_vi, vi_en, listening và spelling.
Không khởi tạo thẻ listening khi từ chưa có audio.

## 4. Các API cần Toàn nối vào router

Tất cả API nghiệp vụ cần phiên đăng nhập hợp lệ.
Mọi POST cần header X-CSRF-Token khớp token trong phiên.

| Method | Path | Dữ liệu |
|---|---|---|
| GET | /api/v1/reviews/bootstrap | Thông tin user, CSRF, chiều ôn |
| GET | /api/v1/reviews/due | limit, cursor?, direction? |
| GET | /api/v1/reviews/history | limit, before_id? |
| POST | /api/v1/reviews/start | review_state_id, request_key |
| POST | /api/v1/reviews/submit | session_question_id, request_key, quality |
| GET | /api/v1/notifications | limit, before_id? |
| POST | /api/v1/notifications/review-due | {} |
| POST | /api/v1/notifications/{id}/read | {} |

Response thành công: {"data": ...}
Response lỗi: {"error": {"code": "...", "message": "..."}}

Các ID trong JSON phải là số nguyên.
request_key là UUID; start và submit dùng hai key riêng.
Khi retry phải giữ nguyên key và nội dung thao tác.

## 5. Điểm nối với Toàn

Sử dụng PDO và Auth chung của dự án:

```php
use App\Modules\Reviews\Services\ReviewModuleFactory;
use App\Modules\Reviews\Controllers\ReviewController;

$services = ReviewModuleFactory::fromPdo($pdo);
$controller = new ReviewController($services);
```

Factory được gọi ngoài transaction.
PDO cần kết nối MySQL/MariaDB với charset utf8mb4.

Router truyền method, đường dẫn API đã bỏ base path, query,
JSON body, user ID lấy từ Auth và CSRF vào:

```php
$reply = $controller->dispatch(
    $method,
    $path,
    $query,
    $jsonBody,
    $authenticatedUserId,
    $sessionCsrfToken,
    $requestCsrfHeader
);
```

Router xuất HTTP status, headers và JSON body từ $reply.
Router kiểm tra JSON hợp lệ, giới hạn kích thước body và xử lý lỗi 500 chung.
Không lấy authenticatedUserId từ query hoặc JSON của client.

Đăng ký GET /reviews sau Auth và render:

```php
$reviewApiBase = '/doulingo/public';
require $projectRoot . '/resources/views/reviews/index.php';
```

Apache trên máy Tuấn dùng cổng 8081.
Cổng Apache không thay thế cổng kết nối MySQL.

Các route chưa tự được đăng ký chỉ bằng việc thêm module.

## 6. Điểm nối với Danh

Sau khi backend xác minh bài học hoàn thành, gọi hook trong
chính transaction lưu kết quả bài học:

```php
use App\Modules\Reviews\Services\LessonReviewHook;

LessonReviewHook::afterLessonCompleted(
    $pdo,
    $userId,
    $learnedWordIds,
    $completedAtUtc,
    ['en_vi']
);
```

- Transaction Learning phải khóa users row đầu tiên.
- learnedWordIds lấy từ snapshot bài học phía server.
- completedAtUtc là thời gian do server xác định.
- Hook không tự commit.
- Learning commit sau khi toàn bộ bước thành công; lỗi thì rollback.

Danh có thể cung cấp renderer dùng chung qua:

```javascript
window.DoulingoReviewCard = ({
    word,
    direction,
    revealed,
    escape
}) => {
    // Trả HTML từ component chung và escape nội dung dữ liệu.
};
```

Nếu chưa có renderer, giao diện sử dụng flashcard chữ đơn giản.
Không triển khai lại bộ trò chơi của Danh.

## 7. Nhắc học

- Chỉ nhắc trong ứng dụng.
- Tạo thông báo khi mở tab Nhắc học và có thẻ đến hạn.
- Mỗi user/ngày địa phương tối đa một thông báo review_due.
- sent_at nghĩa là đã công bố vào hộp thông báo.
- Chưa gửi email và chưa cài tác vụ chạy nền.
- Chỉ user sở hữu được đánh dấu thông báo đã đọc.

## 8. Kiểm thử trên XAMPP

Chạy tại thư mục gốc dự án:

```powershell
& C:\xampp\php\php.exe tests\unit\Sm2SchedulerTest.php
& C:\xampp\php\php.exe tests\unit\ReviewSupportTest.php
& C:\xampp\php\php.exe tests\unit\ReviewRequestTest.php
```

Số kiểm tra mong đợi lần lượt: 112, 17, 21.

Đã tạo DB thử doulingo_sm2_test và nhập
database/samples/doulingo_initial.sql một lần.

Cấu hình terminal:

```powershell
$env:SM2_TEST_DB_HOST = '127.0.0.1'
$env:SM2_TEST_DB_PORT = '3306'
$env:SM2_TEST_DB_NAME = 'doulingo_sm2_test'
$env:SM2_TEST_DB_USER = 'root'
$env:SM2_TEST_DB_PASSWORD = ''
```

Điều chỉnh cổng/tài khoản theo MySQL thực tế.
Không ghi mật khẩu thật vào file được commit.

Chạy kiểm thử database:

```powershell
& C:\xampp\php\php.exe tests\integration\ReviewDatabaseTest.php
& C:\xampp\php\php.exe tests\integration\NotificationDatabaseTest.php
```

Kết quả Reviews đã xác nhận trên máy Tuấn:

Integration checks=25 skips=0 result=PASS.

Kết quả Notifications mong đợi:

Notification checks=11 result=PASS

Cần lưu kết quả thực tế của từng lần chạy vào mô tả PR.
Kiểm thử database chưa thay thế việc kiểm thử toàn luồng web
với Auth, Router và Learning thật.

## 9. Chia PR theo chức năng

1. Tuan - Hoàn thiện chức năng tính lịch SM-2
2. Tuan - Hoàn thiện chức năng ôn tập và lưu lịch cá nhân
3. Tuan - Hoàn thiện chức năng nhắc học trong ứng dụng
4. Tuan - Hoàn thiện chức năng giao diện ôn tập

Chọn file theo từng chức năng khi commit.
Không đưa chức năng tiếp theo vào cùng nhánh khi PR trước còn mở.
Sau khi nhóm trưởng merge, đồng bộ code rồi tiếp tục.
Nhóm trưởng review và merge PR.