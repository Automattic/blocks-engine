import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const viewport = { width: 1280, height: 900 };
const utilities = [
    'body{margin:0;font:16px/1.2 ui-sans-serif,system-ui,sans-serif}',
    '.hidden{display:none}',
    '.flex{display:flex}',
    '.items-center{align-items:center}',
    '.justify-between{justify-content:space-between}',
    '.gap-4{gap:1rem}',
    '.fixed{position:fixed}',
    '.inset-0{inset:0}',
    '.text-white{color:#fff}',
    '.mx-auto{margin-left:auto;margin-right:auto}',
    '.max-w-screen-lg{max-width:1024px}',
    '.wp-block-navigation__container{display:flex;list-style:none;margin:0;padding:0}',
    '.wp-block-navigation-item{display:list-item}',
    '.wp-block-group>:where(p){margin:0}',
    '@media (min-width:768px){.md\\:block{display:block}.md\\:hidden{display:none}}',
].join('');

const overlaySource = `<nav class="mx-auto flex max-w-screen-lg items-center justify-between">`
    + `<a href="/" id="logo">~/site</a>`
    + `<div class="hidden md:block"><ul class="flex gap-4">`
    + `<li><a href="/now">Now</a></li>`
    + `<li><a href="/blog">Archive</a></li>`
    + `<li><a href="/contact">Contact</a></li>`
    + `</ul></div>`
    + `<button type="button" class="md:hidden" aria-label="Toggle Menu"><span></span><span></span><span></span></button>`
    + `<div class="fixed inset-0 md:hidden"><ul>`
    + `<li><a href="/now" class="text-white">Now</a></li>`
    + `<li><a href="/blog" class="text-white">Archive</a></li>`
    + `<li><a href="/contact" class="text-white">Contact</a></li>`
    + `</ul></div></nav>`;

const visibleSecondSource = `<nav class="mx-auto flex max-w-screen-lg items-center justify-between">`
    + `<a href="/" id="logo">~/site</a>`
    + `<div class="menu"><ul class="flex gap-4">`
    + `<li><a href="/now">Now</a></li>`
    + `<li><a href="/blog">Archive</a></li>`
    + `<li><a href="/contact">Contact</a></li>`
    + `</ul></div>`
    + `<div class="extras"><ul class="flex gap-4">`
    + `<li><a href="/notes">Notes</a></li>`
    + `<li><a href="/labs">Labs</a></li>`
    + `<li><a href="/press">Press</a></li>`
    + `</ul></div></nav>`;

