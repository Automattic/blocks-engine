import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const directory = mkdtempSync(join(tmpdir(), 'root-absolute-sprite-'));
let browser;
try {
    const artifact = join(directory, 'fixture.json');
    execFileSync('php', [fileURLToPath(new URL('./root-absolute-sprite-links.php', import.meta.url))], {
        env: {...process.env, ROOT_ABSOLUTE_SPRITE_FIXTURE: artifact},
    });
    const fixtures = JSON.parse(readFileSync(artifact, 'utf8'));
    browser = await chromium.launch({headless: true});
    const page = await browser.newPage();
    const measurements = {};
    for (const [route, fixture] of Object.entries(fixtures)) {
        await page.setContent(`<style>${fixture.css}</style>${fixture.candidate}`);
        measurements[route] = await page.locator('footer a').evaluate(link => ({
            background: getComputedStyle(link).backgroundImage !== 'none',
            width: link.getBoundingClientRect().width,
            height: link.getBoundingClientRect().height,
            labelWidth: link.querySelector('span').getBoundingClientRect().width,
        }));
    }
    console.log(JSON.stringify(measurements));
    for (const [route, measurement] of Object.entries(measurements)) {
        assert.deepEqual(measurement, {background: true, width: 32, height: 32, labelWidth: 0}, route);
    }
} finally {
    await browser?.close();
    rmSync(directory, {recursive: true, force: true});
}
