<?php

$reviewApiBase = $reviewApiBase ?? rtrim(
    str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')),
    '/.'
);

?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Ôn tập từ vựng</title>

    <?php require __DIR__ . '/styles.php'; ?>

    <script>
        window.TuanReviewConfig = {
            baseUrl: <?= json_encode(
                $reviewApiBase,
                JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                    | JSON_THROW_ON_ERROR
            ) ?>
        };
    </script>
</head>
<body>
    <main class="review-shell">
        <header>
            <div>
                <p class="eyebrow">GÓC ÔN TẬP CỦA BẠN</p>

                <h1>Ôn một chút.<br>Nhớ thêm nhiều.</h1>

                <p class="hero-description">
                    Gặp lại những từ đã học và từng bước
                    biến chúng thành vốn từ của bạn.
                </p>

                <p id="review-profile" class="muted">
                    Đang kiểm tra phiên đăng nhập…
                </p>
            </div>

            <span class="module-badge">
                Mỗi ngày một chút
            </span>
        </header>

        <section class="review-benefits" aria-label="Cách ôn tập">
            <div class="benefit-card">
                <span class="benefit-number" aria-hidden="true">01</span>
                <div>
                    <strong>Đúng thời điểm</strong>
                    <p>Ưu tiên những thẻ đã đến hạn.</p>
                </div>
            </div>

            <div class="benefit-card">
                <span class="benefit-number" aria-hidden="true">02</span>
                <div>
                    <strong>Nhớ theo cách của bạn</strong>
                    <p>Tự đánh giá sau khi lật thẻ.</p>
                </div>
            </div>

            <div class="benefit-card">
                <span class="benefit-number" aria-hidden="true">03</span>
                <div>
                    <strong>Theo dõi từng lần ôn</strong>
                    <p>Xem lại lịch sử và lịch hẹn mới.</p>
                </div>
            </div>
        </section>

        <div
            id="review-message"
            class="message"
            role="status"
            hidden
        ></div>

        <div class="review-workspace">
            <div class="review-content">
                <nav class="review-tabs" aria-label="Các phần ôn tập">
                    <button type="button" data-tab="due" class="active">
                        Thẻ đến hạn
                        <b id="review-count">0</b>
                    </button>

                    <button type="button" data-tab="history">
                        Lịch sử ôn
                    </button>

                    <button type="button" data-tab="notifications">
                        Nhắc học
                    </button>
                </nav>

                <section
                    id="review-toolbar"
                    class="toolbar"
                    aria-label="Lọc lịch ôn"
                >
                    <label>
                        Chiều ôn

                        <select id="review-direction">
                            <option value="">Tất cả</option>
                            <option value="en_vi">Anh → Việt</option>
                            <option value="vi_en">Việt → Anh</option>
                            <option value="listening">Nghe</option>
                            <option value="spelling">Cách viết</option>
                        </select>
                    </label>

                    <button
                        type="button"
                        class="button secondary"
                        data-action="reload"
                    >
                        Tải lại
                    </button>
                </section>

                <section id="review-app" aria-live="polite">
                    <div class="panel empty">
                        Đang tải dữ liệu…
                    </div>
                </section>
            </div>

            <aside class="review-guide" aria-label="Hướng dẫn ôn tập">
                <div class="guide-card">
                    <p class="guide-kicker">BẮT ĐẦU THẬT NHẸ NHÀNG</p>

                    <h2>Ôn một thẻ thế nào?</h2>

                    <ol class="guide-steps">
                        <li>Nhìn từ hoặc nghe phát âm.</li>
                        <li>Tự nhớ đáp án trước khi lật thẻ.</li>
                        <li>Lật thẻ, đối chiếu và chọn mức nhớ.</li>
                    </ol>
                </div>

                <div class="guide-card">
                    <p class="guide-kicker">HIỂU MỨC NHỚ CỦA BẠN</p>

                    <h2>Chọn mức nào?</h2>

                    <div class="memory-level">
                        <span class="level-badge level-low">0–2</span>
                        <div>
                            <strong>Chưa nhớ được</strong>
                            <p>Cần xem đáp án để nhớ lại.</p>
                        </div>
                    </div>

                    <div class="memory-level">
                        <span class="level-badge">3</span>
                        <div>
                            <strong>Nhớ nhưng còn khó</strong>
                            <p>Mất nhiều thời gian để nhớ ra.</p>
                        </div>
                    </div>

                    <div class="memory-level">
                        <span class="level-badge">4–5</span>
                        <div>
                            <strong>Nhớ được hoặc nhớ dễ</strong>
                            <p>Tự nhớ ra trước khi xem đáp án.</p>
                        </div>
                    </div>

                    <p class="guide-note">
                        Chọn đúng cảm nhận của bạn.
                        Quên một từ cũng là một phần của việc học.
                    </p>
                </div>
            </aside>
        </div>

        <footer>
            Một lần ôn nhỏ hôm nay, một vốn từ vững hơn ngày mai.
        </footer>
    </main>

    <?php require __DIR__ . '/script.php'; ?>
</body>
</html>