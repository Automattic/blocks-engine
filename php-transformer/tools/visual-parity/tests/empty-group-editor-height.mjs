import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const result = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform('<style>.frame{position:relative;height:120px}.source-grid{display:grid;position:absolute;inset:0}.paint{height:auto;container-type:size;background:#eaf0f4}</style><main><div class="frame"><div class="source-grid"><div class="paint"></div></div></div></main>')->toArray();
echo json_encode(['markup'=>$result['serialized_blocks'], 'css'=>implode("\\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => ($asset['kind'] ?? '') === 'css'), 'content'))]);
`, root], { encoding: 'utf8' }));

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    await page.setContent(`<style>${result.css}</style>${result.markup}`);
    const heights = await page.evaluate(() => {
        const paint = document.querySelector('.paint');
        const sourceHeight = paint.getBoundingClientRect().height;
        const wrapper = document.createElement('div');
        paint.before(wrapper);
        wrapper.append(paint);
        paint.classList.add('wp-block-group__placeholder');
        paint.setAttribute('data-block', 'fixture');
        const withoutEditorScope = paint.getBoundingClientRect().height;
        document.body.classList.add('editor-styles-wrapper');
        const editorHeight = paint.getBoundingClientRect().height;
        wrapper.className = 'authored-wrapper';
        return { sourceHeight, withoutEditorScope, editorHeight, authoredDisplay: getComputedStyle(wrapper).display };
    });
    assert.equal(heights.sourceHeight, 120);
    assert.equal(heights.withoutEditorScope, 0);
    assert.equal(heights.editorHeight, 120);
    assert.equal(heights.authoredDisplay, 'block');
} finally {
    await browser.close();
}
console.log('Empty Group editor height preserved without flattening authored wrappers');
