import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const sourceFixture = `<style>
  .stage { position: relative; width: 540px; height: 540px; }
  .absolute { position: absolute; }
  .center { translate: -50% -50%; }
  .label { display: block; max-width: 7.5rem; padding: 8px 12px; text-align: center; font-size: 9.6px; letter-spacing: 1.536px; line-height: 13.2px; text-transform: uppercase; white-space: normal; box-sizing: border-box; border: 1px solid #ccc; }
  .actions { display: flex; gap: 16px; }
  .btn { padding: 8px 16px; background: #111; color: #fff; }
</style>
<div class="stage">
  <div class="absolute center" style="left:50%;top:50%;width:80px;height:80px">Hub</div>
  <button type="button" class="absolute center" style="left:50%;top:6%"><span class="label">Asset Managers</span></button>
  <button type="button" class="absolute center" style="left:12%;top:28%"><span class="label">Educators &amp; Creators</span></button>
  <button type="button" class="absolute center" style="left:88%;top:28%"><span class="label">Advisors &amp; Distributors</span></button>
  <button type="button" class="absolute center" style="left:88%;top:72%"><span class="label">Platforms &amp; Fintech</span></button>
  <button type="button" class="absolute center" style="left:50%;top:94%"><span class="label">Research &amp; Media</span></button>
  <button type="button" class="absolute center" style="left:12%;top:72%"><span class="label">Market Infrastructure</span></button>
</div>
<div class="actions">
  <a class="btn" href="/one">One</a>
  <a class="btn" href="/two">Two</a>
</div>`;
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('serializedBlocks' => (string) ($result['serialized_blocks'] ?? ''), 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot, Buffer.from(sourceFixture).toString('base64')], { encoding: 'utf8' }));

const wordpressButtonCss = `.wp-block-buttons{box-sizing:border-box;display:flex;flex-wrap:wrap;gap:.5em}.wp-block-button{box-sizing:border-box;display:inline-block}.wp-block-button__link{box-sizing:border-box;cursor:pointer;display:inline-block;padding:0;text-align:center;word-break:break-word}`;
const labels = ['ASSET MANAGERS', 'EDUCATORS & CREATORS', 'ADVISORS & DISTRIBUTORS', 'MARKET INFRASTRUCTURE', 'RESEARCH & MEDIA', 'PLATFORMS & FINTECH'];

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 800, height: 700 } });
    const measure = async (html) => {
        await page.setContent(html);
        return page.evaluate((L) => Object.fromEntries(L.map((label) => {
            const leaf = [...document.querySelectorAll('*')].find((node) => node.children.length === 0 && (node.textContent || '').replace(/\s+/g, ' ').trim().toUpperCase() === label);
            if (!leaf) {
                return [label, null];
            }
            const box = leaf.getBoundingClientRect();
            const wrap = leaf.closest('.wp-block-button') || leaf.closest('button');
            const link = leaf.closest('.wp-block-button__link');
            const outer = wrap ? wrap.getBoundingClientRect() : box;
            const inner = link ? link.getBoundingClientRect() : box;
            return [label, {
                width: parseFloat(getComputedStyle(leaf).width),
                box: [Math.round(box.width), Math.round(box.height)],
                wrapPosition: wrap ? getComputedStyle(wrap).position : '',
                wrapTranslate: wrap ? getComputedStyle(wrap).translate : '',
                linkTranslate: link ? getComputedStyle(link).translate : '',
                delta: [Math.round(inner.x - outer.x), Math.round(inner.y - outer.y)],
            }];
        })), labels);
    };

    const source = await measure(`<!doctype html><style>body{margin:0;font:16px/1.2 sans-serif}${sourceFixture}</style>`);
    const imported = await measure(`<!doctype html><style>body{margin:0;font:16px/1.2 sans-serif}${wordpressButtonCss}${transformed.css}</style>${transformed.serializedBlocks}`);

    for (const label of labels) {
        assert.ok(source[label], `source ${label}`);
        assert.ok(imported[label], `imported ${label}`);
        const sourceWidth = source[label].width;
        const importedWidth = imported[label].width;
        assert.ok(
            Math.abs(importedWidth - sourceWidth) < 8,
            `${label} collapsed to min-content: imported ${importedWidth} vs source ${sourceWidth}`
        );
        assert.ok(
            imported[label].box[1] <= source[label].box[1] + 8,
            `${label} grew taller than shrink-to-fit: imported ${imported[label].box} vs source ${source[label].box}`
        );
        assert.deepEqual(imported[label].delta, [0, 0], `${label} inner link drifted from wrapper: ${JSON.stringify(imported[label])}`);
        assert.equal(imported[label].wrapPosition, 'absolute', `${label} wrap position: ${imported[label].wrapPosition}`);
        assert.equal(imported[label].wrapTranslate, '-50% -50%', `${label} wrap translate: ${imported[label].wrapTranslate}`);
        assert.ok(
            imported[label].linkTranslate === 'none' || imported[label].linkTranslate === '0px' || imported[label].linkTranslate === '',
            `${label} inner link re-applied placement: ${imported[label].linkTranslate}`
        );
    }

    const advisors = imported['ADVISORS & DISTRIBUTORS'];
    const platforms = imported['PLATFORMS & FINTECH'];
    assert.ok(advisors.width > 90 && advisors.width <= 120, `ADVISORS shrink-to-fit width: ${advisors.width}`);
    assert.ok(platforms.width > 80 && platforms.width < 120, `PLATFORMS shrink-to-fit width: ${platforms.width}`);
    assert.ok(advisors.box[1] < 60, `ADVISORS height stayed two lines: ${advisors.box}`);
    assert.ok(platforms.box[1] < 60, `PLATFORMS height stayed two lines: ${platforms.box}`);

    const group = await page.locator('.actions, .wp-block-group.actions').evaluate((element) => {
        const wrappers = [...element.querySelectorAll('.wp-block-buttons')];
        return {
            wrappersAreContents: wrappers.every((wrapper) => getComputedStyle(wrapper).display === 'contents'),
            count: wrappers.length,
        };
    });
    assert.equal(group.wrappersAreContents, false, 'in-flow buttons wrappers were flattened');
    assert.ok(group.count >= 2, `in-flow buttons missing: ${JSON.stringify(group)}`);

    console.log(`Positioned button shrink-to-fit: ${JSON.stringify({ source: Object.fromEntries(labels.map((label) => [label, source[label].box])), imported: Object.fromEntries(labels.map((label) => [label, { width: imported[label].width, box: imported[label].box }])) })}`);
} finally {
    await browser.close();
}

console.log('Positioned button shrink-to-fit geometry passed');
