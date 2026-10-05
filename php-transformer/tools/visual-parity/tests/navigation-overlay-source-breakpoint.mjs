import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

// A source menu that collapses behind its toggle at 1000px must collapse at
// 1000px once it is core/navigation with the native overlay — not at core's
// hard-coded 600px. Between 600px and 1000px core's open button has to show
// and the menu content has to stay hidden until the button is used; above
// 1000px and below 600px nothing changes.
const root = new URL('../../..', import.meta.url).pathname;
const sourceCss = [
    'body{margin:0;font:16px/1.2 ui-sans-serif,system-ui,sans-serif}',
    '.bar{display:flex;align-items:center;gap:16px;min-height:64px}',
    '.bar>button{display:none;border:0;background:none;padding:8px}',
    '.bar nav{display:flex;gap:16px}',
    '.bar nav a{display:block;padding:8px 12px;white-space:nowrap}',
    '@media (max-width:1000px){.bar>button{display:flex}.bar nav{display:none}.bar nav.open{display:flex}}',
].join('');
const sourceHtml = '<style>' + sourceCss + '</style>'
    + '<header><div class="bar">'
    + '<a class="brand" href="/">Brand</a>'
    + '<button aria-controls="site-menu" aria-expanded="false" aria-label="Menu"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>'
    + '<nav id="site-menu">'
    + '<a href="/">Home</a>'
    + '<a href="/about">About</a>'
    + '<a href="/work">Work</a>'
    + '<a href="/contact">Contact</a>'
    + '</nav>'
    + '</div></header>'
    + '<main><h1>Hello</h1></main>';

// The subset of core/navigation's style.css that decides the overlay switch.
const coreOverlayCss = [
    '.wp-block-navigation__responsive-container{display:none;position:fixed;top:0;left:0;right:0;bottom:0}',
    '.wp-block-navigation__responsive-container .wp-block-navigation__responsive-container-content{display:flex;flex-wrap:wrap;justify-content:var(--navigation-layout-justify,initial);align-items:var(--navigation-layout-align,initial)}',
    '.wp-block-navigation__responsive-container.is-menu-open{display:flex;flex-direction:column;background-color:#fff;overflow:auto;z-index:100000}',
    '@media (min-width: 600px){',
    '.wp-block-navigation__responsive-container:not(.hidden-by-default):not(.is-menu-open){display:block;width:100%;position:relative;z-index:auto}',
    '.wp-block-navigation__responsive-container:not(.hidden-by-default):not(.is-menu-open) .wp-block-navigation__responsive-container-close{display:none}',
    '.wp-block-navigation__responsive-container-open:not(.always-shown){display:none}',
    '}',
    '.wp-block-navigation__responsive-container-open{display:flex}',
    // core's layout support emits a flex rule for the block; the author's
    // `display:none` on the same element is what decides the collapsed
    // state in the source, so both have to be present for the test to mean
    // anything.
    'body .is-layout-flex{display:flex}',
].join('');

const result = JSON.parse(execFileSync('php', ['-d', 'memory_limit=-1', '-r', `
require $argv[1] . "/vendor/autoload.php";
echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());
`, root, Buffer.from(sourceHtml).toString('base64')], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));

const serialized = String(result.serialized_blocks ?? '');
assert.match(serialized, /"overlayMenu":"mobile"/, 'the toggle projects overlayMenu mobile');
assert.match(serialized, /blocks-engine-native-responsive-navigation/, 'native overlay marker is present');

const renderBlocks = (blocks) => (blocks ?? []).map(renderBlock).join('');
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
    if (name === 'core/paragraph' || name === 'core/heading') {
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
        const ul = `<ul class="wp-block-navigation__container ${className}">${items}</ul>`;
        if (attrs.overlayMenu !== 'mobile') {
            return `<nav class="wp-block-navigation is-layout-flex ${className}">${ul}</nav>`;
        }
        return `<nav class="wp-block-navigation is-responsive is-layout-flex ${className}">`
            + `<button type="button" class="wp-block-navigation__responsive-container-open" aria-label="Open menu">Open</button>`
            + `<div class="wp-block-navigation__responsive-container">`
            + `<div class="wp-block-navigation__responsive-close">`
            + `<div class="wp-block-navigation__responsive-dialog">`
            + `<button type="button" class="wp-block-navigation__responsive-container-close" aria-label="Close menu">Close</button>`
            + `<div class="wp-block-navigation__responsive-container-content">${ul}</div>`
            + `</div></div></div></nav>`;
    }
    return `${block.innerHTML || ''}${renderBlocks(block.innerBlocks ?? [])}`;
};

