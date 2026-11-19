// WORDPRESS_PATH=/path/to/wordpress CHROME_PATH=/usr/bin/google-chrome node tests/unit/inherited-alignment-lone-link-browser.mjs
//
// #6684: a link alone in a text-align wrapper renders where the source put it.
// Both lowerings are measured: the synthetic paragraph carrier (plain and
// inline-block links) and core/buttons (padded button-like links).
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');

const wpPath = process.env.WORDPRESS_PATH;
if (!wpPath) throw new Error('Set WORDPRESS_PATH to a WordPress checkout to load block-library CSS.');
const blockCss = readFileSync(`${wpPath}/wp-includes/css/dist/block-library/style.min.css`, 'utf8');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./inherited-alignment-lone-link.php', import.meta.url)), '--fixture'], { encoding: 'utf8' }));

// The frontend render adds what WordPress layout support adds to a flex
// core/buttons block: its justification class and the global flex display.
const rendered = fixture.markup.replace(
    /(<!-- wp:buttons (\{.*?\}) -->\s*<div class=")wp-block-buttons /g,
    (match, open, attrs) => {
        const justify = JSON.parse(attrs).layout?.justifyContent;
        return `${open}wp-block-buttons${justify ? ` is-content-justification-${justify}` : ''} `;
    }
);
const layoutCss = 'body .is-layout-flex{display:flex;flex-wrap:wrap;align-items:center}body .is-layout-flex>*{margin:0}';

const hrefs = ['register', 'tickets', 'schedule', 'venue'].map((slug) => `https://example.com/${slug}`);
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    for (const width of [390, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        const measure = () => page.evaluate((links) => links.map((href) => {
            const { x, width } = document.querySelector(`a[href="${href}"]`).getBoundingClientRect();
            return x + width / 2;
        }), hrefs);
        await page.setContent(fixture.source);
        const expected = await measure();
        await page.setContent(`<style>${blockCss}\n${layoutCss}\n${fixture.css}</style>${rendered}`);
        const actual = await measure();
        hrefs.forEach((href, index) => {
            assert.ok(Math.abs(actual[index] - expected[index]) <= 1, `${width}px ${href} centre: source=${expected[index]}, imported=${actual[index]}`);
        });
    }
    console.log('Lone aligned link browser geometry passed');
} finally {
    await browser.close();
}
