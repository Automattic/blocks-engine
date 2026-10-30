import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = process.env.BE_TRANSFORMER_ROOT || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const fixture = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../tests/fixtures/block-link-cards.php');
const output = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$source = require $argv[2];
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('entrypoint'=>'index.html','files'=>array('index.html'=>$source)))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $a): bool => 'css' === ($a['kind'] ?? '') && 'editor' !== ($a['stylesheet_target'] ?? ''));
echo json_encode(array('source'=>$source,'markup'=>$result['serialized_blocks'],'css'=>implode("\\n",array_column($css,'content'))));
`, root, fixture], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true });
try {
    for (const width of [1440, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        const measure = () => page.locator('.grid').evaluate(grid => ({
            height: grid.getBoundingClientRect().height,
            cards: [...grid.querySelectorAll('.card')].map(card => ({
                height: card.getBoundingClientRect().height,
                href: card.getAttribute('href'), target: card.getAttribute('target'), rel: card.getAttribute('rel'),
                titles: [...card.querySelectorAll('h4.card__title')].map(title => ({
                    fontSize: getComputedStyle(title).fontSize,
                    href: title.closest('a')?.getAttribute('href'),
                })),
            })),
        }));
        await page.setContent(output.source);
        const source = await measure();
        await page.setContent(`<!doctype html><style>${output.css}</style>${output.markup}`);
        const imported = await measure();
        assert.equal(imported.cards.length, 3);
        for (let index = 0; index < 3; index++) {
            const card = imported.cards[index], original = source.cards[index];
            assert.deepEqual(card.titles, [{fontSize: width === 1440 ? '16px' : '14px', href: `https://example.com/${index + 1}`}]);
            for (const attr of ['href', 'target', 'rel']) assert.equal(card[attr], original[attr]);
            assert.ok(Math.abs(card.height - original.height) <= 1, `${width}px card height: ${card.height} vs ${original.height}`);
        }
        assert.ok(Math.abs(imported.height - source.height) <= 1, `${width}px grid height: ${imported.height} vs ${source.height}`);
        await page.close();
    }
} finally {
    await browser.close();
}
console.log('Block link cards browser: 1440/390 passed');
