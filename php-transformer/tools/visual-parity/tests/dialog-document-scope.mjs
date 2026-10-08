import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const fixture = (variant) => JSON.parse(execFileSync('php', ['-r', '$fixture=require $argv[1] . "/tests/fixtures/dialog-document-scope.php"; echo json_encode($fixture($argv[2]));', root, variant], { encoding: 'utf8' }));
const compile = (artifact) => JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$r=(new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR))->toArray();
$views=array(); $css=implode("\\n",array_column($r['assets'],'content'));
foreach($r['assets'] as $asset) { if ('inline-script' === ($asset['source'] ?? '') && 'js' === ($asset['kind'] ?? '')) $views[]=$asset['content']; }
foreach($r['source_reports']['companion_plugin_payload']['blocks'] ?? array() as $block) { if (isset($block['view_js'])) $views[]=$block['view_js']; if (isset($block['assets']['style.css'])) $css.="\\n".$block['assets']['style.css']; }
echo json_encode(array('html'=>$r['serialized_blocks'],'css'=>$css,'views'=>$views));
`, root], { input: JSON.stringify(artifact), encoding: 'utf8' }));

const browser = await chromium.launch({ headless: true });
try {
  for (const variant of ['desktop', 'mobile']) {
    const page = await browser.newPage();
    const artifact = fixture(variant);
    await page.setContent(artifact.files['index.html']);
    const sourceTrigger = page.getByRole('button', { name: 'Open gallery', exact: true });
    await sourceTrigger.click();
    const observed = await page.getByRole('dialog').evaluate((panel) => ({ html: panel.outerHTML, caption: panel.querySelector('p').textContent }));
    assert.equal(observed.caption, 'Captured image caption.');
    await page.getByRole('button', { name: 'Close gallery', exact: true }).click();
    assert.equal(await sourceTrigger.getAttribute('aria-expanded'), 'false');

    // Use actual observed open/closed browser DOM as the compiler input and
    // interaction evidence; the incomplete ancestor proof remains unchanged.
    artifact.files['index.html'] = await page.content();
    const report = JSON.parse(artifact.files['interaction-states.json']);
    report.pages[0].states[0].dialog.html = observed.html;
    artifact.files['interaction-states.json'] = JSON.stringify(report);
    const result = compile(artifact);
    assert.doesNotMatch(result.html, /<!-- wp:html/);
    assert.match(result.html, /<!-- wp:heading/);
    assert.match(result.html, /data-dla-document-scope/);
    assert.match(result.html, /data-dla-dialog-ancestor-unverified/);
    await page.setContent(`<style>${result.css}</style>${result.html}`);
    for (const script of result.views) await page.addScriptTag({ content: script });
    const trigger = page.getByRole('button', { name: 'Open gallery', exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog');
    assert.equal(await dialog.locator('p').textContent(), observed.caption);
    assert.equal(await dialog.evaluate(node => Boolean(node.closest('[data-dla-document-scope]'))), true);
    await dialog.getByRole('button', { name: 'Close gallery', exact: true }).click();
    assert.equal(await page.locator('[data-dla-dialog-panel]').evaluate(node => node.hidden), true);
    await trigger.click();
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('[data-dla-dialog-panel]').evaluate(node => node.hidden), true);
    assert.equal(await page.getByRole('heading', { name: 'Unrelated editable heading' }).count(), 1);
    assert.ok((await page.locator('body').textContent()).includes('Unrelated editable footer.'));
    await page.close();
  }
} finally { await browser.close(); }
console.log('Dialog document scopes: browser-captured popup evidence, scoped open/close/Escape, reviewable proof, and editable main/footer passed.');