const assetCss = (payload) => (payload.assets ?? [])
    .filter((asset) => asset.kind === 'css')
    .map((asset) => asset.content ?? '')
    .join('\n');

const labels = ['Home', 'About', 'Work', 'Contact'];
const probe = () => {
    const visible = (el) => {
        if (!el) {
            return false;
        }
        const box = el.getBoundingClientRect();
        return box.width > 0.5 && box.height > 0.5 && getComputedStyle(el).display !== 'none' && getComputedStyle(el).visibility !== 'hidden';
    };
    const labels = ['Home', 'About', 'Work', 'Contact'];
    const links = labels.map((label) => [...document.querySelectorAll('a')].find((a) => (a.textContent ?? '').trim() === label));
    const open = document.querySelector('.wp-block-navigation__responsive-container-open, header button');
    const nav = document.querySelector('nav');
    return {
        visibleLinks: links.filter(visible).length,
        openVisible: visible(open),
        navDisplay: nav ? getComputedStyle(nav).display : null,
        containerDisplay: (() => {
            const container = document.querySelector('.wp-block-navigation__responsive-container');
            return container ? getComputedStyle(container).display : null;
        })(),
    };
};

const measure = async (page, html, css) => {
    await page.setContent(`<!doctype html><style>${css}</style>${html}`);
    return page.evaluate(probe);
};

const importedHtml = renderBlocks(result.blocks ?? []);
const importedCss = `${sourceCss}\n${coreOverlayCss}\n${assetCss(result)}`;
const sourceBody = sourceHtml.replace(/^<style>[\s\S]*?<\/style>/, '');
const browser = await chromium.launch({ headless: true });
try {
    for (const width of [800, 960]) {
        const page = await browser.newPage({ viewport: { width, height: 600 } });
        const source = await measure(page, sourceBody, sourceCss);
        assert.equal(source.visibleLinks, 0, `${width}px source shows no links while collapsed: ${JSON.stringify(source)}`);
        assert.equal(source.openVisible, true, `${width}px source shows its toggle: ${JSON.stringify(source)}`);

        const imported = await measure(page, importedHtml, importedCss);
        assert.equal(imported.openVisible, true, `${width}px imported must show core's open button: ${JSON.stringify(imported)}`);
        assert.equal(imported.visibleLinks, 0, `${width}px imported must hide the closed menu: ${JSON.stringify(imported)}`);
        assert.notEqual(imported.navDisplay, 'none', `${width}px imported host must stay visible so the open button can render: ${JSON.stringify(imported)}`);

        // core's view script toggles `is-menu-open` on the container; the
        // open overlay must show every link.
        const opened = await page.evaluate(`(() => {
            document.querySelector('.wp-block-navigation__responsive-container').classList.add('is-menu-open');
            return (${probe.toString()})();
        })()`);
        assert.equal(opened.visibleLinks, labels.length, `${width}px imported open overlay must show every link: ${JSON.stringify(opened)}`);
        await page.close();
    }

    for (const width of [1200, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 600 } });
        const source = await measure(page, sourceBody, sourceCss);
        const imported = await measure(page, importedHtml, importedCss);
        assert.equal(source.visibleLinks, labels.length, `${width}px source shows the inline menu: ${JSON.stringify(source)}`);
        assert.equal(imported.visibleLinks, labels.length, `${width}px imported shows the inline menu: ${JSON.stringify(imported)}`);
        assert.equal(imported.openVisible, false, `${width}px imported must not show the open button: ${JSON.stringify(imported)}`);
        await page.close();
    }

    const phone = await browser.newPage({ viewport: { width: 390, height: 700 } });
    const importedPhone = await measure(phone, importedHtml, importedCss);
    assert.equal(importedPhone.openVisible, true, `phone imported shows the open button: ${JSON.stringify(importedPhone)}`);
    assert.equal(importedPhone.visibleLinks, 0, `phone imported hides the closed menu: ${JSON.stringify(importedPhone)}`);
    await phone.close();
} finally {
    await browser.close();
}

console.log('Navigation overlay source breakpoint visibility passed');
