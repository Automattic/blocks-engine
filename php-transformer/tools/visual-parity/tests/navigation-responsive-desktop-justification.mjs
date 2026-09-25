import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const sourceCss = [
    'body{margin:0;font:16px/1.2 ui-sans-serif,system-ui,sans-serif}',
    '.site-header{display:table;width:1440px;table-layout:fixed}',
    '.brand{display:table-cell;width:834px}',
    '.nav{display:table-cell}',
    '.nav ul{float:right;display:flex;list-style:none;margin:0;padding:0;gap:8px}',
    '.nav a{display:block;padding:8px 12px;white-space:nowrap}',
    '.menu-toggle{display:none}',
    '@media screen and (max-width:992px){.nav{display:none}.menu-toggle{display:table-cell}}',
    '.menu-toggle span,.menu-toggle span:before,.menu-toggle span:after{display:block;width:22px;height:2px;background:#111;content:""}',
].join('');
const sourceHtml = '<style>' + sourceCss + '</style>'
    + '<header class="site-header">'
    + '<a class="brand" href="/">Site</a>'
    + '<div class="nav"><ul>'
    + '<li><a href="/">Home</a></li>'
    + '<li><a href="/blog">Blog</a></li>'
    + '<li><a href="/about">About</a></li>'
    + '<li><a href="/contact">Contact</a></li>'
    + '</ul></div>'
    + '<label class="menu-toggle"><span></span></label>'
    + '</header>';

const coreOverlayCss = [
    '.wp-block-navigation__responsive-container{display:none;position:fixed;top:0;left:0;right:0;bottom:0}',
    '.wp-block-navigation__responsive-container .wp-block-navigation__responsive-container-content{display:flex;flex-wrap:wrap;justify-content:var(--navigation-layout-justify,initial);align-items:var(--navigation-layout-align,initial)}',
    '@media (min-width: 600px){',
    '.wp-block-navigation__responsive-container:not(.hidden-by-default):not(.is-menu-open){display:block;width:100%;position:relative;z-index:auto}',
    '.wp-block-navigation__responsive-container-open:not(.always-shown){display:none}',
    '}',
    '.wp-block-navigation__responsive-container-open{display:flex}',
].join('');

const result = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . "/vendor/autoload.php";
echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());
`, root, Buffer.from(sourceHtml).toString('base64')], { encoding: 'utf8' }));

assert.match(String(result.serialized_blocks ?? ''), /"overlayMenu":"mobile"/, 'collapsed CSS toggle projects overlayMenu mobile');
assert.match(String(result.serialized_blocks ?? ''), /blocks-engine-native-responsive-navigation/, 'native overlay marker is present');

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
        const ul = `<ul class="wp-block-navigation__container ${className}">${items}</ul>`;
        if (attrs.overlayMenu !== 'mobile') {
            return `<nav class="wp-block-navigation ${className}">${ul}</nav>`;
        }
        return `<nav class="wp-block-navigation is-responsive ${className}">`
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

const measure = async (page, html, css) => {
    await page.setContent(`<!doctype html><style>${css}</style>${html}`);
    return page.evaluate(() => {
        const styleOf = (el) => {
            if (!el) {
                return null;
            }
            const box = el.getBoundingClientRect();
            const css = getComputedStyle(el);
            return {
                x: Math.round(box.x),
                width: Math.round(box.width),
                display: css.display,
                justifyContent: css.justifyContent,
                float: css.float,
            };
        };
        const nav = document.querySelector('nav, .nav');
        const labels = ['Home', 'Blog', 'About', 'Contact'];
        const links = labels.map((label) => {
            const anchor = [...document.querySelectorAll('a')].find((candidate) => (candidate.textContent ?? '').trim() === label);
            return { label, ...styleOf(anchor) };
        });
        return {
            nav: styleOf(nav),
            container: styleOf(document.querySelector('.wp-block-navigation__responsive-container')),
            content: styleOf(document.querySelector('.wp-block-navigation__responsive-container-content')),
            list: styleOf(document.querySelector('ul')),
            links,
            openVisible: (() => {
                const open = document.querySelector('.wp-block-navigation__responsive-container-open, .menu-toggle');
                if (!open) {
                    return false;
                }
                const box = open.getBoundingClientRect();
                return box.width > 0.5 && box.height > 0.5 && getComputedStyle(open).display !== 'none';
            })(),
        };
    });
};

const importedHtml = renderBlocks(result.blocks ?? []);
const importedCss = `${sourceCss}\n${coreOverlayCss}\n${assetCss(result)}`;
const browser = await chromium.launch({ headless: true });
try {
    const desktop = await browser.newPage({ viewport: { width: 1440, height: 400 } });
    const sourceDesktop = await measure(desktop, sourceHtml.replace(/^<style>[\s\S]*?<\/style>/, ''), sourceCss);
    const importedDesktop = await measure(desktop, importedHtml, importedCss);
    const sourceXs = sourceDesktop.links.map((link) => link.x);
    const importedXs = importedDesktop.links.map((link) => link.x);
    assert.equal(sourceDesktop.links.length, 4, `source desktop links: ${JSON.stringify(sourceDesktop.links)}`);
    assert.ok(sourceDesktop.nav.width > 0, `source nav collapsed: ${JSON.stringify(sourceDesktop.nav)}`);
    assert.equal(importedDesktop.nav.x, sourceDesktop.nav.x, `nav x drifted: source=${JSON.stringify(sourceDesktop.nav)} imported=${JSON.stringify(importedDesktop.nav)}`);
    assert.ok(Math.abs(importedDesktop.nav.width - sourceDesktop.nav.width) <= 1, `nav width drifted: source=${sourceDesktop.nav.width} imported=${importedDesktop.nav.width}`);
    for (const [index, link] of importedDesktop.links.entries()) {
        assert.ok(
            Math.abs(link.x - sourceDesktop.links[index].x) <= 1,
            `desktop ${link.label} x ${link.x} drifted from source ${sourceDesktop.links[index].x} (nav=${JSON.stringify(importedDesktop.nav)} container=${JSON.stringify(importedDesktop.container)} content=${JSON.stringify(importedDesktop.content)} list=${JSON.stringify(importedDesktop.list)} sourceXs=${JSON.stringify(sourceXs)} importedXs=${JSON.stringify(importedXs)})`
        );
    }
    assert.equal(importedDesktop.openVisible, false, 'desktop must not show the overlay open control');
    await desktop.close();

    const phone = await browser.newPage({ viewport: { width: 390, height: 700 } });
    const importedPhone = await measure(phone, importedHtml, importedCss);
    assert.equal(importedPhone.openVisible, true, `phone overlay open control missing: ${JSON.stringify(importedPhone)}`);
    await phone.close();
} finally {
    await browser.close();
}

console.log('Navigation responsive desktop justification bounding boxes passed');
