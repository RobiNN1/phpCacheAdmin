import {mkdir, writeFile} from 'node:fs/promises';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';
import sharp from 'sharp';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const options = {
    url: process.env.PCA_URL ?? 'http://127.0.0.1:8123',
    out: process.env.PCA_OUT ?? join(root, 'assets/img/preview'),
    width: Number(process.env.PCA_WIDTH ?? 1920),
    minHeight: Number(process.env.PCA_MIN_HEIGHT ?? 1040),
    quality: Number(process.env.PCA_QUALITY ?? 82),
};

const dashboards = [
    {slug: 'redis', query: 'dashboard=redis', expect: 'test-stream'},
    {slug: 'memcached', query: 'dashboard=memcached', expect: 'test-gzencode'},
    {slug: 'opcache', query: 'dashboard=opcache&pp=15'},
    {slug: 'apcu', query: 'dashboard=apcu', expect: 'test-gzencode'},
    {slug: 'realpath', query: 'dashboard=realpath&pp=15'},
    {slug: 'server', query: 'dashboard=server', rows: false, expect: 'Loaded PHP extensions'},
];

const themes = ['light', 'dark'];

await mkdir(options.out, {recursive: true});

const browser = await chromium.launch();
const failures = [];

for (const theme of themes) {
    const context = await browser.newContext({
        viewport: {width: options.width, height: 1080},
        deviceScaleFactor: 1,
        colorScheme: theme,
        reducedMotion: 'reduce',
    });

    const page = await context.newPage();

    for (const dashboard of dashboards) {
        const url = `${options.url}/?${dashboard.query}`;

        await page.setViewportSize({width: options.width, height: 1080});

        const response = await page.goto(url, {waitUntil: 'networkidle'});

        if (!response.ok()) {
            failures.push(`${url} responded with ${response.status()}`);
            continue;
        }

        await page.waitForSelector(`body[data-dashboard="${dashboard.slug}"]`);
        await page.evaluate(() => document.fonts.ready);

        if (await page.locator('#auth-warning-modal').count() > 0) {
            failures.push(`${url} opened the authentication modal, PCA_AUTHWARNING=false did not reach the app`);
            continue;
        }

        if (dashboard.rows !== false && await page.locator('tbody tr[data-key]').count() === 0) {
            failures.push(`${url} rendered an empty list`);
            continue;
        }

        if (dashboard.expect && !(await page.textContent('body')).includes(dashboard.expect)) {
            failures.push(`${url} does not mention "${dashboard.expect}", it did not render what it should`);
            continue;
        }

        const content = await page.evaluate(() => {
            const {style} = document.body;
            const original = style.minHeight;

            style.minHeight = '0';
            const height = Math.ceil(document.documentElement.getBoundingClientRect().height);
            style.minHeight = original;

            return height;
        });

        const height = Math.max(content, options.minHeight);

        await page.setViewportSize({width: options.width, height});

        const png = await page.screenshot({fullPage: true, animations: 'disabled'});
        const file = join(options.out, `${dashboard.slug}-${theme}.webp`);

        await writeFile(file, await sharp(png).webp({quality: options.quality, effort: 6}).toBuffer());

        console.log(`${dashboard.slug}-${theme}.webp (${options.width}x${height})`);
    }

    await context.close();
}

await browser.close();

if (failures.length > 0) {
    console.error('\nFailed:\n' + failures.map((failure) => ` - ${failure}`).join('\n'));
    process.exit(1);
}
