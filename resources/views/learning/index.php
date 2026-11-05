<?php $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lộ trình học</title>
    <link rel="stylesheet" href="<?= $escape($basePath) ?>/assets/css/learning.css">
</head>
<body class="dark-mode-bg">
<main class="learning-path-container">
    <header class="learning-summary">
        <h1>Lộ trình của bạn</h1>
        <p><?= $escape($stats['xp']) ?> XP · Chuỗi <?= $escape($stats['streak']) ?> ngày</p>
    </header>
    <?php foreach ($topics as $topic): ?>
        <section class="topic-section">
            <div class="topic-header">
                <h2><?= $escape($topic['name']) ?></h2>
                <p><?= $escape($topic['description'] ?? '') ?></p>
            </div>
            <div class="lesson-nodes-container">
                <?php foreach (($lessonsByTopic[$topic['id']] ?? []) as $index => $lesson): ?>
                    <?php
                    $available = $lesson['ready'] && $lesson['unlocked'];
                    $status = $lesson['completed'] ? 'completed' : ($available ? 'active' : 'locked');
                    $label = $lesson['completed'] ? 'Đã hoàn thành' : (!$lesson['ready'] ? 'Chưa sẵn sàng' : ($available ? 'Bắt đầu học' : 'Hoàn thành bài trước'));
                    ?>
                    <div class="lesson-node-wrapper <?= $index % 2 === 0 ? 'left' : 'right' ?> <?= $status ?>">
                        <?php if ($available): ?>
                            <a class="lesson-node btn-duo" href="<?= $escape($basePath) ?>/learning/lesson/<?= (int) $lesson['id'] ?>" aria-label="<?= $escape($lesson['name']) ?>">⭐</a>
                        <?php else: ?>
                            <span class="lesson-node" aria-disabled="true">🔒</span>
                        <?php endif; ?>
                        <span class="lesson-name"><?= $escape($lesson['name']) ?></span>
                        <small><?= $escape($label) ?></small>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($lessonsByTopic[$topic['id']])): ?><p>Chủ đề chưa có bài học.</p><?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if ($topics === []): ?><p>Chưa có dữ liệu bài học.</p><?php endif; ?>
</main>
</body>
</html>
