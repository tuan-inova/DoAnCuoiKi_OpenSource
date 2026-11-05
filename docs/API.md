# Hợp đồng API dự kiến

Prefix /api/v1, JSON UTF-8. Thành công: {"data": ...}; lỗi: {"error": {"code": "...", "message": "..."}}.
Các API nghiệp vụ chưa triển khai. Chỉ có GET /api/v1/health, response database=not_configured.
Trước khi code mỗi endpoint cần ghi: method, path, quyền, request, response, mã lỗi và chống gửi trùng nếu cần.
Web/desktop gọi cùng API; không kết nối MySQL trực tiếp từ desktop.
Không đưa mật khẩu/token vào log. Backend kiểm tra quyền trên từng tài nguyên.

## Tài khoản và hồ sơ (Cuong)

Xác thực bằng phiên PHP: đăng nhập thành công backend gửi cookie phiên (HttpOnly, SameSite=Lax) và đổi mã phiên. Mật khẩu lưu bằng `password_hash`. Quyền: `public` (không cần đăng nhập), `user` (đã đăng nhập), `admin`. Backend kiểm tra `status` của tài khoản ở mỗi request. Desktop dùng chung cookie phiên; nếu cần token sẽ bổ sung hợp đồng riêng.

Đối tượng `user` trả về (không bao giờ có `password_hash`):

```json
{"id": 1, "name": "An", "email": "an@example.com", "role": "user", "status": "active", "current_hp": 3, "daily_goal_xp": 20, "streak_goal_days": 7, "timezone": "Asia/Ho_Chi_Minh"}
```

### POST /api/v1/auth/register
- Quyền: public. Không tự đăng nhập sau khi đăng ký; `role` luôn là `user`.
- Request: `{"name": "An", "email": "an@example.com", "password": "matkhau123"}`
- Ràng buộc: `name` 1-100 ký tự; `email` hợp lệ, tối đa 191 ký tự, không phân biệt hoa thường; `password` 8-72 ký tự.
- Thành công 201: `{"data": {user}}`
- Lỗi: 422 `VALIDATION_FAILED` (có `details` theo từng trường), 409 `EMAIL_ALREADY_EXISTS`.
- Chống gửi trùng: email là duy nhất; gửi lại cùng email trả 409, không tạo bản ghi mới.

### POST /api/v1/auth/login
- Quyền: public.
- Request: `{"email": "an@example.com", "password": "matkhau123"}`
- Thành công 200: `{"data": {user}}` kèm cookie phiên.
- Lỗi: 422 `VALIDATION_FAILED`; 401 `INVALID_CREDENTIALS` (cùng một thông báo cho sai email và sai mật khẩu); 403 `ACCOUNT_LOCKED`; 403 `ACCOUNT_DEACTIVATED`.

### POST /api/v1/auth/logout
- Quyền: user.
- Request: không có nội dung.
- Thành công 200: `{"data": {"logged_out": true}}`; phiên bị hủy.
- Lỗi: 401 `UNAUTHENTICATED`.

### GET /api/v1/users/me
- Quyền: user.
- Thành công 200: `{"data": {user}}`
- Lỗi: 401 `UNAUTHENTICATED`.

### PATCH /api/v1/users/me
- Quyền: user. Chỉ gửi các trường cần sửa; gửi lại cùng giá trị cho kết quả như nhau.
- Request: `{"name": "An", "daily_goal_xp": 30, "streak_goal_days": 10, "timezone": "Asia/Ho_Chi_Minh"}`
- Ràng buộc: `name` 1-100 ký tự; `daily_goal_xp` số nguyên từ 1 đến 1000; `streak_goal_days` số nguyên từ 1 đến 365; `timezone` là múi giờ hợp lệ.
- Không được gửi `email`, `role`, `status`, `password`, `current_hp`; gửi các trường này trả 422.
- Thành công 200: `{"data": {user}}`
- Lỗi: 401 `UNAUTHENTICATED`, 422 `VALIDATION_FAILED`.

