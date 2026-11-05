<?php
$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$boot = ['basePath' => $basePath, 'csrfToken' => $csrfToken, 'lessonId' => (int) $lesson['id'],
    'sessionId' => $activeSession === null ? null : (int) $activeSession['id'], 'words' => $words];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($lesson['name']) ?></title>
    <link rel="stylesheet" href="<?= $escape($basePath) ?>/assets/css/learning.css">
    <script id="learning-data" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    <script src="<?= $escape($basePath) ?>/assets/js/learning.js" defer></script>
</head>
<body class="dark-mode-bg">
<main class="learning-player">
    <header class="player-header">
        <a href="<?= $escape($basePath) ?>/learning" aria-label="Về lộ trình">✕</a>
        <h1><?= $escape($lesson['name']) ?></h1>
        <span id="hearts-display" aria-label="Số tim"></span>
    </header>
    <p id="progress" aria-live="polite"></p>
    <section id="flashcards" class="player-content">
        <?php if ($words === []): ?><p>Chưa có từ vựng để giới thiệu.</p><?php endif; ?>
        <img id="fc-image" class="word-image" alt="" hidden>
        <h2 id="fc-word"></h2>
        <p id="fc-pronunciation"></p>
        <button id="btn-listen" type="button">🔊 Nghe phát âm</button>
        <h3 id="fc-translation"></h3>
        <p id="fc-example" hidden><strong>Ví dụ: </strong><span id="fc-ex-en"></span></p>
        <p id="fc-ex-translation"></p>
        <button id="btn-next-word" type="button">Tiếp tục</button>
        <button id="btn-start" type="button" <?= !$ready && $activeSession === null ? 'disabled' : '' ?>><?= $activeSession === null ? 'Làm bài tập' : 'Tiếp tục lượt học' ?></button>
        <?php if (!$ready): ?><p>Bài học chưa đủ câu hỏi hoặc tài nguyên. Chưa thể nhận kết quả hay XP từ phần giới thiệu từ.</p><?php endif; ?>
    </section>
    <section id="exercise" class="player-content" hidden>
        <p id="phase-label"></p>
        <h2 id="instruction"></h2>
        <p id="prompt"></p>
        <audio id="question-audio" controls hidden></audio>
        <form id="answer-form">
            <div id="answer-controls"></div>
            <button id="btn-submit" type="submit">Kiểm tra</button>
        </form>
        <button id="btn-next-question" type="button" hidden>Tiếp tục</button>
    </section>
    <section id="result" class="player-content" hidden>
        <h2 id="result-title"></h2>
        <p id="result-score"></p>
        <a class="btn-continue" href="<?= $escape($basePath) ?>/learning">Về lộ trình</a>
    </section>
    <p id="feedback" role="status" aria-live="polite"></p>
    <noscript>Hãy bật JavaScript để xem thẻ từ và gửi câu trả lời.</noscript>
</main>
</body>
</html>
