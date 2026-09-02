import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright';
import { describe, expect, it } from 'vitest';

import { emptyNativeRenderOut } from '../theme/native-block-builders.js';
import { iconImageBlock } from '../theme/native-media.js';

describe('captured SVG theme assets', () => {
  it('emits namespace-free captured SVG icons that Chromium decodes as external images', async () => {
    const out = emptyNativeRenderOut();
    const markup = iconImageBlock(
      {
        kind: 'svg',
        markup: '<svg fill="#ababab" viewBox="0 0 24 24" width="24" height="24"><path d="M2 2h20v20H2z"/></svg>',
        width: 24,
        height: 24,
      },
      out,
      { iconCounter: 0, mediaTextIndex: 0, paletteTokens: [], fontFamilies: [] }
    );

    expect(markup).toContain("get_theme_file_uri('assets/icon-0.svg')");
    expect(out.iconAssets).toHaveLength(1);

    const asset = out.iconAssets[0];
    expect(asset.path).toBe('assets/icon-0.svg');
    const outputDir = await mkdtemp(join(tmpdir(), 'blocks-engine-svg-asset-'));
    const server = createServer((request, response) => {
      if (request.url === '/icon.svg') {
        response.writeHead(200, { 'content-type': 'image/svg+xml' }).end(asset.svg);
        return;
      }
      response.writeHead(404).end();
    });
    await new Promise<void>((resolve, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolve);
    });

    try {
      const address = server.address();
      if (!address || typeof address === 'string') throw new Error('SVG test server did not expose a TCP port.');
      await writeFile(
        join(outputDir, 'index.html'),
        `<img id="icon" src="http://127.0.0.1:${address.port}/icon.svg"><script>const image = document.querySelector('#icon'); image.decode().then(() => document.body.dataset.decode = image.naturalWidth + 'x' + image.naturalHeight).catch(() => document.body.dataset.decode = 'failed');</script>`
      );
      const browser = await chromium.launch();
      try {
        const page = await browser.newPage();
        await page.goto(`file://${join(outputDir, 'index.html')}`);
        await page.waitForFunction(() => document.body.dataset.decode !== undefined);
        expect(await page.locator('body').getAttribute('data-decode')).toBe('24x24');
      } finally {
        await browser.close();
      }
    } finally {
      await new Promise<void>((resolve) => server.close(() => resolve()));
      await rm(outputDir, { recursive: true, force: true });
    }
  });
});
