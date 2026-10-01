import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const cwd=fileURLToPath(new URL('../..',import.meta.url));
const browser=await chromium.launch();
try {
 for(const transform of ['translate(12px, -4px)','translate(44px, -4px)','rotate(45deg)','scale(1.6, 0.7)','translate(7px, 2px) rotate(37deg) scale(1.3)']) {
  const html=`<style>body{margin:0;font:16px Arial}h3{margin:0;font:inherit}.list{width:350px;--offset:12px}button{border:0;width:100%;display:flex;align-items:center;justify-content:space-between;padding:20px;background:white;line-height:24px}.label{flex:1;padding-right:16px}.resting{transform:translateX(0px)}.expanded{transform:${transform};transform-origin:25% 75%}svg{color:#253855}</style><main><section class="list"><article><button type="button" aria-expanded="false"><span class="label">A neutral disclosure question?</span><svg xmlns="http://www.w3.org/2000/svg" class="resting" data-dla-disclosure-open-class="expanded" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3L21 7L6 22Z"/></svg></button><div role="region" hidden><p>An editable answer.</p></div></article></section></main>`;
  const input=html.replace(/(<article>.*<\/article>)/,'$1$1').replace('</style>','@media(min-width:401px){button{overflow:hidden}}</style>');
  const result=JSON.parse(execFileSync('php',['-r',`require 'vendor/autoload.php';echo json_encode((new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(json_decode($argv[1]))->toArray(),JSON_THROW_ON_ERROR);`,JSON.stringify(input)],{cwd,encoding:'utf8',maxBuffer:16*1024*1024}));
  const css=result.assets.filter(a=>a.kind==='css').map(a=>a.content).join('\n');
  const scripts=result.assets.filter(a=>a.source==='engine-presentation');assert.equal(scripts.length,1);
  assert(result.serialized_blocks.includes('<span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span>'));
  assert(!result.serialized_blocks.includes('data-blocks-engine-vector-icon'));
  assert(!result.serialized_blocks.includes('<svg'));
  for(const width of [390,768]) {
  const pages=[];
  for(const native of [false,true]) {
   const page=await browser.newPage({viewport:{width,height:200}});
   await page.setContent(native?`<style>.wp-block-accordion-heading__toggle{overflow:hidden}${css}\nbody{margin:0;font:16px Arial}h3{margin:0;font:inherit}.wp-block-accordion-panel{display:none}</style><main style="width:350px">${result.serialized_blocks}</main>`:input);
   if(native){await page.addScriptTag({content:scripts[0].content});await page.addScriptTag({content:scripts[0].content});assert.equal(await page.locator('.wp-block-accordion-heading__toggle-icon > svg').count(),2);}
   await page.locator('button').first().evaluate(b=>{b.setAttribute('aria-expanded','true');if(!b.querySelector('.wp-block-accordion-heading__toggle-icon'))b.querySelector('svg').setAttribute('class','expanded');});
   const svg=page.locator('button svg').first();await svg.waitFor();
   // Chromium's extra ancestor changes float32 accumulation by ~0.00003px.
   // Compare geometry at its 1/64px layout unit; paint below remains byte-exact.
   const geometry=await svg.evaluate(e=>{const s=getComputedStyle(e);return{box:Object.fromEntries(Object.entries(e.getBoundingClientRect().toJSON()).map(([key,value])=>[key,Math.round(value*64)/64])),transform:s.transform,origin:s.transformOrigin};});
   pages.push({page,geometry,pixels:await page.screenshot({clip:{x:280,y:0,width:100,height:100}})});
  }
  assert.deepEqual(pages[1].geometry,pages[0].geometry,`${transform}: actual inline vector keeps transformed bounds and origin`);
  assert.deepEqual(pages[1].pixels,pages[0].pixels,`${transform}: vector artwork retains overflow and exact raster paint`);
  for(const {page} of pages)await page.close();
  }
 }
 console.log('Native saved icon spans hydrate idempotently into exact inline vector paint with arbitrary transforms and origins');
}finally{await browser.close();}
