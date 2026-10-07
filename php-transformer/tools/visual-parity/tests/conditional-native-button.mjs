import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { wordpressButtonCss } from './wordpress-button-css.mjs';

const root = process.env.BE_TRANSFORMER_ROOT || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const fixture = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../tests/fixtures/conditional-native-button.php');
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
        const measure = () => page.locator('a,button').evaluateAll(elements => elements.map(element => {
            const text = element.querySelector('.label') || element;
            const style = getComputedStyle(element), typography = getComputedStyle(text);
            return { text: element.textContent, fontSize: typography.fontSize, fontWeight: typography.fontWeight,
                lineHeight: typography.lineHeight, backgroundColor: style.backgroundColor, color: typography.color,
                padding: style.padding, borderRadius: style.borderRadius };
        }));
        await page.setContent(output.source);
        const source = await measure();
        await page.setContent(`<style>${wordpressButtonCss}${output.css}</style>${output.markup}`);
        assert.deepEqual(await measure(), source, `${width}px native control cascade matches authored source`);
        await page.close();
    }
} finally {
    await browser.close();
}
console.log('Conditional native button browser: 390/768/1440 passed');
