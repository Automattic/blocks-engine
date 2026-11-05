import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// Folded device variants keep their capture markers through compilation, so
// the capture visibility sheet shows the same variant as the source for every
// device selection, at every viewport width.
const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const source = readFileSync(`${transformerRoot}/tests/fixtures/capture-device-marker-pattern-hosts.html`, 'utf8');
const compiled = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => stream_get_contents(STDIN))))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? ''));
echo json_encode(array('markup' => (string) ($result['serialized_blocks'] ?? ''), 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot], { input: source, encoding: 'utf8' }));

const selectionAttribute = 'data-blocks-engine-capture-selected-document';
const visibility = page => page.evaluate(() => Object.fromEntries(
    ['/call/', '/visit/'].map(href => [href, document.querySelector(`a[href="${href}"]`).getClientRects().length > 0])
));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const width of [390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        for (const selection of [null, 'desktop', 'mobile']) {
            const select = () => page.evaluate(([name, value]) => {
                if (null === value) document.documentElement.removeAttribute(name);
                else document.documentElement.setAttribute(name, value);
            }, [selectionAttribute, selection]);
            await page.setContent(source);
            await select();
            const expected = await visibility(page);
            const mobile = 'mobile' === selection;
            assert.deepEqual(expected, { '/call/': mobile, '/visit/': !mobile }, `source shows only the ${mobile ? 'mobile' : 'desktop'} variant at ${width}px`);
            await page.setContent(`<style>${compiled.css}</style>${compiled.markup}`);
            await select();
            assert.deepEqual(await visibility(page), expected, `compiled variant visibility matches the source at ${width}px with selection ${selection ?? 'none'}`);
        }
    }
    console.log('PASS: device marker visibility matches the source for every selection at 390, 768 and 1440 px');
} finally {
    await browser.close();
}
