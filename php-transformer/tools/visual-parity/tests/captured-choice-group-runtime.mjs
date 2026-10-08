import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

const viewScript = execFileSync(
  'php',
  [
    '-r',
    "require 'vendor/autoload.php'; echo (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\CapturedChoiceGroupBlockGenerator())->definition('blocks-engine/choice-probe')['view_js'];",
  ],
  { cwd: packageRoot, encoding: 'utf8' }
);

const config = {
  choices: [0, 1, 2].map((index) => ({
    observed_choice_key: `choice-${index}`,
    source_value: null,
  })),
  states: [0, 1, 2].map((selectedIndex) => ({
    selectedIndex,
    bindings: [0, 1, 2].map((choiceIndex) => ({
      choiceIndex,
      nodes: [
        {
          path: [],
          attributes: { 'aria-pressed': choiceIndex === selectedIndex ? 'true' : 'false' },
        },
        {
          path: [0],
          attributes: { style: choiceIndex === selectedIndex ? 'fill:gold' : 'fill:none' },
        },
      ],
    })),
  })),
};

const source = `<form><div data-blocks-engine-choice-group="true" data-blocks-engine-choice-config='${JSON.stringify(config)}'><button type="button"><svg></svg><span>Saved edited label</span></button><button type="button"><svg></svg><span>Original 1</span></button><button type="button"><svg></svg><span>Original 2</span></button></div></form>`;

const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage();
  await page.setContent(source);
  await page.addScriptTag({ content: viewScript });

  const group = page.locator('[data-blocks-engine-choice-group]');
  const first = page.locator('button').first();
  if (await group.getAttribute('data-blocks-engine-choice-selection') !== null) throw new Error('initial selection was inferred');
  await first.click();
  if (await first.locator('span').textContent() !== 'Saved edited label') throw new Error('activation replaced edited content');
  if (await first.locator('svg').getAttribute('style') !== 'fill:gold') throw new Error('activation did not apply the bounded state binding');

  const saved = await page.locator('[data-blocks-engine-choice-group]').evaluate((element) => {
    element.removeAttribute('data-blocks-engine-choice-mounted');
    element.querySelectorAll('[data-blocks-engine-choice-index],[data-blocks-engine-choice-active]').forEach((node) => {
      node.removeAttribute('data-blocks-engine-choice-index');
      node.removeAttribute('data-blocks-engine-choice-active');
    });
    element.removeAttribute('data-blocks-engine-choice-selection');
    return element.outerHTML;
  });
  await page.setContent(`<form>${saved}</form>`);
  await page.addScriptTag({ content: viewScript });
  await page.locator('button').nth(2).click();
  await page.locator('button').nth(2).focus();
  await page.keyboard.press('ArrowLeft');
  const selection = JSON.parse(await page.locator('[data-blocks-engine-choice-group]').getAttribute('data-blocks-engine-choice-selection'));
  if (selection.selected_index !== 1) throw new Error(`delegated keyboard selection was ${selection.selected_index}`);
  if (await page.locator('button').first().locator('span').textContent() !== 'Saved edited label') throw new Error('reload replay replaced edited content');
  if ((await page.evaluate(() => [...new FormData(document.querySelector('form')).entries()])).length !== 0) throw new Error('runtime invented a form field');
  console.log('OK: captured-choice-group-runtime browser regressions passed');
} finally {
  await browser.close();
}
