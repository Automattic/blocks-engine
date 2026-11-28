import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
// A 4:5 portrait image fills a square padding-bottom frame by absolute
// positioning; it must not fall back to its intrinsic height.
const portrait = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="240" height="300"><rect width="240" height="300" fill="#c33"/></svg>');
const html = `<!doctype html><html><head><meta charset="utf-8"><title>Card</title><style>
body{margin:0}.row{display:flex;width:353px}
.card{display:flex;flex-direction:column;padding:16px;align-items:center}
.square{display:flex;justify-content:center;align-items:center;padding-bottom:100%;overflow:hidden;position:relative;width:100%}
.fill{width:100%;height:100%;top:0;object-position:center;position:absolute}
</style></head><body><main><div class="row"><div style="flex:1 1 0%;width:100%"><div class="card"><a class="square" href="/product/"><img class="fill" src="${portrait}" alt="Product"></a><div class="name">Product</div></div></div></div></main></body></html>`;
const result = JSON.parse(execFileSync('php', ['-d', 'error_reporting=24575', '-r', php, root, html], {encoding: 'utf8'}));
assert.ok(result.serialized_blocks.includes('wp:image'), 'the linked image becomes a native image block');
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH});
try {
	const page = await browser.newPage({viewport: {width: 768, height: 800}});
	const measure = () => page.locator('img[alt="Product"]').evaluate(img => {
		const box = rect => ({width: Math.round(rect.width), height: Math.round(rect.height)});
		return {image: box(img.getBoundingClientRect()), frame: box(img.closest('a').getBoundingClientRect())};
	});
	await page.setContent(html);
	const source = await measure();
	await page.setContent(`<!doctype html><style>body{margin:0}\n${core}\n${css}</style>${result.serialized_blocks}`);
	const native = await measure();
	console.log(JSON.stringify({source, native}));
	assert.deepEqual(source, {image: {width: 321, height: 321}, frame: {width: 321, height: 321}});
	assert.deepEqual(native, source, 'the image fills its square frame as in the source');
} finally {
	await browser.close();
}
