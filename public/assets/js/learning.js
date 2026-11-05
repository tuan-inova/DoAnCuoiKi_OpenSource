'use strict';
(() => {
    const boot = JSON.parse(document.getElementById('learning-data').textContent);
    const $ = id => document.getElementById(id);
    const words = boot.words;
    let wordIndex = 0;
    let session = null;
    let busy = false;
    let pending = null;
    let selectedTokens = [];

    const media = value => {
        if (typeof value !== 'string' || /[\s<>"'\\]/.test(value)) return null;
        if (value.startsWith('/') && !value.startsWith('//')) return value;
        try { return new URL(value).protocol === 'https:' ? value : null; } catch { return null; }
    };
    function showWord() {
        const word = words[wordIndex];
        if (!word) {
            $('btn-next-word').hidden = true;
            $('btn-listen').hidden = true;
            return;
        }
        $('fc-word').textContent = word.english;
        $('fc-pronunciation').textContent = word.ipa || '';
        $('fc-translation').textContent = word.vietnamese;
        // Always replace both values; examples are plain text, including database markup.
        $('fc-ex-en').textContent = word.example || '';
        $('fc-example').hidden = !word.example;
        $('fc-ex-translation').textContent = word.example_translation || '';
        const src = media(word.image);
        $('fc-image').hidden = !src;
        if (src) $('fc-image').src = src; else $('fc-image').removeAttribute('src');
        $('btn-next-word').hidden = wordIndex === words.length - 1;
        $('progress').textContent = `Giới thiệu từ ${wordIndex + 1}/${words.length}`;
        $('btn-listen').disabled = !word.audio && !('speechSynthesis' in window);
    }
    $('btn-next-word').addEventListener('click', () => { wordIndex++; showWord(); });
    $('btn-listen').addEventListener('click', () => {
        const word = words[wordIndex];
        const src = media(word?.audio);
        if (src) {
            new Audio(src).play().catch(() => { $('feedback').textContent = 'Chưa thể phát âm thanh.'; });
        } else if (word && 'speechSynthesis' in window) {
            const voice = new SpeechSynthesisUtterance(word.english);
            voice.lang = 'en-US'; window.speechSynthesis.cancel(); window.speechSynthesis.speak(voice);
        }
    });

    async function api(path, body) {
        const response = await fetch(boot.basePath + '/api/v1/learning/' + path, {
            method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrfToken },
            body: body === undefined ? undefined : JSON.stringify(body)
        });
        const payload = await response.json();
        if (!response.ok) {
            const error = new Error(payload.error?.message || 'Không thể xử lý yêu cầu.');
            error.status = response.status;
            throw error;
        }
        return payload.data;
    }
    function updateSummary() {
        $('hearts-display').textContent = '❤️'.repeat(session.current_hp) + '🖤'.repeat(session.max_hp - session.current_hp);
        $('hearts-display').setAttribute('aria-label', `${session.current_hp}/${session.max_hp} tim`);
        $('progress').textContent = `Đã trả lời ${session.answered_main}/${session.total_questions} câu chính · ${session.stats.xp} XP · Chuỗi ${session.stats.streak} ngày`;
    }
    function renderSession() {
        $('flashcards').hidden = true;
        $('feedback').textContent = '';
        updateSummary();
        const done = session.status !== 'in_progress';
        $('exercise').hidden = done;
        $('result').hidden = !done;
        if (done) {
            $('result-title').textContent = session.status === 'completed' ? 'Hoàn thành bài học!' : 'Lượt học chưa đạt';
            $('result-score').textContent = `${session.correct_count}/${session.total_questions} câu chính đúng · +${session.xp_earned} XP`;
            return;
        }
        const question = session.question;
        if (!question) return;
        $('phase-label').textContent = { main: 'Bài tập', mistake_review: 'Sửa câu sai — không trừ tim', matching: 'Ghép cặp để hoàn tất bài' }[question.phase];
        $('instruction').textContent = question.content.instruction;
        $('prompt').textContent = question.content.prompt;
        const audio = media(question.content.audio);
        $('question-audio').hidden = !audio;
        $('question-audio').pause();
        if (audio) $('question-audio').src = audio; else $('question-audio').removeAttribute('src');
        const controls = $('answer-controls');
        controls.replaceChildren();
        selectedTokens = [];
        $('answer-form').hidden = false;
        $('btn-next-question').hidden = true;
        $('btn-submit').disabled = false;
        $('btn-submit').textContent = 'Kiểm tra';
        if (question.content.options) {
            controls.className = 'answer-options';
            question.content.options.forEach((option, index) => {
                const label = document.createElement('label'); label.className = 'answer-option';
                const input = document.createElement('input');
                input.type = 'radio'; input.name = 'option'; input.value = option.id; input.required = true;
                label.append(input);
                if (question.type === 'choose_image') {
                    const image = document.createElement('img'); image.src = media(option.image); image.alt = `Hình ${index + 1}`;
                    label.append(image);
                } else {
                    const text = document.createElement('span'); text.textContent = option.text; label.append(text);
                }
                controls.append(label);
            });
        } else if (question.type === 'token_order') {
            const sequence = document.createElement('p'); sequence.id = 'token-sequence'; controls.append(sequence);
            question.content.tokens.forEach(token => {
                const button = document.createElement('button'); button.type = 'button'; button.textContent = token.text;
                button.addEventListener('click', () => {
                    selectedTokens.push(token.id); button.disabled = true;
                    sequence.textContent = selectedTokens.map(id => question.content.tokens.find(t => t.id === id).text).join(' ');
                }); controls.append(button);
            });
            const clear = document.createElement('button'); clear.type = 'button'; clear.textContent = 'Xếp lại';
            clear.addEventListener('click', renderSession); controls.append(clear);
        } else if (question.type === 'match_pairs') {
            question.content.left.forEach(left => {
                const label = document.createElement('label'); label.className = 'pair-row';
                const text = document.createElement('span'); text.textContent = left.text;
                const select = document.createElement('select'); select.dataset.leftId = left.id; select.required = true;
                const empty = document.createElement('option'); empty.value = ''; empty.textContent = 'Chọn từ tương ứng'; select.append(empty);
                question.content.right.forEach(right => {
                    const option = document.createElement('option'); option.value = right.id; option.textContent = right.text; select.append(option);
                }); label.append(text, select); controls.append(label);
            });
        } else {
            const input = document.createElement('input'); input.type = 'text'; input.name = 'text'; input.required = true;
            input.maxLength = 1000; input.autocomplete = 'off'; input.setAttribute('aria-label', 'Câu trả lời'); controls.append(input);
        }
    }
    function readAnswer() {
        const type = session.question.type;
        if (session.question.content.options) return { option_id: $('answer-form').elements.option.value };
        if (type === 'token_order') return { token_ids: selectedTokens.slice() };
        if (type === 'match_pairs') return { pairs: Array.from($('answer-controls').querySelectorAll('select')).map(select => ({ left_id: select.dataset.leftId, right_id: select.value })) };
        return { text: $('answer-form').elements.text.value };
    }
    $('btn-start').addEventListener('click', async () => {
        if (busy) return;
        busy = true; $('btn-start').disabled = true;
        try {
            session = boot.sessionId ? await api(`sessions/${boot.sessionId}`) : await api(`lessons/${boot.lessonId}/sessions`, {});
            renderSession();
        } catch (error) { $('feedback').textContent = error.message; }
        finally { busy = false; $('btn-start').disabled = false; }
    });
    $('answer-form').addEventListener('submit', async event => {
        event.preventDefault(); if (busy) return;
        busy = true; $('btn-submit').disabled = true;
        // A lost response reuses the exact body/key. Controls stay frozen until success or a definite rejection.
        pending ??= { question_id: session.question.id, request_key: crypto.randomUUID?.() || Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join(''), answer: readAnswer() };
        const frozen = Array.from($('answer-controls').querySelectorAll('input,button,select'));
        frozen.forEach(control => { control.disabled = true; });
        try {
            const result = await api(`sessions/${session.id}/answers`, pending);
            session = result.session; pending = null; updateSummary();
            $('feedback').textContent = result.is_correct ? 'Chính xác!' : 'Chưa chính xác. Hãy tiếp tục hoặc sửa lại khi đến phần sửa sai.';
            $('answer-form').hidden = true; $('btn-next-question').hidden = false;
        } catch (error) {
            $('feedback').textContent = error.message;
            if (error.status) {
                pending = null;
                try { session = await api(`sessions/${session.id}`); renderSession(); $('feedback').textContent = error.message; }
                catch { frozen.forEach(control => { control.disabled = false; }); }
            } else { $('btn-submit').textContent = 'Gửi lại câu trả lời'; }
            $('btn-submit').disabled = false;
        } finally { busy = false; }
    });
    $('btn-next-question').addEventListener('click', renderSession);
    showWord();
})();
