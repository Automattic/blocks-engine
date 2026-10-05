import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const source = '<style>@layer utilities {'
	+ '.text-muted-foreground{color:rgb(119,119,119)}'
	+ '.hover\\:text-foreground:focus{color:rgb(17,17,17)}'
	+ '.transition-colors{transition:color 150ms linear}'
	+ '}@media (hover:hover){@layer utilities{.hover\\:text-foreground:hover{color:rgb(17,17,17)}}}</style><nav><ul><li><a class="text-muted-foreground hover:text-foreground transition-colors" href="/">Home</a></li></ul></nav>';
const result = JSON.parse(execFileSync('php', [
	'-r',
	'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());',
	root,
	Buffer.from(source).toString('base64'),
], { encoding: 'utf8' }));
const link = result.blocks.flatMap((block) => block.innerBlocks ?? []).find((block) => block.blockName === 'core/navigation-link');
assert.ok(link, 'source navigation is saved as a native navigation link');
assert.ok(!result.serialized_blocks.includes('core/html'), 'navigation conversion has no fallback block');
const itemClasses = link.attrs.className;
const css = result.assets.filter((asset) => asset.kind === 'css').map((asset) => asset.content).join('\n');
assert.match(css, /@media \(hover:hover\)\{\.wp-block-navigation[^{}]*\.hover\\:text-foreground[^{}]*__content:hover\{color:rgb\(17,17,17\)\}\}/);
assert.match(css, /wp-block-navigation-item\.hover\\:text-foreground[^{}]*__content:focus\{color:rgb\(17,17,17\)\}/);
assert.match(css, /transition:color 150ms linear/);

const browser = await chromium.launch({ headless: true });
try {
	for (const viewport of [{ width: 1440, height: 800 }, { width: 390, height: 844 }]) {
		const page = await browser.newPage({ viewport, hasTouch: viewport.width < 600, isMobile: viewport.width < 600 });
		await page.setContent(source);
		const sourceAnchor = page.locator('a');
		const sourceNormal = await sourceAnchor.evaluate((element) => getComputedStyle(element).color);
		await sourceAnchor.hover();
		await page.waitForTimeout(200);
		const sourceHover = await sourceAnchor.evaluate((element) => getComputedStyle(element).color);
		await page.mouse.move(0, viewport.height - 1);
		await sourceAnchor.evaluate((element) => element.blur());
		await page.keyboard.press('Tab');
		assert.equal(await sourceAnchor.evaluate((element) => element.matches(':focus')), true, 'keyboard reaches the source link');
		await page.waitForTimeout(200);
		const sourceFocus = await sourceAnchor.evaluate((element) => getComputedStyle(element).color);

		const target = await browser.newPage({ viewport, hasTouch: viewport.width < 600, isMobile: viewport.width < 600 });
		await target.setContent(`<style>${css}</style><nav class="wp-block-navigation blocks-engine-list-navigation"><ul class="wp-block-navigation__container"><li class="wp-block-navigation-item wp-block-navigation-link ${itemClasses}"><a class="wp-block-navigation-item__content" href="/"><span class="wp-block-navigation-item__label">Home</span></a></li></ul></nav>`);
		const targetAnchor = target.locator('.wp-block-navigation-item__content');
		assert.equal(await targetAnchor.evaluate((element) => getComputedStyle(element).color), sourceNormal, `normal paint matches at ${viewport.width}px`);
		await targetAnchor.hover();
		await target.waitForTimeout(200);
		assert.equal(await targetAnchor.evaluate((element) => getComputedStyle(element).color), sourceHover, `hover paint matches at ${viewport.width}px`);
		await target.mouse.move(0, viewport.height - 1);
		await targetAnchor.evaluate((element) => element.blur());
		await target.keyboard.press('Tab');
		assert.equal(await targetAnchor.evaluate((element) => element.matches(':focus')), true, 'keyboard reaches the native target link');
		await target.waitForTimeout(200);
		assert.equal(await targetAnchor.evaluate((element) => getComputedStyle(element).color), sourceFocus, `keyboard-focus paint matches at ${viewport.width}px`);
		assert.equal(await targetAnchor.evaluate((element) => getComputedStyle(element).transitionDuration), '0.15s', `transition is retained at ${viewport.width}px`);

		const editorShape = await browser.newPage({ viewport, hasTouch: viewport.width < 600, isMobile: viewport.width < 600 });
		await editorShape.setContent(`<style>.wp-block-navigation-item__content{color:inherit}${css}</style><div class="editor-styles-wrapper"><nav class="wp-block-navigation"><ul class="wp-block-navigation__container"><li class="wp-block-navigation-item wp-block-navigation-link ${itemClasses}"><a class="wp-block-navigation-item__content" href="/"><span class="wp-block-navigation-item__label">Home</span></a></li></ul></nav></div>`);
		const editorAnchor = editorShape.locator('.wp-block-navigation-item__content');
		assert.equal(await editorAnchor.evaluate((element) => getComputedStyle(element).color), sourceNormal, `editor wrapper resting paint matches at ${viewport.width}px`);
		await editorAnchor.hover();
		await editorShape.waitForTimeout(200);
		assert.equal(await editorAnchor.evaluate((element) => getComputedStyle(element).color), sourceHover, `editor wrapper hover paint matches at ${viewport.width}px`);
		assert.equal(await editorAnchor.evaluate((element) => getComputedStyle(element).transitionDuration), '0.15s', `editor wrapper transition is retained at ${viewport.width}px`);
		await page.close();
		await target.close();
		await editorShape.close();
	}
} finally {
	await browser.close();
}

console.log('Navigation hover/focus projection browser regression passed at desktop and mobile widths');
