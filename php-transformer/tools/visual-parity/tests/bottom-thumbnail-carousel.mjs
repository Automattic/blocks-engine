import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright';
const directory = mkdtempSync(join(tmpdir(), 'bottom-carousel-'));
const browser = await chromium.launch({ args: ['--no-sandbox'] });
try {
    const output = join(directory, 'result.json');
    execFileSync('php', [new URL('../../../tests/unit/bottom-thumbnail-carousel.php', import.meta.url).pathname, output]);
    const result = JSON.parse(readFileSync(output, 'utf8'));
    const definition = result.source_reports.generated_blocks[0];
    const css = [...result.assets.filter(a => a.kind === 'css').map(a => a.content), definition.assets['style.css']].join('\n');
    for (const width of [1440, 390]) {
        const page = await browser.newPage();
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(`<style>${css}</style>${result.serialized_blocks}`);
        // Run the emitted view module with only the WordPress directive/store
        // adapter replaced. Layout, counter getter and navigation are production code.
        await page.addScriptTag({ content: `
            window.root = document.querySelector('[data-wp-interactive]');
            window.context = JSON.parse(root.dataset.wpContext);
            const getContext = () => context;
            let ref = root;
            const getElement = () => ({ ref });
            const withScope = callback => callback;
            const store = (name, value) => { window.carouselStore = value; };
            ${definition.view_js.replace(/^import .*?;\s*/s, '')}
            const renderStatus = () => { root.querySelector('[aria-live]').textContent = carouselStore.state.statusText; };
            carouselStore.callbacks.init(); renderStatus();
            root.querySelectorAll('[data-carousel-index]').forEach(button => button.addEventListener('click', event => {
                ref = button; carouselStore.actions.goTo(event); renderStatus();
            }));
        ` });
        const geometry = await page.locator('[data-wp-interactive]').evaluate(root => {
            const stage = root.querySelector('.blocks-engine-authored-carousel__source-stage').getBoundingClientRect();
            const rail = root.querySelector('.blocks-engine-authored-carousel__thumbnails').getBoundingClientRect();
            const status = root.querySelector('[aria-live]');
            return { width: root.getBoundingClientRect().width, stageWidth: stage.width, stageBottom: stage.bottom, railTop: rail.top, railWidth: rail.width, statusWidth: status.getBoundingClientRect().width, text: status.textContent, live: status.getAttribute('aria-live'), display: getComputedStyle(status).display };
        });
        assert.ok(Math.abs(geometry.stageWidth - geometry.width) < 1, JSON.stringify(geometry));
        assert.ok(Math.abs(geometry.railWidth - geometry.width) < 1, JSON.stringify(geometry));
        assert.ok(geometry.railTop >= geometry.stageBottom, JSON.stringify(geometry));
        assert.equal(geometry.text, '1 / 3');
        assert.equal(geometry.live, 'polite');
        assert.notEqual(geometry.display, 'none');
        assert.ok(width >= 768 ? geometry.statusWidth === 1 : geometry.statusWidth > 1);
        await page.getByRole('button', { name: 'Show slide 2', exact: true }).click();
        assert.equal(await page.locator('[aria-live]').textContent(), '2 / 3');
        console.log(JSON.stringify({ viewport: width, ...geometry }));
        await page.close();
    }
} finally {
    await browser.close();
    rmSync(directory, { recursive: true, force: true });
}
