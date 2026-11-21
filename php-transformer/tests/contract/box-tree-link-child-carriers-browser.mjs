import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

// #7208: the brand link's children render their carriers' responsive values,
// not base declarations frozen on inline marks, and the small keeps its
// right alignment. Computed against the delivered (engine-owned) CSS.
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const directory = mkdtempSync(join(tmpdir(), 'box-tree-link-child-carriers-'));
const artifact = join(directory, 'fixture.json');
let browser;
try {
    execFileSync('php', [fileURLToPath(new URL('./box-tree-link-child-carriers.php', import.meta.url))], {
        env: {...process.env, BOX_TREE_LINK_CHILD_CARRIERS_ARTIFACT: artifact},
    });
    const {html} = JSON.parse(readFileSync(artifact, 'utf8'));
    browser = await chromium.launch({headless: true});
    const page = await browser.newPage();
    for (const [width, strongSize] of [[1440, '35px'], [768, '28px']]) {
        await page.setViewportSize({width, height: 900});
        await page.setContent(html);
        const probe = await page.evaluate(() => {
            const text = (selector, needle) => {
                const walker = document.createTreeWalker(document.querySelector(selector), NodeFilter.SHOW_TEXT);
                for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                    if (node.textContent.includes(needle)) return getComputedStyle(node.parentElement);
                }
                throw new Error(`No text ${needle} in ${selector}`);
            };
            const small = document.querySelector('a.brand small');
            const smallRun = text('a.brand small', 'BY ME');
            const range = document.createRange();
            range.selectNodeContents(small);
            return {
                strong: text('a.brand strong', 'MX').fontSize,
                span: text('a.brand strong', 'PERF').fontSize,
                smallSize: smallRun.fontSize,
                smallSpacing: smallRun.letterSpacing,
                smallAlign: getComputedStyle(small).textAlign,
                smallMargin: getComputedStyle(small).marginTop,
                textRight: range.getBoundingClientRect().right,
                boxRight: small.getBoundingClientRect().right,
            };
        });
        assert.equal(probe.strong, strongSize, `${width}: strong text font-size`);
        assert.equal(probe.span, strongSize, `${width}: nested span text font-size`);
        assert.equal(probe.smallSize, '11px', `${width}: small text font-size`);
        assert.equal(probe.smallSpacing, '8px', `${width}: small text letter-spacing`);
        assert.equal(probe.smallAlign, 'right', `${width}: small keeps text-align:right`);
        assert.equal(probe.smallMargin, '8px', `${width}: small keeps margin-top on its box`);
        assert.ok(Math.abs(probe.textRight - probe.boxRight) < 1, `${width}: small text sits at its box's right edge`);
        console.log(`PASS: ${width}px brand link computes ${strongSize} strong text and a right-aligned small`);
    }
} finally {
    await browser?.close();
    rmSync(directory, {recursive: true, force: true});
}
