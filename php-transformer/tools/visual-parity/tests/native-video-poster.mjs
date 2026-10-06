import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const source = await fs.readFile(path.join(root, 'tests/fixtures/native-video-poster.html'), 'utf8');
const css = 'body{margin:0}ambient-frame{padding:3px;border:2px solid navy;box-sizing:content-box}.media-frame{color:navy}.media-cover{position:absolute;inset:0}@media(max-width:600px){.media-frame{width:280px!important;height:210px!important}}';
const compiled = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
if ('' !== $argv[4]) { $source = shell_exec('git -C ' . escapeshellarg($argv[1]) . ' show ' . escapeshellarg($argv[4] . ':php-transformer/src/HtmlToBlocks/HtmlCompilation.php')); eval(substr($source, 5)); }
$result = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]), array('static_css' => base64_decode($argv[3])))->toArray();
echo json_encode(array('markup' => $result['serialized_blocks'], 'css' => implode("\\n", array_column($result['assets'], 'content')), 'fallbacks' => $result['fallbacks'], 'validity' => $result['source_reports']['wp_block_validity']['status']));
`, root, Buffer.from(source).toString('base64'), Buffer.from(css).toString('base64'), process.env.BLOCKS_ENGINE_TEST_BASELINE || ''], { encoding: 'utf8' }));

const browser = await chromium.launch({ headless: true });
try {
  // Produce a real, local playable fixture in Chromium, avoiding remote media
  // dependencies and committed binary assets. Every frame is the same blue.
  const producer = await browser.newPage();
  const encoded = await producer.evaluate(async () => {
    const canvas = document.createElement('canvas'); canvas.width = 640; canvas.height = 360;
    const context = canvas.getContext('2d');
    const paint = () => { context.fillStyle = '#2468ac'; context.fillRect(0, 0, canvas.width, canvas.height); };
    paint();
    const stream = canvas.captureStream(10);
    const recorder = new MediaRecorder(stream, { mimeType: 'video/webm;codecs=vp8' });
    const chunks = [];
    recorder.ondataavailable = (event) => chunks.push(event.data);
    const done = new Promise((resolve) => { recorder.onstop = resolve; });
    const interval = setInterval(paint, 100);
    recorder.start();
    await new Promise((resolve) => setTimeout(resolve, 1200));
    recorder.stop(); await done; clearInterval(interval); stream.getTracks().forEach((track) => track.stop());
    const bytes = new Uint8Array(await new Blob(chunks, { type: 'video/webm' }).arrayBuffer());
    return btoa(String.fromCharCode(...bytes));
  });
  await producer.close();
  const videoBytes = Buffer.from(encoded, 'base64');
  const poster = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360"><path fill="red" d="M0 0h640v360H0z"/></svg>';
  for (const width of [390, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    let markup = source; let styles = css;
    await page.route('http://media.test/**', async (route) => {
      const pathname = new URL(route.request().url()).pathname;
      if (pathname === '/clip.webm') return route.fulfill({ contentType: 'video/webm', body: videoBytes });
      if (pathname === '/cover.svg') return route.fulfill({ contentType: 'image/svg+xml', body: poster });
      await route.fulfill({ contentType: 'text/html', body: `<style>${styles}</style>${markup}` });
    });
    const observe = async () => {
      await page.waitForFunction(() => document.querySelector('video')?.readyState >= 2 && !document.querySelector('video').paused && document.querySelector('img')?.naturalWidth > 0);
      const before = await page.locator('video').evaluate((video) => video.currentTime);
      await page.waitForTimeout(150);
      const result = await page.evaluate(() => {
        const video = document.querySelector('video');
        const box = (node) => { const { x, y, width, height } = node.getBoundingClientRect(); return { x, y, width, height }; };
        const canvas = document.createElement('canvas'); canvas.width = 1; canvas.height = 1;
        const context = canvas.getContext('2d'); context.drawImage(video, 0, 0, 1, 1);
        return { time: video.currentTime, geometry: { host: box(document.getElementById('ambient')), video: box(video), poster: box(document.getElementById('cover')), image: box(document.querySelector('img')) }, fit: getComputedStyle(video).objectFit, videoOpacity: getComputedStyle(video).opacity, posterOpacity: getComputedStyle(document.getElementById('cover')).opacity, autoplay: video.autoplay, muted: video.muted, loop: video.loop, inline: video.playsInline, pixel: [...context.getImageData(0, 0, 1, 1).data] };
      });
      assert.notEqual(result.time, before, 'The actual video advances during autoplay');
      assert.equal(result.fit, 'cover'); assert.equal(result.videoOpacity, '1'); assert.equal(result.posterOpacity, '0');
      assert.ok(result.autoplay && result.muted && result.loop && result.inline, 'Native playback properties survive');
      assert.ok(result.pixel[2] > result.pixel[0] + 80, 'Decoded video artwork is blue; the hidden red poster does not replace it');
      const { time, ...presentation } = result;
      return presentation;
    };
    await page.goto('http://media.test/source.html');
    const expected = await observe();
    markup = compiled.markup; styles = css + '\n' + compiled.css;
    await page.goto('http://media.test/compiled.html');
    assert.equal(await page.locator('video').count(), 1, 'Compiled output retains the playable local video');
    const actual = await observe();
    assert.deepEqual(actual, expected, `${width}px native playback, poster opacity, and geometry match source`);
    await page.close();
  }
  assert.deepEqual(compiled.fallbacks, []);
  assert.equal(compiled.validity, 'pass');
  assert.match(compiled.markup, /<!-- wp:video/);
  assert.match(compiled.markup, /<!-- wp:image/);
  assert.doesNotMatch(compiled.markup, /<!-- wp:html/);
} finally { await browser.close(); }
console.log('Native video/poster browser regression passed: real local playback, decoded pixels, editable blocks, exact 390/1440px geometry.');
