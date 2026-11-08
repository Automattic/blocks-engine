import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const directory = mkdtempSync(join(tmpdir(), 'sibling-link-lists-'));
const artifact = join(directory, 'fixture.json');
let browser;
try {
    execFileSync('php', [fileURLToPath(new URL('./sibling-link-lists.php', import.meta.url))], {
        env: {...process.env, SIBLING_LINK_LISTS_ARTIFACT: artifact},
    });
    const fixture = JSON.parse(readFileSync(artifact, 'utf8'));
    browser = await chromium.launch({headless: true});
    const page = await browser.newPage();
    for (const width of [390, 768, 1440]) {
        await page.setViewportSize({width, height: 900});
        const measurements = {};
        for (const role of ['source', 'native']) {
            // Navigation is dynamic in WordPress. Materialize its core render
            // surface from the public block attributes; keep all compiled CSS.
            const html = fixture[role]
                .replace(/<!-- wp:navigation (\{.*?\}) -->/g, (_, json) => {
                    const attrs = JSON.parse(json);
                    const direction = attrs.layout?.orientation === 'vertical' ? 'column' : 'row';
                    return `<nav class="wp-block-navigation ${attrs.className || ''}" style="display:flex;flex-direction:${direction}"><ul class="wp-block-navigation__container">`;
                })
                .replace(/<!-- \/wp:navigation -->/g, '</ul></nav>')
                .replace(/<!-- wp:navigation-link (\{.*?\}) \/-->/g, (_, json) => {
                    const attrs = JSON.parse(json);
                    return `<li class="wp-block-navigation-item wp-block-navigation-link ${attrs.className || ''}"><a class="wp-block-navigation-item__content" href="${attrs.url}"><span class="wp-block-navigation-item__label">${attrs.label}</span></a></li>`;
                });
            await page.setContent(html);
            measurements[role] = await page.locator('a').evaluateAll(links => links.map(link => ({
                label: link.textContent,
                y: link.getBoundingClientRect().top,
                color: getComputedStyle(link).color,
                decoration: getComputedStyle(link).textDecorationLine,
            })));
        }
        assert.deepEqual(measurements.native.map(link => link.label), ['Offer', 'Order', 'Terms']);
        measurements.native.forEach((link, index) => {
            assert.ok(Math.abs(link.y - measurements.source[index].y) <= 2, `${link.label} y at ${width}px`);
            if (index) assert.ok(link.y > measurements.native[index - 1].y, 'links stack vertically');
            assert.equal(link.color, 'rgb(255, 255, 255)', `${link.label} stays white`);
            assert.equal(link.decoration, 'underline', `${link.label} stays underlined`);
        });
        console.log(`${width}px: ${JSON.stringify(measurements)}`);
    }
} finally {
    await browser?.close();
    rmSync(directory, {recursive: true, force: true});
}
