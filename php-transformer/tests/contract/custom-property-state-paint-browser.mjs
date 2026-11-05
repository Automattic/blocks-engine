import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const directory = mkdtempSync(join(tmpdir(), 'state-paint-'));
let browser;
try {
    const artifact = join(directory, 'fixtures.json');
    execFileSync('php', [fileURLToPath(new URL('./custom-property-state-paint.php', import.meta.url))], {
        env: { ...process.env, CUSTOM_PROPERTY_STATE_ARTIFACT: artifact },
    });
    browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
    const page = await browser.newPage();
    for (const fixture of JSON.parse(readFileSync(artifact, 'utf8'))) {
        for (const role of ['source', 'native']) {
            await page.setContent(fixture[role]);
            const button = page.locator('a');
            await button.evaluate(node => node.addEventListener('click', event => event.preventDefault()));
            const background = () => button.evaluate(node => getComputedStyle(node).backgroundColor);
            await page.mouse.move(500, 500);
            assert.equal(await background(), 'rgb(202, 202, 202)', `${role}: rest`);
            await button.hover();
            assert.equal(await background(), 'rgb(189, 189, 189)', `${role}: hover`);
            await page.mouse.move(500, 500);
            assert.equal(await background(), 'rgb(202, 202, 202)', `${role}: rest restored`);
            if (fixture.chain) {
                await button.focus();
                assert.equal(await background(), 'rgb(189, 189, 189)', `${role}: focus`);
                await button.evaluate(node => node.blur());
                await button.hover();
                await page.mouse.down();
                assert.equal(await background(), 'rgb(189, 189, 189)', `${role}: active`);
                await page.mouse.up();
                // Anchors do not support :disabled. Use the same projected
                // identity on a native disabled button to exercise the gate.
                await button.evaluate(node => {
                    const disabled = document.createElement('button');
                    disabled.className = node.className;
                    disabled.textContent = node.textContent;
                    disabled.disabled = true;
                    node.replaceWith(disabled);
                });
                const disabled = page.locator('button');
                await disabled.hover({ force: true });
                assert.equal(await disabled.evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(202, 202, 202)', `${role}: disabled gating`);
            }
        }
    }
} finally {
    await browser?.close();
    rmSync(directory, { recursive: true, force: true });
}
console.log('Custom-property state paint browser contracts passed.');
