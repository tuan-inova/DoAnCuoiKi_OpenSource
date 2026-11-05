<script>
'use strict';

(() => {
    const S = {
        boot: null,
        tab: 'due',
        items: [],
        cursor: null,
        card: null,
        revealed: false,
        busy: false,
        start: null,
        submission: null,
        historyItems: [],
        historyCursor: null,
        notificationItems: [],
        notificationCursor: null,
    };

    const $ = id => document.getElementById(id);

    // Escape du lieu truoc khi chen vao HTML.
    const esc = value => String(value ?? '').replace(
        /[&<>"']/g,
        character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        })[character]
    );

    const labels = {
        en_vi: 'Anh → Việt',
        vi_en: 'Việt → Anh',
        listening: 'Nghe',
        spelling: 'Cách viết',
    };

    function uuid() {
        if (globalThis.crypto?.randomUUID) {
            return crypto.randomUUID();
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(
            /[xy]/g,
            character => {
                const random = Math.floor(Math.random() * 16);
                const value = character === 'x'
                    ? random
                    : (random & 3) | 8;

                return value.toString(16);
            }
        );
    }

    function date(value) {
        if (!value) return '—';

        return new Intl.DateTimeFormat('vi-VN', {
            timeZone: S.boot?.user.timezone || 'Asia/Ho_Chi_Minh',
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(new Date(value));
    }

    let audio;

    function message(text, error = false) {
        $('review-message').textContent = text;
        $('review-message').className =
            'message' + (error ? ' error' : '');
        $('review-message').hidden = false;
    }

    function media(value) {
        if (!value) return '';

        try {
            const url = new URL(value, location.href);

            return ['http:', 'https:'].includes(url.protocol)
                && url.origin === location.origin
                ? url.href
                : '';
        } catch (_) {
            return '';
        }
    }

    // Goi API bang phien dang nhap hien tai.
    async function api(route, body, query = {}) {
        const target = new URL(
            window.TuanReviewConfig.baseUrl.replace(/\/$/, '') + route,
            location.origin
        );

        if (target.origin !== location.origin) {
            throw new Error('Địa chỉ API phải cùng website.');
        }

        for (const [key, value] of Object.entries(query)) {
            if (value !== null && value !== '') {
                target.searchParams.set(key, String(value));
            }
        }

        let response;

        try {
            response = await fetch(target, {
                method: body === undefined ? 'GET' : 'POST',
                credentials: 'same-origin',
                headers: body === undefined
                    ? {}
                    : {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': S.boot?.csrf_token || '',
                    },
                ...(body === undefined
                    ? {}
                    : { body: JSON.stringify(body) }),
            });
        } catch (_) {
            throw new Error(
                'Không kết nối được máy chủ. Giữ thao tác hiện tại và thử lại.'
            );
        }

        let value;

        try {
            value = await response.json();
        } catch (_) {
            throw new Error(
                'Máy chủ chưa trả dữ liệu hợp lệ. Hãy thử lại sau.'
            );
        }

        if (!response.ok || value.error || !('data' in value)) {
            const error = new Error(
                value.error?.message || 'Chưa thực hiện được thao tác.'
            );

            error.code = value.error?.code;
            throw error;
        }

        return value.data;
    }

    function controls() {
        document.querySelectorAll('[data-tab]').forEach(button => {
            button.classList.toggle(
                'active',
                button.dataset.tab === S.tab
            );
        });

        $('review-toolbar').hidden = !!S.card || S.tab !== 'due';
    }

    // Chan bam lap khi dang xu ly.
    async function run(work) {
        if (S.busy) return;

        S.busy = true;

        const locked = [...document.querySelectorAll('button')]
            .map(button => [button, button.disabled]);

        locked.forEach(([button]) => {
            button.disabled = true;
        });

        try {
            await work();
        } catch (error) {
            message(error.message, true);

            const reloadCodes = [
                'STALE_REVIEW',
                'REVIEW_EXPIRED',
                'REVIEW_FINISHED',
                'NOT_DUE',
                'NOT_LEARNED',
                'CONTENT_UNAVAILABLE',
            ];

            if (reloadCodes.includes(error.code)) {
                S.card = null;
                S.start = null;
                S.submission = null;

                try {
                    await loadDue();
                } catch (_) {
                    // Thong bao loi ban dau van duoc giu lai.
                }
            }
        } finally {
            S.busy = false;

            locked.forEach(([button, disabled]) => {
                button.disabled = disabled;
            });
        }
    }

    // Danh sach the den han.
    async function loadDue(more = false) {
        S.tab = 'due';
        S.card = null;
        controls();

        const data = await api('/api/v1/reviews/due', undefined, {
            limit: 20,
            cursor: more ? S.cursor : null,
            direction: $('review-direction').value,
        });

        S.items = more ? [...S.items, ...data.items] : data.items;
        S.cursor = data.meta.next_cursor;

        $('review-count').textContent = data.meta.total_due;

        if (!S.items.length) {
            $('review-app').innerHTML = `
                <div class="panel empty">
                    <strong>Chưa có thẻ đến hạn.</strong>
                    Hoàn thành bài học để bắt đầu có lịch ôn sau một ngày.
                    <br>
                    Thẻ quá hạn vẫn được giữ và đưa lên đầu danh sách.
                </div>
            `;
            return;
        }

        const rows = S.items.map(item => `
            <article class="row">
                <div class="word-text">
                    <h3>${esc(item.word.english)}</h3>
                    <p>
                        ${esc(labels[item.direction])}
                        · Hẹn ôn ${esc(date(item.next_review_at))}
                        <br>
                        ${item.repetitions} lần nhớ liên tiếp
                        · Khoảng ôn ${item.interval_days} ngày
                    </p>
                </div>
                <span class="due-tag">Đến hạn</span>
                <button
                    type="button"
                    class="button"
                    data-open="${item.id}"
                >
                    Ôn thẻ →
                </button>
            </article>
        `).join('');

        $('review-app').innerHTML = `
            <div class="panel">
                <div class="list">${rows}</div>
                ${S.cursor ? `
                    <div class="more">
                        <button
                            type="button"
                            class="button secondary"
                            data-action="more"
                        >
                            Xem thêm
                        </button>
                    </div>
                ` : ''}
            </div>
        `;
    }

    // Flashcard: co the thay bang renderer dung chung cua Danh.
    function renderCard() {
        const item = S.card;
        const word = item.card;

        let front;
        let back;

        if (item.direction === 'en_vi') {
            front = word.english;
            back = word.vietnamese;
        } else if (item.direction === 'listening') {
            front = 'Nghe rồi tự nhớ từ';
            back = word.english + ' — ' + word.vietnamese;
        } else {
            front = word.vietnamese;
            back = word.english;
        }

        const audioUrl = media(word.audio);
        const imageUrl = media(word.image);
        let markup;

        if (typeof window.DoulingoReviewCard === 'function') {
            markup = window.DoulingoReviewCard({
                word,
                direction: item.direction,
                revealed: S.revealed,
                escape: esc,
            });
        } else {
            markup = `
                <div class="flashcard">
                    ${imageUrl && item.direction === 'en_vi' ? `
                        <img
                            src="${esc(imageUrl)}"
                            alt="Hình minh họa"
                        >
                    ` : ''}

                    <h3>${esc(front)}</h3>

                    ${audioUrl ? `
                        <button
                            type="button"
                            class="audio-button"
                            data-audio="${esc(audioUrl)}"
                        >
                            ♫ Nghe phát âm
                        </button>
                    ` : ''}

                    ${S.revealed ? `
                        <div class="back">${esc(back)}</div>
                        <p>
                            ${esc(word.ipa || '')}<br>
                            ${esc(word.example || '')}<br>
                            ${esc(word.example_translation || '')}
                        </p>
                    ` : ''}
                </div>
            `;
        }

        const names = [
            'Quên hoàn toàn',
            'Sai, xem mới nhớ',
            'Sai, thấy quen',
            'Nhớ khó',
            'Nhớ được',
            'Nhớ dễ',
        ];

        const ratings = names.map((name, quality) => `
            <button
                type="button"
                class="rating ${quality < 3 ? 'low' : ''}"
                data-quality="${quality}"
            >
                <strong>${quality}</strong>
                ${esc(name)}
            </button>
        `).join('');

        controls();

        $('review-app').innerHTML = `
            <section class="panel">
                <div class="card-header">
                    <span>
                        ${esc(labels[item.direction])} · TỰ ĐÁNH GIÁ
                    </span>
                    <button
                        type="button"
                        class="button secondary"
                        data-action="back"
                    >
                        ← Danh sách
                    </button>
                </div>

                ${markup}

                ${S.revealed ? `
                    <div class="ratings">${ratings}</div>
                    <p class="small-note">
                        Chọn mức phản ánh đúng khả năng nhớ của bạn.
                        Mức nhớ là tự đánh giá, không phải điểm kiểm tra.
                    </p>
                ` : `
                    <div class="card-actions">
                        <button
                            type="button"
                            class="button"
                            data-action="reveal"
                        >
                            Lật thẻ và tự đánh giá
                        </button>
                    </div>
                `}
            </section>
        `;
    }

    // Lich su on tap.
    async function history(more = false) {
        S.tab = 'history';
        S.card = null;
        controls();

        const data = await api('/api/v1/reviews/history', undefined, {
            limit: 20,
            before_id: more ? S.historyCursor : null,
        });

        S.historyItems = more
            ? [...S.historyItems, ...data.items]
            : data.items;

        S.historyCursor = data.next_before_id;

        const rows = S.historyItems.map(item => `
            <article class="history-item">
                <h3>
                    ${esc(item.english)}
                    · ${esc(labels[item.direction])}
                </h3>
                <p>
                    Mức nhớ ${item.quality}/5
                    · ${esc(date(item.reviewed_at))}
                    <br>
                    Khoảng ôn
                    ${item.before.state.interval_days}
                    → ${item.after.state.interval_days} ngày
                    · EF
                    ${Number(item.before.state.ease_factor).toFixed(3)}
                    → ${Number(item.after.state.ease_factor).toFixed(3)}
                </p>
            </article>
        `).join('');

        $('review-app').innerHTML = `
            <section class="panel">
                ${rows || `
                    <div class="empty">Chưa có lịch sử ôn.</div>
                `}
                ${S.historyCursor ? `
                    <div class="more">
                        <button
                            type="button"
                            class="button secondary"
                            data-action="history-more"
                        >
                            Xem lịch sử cũ hơn
                        </button>
                    </div>
                ` : ''}
            </section>
        `;
    }

    // Nhac hoc trong ung dung.
    async function notifications(more = false) {
        S.tab = 'notifications';
        S.card = null;
        controls();

        if (!more) {
            await api('/api/v1/notifications/review-due', {});
        }

        const data = await api('/api/v1/notifications', undefined, {
            limit: 20,
            before_id: more ? S.notificationCursor : null,
        });

        S.notificationItems = more
            ? [...S.notificationItems, ...data.items]
            : data.items;

        S.notificationCursor = data.next_before_id;

        const rows = S.notificationItems.map(item => `
            <article class="notification ${item.read_at ? '' : 'unread'}">
                <p>${esc(item.message)}</p>
                <small>${esc(date(item.sent_at))}</small>

                ${item.read_at ? '' : `
                    <button
                        type="button"
                        class="button secondary"
                        data-read="${item.id}"
                    >
                        Đánh dấu đã đọc
                    </button>
                `}
            </article>
        `).join('');

        $('review-app').innerHTML = `
            <section class="panel">
                ${rows || `
                    <div class="empty">Chưa có thông báo.</div>
                `}
                ${S.notificationCursor ? `
                    <div class="more">
                        <button
                            type="button"
                            class="button secondary"
                            data-action="notifications-more"
                        >
                            Xem thêm thông báo
                        </button>
                    </div>
                ` : ''}
            </section>
        `;
    }

    // Xu ly thao tac tren cac nut.
    document.addEventListener('click', event => {
        const button = event.target.closest('button');

        if (!button || button.disabled || S.busy) return;

        if (button.dataset.audio) {
            if (audio) audio.pause();

            audio = new Audio(button.dataset.audio);
            audio.play().catch(() => {
                message('Chưa phát được âm thanh.', true);
            });
            return;
        }

        if (button.dataset.tab) {
            run(() => {
                if (button.dataset.tab === 'due') return loadDue();
                if (button.dataset.tab === 'history') return history();
                return notifications();
            });
            return;
        }

        if (button.dataset.open) {
            run(async () => {
                const id = Number(button.dataset.open);

                if (!S.start || S.start.review_state_id !== id) {
                    S.start = {
                        review_state_id: id,
                        request_key: uuid(),
                    };
                }

                S.card = await api('/api/v1/reviews/start', S.start);
                S.start = null;
                S.submission = null;
                S.revealed = false;

                renderCard();
            });
            return;
        }

        if (button.dataset.quality !== undefined) {
            run(async () => {
                const quality = Number(button.dataset.quality);
                const id = S.card.session_question_id;

                // Giu key khi thu lai cung thao tac sau loi ket noi.
                if (
                    !S.submission
                    || S.submission.session_question_id !== id
                    || S.submission.quality !== quality
                ) {
                    S.submission = {
                        session_question_id: id,
                        request_key: uuid(),
                        quality,
                    };
                }

                const result = await api(
                    '/api/v1/reviews/submit',
                    S.submission
                );

                S.submission = null;
                await loadDue();

                message(
                    `Đã lưu mức nhớ ${quality}/5. `
                    + `Hẹn ôn lại ${date(result.state.next_review_at)}.`
                );
            });
            return;
        }

        if (button.dataset.read) {
            run(async () => {
                await api(
                    '/api/v1/notifications/'
                        + button.dataset.read
                        + '/read',
                    {}
                );

                await notifications();
            });
            return;
        }

        switch (button.dataset.action) {
            case 'reload':
            case 'back':
                run(() => loadDue());
                break;

            case 'more':
                run(() => loadDue(true));
                break;

            case 'history-more':
                run(() => history(true));
                break;

            case 'notifications-more':
                run(() => notifications(true));
                break;

            case 'reveal':
                S.revealed = true;
                renderCard();
                break;
        }
    });

    $('review-direction').addEventListener(
        'change',
        () => run(() => loadDue())
    );

    // Tai thong tin phien va danh sach khi mo trang.
    (async () => {
        try {
            S.boot = await api('/api/v1/reviews/bootstrap');

            $('review-profile').textContent =
                S.boot.user.name + ' · ' + S.boot.user.timezone;

            await loadDue();
        } catch (error) {
            message(error.message, true);

            $('review-app').innerHTML =
                error.code === 'UNAUTHENTICATED'
                    ? `
                        <div class="panel empty">
                            <strong>Cần phiên đăng nhập của dự án.</strong>
                            Hãy đăng nhập rồi quay lại trang Ôn tập.
                        </div>
                    `
                    : `
                        <div class="panel empty">
                            <strong>Chưa tải được lịch ôn.</strong>
                            Hãy thử tải lại trang hoặc liên hệ người quản trị.
                        </div>
                    `;
        }
    })();
})();
</script>