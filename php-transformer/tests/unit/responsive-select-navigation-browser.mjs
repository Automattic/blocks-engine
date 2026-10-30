// php tests/unit/responsive-select-navigation.php /tmp/select-navigation.json
// WP_ROOT=/path/to/wordpress node tests/unit/responsive-select-navigation-browser.mjs /tmp/select-navigation.json
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const rendered = JSON.parse(execFileSync('php', ['-r', `
require getenv('WP_ROOT') . '/wp-load.php';
$fixture = json_decode(file_get_contents($argv[1]), true);
echo json_encode(array('html' => do_blocks($fixture['markup']), 'css' =>
    file_get_contents(ABSPATH . WPINC . '/blocks/navigation/style.min.css')));
`, process.argv[2]], { encoding: 'utf8' }));
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 800 } });
    const measurements = {};
    for (const [name, html] of Object.entries({
        source: fixture.source,
        compiled: `<style>${rendered.css}\n${fixture.css}</style>${rendered.html}`,
    })) {
        // Same neutral page frame on both sides; Core supplies the navigation paint.
        await page.setContent(`<style>body{margin:20px;font:16px Arial}p{margin:0}.is-layout-flex{display:flex}</style>${html}`);
        measurements[name] = await page.evaluate(() => {
            const control = document.querySelector('select, .wp-block-navigation__responsive-container-open');
            const rect = control.getBoundingClientRect();
            const style = getComputedStyle(control);
            return { width: rect.width, height: rect.height, marginBottom: style.marginBottom, padding: style.padding, followingY: document.querySelector('p').getBoundingClientRect().y };
        });
    }
    console.log(JSON.stringify(measurements));
    assert.deepEqual(measurements.compiled, measurements.source);
} finally {
    await browser.close();
}
