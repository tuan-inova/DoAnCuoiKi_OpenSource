# Học từ, bài tập và tiến độ

Luồng: đăng nhập qua API Auth chung → `/learning` → `/learning/lesson/{lesson_id}` → xem thẻ từ → bắt đầu hoặc tiếp tục lượt học → backend chấm từng câu → sửa sai → ghép cặp nếu bài có câu ghép → kết quả.

Lộ trình dùng topics/lessons đang hoạt động, sắp theo position. Mỗi bài sau trong cùng chủ đề cần có lượt completed ở tất cả bài trước đang hoạt động. Kiểm tra này cũng chạy ở backend, kể cả mở URL hoặc POST trực tiếp. Không dùng `completed` hay `mode` từ URL để ghi tiến độ hay đổi dạng câu.

## Nội dung và câu hỏi

- Thẻ từ chỉ giới thiệu; xem hết không mở bài hay nhận XP. Dữ liệu ví dụ hiển thị bằng textContent và luôn được thay/xóa khi đổi thẻ.
- Câu hỏi lấy từ exercises của đúng bài, is_active=1; bài, chủ đề, từ và chủ đề của từ đều phải đang hoạt động khi bắt đầu lượt mới. Với câu nhiều từ, mọi target_word_ids phải thuộc các từ đang hoạt động của bài.
- Hỗ trợ choose_meaning, choose_image, listen_select, listen_type, fill_blank, translate_text, token_order, match_pairs và sound_discrimination dạng chọn một đáp án. Dạng chọn dùng đúng một correct_option_ids; dữ liệu đa đáp án chưa được mở.
- choose_image: mỗi options có `{id,image}`; dạng nghe có `content_json.audio`. Media cho phép đường dẫn tuyệt đối bắt đầu bằng `/` hoặc URL HTTPS, cấm `//`, data/javascript và ký tự điều khiển. Người nhập nội dung phải cung cấp tài nguyên tồn tại, có quyền sử dụng. Không tự dùng ảnh/audio mẫu thay thế.
- Nếu ít hơn question_count câu chính hợp lệ, bài hiển thị “Chưa sẵn sàng” và start trả 409. Seed hiện tại có chọn nghĩa, dịch, điền từ, xếp từ và ghép cặp; chưa có tài nguyên ảnh/audio để mở các câu nghe/chọn hình thực.
- Snapshot private cố định type/content/answer cùng thứ tự hiển thị. Client chỉ nhận câu hiện tại, type/content đã chọn trường; không nhận đáp án hoặc rules_json.
- Lượt đang học tiếp tục bằng snapshot kể cả nội dung gốc sau đó bị ẩn/sửa. Không bắt đầu lượt mới từ nội dung ẩn.

## Chấm bài, tim và thưởng

Dùng các bảng của migration 001; không sửa migration/seed đã áp dụng, không cần migration mới.

Mỗi câu main chỉ được nộp một lần; correct_count chỉ tính câu main. Phải có correct_count*100 > total_questions*pass_percent (7/10 ở ngưỡng 70 không đạt). Khi còn tim và đạt ngưỡng, thêm câu sửa sai cùng snapshot/source_question_id; sửa sai không đổi điểm main và không trừ tim. Ghép cặp nếu có được yêu cầu sau khi sửa hết lỗi; sai ghép cặp vẫn trừ tim. Hết tim thì lượt failed, không có XP hoặc mở khóa bài.

Tim thuộc users, tối đa 3. Chính sách hồi mặc định là **1 tim mỗi 30 phút**, tập trung ở `app/Shared/HeartRules.php` để nhóm dùng chung và điều chỉnh khi chốt yêu cầu. Không hồi tim do F5, mở tab, đổi bài hay bắt đầu lượt mới. Các thao tác khóa users rồi learning_sessions trong cùng transaction. Start cùng người/bài tiếp tục lượt in_progress hiện có.

Hoàn tất ghi status, tiến độ các nhiệm vụ đã giao (xp_earned/lessons_completed), xp_transactions (event_key `lesson:{session_id}:complete`) và user_daily_activity trong cùng transaction. Retry không cộng trùng; học lại bằng lượt mới có thể nhận thưởng mới. Ngày hoạt động dùng múi giờ users.timezone; ngày đủ daily_goal_xp mới tính streak. Streak đếm chuỗi ngày đủ mục tiêu kết thúc hôm nay hoặc hôm qua. Múi giờ dùng tại thời điểm hoàn thành; thay đổi timezone không dời dữ liệu cũ. Phần này không tự nhận thưởng nhiệm vụ hay cập nhật SM-2 của module Reviews.

## Cài và kiểm tra

Copy `.env.example` thành `.env` riêng trên máy, đặt DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD. Biến môi trường hệ thống ưu tiên hơn .env. Không commit .env thật. PDO dùng UTC; lỗi chi tiết chỉ ghi server log, client nhận mã lỗi an toàn và HTTP status.

```bash
php tests/unit/learning.php
node --check public/assets/js/learning.js
python scripts/check_structure.py
```

Integration cần database rỗng tên kết thúc `_test`, import migration 001 và seed 001. Đặt các DB_* cho database thử rồi chạy `php tests/integration/learning.php`. Script từ chối database không có hậu tố `_test` hoặc đã có tài khoản. Nó để lại dữ liệu thử để có thể kiểm tra UI bằng tài khoản `a@example.test`, mật khẩu `test-password-123`. Tuyệt đối không chạy trên database dùng thật. CI dùng MariaDB 10.4 tách biệt.

Kiểm thử trình duyệt: cài `playwright@1.62.1` và Chromium, chạy PHP web server với cùng database thử rồi đặt TEST_BASE_URL và chạy `node tests/e2e/learning.cjs`. Bộ thử dùng nội dung/tài khoản thử do integration tạo; ảnh và âm thanh trong E2E được mock riêng, không được thêm vào nội dung học thật. CI chạy cả integration và E2E.
