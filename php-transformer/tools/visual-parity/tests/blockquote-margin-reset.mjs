import { createServer } from 'node:http';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const toolRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const repositoryRoot = path.resolve(toolRoot, '../../..');
const fixturePath = path.join(toolRoot, 'tests/fixtures/blockquote-margin-reset.scenegraph.json');
const outputDir = await mkdtemp(path.join(tmpdir(), 'blocks-engine-blockquote-margin-'));
const transformScript = `
require $argv[1];
$scenegraph = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$result = blocks_engine_figma_transformer_transform_scenegraph($scenegraph);
foreach ($result['files'] as $file) {
    $path = $argv[3] . '/' . $file['path'];
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    file_put_contents($path, $file['content']);
}
`;

const transform = spawnSync('php', [
  '-r',
  transformScript,
  path.join(repositoryRoot, 'figma-transformer/figma-transformer.php'),
  fixturePath,
  outputDir,
], { encoding: 'utf8' });

if (transform.status !== 0) {
  throw new Error(`Figma fixture transform failed:\n${transform.stderr}`);
}

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
      const preceding = document.querySelector('[data-figma-node-id="blockquote-margin:preceding"]');
      const quote = document.querySelector('[data-figma-node-id="blockquote-margin:quote"]');
      const following = document.querySelector('[data-figma-node-id="blockquote-margin:following"]');
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

    assert(geometry.tag === 'BLOCKQUOTE', 'the generated quote uses a blockquote element');
    assert(geometry.margins.top === '8px', 'the generated blockquote class keeps its explicit 8px source margin over the reset');
    assert(geometry.margins.right === '0px' && geometry.margins.bottom === '0px' && geometry.margins.left === '0px', 'the reset removes Chromium blockquote UA 40px horizontal and 16px vertical margins');
    assert(closeTo(geometry.quote.left, geometry.preceding.left), 'the blockquote has no UA horizontal offset');
    assert(closeTo(geometry.quote.top - geometry.preceding.bottom, 24), 'the quote position combines the 16px stack gap and generated 8px margin');
    assert(closeTo(geometry.following.top - geometry.quote.bottom, 16), 'the following sibling remains at the generated 16px stack gap');
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
