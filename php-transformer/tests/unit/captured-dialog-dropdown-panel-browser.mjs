// Real-browser contract for captured dialog modality. The capture records
// whether the source panel was a modal or a dropdown; a dropdown opens without
// making the page inert, its source trigger toggles it, Escape closes it and
// returns focus, and it gets no generated Close control. Modals are unchanged.
// Run `php tests/unit/captured-dialog-dropdown-panel.php` first for fixtures.
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import assert from 'node:assert/strict';

const playwrightPath = process.env.PLAYWRIGHT_MODULE || 'playwright';
const { chromium } = await import(playwrightPath);
const fixture = JSON.parse(readFileSync(`${tmpdir()}/captured-dialog-dropdown-panel.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
let checks = 0;
const check = (actual, expected, message) => { assert.deepEqual(actual, expected, message); checks++; };

async function load(markup) {
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  // A page-level control behind the panel proves the page stays interactive.
  await page.setContent(`<style>${fixture.css}</style>${markup}<p style="margin-top:600px"><button id="behind" type="button" onclick="this.dataset.clicked='yes'">Behind</button></p><script>${fixture.script}</script>`);
  return page;
}
const state = page => page.evaluate(() => {
  const dialog = document.querySelector('dialog[data-blocks-engine-triggers]');
  const trigger = document.getElementById(dialog.getAttribute('data-blocks-engine-triggers').split(' ')[0]);
  const control = trigger.matches('button') ? trigger : trigger.querySelector('button');
  const header = document.querySelector('header');
  return {
    open: dialog.open,
    modal: dialog.matches(':modal'),
    expanded: control.getAttribute('aria-expanded'),
    closeButtons: dialog.querySelectorAll('[data-blocks-engine-dialog-close]').length,
    headerClass: header.className,
    headerBackground: getComputedStyle(header).backgroundColor,
    inHeader: header.contains(dialog),
    position: getComputedStyle(dialog).position,
    focus: document.activeElement === control,
  };
});

for (const [name, markup, placement] of [['in-place', fixture.inPlace, 'static'], ['under-header', fixture.underHeader, 'fixed']]) {
  const page = await load(markup);
  const control = page.locator('dialog[data-blocks-engine-triggers]').evaluateHandle(dialog => document.getElementById(dialog.getAttribute('data-blocks-engine-triggers').split(' ')[0]).querySelector('button'));
  check((await state(page)).expanded, 'false', `${name}: the trigger starts collapsed`);
  await (await control).asElement().click();
  const opened = await state(page);
  check([opened.open, opened.modal, opened.expanded, opened.closeButtons], [true, false, 'true', 0], `${name}: the trigger opens a non-modal dropdown without a generated Close control`);
  check([opened.headerClass.includes('bg-dark'), opened.headerBackground], [true, 'rgb(2, 6, 23)'], `${name}: the recorded header open state is replayed`);
  check([opened.inHeader, opened.position], [name === 'in-place', placement], `${name}: the dropdown keeps its evidence-derived placement`);
  await page.locator('#behind').click({ timeout: 2000 });
  check(await page.evaluate(() => document.getElementById('behind').dataset.clicked), 'yes', `${name}: the page behind an open dropdown stays interactive`);
  check((await state(page)).open, true, `${name}: no outside-click dismissal is invented`);
  await (await control).asElement().click();
  await page.waitForTimeout(50);
  const toggled = await state(page);
  check([toggled.open, toggled.expanded, toggled.headerClass.includes('bg-dark')], [false, 'false', false], `${name}: the trigger toggles the dropdown closed and restores the header`);
  await (await control).asElement().click();
  await page.locator('dialog a, dialog [href]').first().focus().catch(() => {});
  await page.keyboard.press('Escape');
  await page.waitForTimeout(50);
  const escaped = await state(page);
  check([escaped.open, escaped.expanded, escaped.focus, escaped.headerClass.includes('bg-transparent')], [false, 'false', true, true], `${name}: Escape closes the dropdown and returns focus to the trigger`);
  await page.close();
}

{
  const page = await load(fixture.modal);
  await page.locator('.wp-block-button button').click();
  const opened = await state(page);
  check([opened.open, opened.modal, opened.closeButtons, opened.expanded], [true, true, 1, null], 'modal: an unobserved or modal presentation keeps showModal and the generated Close control');
  await page.locator('#behind').click({ timeout: 500 }).then(() => assert.fail('modal must make the page inert'), () => checks++);
  await page.locator('dialog [data-blocks-engine-dialog-close]').click();
  await page.waitForTimeout(50);
  check((await state(page)).open, false, 'modal: the generated Close control closes it');
  await page.close();
}

await browser.close();
console.log(`captured-dialog-dropdown-panel browser contract passed: ${checks} checks`);
