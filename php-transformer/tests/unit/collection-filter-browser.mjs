import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
import assert from 'node:assert/strict';

const result = JSON.parse(readFileSync(`${tmpdir()}/collection-filter-result.json`));
const view = readFileSync(`${tmpdir()}/collection-filter-view.mjs`, 'utf8');
const browser = await chromium.launch({headless:true});
const page = await browser.newPage();
await page.setContent(result.serialized_blocks);
await page.addScriptTag({content:view.replace(/^import .*;$/m,'').replace('export function refresh','function refresh').replace(/store\('__NAME__'.*/s,'').replace(/store\('custom\/collection-filter',[\s\S]*$/,'') + '\nwindow.refreshCollection=refresh;'});
const states = await page.evaluate(() => {
    const root = document.querySelector('[data-wp-interactive]'), context=JSON.parse(root.dataset.wpContext);
    const run = (query, category) => { context.query=query;context.category=category;window.refreshCollection(root,context);return {visible:Array.from(root.querySelectorAll('.wp-block-accordion-item')).map((item,index)=>!item.hidden?index:null).filter(index=>index!==null),empty:!root.querySelector('[data-collection-empty]').hidden}; };
    const results=[run('',0),run('ORCHID',0),run('violet',1),run('orchid',1),run('not-present',0)];
    root.querySelector('.wp-block-accordion-item p').textContent='Owner edited answer magnolia.';
    results.push(run('magnolia',0),run('orchid',0));
    return results;
});
assert.deepEqual(states,[{visible:[0,1],empty:false},{visible:[0],empty:false},{visible:[],empty:true},{visible:[0],empty:false},{visible:[],empty:true},{visible:[0],empty:false},{visible:[],empty:true}]);
await browser.close();
console.log('PASS: answer-only search, case, category composition, external empty state, owner-edited live text');
