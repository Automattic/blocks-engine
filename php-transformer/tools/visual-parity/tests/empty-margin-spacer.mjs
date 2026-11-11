import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, mkdtempSync, rmSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const temp = mkdtempSync(path.join(os.tmpdir(), 'empty-margin-'));
const artifact = path.join(temp, 'artifact.json');
let browser;
try {
  execFileSync('php', [path.join(root, 'tests/unit/empty-margin-spacer.php')], {
    env: { ...process.env, EMPTY_MARGIN_ARTIFACT: artifact },
  });
  const fixture = JSON.parse(readFileSync(artifact, 'utf8'));
  browser = await chromium.launch();
  const page = await browser.newPage();
  for (const width of [390, 768, 1440]) {
    await page.setViewportSize({ width, height: 800 });
    const observations = [];
    for (const html of [fixture.source, fixture.native]) {
      await page.setContent(html);
      observations.push(await page.locator('.media').evaluate(el => ({
        top: el.getBoundingClientRect().top,
        height: el.getBoundingClientRect().height,
      })));
    }
    assert.equal(observations[0].top, 106);
    assert.deepEqual(observations[1], observations[0], `Media geometry at ${width}px`);
    console.log(`${width}px: source ${JSON.stringify(observations[0])}, native ${JSON.stringify(observations[1])}`);
  }
} finally {
  await browser?.close();
  rmSync(temp, { recursive: true, force: true });
}
