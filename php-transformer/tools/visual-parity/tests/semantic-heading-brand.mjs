import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = process.env.BE_TRANSFORMER_ROOT || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const fixture = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../tests/fixtures/semantic-heading-brand.php');
const output = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$source = require $argv[2];
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('entrypoint'=>'index.html','files'=>array('index.html'=>$source)))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $a): bool => 'css' === ($a['kind'] ?? '') && 'editor' !== ($a['stylesheet_target'] ?? ''));
echo json_encode(array('source'=>$source,'markup'=>$result['serialized_blocks'],'css'=>implode("\\n",array_column($css,'content'))));
`, root, fixture], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true });
try {
    for (const width of [390, 768, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        const measure = () => page.locator('header .wordmark').evaluate(element => {
            const style = getComputedStyle(element), box = element.getBoundingClientRect();
            return { tag: element.tagName, text: element.textContent, fontFamily: style.fontFamily,
                fontSize: style.fontSize, fontWeight: style.fontWeight, lineHeight: style.lineHeight,
                width: box.width, height: box.height };
        });
        await page.setContent(output.source);
        const source = await measure();
        await page.setContent(`<style>${output.css}</style>${output.markup}`);
        assert.deepEqual(await measure(), source, `${width}px semantic wordmark typography and box match source`);
        await page.close();
    }
} finally {
    await browser.close();
}
console.log('Semantic heading brand browser: 390/768/1440 passed');
