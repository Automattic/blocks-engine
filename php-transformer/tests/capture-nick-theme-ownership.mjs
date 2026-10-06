#!/usr/bin/env node
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const evidenceDir = process.env.NICK_THEME_EVIDENCE_DIR;
if (! evidenceDir) throw new Error('NICK_THEME_EVIDENCE_DIR is required.');
await mkdir(evidenceDir, { recursive: true });
const sourceUrl = 'https://nickdiego.com/';
const sourcePath = 'index.html';
const selector = 'footer > div > div.flex.justify-between.items-start.gap-8.mb-12 > div.flex.transition-opacity.duration-200.opacity-100';
const labels = ['Light theme', 'System theme', 'Dark theme'];
const modes = ['light', 'system', 'dark'];
const semantics = ['sun', 'monitor', 'moon'];
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ colorScheme: 'light' });
const page = await context.newPage();
const resources = new Map();
await context.addInitScript(() => {
    const setItem = Storage.prototype.setItem;
    Storage.prototype.setItem = function (key, value) {
        if ('theme' === key) window.__nickThemeStorageWrites = [...(window.__nickThemeStorageWrites || []), { key, value, stack: new Error().stack }];
        return setItem.call(this, key, value);
    };
    for (const method of ['add', 'remove', 'toggle']) {
        const native = DOMTokenList.prototype[method];
        DOMTokenList.prototype[method] = function (...tokens) {
            if (tokens.some((token) => ['light', 'dark'].includes(String(token)))) {
                window.__nickThemeClassMutations = [...(window.__nickThemeClassMutations || []), { method, tokens, stack: new Error().stack }];
            }
            return native.apply(this, tokens);
        };
    }
});
page.on('response', (response) => {
    const request = response.request();
    if (! ['script', 'stylesheet', 'font'].includes(request.resourceType())) return;
    const url = new URL(response.url());
    if (url.origin !== 'https://nickdiego.com' || response.status() < 200 || response.status() >= 300) return;
    const pending = response.body().then((body) => {
        const path = decodeURIComponent(url.pathname.replace(/^\//, ''));
        resources.set(path, { path, content: body.toString('utf8'), bytes: body.length, sha256: createHash('sha256').update(body).digest('hex'), url: response.url(), resource_type: request.resourceType() });
    }).catch(() => {});
    resources.set(`pending:${response.url()}`, pending);
});

const rootState = async () => page.locator('html').evaluate((root) => ({
    attribute: 'class',
    value: root.classList.contains('dark') ? 'dark' : root.classList.contains('light') ? 'light' : null,
    complete_class: root.className,
    color_scheme: root.style.colorScheme,
    storage_value: localStorage.getItem('theme'),
}));
const waitForState = async (mode, storageValue = mode) => page.waitForFunction(({ resolved, stored }) => {
    const root = document.documentElement;
    return localStorage.getItem('theme') === stored && root.classList.contains(resolved);
}, { resolved: mode, stored: storageValue });

try {
    await page.goto(sourceUrl, { waitUntil: 'networkidle' });
    await page.evaluate(() => localStorage.removeItem('theme'));
    await page.reload({ waitUntil: 'networkidle' });
    const group = page.locator(selector);
    assert.equal(await page.locator(selector).count(), 1, 'the unmodified live DOM resolves the captured bounded footer anchor uniquely');
    assert.equal(await group.getByRole('button').count(), 3);
    const sourceControls = await group.getByRole('button').evaluateAll((buttons) => buttons.map((button) => ({
        accessible_name: button.getAttribute('aria-label'),
        markup: button.outerHTML,
        icon_class: button.querySelector('svg')?.getAttribute('class') || '',
    })));
    assert.deepEqual(sourceControls.map((control) => control.accessible_name), labels);
    assert.deepEqual(sourceControls.map((control) => control.icon_class.split(/\s+/).find((token) => /^lucide-(sun|monitor|moon)$/.test(token))?.replace('lucide-', '')), semantics);

    const defaultObservations = [];
    await page.emulateMedia({ colorScheme: 'light' });
    await page.waitForFunction(() => null === localStorage.getItem('theme') && document.documentElement.classList.contains('light'));
    let defaultState = await rootState();
    defaultObservations.push({ storage_value: defaultState.storage_value, os_scheme: 'light', resolved: 'light', root_state: { attribute: defaultState.attribute, value: defaultState.value } });
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForFunction(() => null === localStorage.getItem('theme') && document.documentElement.classList.contains('dark'));
    defaultState = await rootState();
    defaultObservations.push({ storage_value: defaultState.storage_value, os_scheme: 'dark', resolved: 'dark', root_state: { attribute: defaultState.attribute, value: defaultState.value } });
    await page.emulateMedia({ colorScheme: 'light' });
    await page.waitForFunction(() => null === localStorage.getItem('theme') && document.documentElement.classList.contains('light'));

    const observations = [];
    for (const mode of ['light', 'dark']) {
        await group.getByRole('button', { name: labels[modes.indexOf(mode)] }).click();
        await waitForState(mode);
        const state = await rootState();
        observations.push({ mode, storage_value: state.storage_value, resolved: mode, root_state: { attribute: state.attribute, value: state.value } });
    }
    await page.emulateMedia({ colorScheme: 'light' });
    await group.getByRole('button', { name: labels[1] }).click();
    await waitForState('light', 'system');
    let state = await rootState();
    observations.push({ mode: 'system', storage_value: state.storage_value, os_scheme: 'light', resolved: 'light', root_state: { attribute: state.attribute, value: state.value } });
    await page.emulateMedia({ colorScheme: 'dark' });
    await waitForState('dark', 'system');
    state = await rootState();
    observations.push({ mode: 'system', storage_value: state.storage_value, os_scheme: 'dark', resolved: 'dark', root_state: { attribute: state.attribute, value: state.value } });
    assert.equal(state.storage_value, 'system');
    const runtimeAssetCallStacks = await page.evaluate(() => ({ storage: window.__nickThemeStorageWrites || [], class_mutations: window.__nickThemeClassMutations || [] }));
    await page.reload({ waitUntil: 'networkidle' });
    await waitForState('dark', 'system');
    assert.equal((await rootState()).storage_value, 'system', 'the live site restores the saved System preference across reload');

    await Promise.all([...resources.values()].filter((value) => value instanceof Promise));
    for (const [key, value] of resources) if (key.startsWith('pending:')) resources.delete(key);
    const scriptAssets = [...resources.values()].filter((resource) => 'script' === resource.resource_type);
    const owners = scriptAssets.filter((asset) => /localStorage\.getItem\(t\)|localStorage\.getItem\(l\)/.test(asset.content)
        && asset.content.includes('matchMedia("(prefers-color-scheme: dark)")')
        && asset.content.includes('document.documentElement'));
    assert.equal(owners.length, 1, 'one unchanged first-party runtime bundle contains the observed root/storage/system preference implementation');
    const owner = owners[0];
    const sourceHtml = await page.content();
    const unchangedSourceFooter = await page.locator('footer').evaluate((footer) => footer.outerHTML);
    const localPaths = new Set(resources.keys());
    let portableHtml = sourceHtml;
    for (const resource of resources.values()) {
        const local = `./${ resource.path }`;
        const url = new URL(resource.url);
        const escapedPath = url.pathname.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        portableHtml = portableHtml.replace(new RegExp(`(src|href)=("|')${ escapedPath }(?:\\?[^"']*)?\\2`, 'g'), (_match, attribute, quote) => `${ attribute }=${ quote }${ local }${ quote }`);
    }
    portableHtml = portableHtml.replace(/(<body\b[^>]*>)[\s\S]*?(<\/body>)/i, `$1${ unchangedSourceFooter }$2`);
    // Retain the original resources and hashes in the artifact boundary as
    // evidence, but let the canonical WordPress companion own frontend runtime.
    portableHtml = portableHtml.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
    // Next inserts this empty accessibility announcer into the live DOM; it is
    // absent from its response document and is not source page content.
    portableHtml = portableHtml.replace(/<next-route-announcer\b[^>]*>\s*<\/next-route-announcer>/gi, '');
    // The browser's tab icons are not page-content assets in this one-page
    // artifact. Keep the source page body and its authored theme resources.
    portableHtml = portableHtml.replace(/<link\b(?=[^>]*\brel=("|')(?:icon|apple-touch-icon)\1)[^>]*>/gi, '');
    const files = [{ path: sourcePath, kind: 'html', content: portableHtml }];
    for (const resource of resources.values()) {
        if ('script' === resource.resource_type && owner.path !== resource.path) continue;
        files.push({ path: resource.path, content: resource.content });
    }
    const ownership = {
        schema: 'blocks-engine/php-transformer/theme-preference-ownership/v1',
        source_path: sourcePath,
        group_selector: selector,
        runtime_script_path: owner.path,
        runtime_script_sha256: owner.sha256,
        runtime_script_content: owner.content,
        storage_key: 'theme',
        system_query: '(prefers-color-scheme: dark)',
        root: { selector: 'html', attribute: 'class', dark_value: 'dark', light_value: 'light', light_operation: 'set-theme-class' },
        default_preference: 'system',
        default_observations: defaultObservations,
        controls: sourceControls.map((control, index) => ({ mode: modes[index], accessible_name: control.accessible_name, icon: semantics[index] })),
        observed_transitions: observations,
    };
    const artifact = {
        entrypoint: sourcePath,
        files,
        runtime_declarations: [{
            kind: 'theme_control',
            type: 'preference_ownership',
            source_path: sourcePath,
            payload: { schema: 'blocks-engine/php-transformer/theme-preference-ownership-evidence/v1', ownership },
        }],
    };
    const evidence = {
        schema: 'blocks-engine/php-transformer/nick-source-theme-capture/v1',
        captured_at: new Date().toISOString(),
        source_url: sourceUrl,
        source_path: sourcePath,
        unique_group_anchor: { selector, matches: 1, group_markup: await group.evaluate((element) => element.outerHTML) },
        unchanged_source_controls: sourceControls,
        browser_observations: observations,
        browser_default_observations: defaultObservations,
        storage_and_root_after_reload: await rootState(),
        runtime_asset: { path: owner.path, url: owner.url, bytes: owner.bytes, sha256: owner.sha256 },
        runtime_asset_call_stacks: runtimeAssetCallStacks,
        resources: [...resources.values()].map(({ path, bytes, sha256, url, resource_type }) => ({ path, bytes, sha256, url, resource_type })),
        artifact_source_sha256: createHash('sha256').update(portableHtml).digest('hex'),
        ownership,
    };
    assert.equal(owner.sha256, createHash('sha256').update(owner.content).digest('hex'));
    assert(localPaths.has(owner.path));
    await writeFile(`${ evidenceDir }/nick-live-browser-evidence.json`, JSON.stringify(evidence, null, 2) + '\n');
    await writeFile(`${ evidenceDir }/nick-site-artifact.json`, JSON.stringify(artifact) + '\n');
    console.log(`PASS: unchanged Nick controls, unique bounded anchor, runtime ${ owner.path } sha256:${ owner.sha256 }, and observed theme/root/storage transitions`);
} finally {
    await browser.close();
}
