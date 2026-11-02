// Optional browser regression: node tests/fixtures/table-width-hints/render-check.mjs
import { chromium } from '../../../tools/visual-parity/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const compiled = JSON.parse(execFileSync('php', ['-r', `
require 'vendor/autoload.php';
$dir = 'tests/fixtures/table-width-hints/';
$r = (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('entrypoint'=>'index.html', 'files'=>array('index.html'=>file_get_contents($dir.'index.html'), 'style.css'=>file_get_contents($dir.'style.css'))))->toArray();
echo json_encode(array('markup'=>$r['serialized_blocks'], 'css'=>implode("\\n", array_column(array_filter($r['assets'], static fn($a)=>str_ends_with($a['path'], '.css')), 'content'))));
`], { cwd: root, encoding: 'utf8' }));
const source = readFileSync(new URL('./index.html', import.meta.url), 'utf8')
    .replace('<link rel="stylesheet" href="style.css">', `<style>${readFileSync(new URL('./style.css', import.meta.url), 'utf8')}</style>`);
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const viewport of [1000, 500]) {
        await page.setViewportSize({ width: viewport, height: 800 });
        const measure = async (html) => {
            await page.setContent(html);
            return page.evaluate(() => ({
                table: document.querySelector('table').getBoundingClientRect().width,
                cells: [...document.querySelectorAll('thead th')].map(c => c.getBoundingClientRect().width),
            }));
        };
        const original = await measure(source);
        const output = await measure(`<style>${compiled.css}</style>${compiled.markup}`);
        assert.ok(Math.abs(output.table - 540) < 1, `${viewport}: table is 100% wide`);
        original.cells.forEach((width, i) => assert.ok(Math.abs(output.cells[i] - width) < 1, `${viewport}: cell ${i} matches source (${output.cells[i]} vs ${width})`));
        if (viewport === 1000) {
            output.cells.forEach(width => assert.ok(Math.abs(width - 270) < 1, 'desktop columns are 50/50'));
        } else {
            assert.ok(Math.abs(output.cells[0] - output.cells[1]) > 100, 'mobile auto reset replaces equal widths');
        }
    }
    console.log('Table width hints rendered regression passed.');
} finally {
    await browser.close();
}
