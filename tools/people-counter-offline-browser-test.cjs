const {chromium} = require('playwright');
const {spawn, spawnSync} = require('node:child_process');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
    const origin = 'http://127.0.0.1:8093';
    const php = process.env.PHP_BINARY || 'php';
    const hash = spawnSync(php, ['-r', "echo password_hash('counter-browser-test-only', PASSWORD_DEFAULT);"], {
        encoding: 'utf8', windowsHide: true
    });
    assert.equal(hash.status, 0, 'PHP fixture hash must be generated');
    const server = spawn(php, ['-S', '127.0.0.1:8093', '-t', 'public', 'public/index.php'], {
        env: {...process.env, ADMIN_EMAIL: 'counter-test@example.test', ADMIN_PASSWORD_HASH: hash.stdout.trim()},
        stdio: 'ignore', windowsHide: true
    });
    let browser;
    try {
        let ready = false;
        for (let attempt = 0; attempt < 80; attempt += 1) {
            assert.equal(server.exitCode, null, 'Test server must own its local port');
            try {
                const response = await fetch(`${origin}/auth/login`);
                ready = response.ok;
            } catch {}
            if (ready) break;
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        assert(ready, 'Local PHP fixture server must start');
        browser = await chromium.launch({headless: true, channel: 'chrome'});
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', async dialog => {
            errors.push('Unexpected dialog');
            await dialog.dismiss();
        });
        await page.goto(`${origin}/auth/login`);
        await page.locator('[name=email]').fill('counter-test@example.test');
        await page.locator('[name=password]').fill('counter-browser-test-only');
        await page.getByRole('button', {name: 'Đăng nhập', exact: true}).click();
        await page.waitForURL(url => url.pathname === '/admin');
        let polling = 0;
        page.on('request', request => {
            if (new URL(request.url()).pathname.startsWith('/api/admin/people-counter')) polling += 1;
        });
        await page.goto(`${origin}/admin/people-counter?tab=offline`);
        await page.locator('[data-offline-counter]').waitFor();
        assert.equal(await page.locator('[data-counter-screen]').count(), 0);
        assert.equal(await page.getByRole('link', {name: 'Video đã ghi', exact: true}).getAttribute('aria-current'), 'page');
        assert.equal(await page.getByRole('link', {name: 'Video đã ghi', exact: true}).evaluate(element => getComputedStyle(element).color), 'rgb(255, 255, 255)');
        assert.equal(await page.locator('[data-offline-results]').isVisible(), false);
        const report = {
            status: 'complete', sourceFile: 'fixture-video.mp4', sourceSha256: 'a'.repeat(64),
            processedFrames: 90, videoSeconds: 7.5, cameraStart: null,
            totals: {IN: 3, OUT: 2}, expected: {IN: 2, OUT: null},
            calibrationVerified: false, operationalEventsSent: 0
        };
        const select = value => page.locator('[data-offline-file]').setInputFiles({
            name: 'report.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(value))
        });
        await select(report);
        await page.locator('[data-offline-results]:visible').waitFor();
        assert.equal(await page.locator('[data-offline-total]').textContent(), '5');
        assert.equal(await page.locator('[data-offline-diff-in]').textContent(), '+1');
        assert.equal(await page.locator('[data-offline-expected-out]').textContent(), 'Chưa nhập');
        assert((await page.locator('[data-offline-calibration]').textContent()).includes('Chưa xác minh'));
        fs.mkdirSync('.deploy/counter-offline-ui', {recursive: true});
        for (const [name, width, height] of [
            ['desktop', 1920, 1080], ['laptop', 1366, 768], ['tablet', 768, 1024],
            ['mobile', 390, 844], ['mobile-small', 320, 720]
        ]) {
            await page.setViewportSize({width, height});
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${name}: no page overflow`);
            assert(await page.locator('[data-offline-file]').evaluate(element => element.getBoundingClientRect().height >= 44));
            if (width >= 768) {
                assert(await page.evaluate(() => {
                    const title = document.querySelector('h1').getBoundingClientRect();
                    const tabs = document.querySelector('[data-counter-tabs]').getBoundingClientRect();
                    return tabs.left >= title.right && tabs.top < title.bottom && tabs.bottom > title.top;
                }), `${name}: tabs must sit to the right of the title`);
            }
            await page.screenshot({path: `.deploy/counter-offline-ui/${name}.png`, fullPage: true});
        }
        await select({...report, status: 'incomplete'});
        await page.getByText('Video chưa được xử lý đầy đủ.', {exact: false}).waitFor();
        await select({...report, sourceFile: '<img src=x onerror=alert(1)>.mp4'});
        await page.locator('[data-offline-video]').filter({hasText: '<img'}).waitFor();
        assert.equal(await page.locator('[data-offline-video] img').count(), 0, 'Filename must be text, not markup');
        await select({invalid: true});
        await page.locator('[data-offline-error]:visible').waitFor();
        assert.equal(await page.locator('[data-offline-results]').isVisible(), false, 'Bad report hides previous totals');
        await page.waitForTimeout(1500);
        assert.equal(polling, 0, 'Offline tab must not poll the camera or report API');
        await page.getByRole('link', {name: 'Trực tiếp', exact: true}).click();
        await page.locator('[data-counter-screen]').waitFor();
        assert.equal(await page.locator('[data-offline-counter]').count(), 0);
        assert.equal(await page.getByRole('link', {name: 'Trực tiếp', exact: true}).getAttribute('aria-current'), 'page');
        assert.equal(await page.getByRole('link', {name: 'Trực tiếp', exact: true}).evaluate(element => getComputedStyle(element).color), 'rgb(255, 255, 255)');
        assert.deepEqual(errors, []);
        await browser.close();
        browser = null;
        const live = spawnSync(process.execPath, ['tools/people-counter-browser-test.cjs'], {
            env: {...process.env, COUNTER_TEST_ORIGIN: origin}, stdio: 'inherit',
            windowsHide: true, timeout: 180000
        });
        assert.equal(live.status, 0, 'Existing live browser regression must still pass');
        console.log('Counter tabs: live retained; offline import, validation, no polling, XSS and 5 responsive sizes OK');
    } finally {
        if (browser) await browser.close();
        server.kill();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