const transform = (html) => JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . "/vendor/autoload.php";
echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());
`, root, Buffer.from(`<style>${utilities}</style>${html}`).toString('base64')], { encoding: 'utf8' }));

const renderBlocks = (blocks) => blocks.map(renderBlock).join('');

const renderBlock = (block) => {
    if (!block || typeof block !== 'object') {
        return '';
    }
    const name = block.blockName ?? '';
    const attrs = block.attrs ?? {};
    const className = attrs.className ?? '';
    if (name === 'core/group') {
        const tag = attrs.tagName || 'div';
        return `<${tag} class="wp-block-group ${className}">${renderBlocks(block.innerBlocks ?? [])}</${tag}>`;
    }
    if (name === 'core/paragraph') {
        return block.innerHTML || '';
    }
    if (name === 'core/navigation') {
        const items = (block.innerBlocks ?? [])
            .filter((item) => item.blockName === 'core/navigation-link')
            .map((item) => {
                const link = item.attrs ?? {};
                return `<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="${link.url ?? ''}">${link.label ?? ''}</a></li>`;
            })
            .join('');
        return `<nav class="wp-block-navigation ${className}"><ul class="wp-block-navigation__container ${className}">${items}</ul></nav>`;
    }
    return `${block.innerHTML || ''}${renderBlocks(block.innerBlocks ?? [])}`;
};

const assetCss = (result) => (result.assets ?? [])
    .filter((asset) => asset.kind === 'css')
    .map((asset) => asset.content ?? '')
    .join('\n');

const measure = async (page, html, css) => {
    await page.setContent(`<!doctype html><style>${css}</style>${html}`);
    return page.evaluate(() => {
        const nav = document.querySelector('nav');
        const anchors = [...document.querySelectorAll('nav a')].map((anchor) => {
            const box = anchor.getBoundingClientRect();
            return {
                text: (anchor.textContent ?? '').trim(),
                x: Math.round(box.x),
                width: Math.round(box.width),
                visible: box.width > 0.5 && box.height > 0.5 && getComputedStyle(anchor).display !== 'none',
            };
        });
        const navBox = nav ? nav.getBoundingClientRect() : { x: 0, width: 0 };
        return {
            nav: { x: Math.round(navBox.x), width: Math.round(navBox.width) },
            anchors,
            visible: anchors.filter((anchor) => anchor.visible),
        };
    });
};

const overlay = transform(overlaySource);
const visibleSecond = transform(visibleSecondSource);
const overlayHtml = renderBlocks(overlay.blocks ?? []);
const visibleHtml = renderBlocks(visibleSecond.blocks ?? []);

assert.match(overlayHtml, /blocks-engine-brand-navigation-carrier/, 'imported overlay nav keeps the brand carrier');
assert.equal(
    (overlayHtml.match(/wp-block-navigation-item__content/g) ?? []).length,
    3,
    'imported overlay nav emits three in-flow links'
);

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport });

    const sourceOverlay = await measure(page, overlaySource, utilities);
    const importedOverlay = await measure(page, overlayHtml, `${utilities}\n${assetCss(overlay)}`);

    const sourceVisible = sourceOverlay.visible;
    const importedVisible = importedOverlay.visible;
    assert.equal(sourceVisible.length, 4, `source visible anchors: ${JSON.stringify(sourceVisible)}`);
    assert.equal(importedVisible.length, 4, `imported visible anchors: ${JSON.stringify(importedVisible)}`);
    assert.deepEqual(
        importedVisible.map((anchor) => anchor.text),
        ['~/site', 'Now', 'Archive', 'Contact'],
        `imported visible labels: ${JSON.stringify(importedVisible)}`
    );
    for (const extra of importedOverlay.anchors.filter((anchor) => !anchor.visible)) {
        assert.equal(extra.width, 0, `collapsed overlay copy still occupies layout: ${JSON.stringify(extra)}`);
    }

    const sourceMenu = sourceVisible.slice(1);
    const importedMenu = importedVisible.slice(1);
    for (const [index, item] of importedMenu.entries()) {
        assert.equal(item.width, sourceMenu[index].width, `${item.text} width ${item.width} drifted from source ${sourceMenu[index].width}`);
        assert.ok(
            Math.abs(item.x - sourceMenu[index].x) <= 24,
            `${item.text} x ${item.x} drifted from source ${sourceMenu[index].x} (spread bar, not right cluster)`
        );
    }
    const last = importedMenu.at(-1);
    const navRight = importedOverlay.nav.x + importedOverlay.nav.width;
    assert.ok(last.x > importedOverlay.nav.x + importedOverlay.nav.width / 2, `menu still starts mid-bar: ${JSON.stringify(importedMenu)}`);
    assert.ok(Math.abs(last.x + last.width - navRight) <= 24, `Contact does not sit on the right edge: x=${last.x} width=${last.width} navRight=${navRight}`);

    const sourceSecond = await measure(page, visibleSecondSource, utilities);
    const importedSecond = await measure(page, visibleHtml, `${utilities}\n${assetCss(visibleSecond)}`);
    const secondLabels = importedSecond.visible.map((anchor) => anchor.text);
    for (const label of ['Now', 'Archive', 'Contact', 'Notes', 'Labs', 'Press']) {
        assert.ok(secondLabels.includes(label), `visible second group lost ${label}: ${JSON.stringify(importedSecond.visible)}`);
        const box = importedSecond.visible.find((anchor) => anchor.text === label);
        assert.ok(box.width > 8, `visible second group collapsed ${label}: ${JSON.stringify(box)}`);
    }
    assert.ok(
        importedSecond.visible.length >= sourceSecond.visible.length,
        `visible second group shrank ${importedSecond.visible.length} vs source ${sourceSecond.visible.length}`
    );

    console.log(`overlay source=${JSON.stringify(sourceOverlay.visible)} imported=${JSON.stringify(importedOverlay.visible)}`);
    console.log(`visible-second imported=${JSON.stringify(importedSecond.visible)}`);
} finally {
    await browser.close();
}

console.log('Navigation overlay duplicate collapse bounding boxes passed');
