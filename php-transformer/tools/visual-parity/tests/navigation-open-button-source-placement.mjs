import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

// A source menu toggle placed absolutely at the header's right edge
// (`position:absolute;right:0;top:50%;transform:translateY(-50%)` under the
// breakpoint that shows it) is dropped for core/navigation's native overlay.
// Core's open button must then sit where the toggle sat — flush with the
// header's right edge, vertically centred — instead of in the header's flex
// flow at the navigation's slot, and the fixed overlay it opens must still
// cover the viewport: the placement may transform the button, never an
// ancestor of the overlay.
const root = new URL('../../..', import.meta.url).pathname;
const sourceCss = [
    'body{margin:0;font:16px/1.2 ui-sans-serif,system-ui,sans-serif}',
    '.bar{display:flex;align-items:center;position:relative;min-height:72px;gap:20px;background:#061b38;color:#fff}',
    '.brand{display:flex;align-items:center;width:240px;height:52px;color:#fff;text-decoration:none}',
    '.bar nav{display:flex;align-items:center;gap:22px}',
    '.bar nav a{font-size:18px;font-weight:700;text-decoration:none;color:rgba(255,255,255,.86)}',
    '.bar button{display:none;border:0;background:none;font-size:27px;color:#fff}',
    '.bar button svg{display:block;width:24px;height:24px}',
    '@media(max-width:1000px){',
    '.bar button{position:absolute;right:0;top:50%;transform:translateY(-50%);margin:0;padding:8px;display:flex;align-items:center;justify-content:center}',
    '.bar nav{display:none;position:absolute;left:0;right:0;top:100%;transform:none;background:#061b38;padding:20px;flex-direction:column;border:1px solid rgba(255,255,255,.14);z-index:50}',
    '.bar nav.open{display:flex}',
    '}',
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

// The subset of core/navigation's style.css that decides the overlay switch
// and the open button's own box.
const coreOverlayCss = [
    '.wp-block-navigation__responsive-container{display:none;position:fixed;top:0;left:0;right:0;bottom:0}',
    '.wp-block-navigation__responsive-container .wp-block-navigation__responsive-container-content{display:flex;flex-wrap:wrap}',
    '.wp-block-navigation__responsive-container.is-menu-open{display:flex;flex-direction:column;background-color:#fff;overflow:auto;z-index:100000}',
    '@media (min-width: 600px){',
    '.wp-block-navigation__responsive-container:not(.hidden-by-default):not(.is-menu-open){display:block;width:100%;position:relative;z-index:auto}',
    '.wp-block-navigation__responsive-container-open:not(.always-shown){display:none}',
    '}',
    '.wp-block-navigation__responsive-container-open,.wp-block-navigation__responsive-container-close{vertical-align:middle;cursor:pointer;color:currentColor;background:transparent;border:none;margin:0;padding:0}',
    '.wp-block-navigation__responsive-container-open svg{fill:currentColor;display:block;width:24px;height:24px}',
    '.wp-block-navigation__responsive-container-open{display:flex}',
    'body .is-layout-flex{display:flex}',
].join('');

const result = JSON.parse(execFileSync('php', ['-d', 'memory_limit=-1', '-r', `
require $argv[1] . "/vendor/autoload.php";
echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());
`, root, Buffer.from(sourceHtml).toString('base64')], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));

const serialized = String(result.serialized_blocks ?? '');
assert.match(serialized, /"overlayMenu":"mobile"/, 'the toggle projects overlayMenu mobile');
assert.match(serialized, /blocks-engine-native-navigation-toggle-/, 'the navigation carries the toggle marker');

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
    if (name === 'custom/layout-shell') {
        const wrappers = attrs.wrappers ?? [];
        const open = wrappers.map((w) => `<${w.tagName || 'div'} class="${(w.attributes ?? {}).class ?? ''}">`).join('');
        const close = wrappers.map((w) => `</${w.tagName || 'div'}>`).reverse().join('');
        return `${open}${renderBlocks(block.innerBlocks ?? [])}${close}`;
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
            + `<button type="button" class="wp-block-navigation__responsive-container-open" aria-label="Open menu"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>`
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
    .filter((asset) => asset.kind === 'css' || String(asset.path ?? '').endsWith('.css') || String(asset.path ?? '').startsWith('inline-style'))
    .map((asset) => asset.content ?? '')
    .join('\n');

const probe = () => {
    const rect = (el) => {
        const box = el.getBoundingClientRect();
        return { x: box.x, y: box.y, w: box.width, h: box.height, right: box.right, cy: box.y + box.height / 2 };
    };
    const bar = document.querySelector('.bar');
    const open = document.querySelector('.wp-block-navigation__responsive-container-open') || document.querySelector('header button');
    const host = document.querySelector('nav.wp-block-navigation');
    const brand = document.querySelector('.brand, a[href="/"]');
    const overlay = document.querySelector('.wp-block-navigation__responsive-container');
    const cs = (el, p) => (el ? getComputedStyle(el).getPropertyValue(p) : null);
    return {
        bar: bar ? rect(bar) : null,
        open: open ? rect(open) : null,
        openVisible: !!open && rect(open).w > 0 && cs(open, 'display') !== 'none',
        brand: brand ? rect(brand) : null,
        host: host ? { ...rect(host), position: cs(host, 'position'), transform: cs(host, 'transform'), border: cs(host, 'border-top-width') } : null,
        overlay: overlay ? rect(overlay) : null,
        viewport: { w: window.innerWidth, h: window.innerHeight },
    };
};

const measure = async (page, html, css) => {
    await page.setContent(`<!doctype html><style>${css}</style>${html}`);
    return page.evaluate(probe);
};

const importedHtml = renderBlocks(result.blocks ?? []);
const importedCss = `${sourceCss}\n${coreOverlayCss}\n${assetCss(result)}`;
const sourceBody = sourceHtml.replace(/^<style>[\s\S]*?<\/style>/, '');
const near = (a, b, tolerance, message) => assert.ok(Math.abs(a - b) <= tolerance, `${message}: ${a} vs ${b}`);
const browser = await chromium.launch({ headless: true });
try {
    for (const width of [390, 560]) {
        const page = await browser.newPage({ viewport: { width, height: 700 } });
        const source = await measure(page, sourceBody, sourceCss);
        assert.ok(source.openVisible, `${width}px source shows its toggle: ${JSON.stringify(source)}`);
        near(source.open.right, source.bar.right, 0.5, `${width}px source toggle is flush with the bar's right edge`);
        near(source.open.cy, source.bar.cy, 0.5, `${width}px source toggle is vertically centred in the bar`);

        const imported = await measure(page, importedHtml, importedCss);
        assert.ok(imported.openVisible, `${width}px imported shows core's open button: ${JSON.stringify(imported)}`);
        near(imported.open.right, imported.bar.right, 0.5, `${width}px imported open button is flush with the bar's right edge`);
        near(imported.open.cy, imported.bar.cy, 1, `${width}px imported open button is vertically centred in the bar`);
        near(imported.open.right, source.open.right, 0.5, `${width}px imported open button's right edge matches the source toggle`);
        near(imported.open.cy, source.open.cy, 1, `${width}px imported open button's centre matches the source toggle`);
        assert.ok(imported.open.x >= imported.brand.right, `${width}px imported open button does not overlap the brand: ${JSON.stringify(imported)}`);
        assert.equal(imported.host.position, 'static', `${width}px imported host is not the open button's containing block`);
        assert.equal(imported.host.transform, 'none', `${width}px imported host carries no transform`);
        assert.equal(imported.host.border, '0px', `${width}px imported host carries no panel border`);

        // core's view script toggles `is-menu-open` on the container; the
        // fixed overlay must cover the viewport, not be trapped by a
        // transformed or sized ancestor.
        const opened = await page.evaluate(`(() => {
            document.querySelector('.wp-block-navigation__responsive-container').classList.add('is-menu-open');
            return (${probe.toString()})();
        })()`);
        near(opened.overlay.x, 0, 0.5, `${width}px open overlay starts at the viewport's left edge`);
        near(opened.overlay.y, 0, 0.5, `${width}px open overlay starts at the viewport's top edge`);
        near(opened.overlay.w, opened.viewport.w, 0.5, `${width}px open overlay spans the viewport width`);
        near(opened.overlay.h, opened.viewport.h, 0.5, `${width}px open overlay spans the viewport height`);
        await page.close();
    }

    // Above the source breakpoint nothing is placed: the open button is gone
    // and the menu renders inline, as in the source.
    const desktop = await browser.newPage({ viewport: { width: 1200, height: 700 } });
    const source = await measure(desktop, sourceBody, sourceCss);
    const imported = await measure(desktop, importedHtml, importedCss);
    assert.equal(source.openVisible, false, `1200px source hides its toggle: ${JSON.stringify(source)}`);
    assert.equal(imported.openVisible, false, `1200px imported must not show the open button: ${JSON.stringify(imported)}`);
    const visibleLinks = await desktop.evaluate(() => [...document.querySelectorAll('nav a')].filter((a) => a.getBoundingClientRect().width > 0).length);
    assert.equal(visibleLinks, 4, `1200px imported shows the inline menu`);
    await desktop.close();
} finally {
    await browser.close();
}

console.log('Navigation open button source placement geometry passed');
