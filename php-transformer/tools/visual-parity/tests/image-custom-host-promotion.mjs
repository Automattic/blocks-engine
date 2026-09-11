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

    const responsiveCropSource = '<!doctype html><style>@supports ((display:grid) and (object-fit:cover)){.responsive-crop{display:block;width:300px;margin:0}.responsive-crop img{display:block;width:100%;aspect-ratio:1 / 1!important;object-fit:contain!important}@media (min-width:701px){.responsive-crop{width:960px}.responsive-crop img{aspect-ratio:4 / 3!important;object-fit:cover!important}}}</style><main><figure class="responsive-crop"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="Responsive crop"></figure></main>';
    const responsiveCropResult = transform(responsiveCropSource);
    const responsiveCrop = responsiveCropResult.blocks.find((block) => block.blockName === 'core/image');
    assert.deepEqual(
        { aspectRatio: responsiveCrop?.attrs.aspectRatio, scale: responsiveCrop?.attrs.scale },
        { aspectRatio: '4/3', scale: 'cover' },
        '#841 promotes the desktop crop selected after the base rule'
    );
    const responsiveCropCss = responsiveCropResult.assets
        .filter((asset) => asset.kind === 'css')
        .map((asset) => asset.content)
        .join('\n');
    const responsiveEvidence = async (html, css, width) => {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        await page.setContent(`<!doctype html><style>body{margin:0}${css}</style>${html}`);
        const evidence = await page.locator('.responsive-crop img').evaluate((image) => {
            const box = image.getBoundingClientRect();
            const style = getComputedStyle(image);
            return { aspectRatio: style.aspectRatio, objectFit: style.objectFit, width: box.width, height: box.height };
        });
        await page.close();
        return evidence;
    };
    for (const [width, expected] of [[1440, { aspectRatio: '4 / 3', objectFit: 'cover', width: 960, height: 720 }], [390, { aspectRatio: '1 / 1', objectFit: 'contain', width: 300, height: 300 }]]) {
        const source = await responsiveEvidence(responsiveCropSource, '', width);
        const output = await responsiveEvidence(responsiveCropResult.serialized_blocks, responsiveCropCss, width);
        assert.deepEqual(source, expected, `#841 source crop has its expected ${width}px authored behavior`);
        assert.deepEqual(output, source, `#841 transformed core/image retains the ${width}px crop and bounds`);
    }

    const supportsEvidence = async (html, css) => {
        const page = await browser.newPage({ viewport: { width: 600, height: 300 } });
        await page.setContent(`<!doctype html><style>body{margin:0}${css}</style>${html}`);
        const evidence = await page.locator('.supports-crop img').evaluate((image) => {
            const box = image.getBoundingClientRect();
            const style = getComputedStyle(image);
            return { aspectRatio: style.aspectRatio, objectFit: style.objectFit, width: box.width, height: box.height };
        });
        await page.close();
        return evidence;
    };
    for (const fixture of [
        { name: 'true compound @supports', condition: '(display:grid) and (object-fit:cover)', promoted: true },
        { name: 'known-false compound @supports', condition: '(display:grid) and (object-fit:invalid)', promoted: false },
        { name: 'unknown-term compound @supports', condition: '(display:grid) and (unrecognized-property:value)', promoted: false },
    ]) {
        const source = `<style>.supports-crop{display:block;width:120px}.supports-crop img{display:block;width:120px;height:90px}@supports (${fixture.condition}){.supports-crop img{aspect-ratio:4 / 3;object-fit:cover}}</style><figure class="supports-crop"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="Supports crop"></figure>`;
        const result = transform(source);
        const image = result.blocks.find((block) => block.blockName === 'core/image');
        assert.ok(image, `${fixture.name} compiles to core/image`);
        assert.deepEqual(
            { aspectRatio: image.attrs.aspectRatio, scale: image.attrs.scale },
            fixture.promoted ? { aspectRatio: '4/3', scale: 'cover' } : { aspectRatio: undefined, scale: undefined },
            `${fixture.name} ${fixture.promoted ? 'promotes' : 'does not promote'} crop attributes`
        );
        const css = result.assets.filter((asset) => asset.kind === 'css').map((asset) => asset.content).join('\n');
        const before = await supportsEvidence(source, '');
        const after = await supportsEvidence(result.serialized_blocks, css);
        assert.deepEqual(after, before, `${fixture.name} preserves rendered crop and bounds`);
    }

    const zeroSizeSource = '<style>.zero-size-crop{display:block}.zero-size-crop img{display:block;width:+0;height:+0px;aspect-ratio:4 / 3;object-fit:cover}</style><figure class="zero-size-crop"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" width="0" height="0" alt="Zero size crop"></figure>';
    let zeroSizeResult;
    assert.doesNotThrow(() => { zeroSizeResult = transform(zeroSizeSource); }, '#841 signed-zero authored crop compiles');
    const zeroSizeImage = zeroSizeResult.blocks.find((block) => block.blockName === 'core/image');
    assert.ok(zeroSizeImage, 'zero-sized authored crop compiles to core/image');
    assert.deepEqual(
        { aspectRatio: zeroSizeImage.attrs.aspectRatio, scale: zeroSizeImage.attrs.scale },
        { aspectRatio: undefined, scale: undefined },
        'signed-zero authored crop does not manufacture promoted crop geometry'
    );
    const zeroSizeCss = zeroSizeResult.assets.filter((asset) => asset.kind === 'css').map((asset) => asset.content).join('\n');
    const zeroSizeEvidence = async (html, css) => {
        const page = await browser.newPage({ viewport: { width: 600, height: 300 } });
        await page.setContent(`<!doctype html><style>body{margin:0}${css}</style>${html}`);
        const evidence = await page.locator('.zero-size-crop img').evaluate((image) => {
            const box = image.getBoundingClientRect();
            return { width: box.width, height: box.height };
        });
        await page.close();
        return evidence;
    };
    const zeroSizeBefore = await zeroSizeEvidence(zeroSizeSource, '');
    const zeroSizeAfter = await zeroSizeEvidence(zeroSizeResult.serialized_blocks, zeroSizeCss);
    assert.deepEqual(zeroSizeBefore, { width: 0, height: 0 }, 'signed-zero source keeps zero bounds');
    assert.deepEqual(zeroSizeAfter, zeroSizeBefore, 'signed-zero transformed output keeps zero bounds');
} finally {
  await browser.close();
}

console.log('Image custom-host promotion browser parity passed');
