import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../..', import.meta.url).pathname;
const php = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[2])->toArray());';
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress block-library/style.min.css');
const core = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH});
try {
	const page = await browser.newPage({viewport: {width: 768, height: 800}});
	for (const boxSizing of ['', 'content-box', 'border-box']) {
		// A percentage content width excludes padding: the source intentionally
		// overflows its 320px flex column by 40px. No global box-sizing reset.
		const html = `<!doctype html><html><head><meta charset="utf-8"><title>Button</title><style>
body{margin:0}
.btns{display:flex;flex-flow:column;padding:0;margin:0;list-style:none;width:320px}
.btns>li>a{display:inline-flex;width:100%;flex-direction:row-reverse;padding:12px 20px;background:#f05e3e;color:#fff;text-decoration:none;${boxSizing ? `box-sizing:${boxSizing}` : ''}}
.btns>li>a>svg{display:block;width:24px;height:24px;fill:#fff;flex-shrink:0}
</style></head><body><main>
<ul class="btns"><li><a href="#about" role="button"><svg viewBox="0 0 40 40"><path d="M17 5l13 14-13 14-3-3 11-11-11-11z"></path></svg><span>About</span></a></li></ul>
</main></body></html>`;
		const result = JSON.parse(execFileSync('php', ['-r', php, root, html], {encoding: 'utf8'}));
		assert.ok(result.serialized_blocks.includes('wp:button '), 'the source becomes a native button');
		const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
		const measure = () => page.locator('a[href="#about"]').evaluate(link => {
			const rect = link.getBoundingClientRect();
			const icon = link.querySelector('svg,img').getBoundingClientRect();
			const range = document.createRange();
			const walker = document.createTreeWalker(link, NodeFilter.SHOW_TEXT);
			while (walker.nextNode()) {
				if (walker.currentNode.textContent.trim() === 'About') break;
			}
			range.selectNodeContents(walker.currentNode);
			const label = range.getBoundingClientRect();
			return {width: rect.width, boxSizing: getComputedStyle(link).boxSizing, iconX: icon.x, iconY: icon.y, labelX: label.x, labelY: label.y};
		});
		await page.setContent(html);
		const source = await measure();
		await page.setContent(`<!doctype html><style>body{margin:0}\n${core}\n${css}</style>${result.serialized_blocks}`);
		const native = await measure();
		console.log(JSON.stringify({boxSizing: boxSizing || 'default', source, native}));
		assert.equal(source.width, boxSizing === 'border-box' ? 320 : 360);
		assert.deepEqual(native, source, 'native link keeps the source box model and label/icon positions');
	}
} finally {
	await browser.close();
}
