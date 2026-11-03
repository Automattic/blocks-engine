// php tests/unit/author-grid-track-ownership.php /tmp/author-grid.json
// WP_ROOT=/path/to/wordpress node tests/unit/author-grid-track-ownership-browser.mjs /tmp/author-grid.json
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
// Exercise real WordPress layout support, including the enqueued per-container
// rules. Rendering serialized save HTML alone would miss the original defect.
const rendered = JSON.parse(execFileSync('php', ['-r', `
$_SERVER['HTTP_HOST'] = 'localhost';
require getenv('WP_ROOT') . '/wp-load.php';
$fixture = json_decode(file_get_contents($argv[1]), true);
$html = do_blocks($fixture['markup']);
ob_start();
wp_print_styles();
wp_print_footer_scripts();
$styles = ob_get_clean();
echo json_encode(array('html' => $html, 'styles' => $styles));
`, process.argv[2]], { encoding: 'utf8' }));
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
try {
    const page = await browser.newPage();
    for (const [width, count] of [[1440, 2], [800, 1]]) {
        await page.setViewportSize({ width, height: 1000 });
        const measurements = {};
        for (const [name, html] of Object.entries({
            source: fixture.source,
            compiled: `<style>${fixture.css}</style>${rendered.styles}${rendered.html}`,
        })) {
            await page.setContent(html);
            measurements[name] = await page.locator('.posts-grid').evaluate(element => {
                const tracks = getComputedStyle(element).gridTemplateColumns;
                const rules = Array.from(document.styleSheets).flatMap(sheet => {
                    try { return Array.from(sheet.cssRules); } catch { return []; }
                });
                const defaultApplies = rules.some(rule => rule.selectorText &&
                    rule.style?.gridTemplateColumns.includes('12rem') && element.matches(rule.selectorText));
                return { tracks, count: tracks.split(' ').length, defaultApplies };
            });
            assert.equal(measurements[name].count, count);
            assert.equal(measurements[name].defaultApplies, false);
        }
        assert.deepEqual(measurements.compiled, measurements.source);
        console.log(JSON.stringify({ width, ...measurements }));
    }
} finally {
    await browser.close();
}
