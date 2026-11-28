import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
// A carousel viewport clips its wider slide track with an inline overflow.
const slide = name => `<div style="flex: 1 1 0%; width: 33.3333%;"><div class="card"><a href="/${name}/">Product ${name}</a></div></div>`;
const html = `<!doctype html><html><head><meta charset="utf-8"><title>Carousel</title><style>
body{margin:0}.wrap{width:555px}.viewport{width:100%}.card{padding:16px}
</style></head><body><main><div class="wrap"><div class="viewport" style="overflow: hidden;direction: ltr">
<div style="width: 1059px; display: flex; flex-direction: row;">${slide('a')}${slide('b')}${slide('c')}</div>
</div></div></main></body></html>`;
const result = JSON.parse(execFileSync('php', ['-d', 'error_reporting=24575', '-r', php, root, html], {encoding: 'utf8'}));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH});
try {
	const page = await browser.newPage({viewport: {width: 768, height: 600}});
	const measure = () => page.evaluate(() => {
		const link = document.querySelector('a[href="/c/"]').getBoundingClientRect();
		const clip = document.elementFromPoint(Math.min(link.x + 4, innerWidth - 1), link.y + 4);
		return {scrollWidth: document.documentElement.scrollWidth, thirdSlideVisible: clip?.closest('a')?.getAttribute('href') === '/c/'};
	});
	await page.setContent(html);
	const source = await measure();
	await page.setContent(`<!doctype html><style>body{margin:0}\n${core}\n${css}</style>${result.serialized_blocks}`);
	const native = await measure();
	console.log(JSON.stringify({source, native}));
	assert.deepEqual(source, {scrollWidth: 768, thirdSlideVisible: false});
	assert.deepEqual(native, source, 'the viewport still clips its off-screen slides');
} finally {
	await browser.close();
}
