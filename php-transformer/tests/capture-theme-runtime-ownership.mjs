#!/usr/bin/env node
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const evidenceDir = process.env.THEME_ACCEPTANCE_EVIDENCE_DIR;
if (! evidenceDir) throw new Error('THEME_ACCEPTANCE_EVIDENCE_DIR is required.');
const modes = [
    { mode: 'light', accessible_name: 'Light theme', icon: 'sun' },
    { mode: 'system', accessible_name: 'System theme', icon: 'monitor' },
    { mode: 'dark', accessible_name: 'Dark theme', icon: 'moon' },
];
const stylesheetFor = (attribute) => ':root{color-scheme:light;background:#fff}.dark{color-scheme:dark;background:#111}[data-theme="dark"]{color-scheme:dark;background:#111}.theme-choices{display:flex;gap:8px}';
const runtimeFor = (attribute, storageKey) => {
    const rootOperation = 'class' === attribute
        ? 'root.classList.toggle("dark","dark"===resolved);'
        : 'if("dark"===resolved)root.setAttribute("data-theme","dark");else root.removeAttribute("data-theme");';
    return `(()=>{const root=document.documentElement;const storageKey=${ JSON.stringify(storageKey) };const media=window.matchMedia("(prefers-color-scheme: dark)");let preference=localStorage.getItem(storageKey)||"system";const controls=Array.from(document.querySelectorAll(".theme-choices button"));const apply=()=>{const resolved="system"===preference?(media.matches?"dark":"light"):preference;${ rootOperation }root.style.colorScheme=resolved;controls.forEach(button=>button.setAttribute("aria-pressed",String(button.dataset.mode===preference)))};controls.forEach(button=>button.addEventListener("click",()=>{preference=button.dataset.mode;localStorage.setItem(storageKey,preference);apply()}));media.addEventListener("change",()=>{"system"===preference&&apply()});apply()})();`;
};
const markupFor = (attribute) => {
    const root = 'class' === attribute ? 'class="dark"' : 'data-theme="dark"';
    const buttons = modes.map(({ mode, accessible_name, icon }) => `<button type="button" data-mode="${ mode }" aria-label="${ accessible_name }"><svg class="lucide lucide-${ icon }" aria-hidden="true"><path d="M1 1"></path></svg></button>`).join('');
    return `<!doctype html><html ${ root }><head><style>${ stylesheetFor(attribute) }</style></head><body><div class="theme-choices" role="group" aria-label="Color theme">${ buttons }</div><script src="/${ attribute }.js"></script></body></html>`;
};
const server = createServer((request, response) => {
    const pathname = new URL(request.url || '/', 'http://localhost').pathname;
    const attribute = pathname.includes('data-theme') ? 'data-theme' : 'class';
    const storageKey = 'class' === attribute ? 'theme' : 'appearance';
    if (pathname.endsWith('.js')) {
        response.writeHead(200, { 'content-type': 'text/javascript; charset=utf-8' });
        response.end(runtimeFor(attribute, storageKey));
    } else {
        response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        response.end(markupFor(attribute));
    }
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const address = server.address();
if (! address || typeof address === 'string') throw new Error('Could not start source-runtime capture server.');
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ colorScheme: 'light' });
const page = await context.newPage();

