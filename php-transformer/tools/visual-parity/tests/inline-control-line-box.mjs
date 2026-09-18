import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { wordpressButtonCss } from './wordpress-button-css.mjs';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

// Source: header CTA on aromatic-monolith about/index.html.
// Parent `div.hidden.lg:block` is a block box whose line box is the inherited
// 16px/1.5 strut (24px). The anchor is UA `display:inline`, so its 12px
// vertical padding overflows and does not grow the container.
const sourceFixture = `<div class="cta-host"><a class="cta" href="/contact">Start Your Project</a></div>`;
const sourceCss = `.cta-host{font:16px/1.5 sans-serif}.cta{padding:12px 24px;font-size:14px;line-height:20px;font-weight:600;text-transform:uppercase;background:#111;color:#fff}`;
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('serializedBlocks' => (string) ($result['serialized_blocks'] ?? ''), 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot, Buffer.from(`<style>${sourceCss}</style>${sourceFixture}`).toString('base64')], { encoding: 'utf8' }));

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 400 } });
    await page.setContent(`<!doctype html><style>body{margin:0}${sourceCss}</style>${sourceFixture}`);
    const sourceHost = await page.locator('.cta-host').evaluate((element) => element.getBoundingClientRect().height);

    await page.setContent(`<!doctype html><style>body{margin:0}${wordpressButtonCss}${transformed.css}</style>${transformed.serializedBlocks}`);
    const importedHost = await page.locator('.cta-host, .wp-block-group.cta-host').evaluate((element) => element.getBoundingClientRect().height);

    // 24px is the source line box (16px font × 1.5). Materializing the inline
    // anchor as display:flex core/buttons made this 44px (delta +20).
    assert.equal(sourceHost, 24, `source host height: ${sourceHost}`);
    assert.ok(
        Math.abs(importedHost - sourceHost) < 1,
        `inline CTA host grew from ${sourceHost}px to ${importedHost}px (block-level buttons box)`
    );
    console.log(`Inline control line box: sourceHost=${sourceHost} importedHost=${importedHost}`);
} finally {
    await browser.close();
}

console.log('Inline control line box geometry passed');
