<style>
:root {
    font-family: "Segoe UI", system-ui, sans-serif;
    color: #243653;
    background: #f5f8fc;
    font-synthesis: none;

    --blue: #2563eb;
    --muted: #7d8da5;
    --line: #e2e9f4;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
}

button,
input,
select {
    font: inherit;
}

button {
    cursor: pointer;
}

button:disabled {
    opacity: 0.5;
    cursor: default;
}

button:focus-visible,
select:focus-visible {
    outline: 3px solid #a9c8ff;
    outline-offset: 3px;
}

/* Khung trang */
.review-shell {
    max-width: 1020px;
    margin: auto;
    padding: 48px 24px;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 30px;
}

.eyebrow {
    color: #8c9db8;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.7px;
    margin: 0 0 12px;
}

h1 {
    font-size: 31px;
    letter-spacing: -0.8px;
    margin: 0 0 13px;
    line-height: 1.4;
}

h2 {
    font-size: 22px;
    margin: 0 0 12px;
}

.muted {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.8;
    margin: 0;
}

.module-badge {
    font-size: 10px;
    white-space: nowrap;
    padding: 9px 12px;
    color: #6585b8;
    border: 1px solid #d9e5fa;
    background: white;
    border-radius: 8px;
}

/* Ba tab: den han, lich su, nhac hoc */
.review-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 1px solid var(--line);
    margin-bottom: 23px;
    padding-bottom: 11px;
}

.review-tabs button {
    background: none;
    border: 0;
    border-radius: 8px;
    color: #8192ae;
    font-size: 12px;
    font-weight: 600;
    padding: 12px 17px;
}

.review-tabs button.active {
    color: #356dc2;
    background: #eaf2ff;
}

.review-tabs b {
    font-size: 10px;
    background: #d4e4ff;
    padding: 3px 6px;
    border-radius: 4px;
    margin-left: 5px;
}

/* Bo loc va nut thao tac */
.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    gap: 15px;
}

.toolbar label {
    font-size: 11px;
    color: #8a9bb5;
    display: flex;
    align-items: center;
    gap: 12px;
}

select {
    border: 1px solid #dce5f4;
    border-radius: 8px;
    background: white;
    padding: 9px 12px;
    color: #6882aa;
    font-size: 12px;
}

.button {
    border: 1px solid transparent;
    background: var(--blue);
    color: white;
    border-radius: 9px;
    padding: 11px 18px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 7px;
}

.button:hover {
    background: #1d4ed8;
}

.button.secondary {
    background: white;
    color: #718ab0;
    border-color: #dce6f5;
}

/* Danh sach the */
.panel {
    background: white;
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 26px;
    box-shadow: 0 4px 20px #263e6503;
}

.list {
    display: grid;
    gap: 12px;
}

.row {
    display: flex;
    align-items: center;
    gap: 17px;
    border: 1px solid #e7edf6;
    border-radius: 12px;
    padding: 17px;
}

.word-text {
    flex: 1;
    min-width: 0;
}

.word-text h3 {
    font-size: 20px;
    margin: 0 0 7px;
    color: #3f5d86;
}

.word-text p {
    font-size: 12px;
    line-height: 1.8;
    color: #8a9db9;
    margin: 0;
}

.due-tag {
    font-size: 10px;
    padding: 5px 8px;
    background: #eaf8ee;
    border-radius: 5px;
    color: #2c8857;
}

/* Trang thai rong va thong bao */
.empty {
    text-align: center;
    padding: 55px 25px;
    color: #8a9bb6;
    font-size: 13px;
    line-height: 1.9;
}

.empty strong {
    display: block;
    color: #4c6a96;
    font-size: 19px;
    margin-bottom: 9px;
}

.message {
    padding: 13px 17px;
    background: #eaf3ff;
    border: 1px solid #d4e6ff;
    border-radius: 9px;
    color: #5279b4;
    font-size: 12px;
    line-height: 1.8;
    margin-bottom: 19px;
}

.message.error {
    background: #fff3f2;
    border-color: #f5d7d4;
    color: #a95d53;
}

.message[hidden] {
    display: none;
}

/* Flashcard */
.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 20px;
    font-size: 11px;
    color: #8c9fbb;
}

.flashcard {
    text-align: center;
    padding: 26px 22px;
    border: 1px solid #e6edf8;
    border-radius: 15px;
    background: #fcfdff;
}

.flashcard h3 {
    font-size: 34px;
    margin: 12px 0;
    letter-spacing: -0.7px;
    color: #355985;
}

.flashcard .back {
    font-size: 24px;
    color: #4e73a2;
    border-top: 1px solid #e5edf8;
    margin-top: 23px;
    padding-top: 22px;
}

.flashcard img {
    display: block;
    max-width: 220px;
    max-height: 160px;
    object-fit: contain;
    margin: 0 auto 18px;
    border-radius: 12px;
}

.flashcard p {
    font-size: 12px;
    color: #8b9fbb;
    line-height: 1.9;
}

.card-actions {
    text-align: center;
    margin: 23px 0 0;
}

.audio-button {
    background: #eff6ff;
    color: #5183ca;
    border: 1px solid #dceaff;
    border-radius: 9px;
    padding: 10px 17px;
    font-size: 12px;
}

/* Sau muc tu danh gia 0-5 */
.ratings {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 9px;
    margin-top: 20px;
}

.rating {
    background: white;
    border: 1px solid #dee8f7;
    padding: 14px 7px;
    border-radius: 10px;
    font-size: 10px;
    color: #7390b5;
}

.rating strong {
    display: block;
    font-size: 22px;
    color: #4774b0;
    margin-bottom: 7px;
}

