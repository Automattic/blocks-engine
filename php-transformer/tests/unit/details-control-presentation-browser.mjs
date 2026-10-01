import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const fixture=JSON.parse(execFileSync('php',[fileURLToPath(new URL('./details-control-presentation-fixture.php',import.meta.url))],{encoding:'utf8'}));
assert.equal(fixture.validity.status,'pass');
const browser=await chromium.launch();
try {
const result={},images={};for(const stage of ['source','candidate']){const page=await browser.newPage({viewport:{width:1000,height:500}});await page.setContent(fixture[stage]);result[stage]=await page.evaluate(()=>{const summary=document.querySelector('summary');const box=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,w:r.width,h:r.height};};const walker=document.createTreeWalker(summary,NodeFilter.SHOW_TEXT);const text=[];for(let n=walker.nextNode();n;n=walker.nextNode()){if(!n.textContent.trim())continue;const range=document.createRange();range.selectNodeContents(n);text.push(box(range));}return{control:box(summary),text,icon:box(summary.querySelector('svg')),next:box(document.querySelector('.next'))};});images[stage]=[await page.locator('summary').screenshot()];await page.locator('summary').hover();images[stage].push(await page.locator('summary').screenshot());await page.locator('summary').click();assert.equal(await page.locator('details').evaluate(e=>e.open),true);await page.close();}
assert.deepEqual(result.candidate,result.source,'native disclosure must retain exactly one source control box and label/icon gap');
assert.deepEqual(images.candidate,images.source,'source resting and hover control paint must remain pixel identical');
console.log('Details control presentation browser regression passed');
} finally {await browser.close();}
