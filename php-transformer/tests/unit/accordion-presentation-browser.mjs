import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const directory=fileURLToPath(new URL('../..',import.meta.url));
const label='What information do I need to include in a referral?';
const item=`<article><button type="button" aria-expanded="false"><span class="label">${label}</span><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="m6 9 6 6 6-6"/></svg></button><div role="region" hidden><p>Answer.</p></div></article>`;
const source=`<style>body{margin:0;font:16px Arial}h3{margin:0;font:inherit}.list{width:350px}button{border:0;width:100%;display:flex;align-items:center;justify-content:space-between;padding:20px;line-height:24px}.label{flex:1;padding-right:16px;font-size:16px;line-height:24px}svg{color:#253855}</style><main><section class="list">${item}${item}</section></main>`;
const result=JSON.parse(execFileSync('php',['-r',`require 'vendor/autoload.php';$html=json_decode($argv[1]);echo json_encode((new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($html)->toArray(),JSON_THROW_ON_ERROR);`,JSON.stringify(source)],{cwd:directory,encoding:'utf8',maxBuffer:16*1024*1024}));
const css=(result.assets||[]).filter(a=>a.kind==='css').map(a=>a.content).join('\n');
const browser=await chromium.launch();
try{
 const measure=async html=>{const page=await browser.newPage({viewport:{width:390,height:1000}});await page.setContent(html);const measured=await page.locator('.label').first().evaluate(element=>{const node=element.firstChild;const words=[];for(const match of node.textContent.matchAll(/\S+/g)){const range=document.createRange();range.setStart(node,match.index);range.setEnd(node,match.index+match[0].length);const box=range.getBoundingClientRect();words.push({text:match[0],x:box.x,y:box.y,w:box.width,h:box.height});}return words;});const icon=page.locator('.wp-block-accordion-heading__toggle-icon').first();if(await icon.count()){const decoded=await icon.evaluate(async e=>{const url=getComputedStyle(e).backgroundImage.match(/url\("(.*)"\)/)?.[1];const image=new Image();image.src=url;await image.decode();return{w:image.naturalWidth,h:image.naturalHeight};});assert.deepEqual(decoded,{w:18,h:18});}await page.close();return measured;};
 if(process.env.DEBUG_ACCORDION) console.log(JSON.stringify({css:css.split('\n'),blocks:result.serialized_blocks.split('><')},null,2));
 const original=await measure(source);const native=await measure(`<style>${css}\nbody{margin:0;font:16px Arial}h3{margin:0;font:inherit}.wp-block-accordion-panel{display:none}</style><div style="width:350px">${result.serialized_blocks}</div>`);
 assert.deepEqual(native,original,'retained source label words keep the same line breaks and positions across the core title wrapper');
 console.log('Neutral accordion source/native label wrapping and real SVG decoding passed');
}finally{await browser.close();}
