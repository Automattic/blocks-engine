import { createServer } from 'node:http';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { chromium } from 'playwright';

const outputDir = await mkdtemp(path.join(tmpdir(), 'blocks-engine-blockquote-margin-'));
await writeFile(path.join(outputDir, 'index.html'), `<!doctype html>
<html><head><style>
body { margin: 0; }
.stack { display: flex; flex-direction: column; gap: 16px; }
.quote { margin: 8px 0 0; }
p { margin: 0; }
</style></head><body>
<div class="stack">
  <p data-node-id="preceding">Before</p>
  <blockquote class="quote" data-node-id="quote">Quote</blockquote>
  <p data-node-id="following">After</p>
</div>
</body></html>`);

const server = createServer(async (request, response) => {
  const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
  const filename = pathname === '/' ? 'index.html' : pathname.slice(1);
  if (!/^[A-Za-z0-9._/-]+$/.test(filename) || filename.includes('..')) {
    response.writeHead(400).end();
    return;
  }

  try {
    const content = await readFile(path.join(outputDir, filename));
    response.writeHead(200, { 'content-type': filename.endsWith('.css') ? 'text/css' : 'text/html' });
    response.end(content);
  } catch {
    response.writeHead(404).end();
  }
});

await new Promise((resolve, reject) => {
  server.on('error', reject);
  server.listen(0, '127.0.0.1', resolve);
});

try {
  const address = server.address();
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({ viewport: { width: 800, height: 600 } });
    await page.goto(`http://127.0.0.1:${address.port}/`, { waitUntil: 'load' });
    const geometry = await page.evaluate(() => {
      const preceding = document.querySelector('[data-node-id="preceding"]');
      const quote = document.querySelector('[data-node-id="quote"]');
      const following = document.querySelector('[data-node-id="following"]');
      if (!preceding || !quote || !following) {
        throw new Error('Generated stack nodes are missing.');
      }

      const rect = (element) => element.getBoundingClientRect();
      const computed = getComputedStyle(quote);
      return {
        tag: quote.tagName,
        margins: {
          top: computed.marginTop,
          right: computed.marginRight,
          bottom: computed.marginBottom,
          left: computed.marginLeft,
        },
        preceding: rect(preceding).toJSON(),
        quote: rect(quote).toJSON(),
        following: rect(following).toJSON(),
      };
    });

    assert(geometry.tag === 'BLOCKQUOTE', 'the quote uses a blockquote element');
    assert(geometry.margins.top === '8px', 'the blockquote keeps its explicit 8px margin');
    assert(geometry.margins.right === '0px' && geometry.margins.bottom === '0px' && geometry.margins.left === '0px', 'the reset removes browser blockquote margins');
    assert(closeTo(geometry.quote.left, geometry.preceding.left), 'the blockquote has no browser horizontal offset');
    assert(closeTo(geometry.quote.top - geometry.preceding.bottom, 24), 'the quote position combines the 16px stack gap and explicit 8px margin');
    assert(closeTo(geometry.following.top - geometry.quote.bottom, 16), 'the following sibling remains at the 16px stack gap');
  } finally {
    await browser.close();
  }
} finally {
  await new Promise((resolve) => server.close(resolve));
  await rm(outputDir, { recursive: true, force: true });
}

console.log('Blockquote margin reset geometry test passed.');

function closeTo(actual, expected) {
  return Math.abs(actual - expected) < 0.01;
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}
