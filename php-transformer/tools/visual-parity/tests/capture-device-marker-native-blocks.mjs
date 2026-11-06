import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// Device profiles core block visibility cannot express fall back to a class and
// a generated rule, which shows the same parts as the source for every device
// selection, at every viewport width. WordPress renders core block visibility
// itself, so the contract test covers those viewports.
const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const source = readFileSync(`${transformerRoot}/tests/fixtures/capture-device-marker-native-blocks.html`, 'utf8');
const compiled = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => stream_get_contents(STDIN))))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? ''));
echo json_encode(array('markup' => (string) ($result['serialized_blocks'] ?? ''), 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot], { input: source, encoding: 'utf8' }));

const selectionAttribute = 'data-blocks-engine-capture-selected-document';
const parts = ['kiosk', 'not-kiosk'];
const visibility = page => page.evaluate(ids => Object.fromEntries(
    ids.map(id => [id, document.getElementById(id).getClientRects().length > 0])
), parts);
const expectedFor = selection => ({ kiosk: 'kiosk' === selection, 'not-kiosk': 'kiosk' !== selection });
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const width of [390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        for (const selection of [null, 'desktop', 'mobile', 'kiosk']) {
            const select = () => page.evaluate(([name, value]) => {
                if (null === value) document.documentElement.removeAttribute(name);
                else document.documentElement.setAttribute(name, value);
            }, [selectionAttribute, selection]);
            const label = `at ${width}px with selection ${selection ?? 'none'}`;
            await page.setContent(source);
            await select();
            assert.deepEqual(await visibility(page), expectedFor(selection), `source shows the expected parts ${label}`);
            await page.setContent(`<style>${compiled.css}</style>${compiled.markup}`);
            await select();
            assert.deepEqual(await visibility(page), expectedFor(selection), `compiled parts match the source ${label}`);
        }
    }
    console.log('PASS: custom device profiles on native blocks match the source for every selection at 390, 768 and 1440 px');
} finally {
    await browser.close();
}
