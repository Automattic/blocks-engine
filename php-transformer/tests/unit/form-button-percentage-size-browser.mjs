import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    for (const sizing of ['style="max-width:30%;padding:0 32px"', 'class="submit"']) {
        const reset = 'body{margin:8px}input{min-width:0}';
        const html = `<style>${reset}.submit{padding:0 32px}@media(min-width:768px){.submit{max-width:30%}}</style>`
            + `<form action="/subscribe"><div style="display:flex;width:100%"><input name="email" style="flex:1"><button type="submit" ${sizing}>Registrarse</button></div></form>`;
        const result = JSON.parse(execFileSync('php', ['-r', php, root, html], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
        const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
        for (const width of [390, 768, 1440]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const measure = () => page.evaluate(() => {
                const button = document.querySelector('button');
                const rect = button.getBoundingClientRect();
                const range = document.createRange();
                range.selectNodeContents(button);
                return { width: rect.width, x: rect.x, labelX: range.getBoundingClientRect().x };
            });
            await page.setContent(html);
            const source = await measure();
            await page.setContent(`<style>${reset}\n${css}</style>${result.serialized_blocks}`);
            assert.deepEqual(await measure(), source, `${sizing} at ${width}px retains control and label geometry`);
            assert.equal(await page.locator('button').evaluate(button => button.parentElement.tagName), 'DIV');
            await page.close();
        }
    }
} finally {
    await browser.close();
}
console.log('Percentage-sized form button geometry matches at 390/768/1440px.');
