import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import assert from 'node:assert/strict';
const playwright = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { chromium } = playwright.chromium ? playwright : playwright.default;
const artifactDir = process.env.COLLECTION_FILTER_ARTIFACT_DIR || tmpdir();
const fixture = JSON.parse(readFileSync(join(artifactDir, 'collection-choice-role.json'), 'utf8'));
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
await page.setContent(fixture.markup);
const snapshot = await page.locator('[role="tab"]').evaluateAll((nodes) => nodes.map((node) => ({
    tag: node.tagName,
    role: node.getAttribute('role'),
    tabIndex: node.getAttribute('tabindex'),
    keydown: node.getAttribute('data-wp-on--keydown'),
})));
assert.deepEqual(snapshot.map((node) => node.tag), ['DIV', 'DIV']);
assert.ok(snapshot.every((node) => node.role === 'tab' && String(node.keydown).endsWith('::actions.chooseKey')));
assert.deepEqual(snapshot.map((node) => node.tabIndex), ['0', '-1']);
const keys = await page.evaluate((source) => {
    const start = source.indexOf('chooseKey( event )');
    const end = source.indexOf('},', start);
    const factory = new Function('getContext', 'namespace', `return function chooseKey(event) ${source.slice(source.indexOf('{', start), end + 1)}`);
    const root = document.querySelector('[data-wp-interactive]');
    const inactive = document.querySelector('[role="tab"][tabindex="-1"]');
    const context = Object.assign({}, JSON.parse(root.dataset.wpContext), JSON.parse(inactive.dataset.wpContext));
    const activate = factory(() => context, 'custom/collection-filter');
    inactive.addEventListener('keydown', (event) => activate(event));
    inactive.focus();
    inactive.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
    const afterArrow = context.category;
    inactive.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    return { afterArrow, afterEnter: context.category };
}, fixture.view);
assert.equal(keys.afterArrow, 0, 'arrow keys do not change the collection category');
assert.equal(keys.afterEnter, 1, 'Enter activates a non-button role=tab choice once');
const space = await page.evaluate((source) => {
    const start = source.indexOf('chooseKey( event )');
    const end = source.indexOf('},', start);
    const factory = new Function('getContext', 'namespace', `return function chooseKey(event) ${source.slice(source.indexOf('{', start), end + 1)}`);
    const root = document.querySelector('[data-wp-interactive]');
    const inactive = document.querySelector('[role="tab"][tabindex="-1"]');
    const context = Object.assign({}, JSON.parse(root.dataset.wpContext), JSON.parse(inactive.dataset.wpContext));
    const activate = factory(() => context, 'custom/collection-filter');
    const event = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });
    Object.defineProperty(event, 'currentTarget', { value: inactive });
    activate(event);
    return { category: context.category, prevented: event.defaultPrevented };
}, fixture.view);
assert.equal(space.category, 1);
assert.equal(space.prevented, true);
const saved = await page.evaluate((editor) => {
    const registered = {};
    window.wp = {
        blocks: { registerBlockType(name, settings) { registered[name] = settings; } },
        blockEditor: {
            useBlockProps: (props) => props || {},
            useInnerBlocksProps: { save: (props) => props || {} },
            RichText: { Content() { return null; } },
        },
        components: {},
        element: { createElement: (type, props) => ({ type, props: props || {} }) },
    };
    window.eval(editor);
    const settings = Object.values(registered)[0];
    return settings.save({ attributes: {
        tagName: 'div', role: 'tab', tabIndex: 0, label: 'All', ariaLabel: '', initial: true, index: 0,
        active: { className: 'active', style: {}, selected: 'true', tabIndex: 0, role: 'tab' },
        inactive: { className: 'inactive', style: {}, selected: 'false', tabIndex: -1, role: 'tab' },
    } }).props;
}, fixture.editor);
assert.equal(saved.role, 'tab');
assert.equal(saved.tabIndex, 0);
assert.equal(typeof saved.role, 'string');
assert.ok(String(saved['data-wp-on--keydown']).endsWith('::actions.chooseKey'));
await browser.close();
console.log('collection choice role keyboard and save proof passed');
