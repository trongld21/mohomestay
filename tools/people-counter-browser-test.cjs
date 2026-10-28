// Run against a local PHP server with synthetic ADMIN_EMAIL/ADMIN_PASSWORD_HASH.
// Reports are mocked so no camera or operational database is needed.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
    const origin = process.env.COUNTER_TEST_ORIGIN || 'http://127.0.0.1:8093';
    assert(['localhost', '127.0.0.1'].includes(new URL(origin).hostname), 'Local test server only');
    const browser = await chromium.launch({headless: true, channel: 'chrome'});
    try {
        const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => {
            if (message.type() === 'error' && !message.text().includes('503')) errors.push(message.text());
        });
        await page.goto(`${origin}/auth/login`);
        await page.locator('[name=email]').fill('counter-test@example.test');
        await page.locator('[name=password]').fill('counter-browser-test-only');
        await page.getByRole('button', {name: 'Đăng nhập', exact: true}).click();
        await page.waitForURL(url => url.pathname === '/admin');
        let state = 'WAITING';
        let sourceState = null;
        let unavailable = false;
        let latestQuery;
        let liveIn = 7;
        const jpeg = await page.evaluate(async () => {
            const image = new Image();
            image.src = '/images/black-illustration.jpg';
            await image.decode();
            const canvas = document.createElement('canvas');
            canvas.width = 320;
            canvas.height = 180;
            canvas.getContext('2d').drawImage(image, 0, 0, 320, 180);
            return canvas.toDataURL('image/jpeg', 0.5).split(',')[1];
        });
        await page.route('**/api/admin/people-counter/preview?*', route => route.fulfill({json: sourceState
            ? {available: false, state: sourceState} : state === 'RUNNING'
            ? {available: true, state: 'RUNNING', frameAt: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'), jpeg, session: {id: 'a'.repeat(32), startedAt: '2026-10-27T00:00:00Z', in: liveIn, out: 10, trial: true}}
            : {available: false, state: state === 'STALE' ? 'STALE' : 'WAITING'}}));
        await page.route('**/api/admin/people-counter?*', route => {
            latestQuery = new URL(route.request().url()).searchParams;
            if (unavailable) return route.fulfill({status: 503, json: {error: 'Chưa tải được bộ đếm.'}});
            const active = state === 'RUNNING';
            const requestedPage = Number(latestQuery.get('page') || 1);
            return route.fulfill({json: {
                from: latestQuery.get('from'), to: latestQuery.get('to'), mode: latestQuery.get('mode'),
                totals: {in: active ? 52 : 0, out: active ? 12 : 0}, count: active ? 64 : 0,
                page: requestedPage, pages: active ? 2 : 1,
                camera: {id: 'entrance', name: 'Lễ tân / cửa chính', state, lastReceivedAt: active ? '2026-10-27T12:00:00Z' : null},
                cameras: [{id: 'entrance', name: 'Lễ tân / cửa chính'}],
                hours: Array.from({length: 24}, (_, hour) => ({hour, in: active && hour === 19 ? 52 : 0, out: active && hour === 19 ? 12 : 0})),
                events: active ? [{id: 'a'.repeat(32), direction: 'IN', occurredAt: '2026-10-27T12:00:00Z'}, {id: 'b'.repeat(32), direction: 'OUT', occurredAt: '2026-10-27T11:00:00Z'}] : []
            }});
        });
        await page.goto(`${origin}/admin/people-counter`);
        await page.getByText('Chưa có dữ liệu đếm trong báo cáo.', {exact: false}).waitFor();
        assert.equal(await page.locator('[data-counter-health]').count(), 0, 'Removed status badge must not render');
        assert.equal(await page.locator('body.admin-page').count(), 1, 'Counter must use admin layout');
        assert.equal(await page.locator('#admin-sidebar a[href="/admin/people-counter"]').count(), 1);
        assert.equal(await page.locator('[data-counter-report-total]').textContent(), '0');
        assert.equal(await page.locator('[data-counter-total]').textContent(), '—');
        assert.equal(await page.locator('[data-counter-next]').isDisabled(), true);
        fs.mkdirSync('.deploy/counter-ui', {recursive: true});
        await page.screenshot({path: '.deploy/counter-ui/desktop-waiting.png', fullPage: true});
        state = 'RUNNING';
        await page.getByRole('button', {name: 'Xem báo cáo'}).click();
        await page.getByText('Dịch vụ AI đang nhận video.', {exact: false}).waitFor();
        await page.locator('[data-counter-image]:visible').waitFor();
        sourceState = 'MINIMIZED';
        await page.getByText('Chờ mở lại Imou PC', {exact: true}).waitFor();
        assert.equal(await page.locator('[data-counter-image]').isVisible(), false);
        sourceState = 'BRIDGE_OFFLINE';
        await page.getByText('Dịch vụ lấy video đã dừng', {exact: true}).waitFor();
        sourceState = null;
        await page.locator('[data-counter-image]:visible').waitFor();
        assert(await page.locator('[data-counter-image]').evaluate(image => image.complete && image.naturalWidth > 0), 'Camera image must decode');
        await page.getByRole('button', {name: 'Tạm dừng hình ảnh'}).click();
        assert.equal(await page.locator('[data-counter-image]').isVisible(), false);
        liveIn = 8;
        await page.waitForFunction(() => document.querySelector('[data-counter-in]').textContent === '8');
        assert.equal(await page.locator('[data-counter-total]').textContent(), '18', 'Pausing image must not pause live totals');
        liveIn = 7;
        await page.getByRole('button', {name: 'Tiếp tục hình ảnh'}).click();
        await page.locator('[data-counter-image]:visible').waitFor();
        assert.equal(await page.locator('[data-counter-total]').textContent(), '17');
        assert.equal(await page.locator('[data-counter-report-total]').textContent(), '64');
        assert.equal(latestQuery.get('mode'), 'trial');
        assert((await page.locator('[data-counter-report-title]').textContent()).includes('chưa đối soát'));
        await page.locator('[name=mode]').selectOption('operational');
        await page.getByRole('button', {name: 'Xem báo cáo'}).click();
        await page.waitForFunction(() => document.querySelector('[data-counter-report-title]').textContent.startsWith('Báo cáo vận hành'));
        assert.equal(latestQuery.get('mode'), 'operational');
        assert.equal(await page.locator('[data-counter-total]').textContent(), '17');
        await page.locator('[name=mode]').selectOption('trial');
        await page.getByRole('button', {name: 'Xem báo cáo'}).click();
        await page.waitForFunction(() => document.querySelector('[data-counter-report-title]').textContent.includes('chưa đối soát'));
        assert.equal(await page.locator('[data-counter-chart] [role=img]').count(), 24);
        assert((await page.locator('[data-counter-events]').textContent()).includes('19:00:00'));
        await page.locator('[data-counter-next]').click();
        await page.getByText('Trang 2/2 · 64 lượt').waitFor();
        assert.equal(latestQuery.get('page'), '2');
        assert.equal(await page.locator('[data-counter-next]').isDisabled(), true);
        assert.equal(await page.locator('[data-counter-total]').textContent(), '17', 'Report refresh must not overwrite session totals');
        unavailable = true;
        await page.getByRole('button', {name: 'Xem báo cáo'}).click();
        await page.locator('[data-counter-error]:visible').waitFor();
        assert.equal(await page.locator('[data-counter-total]').textContent(), '17', 'Report failure must preserve fresh live totals');
        assert.equal(await page.locator('[data-counter-report-total]').textContent(), '—');
        unavailable = false;
        await page.getByRole('button', {name: 'Xem báo cáo'}).click();
        await page.locator('[data-counter-error]').waitFor({state: 'hidden'});
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({path: '.deploy/counter-ui/desktop-running.png', fullPage: true});
        await page.setViewportSize({width: 390, height: 844});
        await page.evaluate(() => window.scrollTo(0, 0));
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Mobile horizontal overflow');
        await page.screenshot({path: '.deploy/counter-ui/mobile-running.png', fullPage: true});
        await page.getByRole('button', {name: 'Mở menu quản trị'}).press('Enter');
        await page.locator('#admin-sidebar a[href="/admin/people-counter"]').waitFor({state: 'visible'});
        await page.getByRole('button', {name: 'Đóng menu quản trị'}).press('Enter');
        const viewports = [
            ['monitor-24-fhd', 1920, 1080],
            ['monitor-qhd', 2560, 1440],
            ['laptop-large', 1440, 900],
            ['laptop', 1366, 768],
            ['tablet-landscape', 1024, 768],
            ['tablet-portrait', 768, 1024],
            ['mobile', 390, 844],
            ['mobile-small', 320, 720],
            ['mobile-landscape', 844, 390],
            ['desktop-scaled', 960, 540]
        ];
        for (const [name, width, height] of viewports) {
            await page.setViewportSize({width, height});
            await page.evaluate(() => window.scrollTo(0, 0));
            const layout = await page.evaluate(() => {
                const box = element => {
                    const {x, y, width, height, right, bottom} = element.getBoundingClientRect();
                    return {x, y, width, height, right, bottom};
                };
                return {
                    root: box(document.querySelector('[data-people-counter]')),
                    camera: box(document.querySelector('[aria-labelledby="counter-camera-title"]')),
                    screen: box(document.querySelector('[data-counter-screen]')),
                    stats: box(document.querySelector('[data-counter-stats]')),
                    controls: [...document.querySelectorAll('[data-counter-filter] input, [data-counter-filter] select, [data-counter-filter] button')].map(box),
                    imageFit: getComputedStyle(document.querySelector('[data-counter-image]')).objectFit
                };
            });
            assert(layout.root.x >= 0 && layout.root.right <= width + 1, `${name}: page must fit viewport`);
            assert(layout.screen.width <= layout.root.width && layout.screen.height <= Math.max(208, height * 0.61) + 1,
                `${name}: video must fit available width and viewport height`);
            assert.equal(layout.imageFit, 'contain', `${name}: camera must not crop people or counting line`);
            for (const control of layout.controls) {
                assert(control.x >= layout.root.x - 1 && control.right <= layout.root.right + 1 && control.height >= 44,
                    `${name}: filter control must fit and remain touch accessible`);
            }
            if (width >= 1536) {
                assert(layout.stats.x >= layout.camera.right, `${name}: wide-screen stats should sit beside camera`);
            } else {
                assert(layout.stats.y >= layout.camera.bottom, `${name}: stats should follow camera`);
            }
            const cameraCell = page.locator('[data-counter-events] tr').first().locator('td').nth(2);
            assert.equal(await cameraCell.isVisible(), width >= 640, `${name}: history column should adapt to screen`);
            await page.screenshot({path: `.deploy/counter-ui/${name}.png`, fullPage: true});
        }
        state = 'STALE';
        await page.getByRole('button', {name: 'Xem báo cáo'}).click();
        await page.getByText('Đã quá 90 giây chưa có tín hiệu mới.', {exact: false}).waitFor();
        await page.getByText('Hình ảnh bị gián đoạn', {exact: true}).waitFor();
        assert.equal(await page.locator('[data-counter-image]').isVisible(), false);
        unavailable = true;
        await page.getByRole('button', {name: 'Xem báo cáo'}).click();
        await page.locator('[data-counter-error]:visible').waitFor();
        assert.equal(await page.locator('[data-counter-total]').textContent(), '—');
        assert.equal(await page.locator('[data-counter-next]').isDisabled(), true);
        assert.deepEqual(errors, []);
        const anonymous = await browser.newContext();
        assert.equal((await anonymous.request.get(`${origin}/api/admin/people-counter`)).status(), 401);
        assert.equal((await anonymous.request.get(`${origin}/api/admin/people-counter/preview`)).status(), 401);
        const rejected = await anonymous.request.post(`${origin}/api/integration/people-counter`, {data: {events: []}});
        assert.equal(rejected.status(), 401);
        assert.equal(rejected.headers()['set-cookie'], undefined, 'Ingest must not create a session');
        assert.equal((await anonymous.request.get(`${origin}/api/integration/people-counter`)).status(), 405);
        console.log('Counter browser: 10 responsive viewports, camera bounds, touch controls, history columns, states and session isolation OK');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
