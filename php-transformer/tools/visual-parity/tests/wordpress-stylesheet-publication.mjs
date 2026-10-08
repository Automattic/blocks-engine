import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = process.env.PLAYWRIGHT_MODULE ? await import(process.env.PLAYWRIGHT_MODULE) : require('playwright');
const root = new URL('../../..', import.meta.url).pathname;
const fixture = JSON.parse(execFileSync('php', [root + '/tests/unit/navigation-stylesheet-order.php', '--fixture'], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }));
assert.ok(process.env.WORDPRESS_PATH, 'Actual WordPress is required');
const wp = process.env.WORDPRESS_PATH;
const nativeCss = ['navigation', 'group'].map(name => readFileSync(wp + '/wp-includes/blocks/' + name + '/style.min.css', 'utf8')).join('\n');
const files = new Map(Object.entries({ ...fixture.documents, ...fixture.stylesheets }).map(([path, content]) => ['/' + path, content]));
const server = createServer((request, response) => {
    const path = decodeURIComponent(new URL(request.url, 'http://local').pathname);
    response.statusCode = files.has(path) ? 200 : 404;
    response.setHeader('Content-Type', path.endsWith('.css') ? 'text/css' : 'text/html');
    response.end(files.get(path) ?? '');
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const scratch = mkdtempSync(join(tmpdir(), 'be-stylesheet-publication-'));
const evidence = [];
const routeFilter = process.argv.find(arg => arg.startsWith('--route='))?.slice(8);
let browser;
try {
    for (const [index, candidate] of fixture.cases.entries()) {
        const plan = candidate.plan;
        const bootstrap = plan.writes.find(w => w.target_path === 'functions.php').payload.data;
        for (const document of candidate.site.pages) {
            if (routeFilter && document.source_path !== routeFilter) continue;
            const page = plan.pages.find(p => p.source_path === document.source_path);
            const input = join(scratch, 'input.json');
            writeFileSync(input, JSON.stringify({ plan, page, bootstrap, markup: document.block_markup, base: `${origin}/native-${index}` }));
            const runtime = JSON.parse(execFileSync(process.env.WP_CLI ?? 'wp', ['--path=' + wp, '--skip-plugins', '--skip-themes', 'eval-file', root + '/tools/visual-parity/tests/wordpress-stylesheet-publication-runtime.php', input], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }));
            assert.equal(runtime.stable, true);
            assert.deepEqual(runtime.unregistered, []);
            assert.equal(runtime.names.filter(name => name === 'core/navigation-link').length, 4);
            for (const write of runtime.resources) files.set(`/native-${index}/${write.target_path}`, write.payload.data);
            files.set(`/native-${index}/${document.source_path}`, `<style>${nativeCss}</style>${runtime.head}${runtime.html}`);
            evidence.push({ index, route: document.source_path, enqueued: runtime.enqueued, version: runtime.version, resourcePaths: plan.assets.map(a => a.target_path) });
        }
    }
    browser = await chromium.launch({ headless: true });
    for (const width of [1440, 390, 768]) for (const item of evidence) {
        const observations = [];
        for (const path of [item.route, `native-${item.index}/${item.route}`]) {
            const page = await browser.newPage({ viewport: { width, height: 400 } });
            await page.goto(`${origin}/${path}`, { waitUntil: 'load' });
            observations.push(await page.evaluate(() => {
                const box = e => { const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height }; };
                return { bands: [...document.querySelectorAll('div.navbar')].map(box), landmarks: [...document.querySelectorAll('nav.landmark')].map(box), hosts: [...document.querySelectorAll('.landmark > .nav')].map(e => ({ box: box(e), display: getComputedStyle(e).display, vertical: getComputedStyle(e).verticalAlign })), items: [...document.querySelectorAll('.landmark li')].map(e => ({ box: box(e), display: getComputedStyle(e).display, float: getComputedStyle(e).float })), following: box(document.querySelector('.following')), anchors: [...document.querySelectorAll('.landmark a')].map(e => ({ box: box(e), font: getComputedStyle(e).fontSize, line: getComputedStyle(e).lineHeight, padding: getComputedStyle(e).padding, color: getComputedStyle(e).color })), width: document.documentElement.scrollWidth };
            }));
            await page.close();
        }
        assert.deepEqual(observations[1], observations[0], `${item.route} / permutation ${item.index} / ${width}px: actual emitted WordPress publication matches source`);
        if (item.route === 'named-repeated.html' || item.route === 'repeated.html') {
            const name = item.route === 'repeated.html' ? 'repeat-a.css' : 'named-a.css';
            const occurrences = item.enqueued.filter(s => s.src.endsWith('/' + name));
            assert.equal(occurrences.length, 2, 'the generated bootstrap enqueues both A instances');
            assert.equal(new Set(occurrences.map(s => s.handle)).size, 2, 'A instances have unique WordPress handles');
            assert.equal(new Set(occurrences.map(s => s.src)).size, 1, 'A instances share one resource URI');
            assert.equal(item.resourcePaths.filter(path => path.endsWith('/' + name)).length, 1, 'the plan writes one A resource');
        }
    }
    console.log(JSON.stringify({ comparisons: evidence.length * 3, evidence }, null, 2));
    console.log('Actual WordPress stylesheet-instance publication passed');
} finally { await browser?.close(); await new Promise(resolve => server.close(resolve)); rmSync(scratch, { recursive: true, force: true }); }