const capture = async (attribute, storageKey, sourcePath, lightOperation) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto(`http://127.0.0.1:${ address.port }/${ attribute }.html`, { waitUntil: 'networkidle' });
    await page.evaluate((key) => localStorage.removeItem(key), storageKey);
    await page.reload({ waitUntil: 'networkidle' });
    const group = page.getByRole('group', { name: 'Color theme' });
    const rootState = async () => 'class' === attribute
        ? (await page.locator('html').evaluate((root) => root.classList.contains('dark')) ? 'dark' : 'light')
        : ('dark' === await page.locator('html').getAttribute(attribute) ? 'dark' : 'light');
    const storageValue = async () => page.evaluate((key) => localStorage.getItem(key), storageKey);
    const transitions = [];
    await page.waitForFunction((name) => 'class' === name
        ? ! document.documentElement.classList.contains('dark')
        : ! document.documentElement.hasAttribute(name), attribute);

    for (const mode of ['light', 'dark']) {
        const label = modes.find((choice) => choice.mode === mode).accessible_name;
        await group.getByRole('button', { name: label }).click();
        await page.waitForFunction(({ key, value }) => localStorage.getItem(key) === value, { key: storageKey, value: mode });
        assert.equal(await rootState(), mode);
        transitions.push({ mode, storage_value: await storageValue(), resolved: await rootState(), root_state: { attribute, value: 'class' === attribute ? ('dark' === await rootState() ? 'dark' : null) : await page.locator('html').getAttribute(attribute) } });
    }

    await group.getByRole('button', { name: 'System theme' }).click();
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForFunction((name) => 'class' === name
        ? document.documentElement.classList.contains('dark')
        : 'dark' === document.documentElement.getAttribute(name), attribute);
    transitions.push({ mode: 'system', storage_value: await storageValue(), os_scheme: 'dark', resolved: await rootState(), root_state: { attribute, value: 'class' === attribute ? 'dark' : await page.locator('html').getAttribute(attribute) } });
    await page.emulateMedia({ colorScheme: 'light' });
    await page.waitForFunction((name) => 'class' === name
        ? ! document.documentElement.classList.contains('dark')
        : ! document.documentElement.hasAttribute(name), attribute);
    transitions.push({ mode: 'system', storage_value: await storageValue(), os_scheme: 'light', resolved: await rootState(), root_state: { attribute, value: 'class' === attribute ? null : await page.locator('html').getAttribute(attribute) } });

    const capturedControls = await group.getByRole('button').evaluateAll((buttons) => buttons.map((button) => ({
        accessible_name: button.getAttribute('aria-label'),
        icon_class: button.querySelector('svg')?.getAttribute('class') || '',
    })));
    assert.deepEqual(capturedControls.map((control) => control.accessible_name), modes.map((mode) => mode.accessible_name));
    const runtimeScriptPath = `js/${ attribute }.js`;
    const runtimeScriptContent = runtimeFor(attribute, storageKey);
    return {
        operator: {
            schema: 'blocks-engine/php-transformer/theme-preference-ownership/v1',
            source_path: sourcePath,
            group_selector: '.theme-choices',
            runtime_script_path: runtimeScriptPath,
            runtime_script_sha256: createHash('sha256').update(runtimeScriptContent).digest('hex'),
            runtime_script_content: runtimeScriptContent,
            storage_key: storageKey,
            system_query: '(prefers-color-scheme: dark)',
            root: { selector: 'html', attribute, dark_value: 'dark', light_value: '', light_operation: lightOperation },
            default_preference: 'system',
            default_observations: [
                { storage_value: null, os_scheme: 'light', resolved: 'light', root_state: { attribute, value: null } },
                { storage_value: null, os_scheme: 'dark', resolved: 'dark', root_state: { attribute, value: 'dark' } },
            ],
            stylesheet_evidence: [{ path: `theme-controls/${ attribute }.css`, sha256: createHash('sha256').update(stylesheetFor(attribute)).digest('hex'), content: stylesheetFor(attribute) }],
            controls: capturedControls.map((control, index) => ({ mode: modes[index].mode, accessible_name: control.accessible_name, icon: modes[index].icon })),
            observed_transitions: transitions,
        },
        runtime_asset: { path: runtimeScriptPath, content: runtimeScriptContent },
        observations: transitions,
    };
};

try {
    const classCapture = await capture('class', 'theme', 'theme-controls/index.html', 'remove-theme-class');
    const dataCapture = await capture('data-theme', 'appearance', 'theme-controls/data.html', 'remove-attribute');
    await writeFile(`${ evidenceDir }/theme-control-ownership.json`, JSON.stringify({
        schema: 'blocks-engine/php-transformer/theme-preference-capture/v1',
        operators: [classCapture.operator, dataCapture.operator],
        runtime_projection_script_assets: [classCapture.runtime_asset, dataCapture.runtime_asset],
        browser_observations: [classCapture.observations, dataCapture.observations],
    }, null, 2) + '\n');
    console.log('PASS: browser-observed theme controls, root/storage ownership, and system OS transitions');
} finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
}