### PATCH /api/v1/users/{id}/status
- Quyền: admin. Khóa hoặc mở khóa tài khoản.
- Request: `{"status": "locked"}` (giá trị `locked` hoặc `active`).
- Thành công 200: `{"data": {user}}`. Tài khoản bị khóa mất phiên hiện tại ở request kế tiếp.
- Lỗi: 401 `UNAUTHENTICATED`; 403 `FORBIDDEN` (không phải admin); 404 `USER_NOT_FOUND`; 422 `VALIDATION_FAILED` (gồm trường hợp admin tự khóa chính mình).

### Mã lỗi dùng chung
| HTTP | code | Ý nghĩa |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Chưa đăng nhập |
| 401 | `INVALID_CREDENTIALS` | Sai email hoặc mật khẩu |
| 403 | `FORBIDDEN` | Không đủ quyền |
| 403 | `ACCOUNT_LOCKED` | Tài khoản bị khóa |
| 403 | `ACCOUNT_DEACTIVATED` | Tài khoản đã ngưng hoạt động |
| 404 | `NOT_FOUND` | Không có đường dẫn này |
| 404 | `USER_NOT_FOUND` | Không có người dùng này |
| 405 | `METHOD_NOT_ALLOWED` | Sai phương thức HTTP |
| 409 | `EMAIL_ALREADY_EXISTS` | Email đã được đăng ký |
| 422 | `VALIDATION_FAILED` | Dữ liệu gửi lên không hợp lệ |
| 500 | `INTERNAL_ERROR` | Lỗi máy chủ (không lộ chi tiết) |
## Học và tiến độ (Danh)

Các endpoint dưới đây dùng Auth::user() và cookie Session chung; khách 401, tài khoản locked/deactivated 403. Web `/learning` và `/learning/lesson/{id}` cũng áp dụng quyền này. Cấu trúc chi tiết: [LEARNING.md](LEARNING.md).

GET trang học cung cấp CSRF token theo phiên trong JSON boot. POST học bắt buộc header `X-CSRF-Token`; thiếu/sai trả 403 `CSRF_FAILED`. Chỉ backend được xác nhận hoàn thành; không có endpoint đặt completed, score, HP hay XP từ client.

| Method | Path | Nội dung gửi | Kết quả |
|---|---|---|---|
| POST | `/api/v1/learning/lessons/{id}/sessions` | `{}` | Bắt đầu lượt hoặc trả lượt in_progress hiện có |
| GET | `/api/v1/learning/sessions/{id}` | — | Trạng thái và câu hiện tại của chính người dùng |
| POST | `/api/v1/learning/sessions/{id}/answers` | `{question_id,request_key,answer}` | `{is_correct,replayed,session}` |

Session có id, lesson_id, status, total_questions, correct_count, answered_main, current_hp, max_hp, next_hp_at (UTC), xp_earned, stats:{xp,streak}, question. Question có id, phase, position, type, content. Khi lượt kết thúc, question=null. Không trả answer_json/snapshot_json hay khóa đáp án.

Answer theo dạng: chọn nghĩa/hình/nghe `{option_id:"a"}`; dịch/điền/nghe nhập `{text:"milk"}`; xếp từ `{token_ids:["t2","t1","t3"]}`; ghép `{pairs:[{left_id:"L1",right_id:"R1"},...]}`. Backend từ chối cấu trúc sai bằng 422, không trừ tim.

request_key là chuỗi ASCII `[A-Za-z0-9:_-]` 16–100 ký tự, tạo một lần cho mỗi lần nộp; retry phải giữ nguyên key, question_id và answer. Retry trả lại is_correct đã lưu, replayed=true, cùng trạng thái lượt hiện tại; không ghi submission, trừ tim, thay điểm hay thưởng lại. Cùng key nhưng thao tác khác: 409 REQUEST_KEY_CONFLICT. Câu đã xử lý gửi bằng key mới, nhảy thứ tự hoặc lượt đã kết thúc: 409 QUESTION_OUT_OF_ORDER.

Lỗi khác: 404 LESSON_NOT_FOUND (gồm nội dung bị ẩn), SESSION_NOT_FOUND (gồm lượt thuộc người khác), QUESTION_NOT_FOUND; 403 LESSON_LOCKED; 409 LESSON_NOT_READY, NO_HEARTS; 422 VALIDATION_FAILED. Lỗi ngoài dự kiến ghi log và trả 500 INTERNAL_ERROR, không hiện file/trace.
