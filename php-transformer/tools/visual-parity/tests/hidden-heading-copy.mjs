import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const wpPath = process.env.WORDPRESS_PATH;
if (!wpPath) throw new Error('Set WORDPRESS_PATH to a WordPress checkout to load block-library CSS.');
const blockCss = readFileSync(`${wpPath}/wp-includes/css/dist/block-library/style.min.css`, 'utf8');
const source = readFileSync(`${root}/tests/fixtures/hidden-heading-copy.html`, 'utf8');
const output = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('entrypoint'=>'index.html','files'=>array('index.html'=>stream_get_contents(STDIN))))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $a): bool => 'css' === ($a['kind'] ?? '') && 'editor' !== ($a['stylesheet_target'] ?? ''));
echo json_encode(array('markup'=>$result['serialized_blocks'],'css'=>implode("\\n",array_column($css,'content'))));
`, root], { input: source, encoding: 'utf8' }));
assert.ok(!output.markup.includes('Research and heritage'), 'no phantom measuring paragraph');
console.log(output.markup);
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    const measure = () => page.evaluate(() => Object.fromEntries(['h4', 'p', '.cell'].map(selector => {
        const rect = document.querySelector(selector).getBoundingClientRect();
        return [selector, { x: rect.x, y: rect.y, width: rect.width, height: rect.height }];
    })));
    for (const width of [390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(source);
        const before = await measure();
        await page.setContent(`<style>${blockCss}\n${output.css}</style>${output.markup}`);
        const after = await measure();
        console.log(JSON.stringify({ width, before, after }));
        for (const selector of ['h4', 'p', '.cell']) {
            for (const dimension of ['x', 'y', 'width', 'height']) {
                assert.ok(Math.abs(before[selector][dimension] - after[selector][dimension]) <= 1, `${width}: ${selector} ${dimension}`);
            }
        }
    }
} finally {
    await browser.close();
}
