// Run with the DLA #1039 checkout's tsx loader and pass that checkout as argv[2].
// Refs Automattic/blocks-engine#3709. No hand-authored sidecar normalization.
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
const here = dirname(fileURLToPath(import.meta.url));
const upstream = resolve(process.argv[2]);
const load = path => import(pathToFileURL(resolve(upstream, path)).href);
const { chromium } = await load('node_modules/playwright/index.mjs');
const { snapshotComponents, exploreComponents, routeComponents, componentReplayStates } = await load('src/lib/screenshot/component-states.ts');
const { captureTriggeredDialogs } = await load('src/lib/screenshot/interaction-capture.ts');
const { wireCapturedComponents } = await load('src/lib/static-components.ts');
const { wireCapturedDialogs } = await load('src/lib/static-dialogs.ts');
const { CAPTURED_INTERACTIONS_SCHEMA } = await load('src/lib/capture-export-evidence.ts');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
try {
 const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
 await page.setContent(readFileSync(resolve(here, 'component-capture-source.html'), 'utf8'));
 // tsx preserves function names inside serialized Playwright callbacks.
 await page.evaluate('globalThis.__name = (fn) => fn');
 const initial = await page.evaluate(() => { const root = document.documentElement.cloneNode(true); root.querySelectorAll('script').forEach(node => node.remove()); return '<!doctype html>' + root.outerHTML; });
 const baseline = await snapshotComponents(page);
 const components = await exploreComponents(page, baseline);
 const report = await captureTriggeredDialogs(page, 'https://example.test/', { skip: baseline.skip });
 report.components = components;
 const nodes = routeComponents(report);
 const html = wireCapturedComponents(wireCapturedDialogs(initial, componentReplayStates(nodes)), nodes);
 writeFileSync(resolve(here, 'component-capture.html'), html);
 const { states, initialDialogs, ...route } = report;
 writeFileSync(resolve(here, 'component-capture.json'), JSON.stringify({ schema: CAPTURED_INTERACTIONS_SCHEMA, pages: [{ ...route, components: nodes }] }, null, 2) + '\n');
} finally { await browser.close(); }
