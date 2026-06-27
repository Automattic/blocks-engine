#!/usr/bin/env node

const DEFAULT_VIEWPORT = { width: 1440, height: 900, device_scale_factor: 1 };
const PLAYWRIGHT_SETUP_HELP = 'Install DOM capture dependencies with: npm ci --prefix php-transformer/tools/visual-parity && npm --prefix php-transformer/tools/visual-parity run install:browsers';

main().catch((error) => {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
});

async function main() {
  if (process.argv.includes('--help') || process.argv.includes('-h')) {
    printHelp();
    return;
  }

  const baseUrl = requiredEnv('HOMEBOY_DOM_BOX_BASE_URL').replace(/\/+$/, '');
  const pagePaths = parsePagePaths(requiredEnv('HOMEBOY_DOM_BOX_PAGE_PATHS_JSON'));
  const textSampleLimit = parseTextSampleLimit(process.env.HOMEBOY_DOM_BOX_TEXT_SAMPLE_LIMIT ?? '160');
  const { chromium } = await loadPlaywright();

  let browser;
  try {
    browser = await chromium.launch();
  } catch (error) {
    throw withPlaywrightSetupHelp(error);
  }
  try {
    const context = await browser.newContext({
      viewport: { width: DEFAULT_VIEWPORT.width, height: DEFAULT_VIEWPORT.height },
      deviceScaleFactor: DEFAULT_VIEWPORT.device_scale_factor,
    });
    const entrypoints = [];

    for (const pagePath of pagePaths) {
      const page = await context.newPage();
      const pageUrl = `${baseUrl}${String(pagePath).startsWith('/') ? pagePath : `/${pagePath}`}`;
      await page.goto(pageUrl, { waitUntil: 'load' });
      entrypoints.push({
        page_path: pagePath,
        page_url: page.url(),
        viewport: DEFAULT_VIEWPORT,
        elements: await extractElements(page, textSampleLimit),
      });
      await page.close();
    }

    process.stdout.write(`${JSON.stringify({ entrypoints }, null, 2)}\n`);
  } finally {
    await browser.close();
  }
}

async function loadPlaywright() {
  try {
    return await import('playwright');
  } catch (error) {
    if (isMissingPlaywrightModule(error)) {
      throw new Error(`Playwright is required for DOM box capture but is not installed. ${PLAYWRIGHT_SETUP_HELP}`);
    }
    throw error;
  }
}

function isMissingPlaywrightModule(error) {
  return error?.code === 'ERR_MODULE_NOT_FOUND' && String(error?.message ?? '').includes("'playwright'");
}

function withPlaywrightSetupHelp(error) {
  const message = error instanceof Error ? error.message : String(error);
  if (message.includes('Executable doesn\'t exist') || message.includes('playwright install')) {
    return new Error(`${message}\n${PLAYWRIGHT_SETUP_HELP}`);
  }
  return error;
}

function requiredEnv(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return value;
}

function parsePagePaths(raw) {
  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (error) {
    throw new Error(`HOMEBOY_DOM_BOX_PAGE_PATHS_JSON must be valid JSON: ${error.message}`);
  }
  if (!Array.isArray(parsed) || parsed.some((item) => typeof item !== 'string' || item.trim() === '')) {
    throw new Error('HOMEBOY_DOM_BOX_PAGE_PATHS_JSON must be a JSON array of paths.');
  }
  return parsed;
}

function parseTextSampleLimit(raw) {
  const value = Number(raw);
  if (!Number.isInteger(value) || value < 1) {
    throw new Error('HOMEBOY_DOM_BOX_TEXT_SAMPLE_LIMIT must be a positive integer.');
  }
  return value;
}

function printHelp() {
  process.stdout.write(`Capture DOM boxes for Homeboy artifact-origin dom-boxes.\n\nEnvironment:\n  HOMEBOY_DOM_BOX_BASE_URL             Static artifact origin base URL.\n  HOMEBOY_DOM_BOX_PAGE_PATHS_JSON      JSON array of page paths to capture.\n  HOMEBOY_DOM_BOX_TEXT_SAMPLE_LIMIT    Optional positive integer, default 160.\n\nOutput:\n  JSON browser payload on stdout for Homeboy to shape as homeboy/static-artifact-dom-boxes/v1.\n`);
}

async function extractElements(page, textSampleLimit) {
  return page.evaluate((limit) => {
    function normalizeText(value) {
      return String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, limit);
    }

    function selectorFor(element, nodeId) {
      const tag = element.tagName.toLowerCase();
      return `${tag}[data-figma-node-id="${String(nodeId).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"]`;
    }

    return Array.from(document.querySelectorAll('[data-figma-node-id]')).map((element) => {
      const rect = element.getBoundingClientRect();
      const nodeId = element.getAttribute('data-figma-node-id') || '';
      const nodeName = element.getAttribute('data-figma-node-name') || element.getAttribute('data-figma-name') || element.getAttribute('aria-label') || null;
      return {
        node_id: nodeId,
        node_name: nodeName,
        selector: selectorFor(element, nodeId),
        tag: element.tagName.toLowerCase(),
        text_sample: normalizeText(element.textContent),
        boundingClientRect: {
          x: rect.x,
          y: rect.y,
          width: rect.width,
          height: rect.height,
          top: rect.top,
          right: rect.right,
          bottom: rect.bottom,
          left: rect.left,
        },
      };
    });
  }, textSampleLimit);
}
