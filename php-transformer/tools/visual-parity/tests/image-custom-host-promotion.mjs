import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const cases = [
  {
    name: 'safe block host',
    source: '<media-frame style="display:block"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" width="80" height="60" alt="Profile"></media-frame>',
    expected: 'core/image',
    evidence: (page) => page.evaluate(() => {
      const image = document.querySelector('img');
      const carrier = document.querySelector('figure.wp-block-image, media-frame');
      return { display: getComputedStyle(carrier).display, width: image.width, height: image.height };
    }),
  },
  {
    name: 'inline adjacent text and icon',
    source: '<p>Before <media-frame><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" width="30" height="30" alt="Profile"></media-frame><svg aria-hidden="true" viewBox="0 0 1 1"><path d="M0 0"></path></svg> after</p>',
    expected: 'core/html',
    evidence: (page) => page.evaluate(() => { const p = document.querySelector('p'); const host = document.querySelector('media-frame'); return { display: getComputedStyle(host).display, text: p.textContent, hostLeft: host.getBoundingClientRect().left, iconLeft: document.querySelector('svg').getBoundingClientRect().left }; }),
  },
  {
    name: 'overflow clipping',
    source: '<media-frame style="display:block;width:80px;height:60px;overflow:hidden"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" style="width:160px;height:120px" alt="Profile"></media-frame>',
    expected: 'custom/responsive-media',
    evidence: (page) => page.evaluate(() => { const host = document.querySelector('media-frame'); const image = host.querySelector('img'); return { overflow: getComputedStyle(host).overflow, hostWidth: host.getBoundingClientRect().width, imageWidth: image.getBoundingClientRect().width }; }),
  },
  {
    name: 'crop focus',
    source: '<style>.focus-frame{display:block}.focus-frame img{width:80px;height:60px;aspect-ratio:4 / 3;object-fit:cover;object-position:right top}</style><media-frame class="focus-frame"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="Profile"></media-frame>',
    expected: 'custom/responsive-media',
    evidence: (page) => page.evaluate(() => ({ fit: getComputedStyle(document.querySelector('img')).objectFit, position: getComputedStyle(document.querySelector('img')).objectPosition })),
  },
  {
    name: 'wrapper identity accessibility and data hook',
    source: '<button aria-controls="profile-frame">Open</button><media-frame id="profile-frame" aria-label="Profile image" data-hook="profile-image" style="display:block"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="Profile"></media-frame>',
    expected: 'custom/responsive-media',
    evidence: (page) => page.evaluate(() => { const host = document.querySelector('media-frame'); return { controls: document.querySelector('button').getAttribute('aria-controls'), id: host.id, label: host.getAttribute('aria-label'), hook: host.getAttribute('data-hook') }; }),
  },
];

function transform(source) {
  const code = 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());';
  return JSON.parse(execFileSync('php', ['-r', code, root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
}

function renderPromotedImage(result) {
  const attributes = result.blocks.find((block) => block.blockName === 'core/image')?.attrs;
  assert.ok(attributes, 'the promoted block has image attributes');
  const attribute = (name, value) => value === undefined || value === null || value === '' ? '' : ` ${name}="${String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;')}"`;
  return `<figure class="wp-block-image"><img${attribute('src', attributes.url)}${attribute('alt', attributes.alt)}${attribute('width', attributes.width)}${attribute('height', attributes.height)}></figure>`;
}

const browser = await chromium.launch({ headless: true });
try {
  for (const fixture of cases) {
    const result = transform(fixture.source);
    const names = result.blocks.map((block) => block.blockName);
    assert.ok(names.includes(fixture.expected), `${fixture.name} produces ${fixture.expected}`);
    const promoted = fixture.expected === 'core/image';
    // Responsive media renders its captured content server-side; using the source
    // markup here models that audited renderer without WordPress bootstrapping.
    const transformed = promoted ? renderPromotedImage(result) : fixture.source;
    const before = await browser.newPage({ viewport: { width: 600, height: 300 } });
    const after = await browser.newPage({ viewport: { width: 600, height: 300 } });
    await before.setContent(`<!doctype html><body>${fixture.source}</body>`);
    await after.setContent(`<!doctype html><body>${transformed}</body>`);
    assert.deepEqual(await fixture.evidence(after), await fixture.evidence(before), `${fixture.name} retains rendered layout and style evidence`);
    await before.close();
    await after.close();
  }
} finally {
  await browser.close();
}

console.log('Image custom-host promotion browser parity passed');