.rating:hover {
    background: #f0f6ff;
    border-color: #8ab1ee;
}

.rating.low strong {
    color: #bb806b;
}

.small-note {
    font-size: 10px;
    color: #9aa8bd;
    line-height: 1.9;
    margin: 17px 0 0;
}

.more {
    text-align: center;
    margin-top: 18px;
}

/* Lich su va thong bao */
.history-item,
.notification {
    border-bottom: 1px solid #edf1f7;
    padding: 17px 0;
}

.history-item:last-child,
.notification:last-child {
    border: 0;
}

.history-item h3 {
    font-size: 15px;
    margin: 0 0 8px;
}

.history-item p,
.notification p {
    font-size: 12px;
    color: #8b9bb3;
    line-height: 1.8;
    margin: 0;
}

.notification.unread {
    border-left: 3px solid #8aaff0;
    padding-left: 16px;
}

.notification .button {
    margin-top: 12px;
    padding: 7px 11px;
    font-size: 10px;
}

.notification small {
    display: block;
    font-size: 10px;
    color: #a0aec2;
    margin-top: 9px;
}

footer {
    text-align: center;
    color: #9baac0;
    font-size: 10px;
    line-height: 1.9;
    margin-top: 30px;
}

footer span {
    color: #aab6c8;
}

.result {
    padding: 25px;
    background: #eff8f2;
    color: #397e55;
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.9;
    margin-bottom: 18px;
}

/* Hien thi tren dien thoai */
@media (max-width: 700px) {
    .review-shell {
        padding: 29px 18px;
    }

    header {
        margin-bottom: 22px;
    }

    h1 {
        font-size: 25px;
    }

    .module-badge {
        display: none;
    }

    .ratings {
        grid-template-columns: repeat(3, 1fr);
    }

    .row {
        gap: 11px;
        padding: 14px;
    }

    .due-tag {
        display: none;
    }

    .word-text h3 {
        font-size: 18px;
    }

    .word-text p {
        font-size: 11px;
    }

    .row .button {
        font-size: 11px;
        padding: 10px 12px;
    }

    .panel {
        padding: 20px;
    }

    .review-tabs {
        gap: 3px;
    }

    .review-tabs button {
        padding: 10px;
        font-size: 11px;
    }

    .toolbar label {
        font-size: 10px;
        gap: 7px;
    }

    .flashcard h3 {
        font-size: 29px;
    }

    .flashcard .back {
        font-size: 23px;
    }
}
/* Bo cuc trang on tap */
.review-shell .hero-description {
    max-width: 550px;
    margin: 0 0 18px;
    color: #52644a;
    font-size: 16px;
    line-height: 1.8;
}

.review-shell .review-benefits {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 28px;
}

.review-shell .benefit-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 18px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: #ffffff;
}

.review-shell .benefit-number {
    display: inline-flex;
    flex-shrink: 0;
    justify-content: center;
    align-items: center;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: var(--green-light);
    color: var(--green-dark);
    font-size: 12px;
    font-weight: 800;
}

.review-shell .benefit-card strong {
    display: block;
    margin-bottom: 4px;
    font-size: 14px;
}

.review-shell .benefit-card p {
    margin: 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.7;
}

.review-shell .review-workspace {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 260px;
    align-items: start;
    gap: 22px;
}

.review-shell .review-content {
    min-width: 0;
}

.review-shell .review-guide {
    display: grid;
    gap: 16px;
}

.review-shell .guide-card {
    padding: 22px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: #ffffff;
}

.review-shell .guide-kicker {
    margin: 0 0 10px;
    color: var(--green-dark);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.8px;
}

.review-shell .guide-card h2 {
    margin: 0 0 18px;
    font-size: 19px;
    line-height: 1.4;
    letter-spacing: -0.3px;
}

.review-shell .guide-steps {
    margin: 0;
    padding-left: 20px;
    color: var(--muted);
    font-size: 13px;
}

.review-shell .guide-steps li {
    margin-bottom: 12px;
    padding-left: 3px;
}

.review-shell .guide-steps li:last-child {
    margin-bottom: 0;
}

.review-shell .guide-steps li::marker {
    color: var(--green-dark);
    font-weight: 800;
}

.review-shell .memory-level {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 18px;
}

.review-shell .level-badge {
    display: inline-flex;
    flex-shrink: 0;
    justify-content: center;
    align-items: center;
    min-width: 40px;
    min-height: 36px;
    padding: 4px;
    border-radius: 10px;
    background: #edf8df;
    color: #367800;
    font-size: 13px;
    font-weight: 800;
}

.review-shell .level-badge.level-low {
    background: #fff3df;
    color: #825900;
}

.review-shell .memory-level strong {
    display: block;
    margin-bottom: 4px;
    font-size: 13px;
}

.review-shell .memory-level p {
    margin: 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.7;
}

.review-shell .guide-note {
    margin: 0;
    padding-top: 16px;
    border-top: 1px dashed var(--border);
    color: var(--muted);
    font-size: 12px;
    line-height: 1.8;
}

@media (max-width: 1000px) {
    .review-shell .review-workspace {
        grid-template-columns: minmax(0, 1fr);
    }

    .review-shell .review-guide {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .review-shell .review-benefits {
        grid-template-columns: minmax(0, 1fr);
        gap: 10px;
    }

    .review-shell .benefit-card {
        padding: 14px 16px;
    }

    .review-shell .review-guide {
        grid-template-columns: minmax(0, 1fr);
    }

    .review-shell .hero-description {
        font-size: 14px;
    }
}
</style>