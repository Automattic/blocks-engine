import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { wordpressButtonCss } from './wordpress-button-css.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const output = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$source = file_get_contents($argv[1] . '/tests/fixtures/button-inline-layout.html');
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('entrypoint'=>'index.html','files'=>array('index.html'=>$source)))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $a): bool => 'css' === ($a['kind'] ?? '') && 'editor' !== ($a['stylesheet_target'] ?? ''));
echo json_encode(array('source'=>$source,'markup'=>$result['serialized_blocks'],'css'=>implode("\\n",array_column($css,'content'))));
`, root], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const measure = () => page.locator('a').evaluate(element => {
        const button = element.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(element);
        const label = range.getBoundingClientRect();
        return { button: { x: button.x, y: button.y, width: button.width, height: button.height },
            label: { x: label.x, y: label.y - button.y, width: label.width, height: label.height } };
    });
    await page.setContent(output.source);
    const source = await measure();
    await page.setContent(`<style>${wordpressButtonCss}${output.css}</style>${output.markup}`);
    const actual = await measure();
    console.log(JSON.stringify({ source, actual }));
    for (const box of ['button', 'label']) {
        // Vertical flow/margin collapse is independent of the control's layout.
        for (const dimension of box === 'button' ? ['x', 'width', 'height'] : ['x', 'y', 'width', 'height']) {
            assert.ok(Math.abs(actual[box][dimension] - source[box][dimension]) <= 1,
                `${box} ${dimension} matches the source`);
        }
    }
} finally {
    await browser.close();
}
console.log('Inline button layout passed');
