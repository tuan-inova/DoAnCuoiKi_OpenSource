// Requires Playwright and a disposable test server initialized by tests/integration/learning.php.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const base = process.env.TEST_BASE_URL;
if (!base) throw new Error('Set TEST_BASE_URL to the local *_test database server.');
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, args: ['--no-sandbox'] });
    try {
        const guest = await browser.newContext();
        assert.equal((await guest.request.get(base + '/learning?completed=4')).status(), 401);
        const context = await browser.newContext();
        const login = await context.request.post(base + '/api/v1/auth/login', { data: { email: 'a@example.test', password: 'test-password-123' } });
        assert.equal(login.status(), 200);
        assert.match(login.headers()['set-cookie'], /doulingo_session=.*HttpOnly/i);
        const locked = await guest.request.post(base + '/api/v1/auth/login', { data: { email: 'b@example.test', password: 'test-password-123' } });
        assert.equal(locked.status(), 403);
        await context.route('**/assets/images/learning-test.svg', route => route.fulfill({
            contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="blue"/></svg>'
        }));
        await context.route('**/assets/audio/learning-test.wav', route => route.fulfill({
            contentType: 'audio/wav', body: Buffer.from('UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQAAAAA=', 'base64')
        }));
        const page = await context.newPage();
        const jsErrors = []; page.on('pageerror', error => jsErrors.push(error.message));
        await page.goto(base + '/learning?completed=4');
        assert.equal(await page.locator('.lesson-node-wrapper').count(), 3);
        const hrefs = await page.locator('a.lesson-node').evaluateAll(links => links.map(link => link.getAttribute('href')));
        assert(hrefs.includes('/learning/lesson/1') && hrefs.includes('/learning/lesson/2') && hrefs.includes('/learning/lesson/3'));
        await page.goto(base + '/learning/lesson/1');
        assert.equal(await page.locator('#fc-ex-en').textContent(), '</script><img src=x onerror="window.__learningXss=1">');
        assert.equal(await page.locator('#fc-ex-en img').count(), 0);
        assert.equal(await page.evaluate(() => window.__learningXss), undefined);
        await page.locator('#btn-next-word').click();
        assert.equal(await page.locator('#fc-ex-en').textContent(), '');
        assert.equal(await page.locator('#fc-example').isVisible(), false);
        assert.equal(await page.locator('#fc-ex-translation').textContent(), '');
        const boot = await page.locator('#learning-data').textContent().then(JSON.parse);
        const noCsrf = await context.request.post(base + '/api/v1/learning/lessons/1/sessions', { data: {} });
        assert.equal(noCsrf.status(), 403);
        // No answer keys occur in session responses; only current public question is available.
        const started = await context.request.post(base + '/api/v1/learning/lessons/1/sessions', {
            headers: { 'X-CSRF-Token': boot.csrfToken }, data: {}
        });
        assert.equal(started.status(), 200);
        const state = (await started.json()).data;
        assert(!JSON.stringify(state).includes('correct_option_ids'));
        assert(!JSON.stringify(state).includes('accepted_answers'));
        // Real browser resumes the server session rather than making a new one on refresh.
        await page.reload(); await page.locator('#btn-start').click();
        await page.locator('#exercise').waitFor({ state: 'visible' });
        assert.match(await page.locator('#hearts-display').getAttribute('aria-label'), /\/3 tim/);
        await page.goto(base + '/learning/lesson/3');
        await page.locator('#btn-start').click();
        await page.locator('#exercise').waitFor({ state: 'visible' });
        for (let i = 0; i < 3; i++) {
            const hasOptions = await page.locator('#answer-controls input[type=radio]').count();
            if (hasOptions) {
                await page.locator('#answer-controls input[value="a"]').check();
            } else {
                await page.locator('#answer-controls input[type=text]').fill('milk');
            }
            await page.locator('#btn-submit').click();
            await page.locator('#btn-next-question').waitFor({ state: 'visible' });
            assert.equal(await page.locator('#feedback').textContent(), 'Chính xác!');
            await page.locator('#btn-next-question').click();
        }
        assert.equal(await page.locator('#result-title').textContent(), 'Hoàn thành bài học!');
        assert.match(await page.locator('#result-score').textContent(), /3\/3.*\+5 XP/);
        assert.equal(jsErrors.length, 0, jsErrors.join('\n'));
        await page.setViewportSize({ width: 390, height: 844 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        console.log('PASS: guest/locked auth, shared cookie, CSRF, URL progress, correct lesson IDs, XSS, stale example, resume, image/listening submissions, server result and mobile layout');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
