#!/usr/bin/env node

const DEFAULT_VIEWPORT = { width: 1440, height: 900, device_scale_factor: 1 };
const PLAYWRIGHT_SETUP_HELP = 'Install DOM capture dependencies with: npm ci --prefix php-transformer/tools/visual-parity && npm --prefix php-transformer/tools/visual-parity run install:browsers';
const COMPUTED_STYLE_PROPERTIES = [
  'font-family',
  'font-size',
  'font-weight',
  'line-height',
  'letter-spacing',
  'color',
  'background-color',
  'opacity',
  'display',
  'position',
  'overflow',
  'overflow-x',
  'overflow-y',
  'transform',
  'border-top-left-radius',
  'border-top-right-radius',
  'border-bottom-right-radius',
  'border-bottom-left-radius',
  'border-top-width',
  'border-right-width',
  'border-bottom-width',
  'border-left-width',
  'border-top-color',
  'border-right-color',
  'border-bottom-color',
  'border-left-color',
  'border-top-style',
  'border-right-style',
  'border-bottom-style',
  'border-left-style',
  'box-shadow',
  'background-image',
  'background-size',
  'background-position',
  'background-repeat',
  'object-fit',
  'object-position',
  'text-align',
  'white-space',
  'visibility',
  'pointer-events',
];

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
        elements: await extractElements(page, pagePath, textSampleLimit),
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

async function extractElements(page, pagePath, textSampleLimit) {
  return page.evaluate(({ pagePath: currentPagePath, limit, computedStyleProperties }) => {
    function normalizeText(value) {
      return String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, limit);
    }

    function fullTextLength(value) {
      return String(value ?? '').replace(/\s+/g, ' ').trim().length;
    }

    function selectorFor(element, nodeId) {
      const tag = element.tagName.toLowerCase();
      return `${tag}[data-figma-node-id="${String(nodeId).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"]`;
    }

    function serializeComputedStyle(element) {
      const computed = window.getComputedStyle(element);
      return Object.fromEntries(computedStyleProperties.map((property) => [property, computed.getPropertyValue(property)]));
    }

    function estimateLineCount(element, computedStyle, textLength) {
      if (textLength === 0 || element.clientHeight <= 0) {
        return 0;
      }

      const lineHeight = Number.parseFloat(computedStyle['line-height']);
      if (Number.isFinite(lineHeight) && lineHeight > 0) {
        return Math.max(1, Math.round(element.clientHeight / lineHeight));
      }

      const fontSize = Number.parseFloat(computedStyle['font-size']);
      if (Number.isFinite(fontSize) && fontSize > 0) {
        return Math.max(1, Math.round(element.clientHeight / (fontSize * 1.2)));
      }

      return 1;
    }

    function assetElementSummary(assetElement) {
      const tag = assetElement.tagName.toLowerCase();
      const summary = {
        tag,
        src: assetElement.currentSrc || assetElement.src || assetElement.getAttribute('href') || assetElement.getAttribute('xlink:href') || null,
      };

      if (assetElement instanceof HTMLImageElement) {
        summary.complete = assetElement.complete;
        summary.naturalWidth = assetElement.naturalWidth;
        summary.naturalHeight = assetElement.naturalHeight;
      }

      return summary;
    }

    function serializeAssetState(element, computedStyle) {
      const assetElements = Array.from(element.querySelectorAll('img, svg, image'));
      if (element.matches('img, svg, image')) {
        assetElements.unshift(element);
      }

      const backgroundImage = computedStyle['background-image'];
      return {
        background_image_present: Boolean(backgroundImage && backgroundImage !== 'none'),
        background_image: backgroundImage,
        descendants: assetElements.map(assetElementSummary),
      };
    }

    function serializeVisibility(element, rect, computedStyle) {
      const opacity = Number.parseFloat(computedStyle.opacity);
      const visibleDisplay = computedStyle.display !== 'none';
      const visibleVisibility = computedStyle.visibility !== 'hidden' && computedStyle.visibility !== 'collapse';
      const visibleOpacity = !Number.isFinite(opacity) || opacity > 0;
      const hasPaintableRect = rect.width > 0 && rect.height > 0;

      return {
        visible: visibleDisplay && visibleVisibility && visibleOpacity && hasPaintableRect,
        display: computedStyle.display,
        visibility: computedStyle.visibility,
        opacity: computedStyle.opacity,
        'pointer-events': computedStyle['pointer-events'],
        clipped: element.clientWidth < element.scrollWidth || element.clientHeight < element.scrollHeight || !hasPaintableRect,
      };
    }

    return Array.from(document.querySelectorAll('[data-figma-node-id]')).map((element) => {
      const rect = element.getBoundingClientRect();
      const nodeId = element.getAttribute('data-figma-node-id') || '';
      const nodeName = element.getAttribute('data-figma-node-name') || element.getAttribute('data-figma-name') || element.getAttribute('aria-label') || null;
      const computedStyle = serializeComputedStyle(element);
      const textSample = normalizeText(element.textContent);
      const textLength = fullTextLength(element.textContent);
      return {
        page_path: currentPagePath,
        node_id: nodeId,
        node_name: nodeName,
        selector: selectorFor(element, nodeId),
        tag: element.tagName.toLowerCase(),
        text_sample: textSample,
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
        computed_style: computedStyle,
        text_metrics: {
          text_sample: textSample,
          text_length: textLength,
          scroll_width: element.scrollWidth,
          scroll_height: element.scrollHeight,
          client_width: element.clientWidth,
          client_height: element.clientHeight,
          line_count_estimate: estimateLineCount(element, computedStyle, textLength),
        },
        asset_state: serializeAssetState(element, computedStyle),
        visibility: serializeVisibility(element, rect, computedStyle),
      };
    });
  }, {
    pagePath,
    limit: textSampleLimit,
    computedStyleProperties: COMPUTED_STYLE_PROPERTIES,
  });
}
