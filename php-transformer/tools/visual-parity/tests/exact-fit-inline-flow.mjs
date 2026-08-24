import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const autoload = new URL('../../../vendor/autoload.php', import.meta.url).pathname;
const php = String.raw`
require ${JSON.stringify(autoload)};
$items = '';
foreach ([100, 110, 120, 130, 143] as $index => $width) {
    $items .= '<div style="display:inline-block;width:' . $width . 'px;height:49px"><a href="/' . $index . '">Item ' . $index . '</a></div>';
}
$result = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform(
    '<style>@media(max-width:700px){.exact-menu{display:none}}</style>' .
    '<div class="exact-menu" style="width:603px;height:49px;overflow:hidden">' . $items . '</div>'
)->toArray();
$css = implode("\n", array_map(
    static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    $result['assets'] ?? []
));
echo json_encode(['markup' => $result['serialized_blocks'], 'css' => $css], JSON_THROW_ON_ERROR);
`;
const fixture = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));

// Gutenberg may put formatting whitespace between nested block delimiters.
const serializedWithWhitespace = fixture.markup.replaceAll('--><!-- wp:group', '-->\n<!-- wp:group');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1008, height: 400 } });
    await page.setContent(`<style>*{box-sizing:border-box}${fixture.css}</style>${serializedWithWhitespace}`);
    const boxes = await page.locator('.exact-menu > .wp-block-group').evaluateAll((items) =>
        items.map((item) => {
            const box = item.getBoundingClientRect();
            return { x: box.x, y: box.y, width: box.width, height: box.height };
        })
    );
    const menu = await page.locator('.exact-menu').evaluate((item) => {
        const box = item.getBoundingClientRect();
        return { x: box.x, y: box.y, width: box.width, height: box.height };
    });

    assert.equal(menu.width, 603);
    assert.equal(menu.height, 49);
    assert.deepEqual(boxes.map(({ width }) => width), [100, 110, 120, 130, 143]);
    assert.ok(boxes.every(({ y }) => y === boxes[0].y), 'all five items remain on one row');
    assert.equal(boxes.at(-1).x + boxes.at(-1).width, menu.x + menu.width);

    await page.setViewportSize({ width: 600, height: 400 });
    assert.equal(await page.locator('.exact-menu').evaluate((item) => getComputedStyle(item).display), 'none');
} finally {
    await browser.close();
}

console.log('Exact-fit inline flow geometry passed');
