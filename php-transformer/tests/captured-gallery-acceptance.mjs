import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';
const base=process.env.BE_EDITOR_WP_URL,id=process.env.BE_EDITOR_POST_ID,evidence=process.env.BE_EDITOR_EVIDENCE_DIR;
const browser=await chromium.launch();
const page=await browser.newPage({locale:'en-US',viewport:{width:1440,height:900}});
const result={widths:[],editor:null},errors=[],blocked=[];
page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
const state=async(root)=>root.evaluate(el=>{
 const items=[...el.querySelector('.blocks-engine-authored-carousel__track').children];
 const active=items.findIndex(item=>item.classList.contains('blocks-engine-authored-carousel__slide--active'));
 const image=items[active]?.querySelector('img');
 return {index:active,count:items.length,src:image?.src,decoded:!!image?.complete&&image.naturalWidth>1,counter:el.querySelector('.blocks-engine-authored-carousel__status')?.textContent,visible:items.filter(item=>getComputedStyle(item).visibility==='visible').length};
});
const settled=async(root)=>{
 await root.locator('.blocks-engine-authored-carousel__slide--active img').evaluate(img=>img.decode());
 await root.evaluate(async el=>{await Promise.all(el.getAnimations({subtree:true}).filter(a=>a.effect?.getComputedTiming().iterations!==Infinity).map(a=>a.finished.catch(()=>{})));});
};
try{
 await page.route('**/*',route=>{if(new URL(route.request().url()).origin===new URL(base).origin)return route.continue();blocked.push(route.request().url());return route.abort();});
 for(const width of [390,768,1440]){
  await page.setViewportSize({width,height:900});await page.goto(`${base}/?page_id=${id}`,{waitUntil:'domcontentloaded'});
  const root=page.locator('.blocks-engine-authored-carousel').filter({visible:true}).first();await root.waitFor();
  await page.waitForFunction(()=>document.querySelector('.blocks-engine-authored-carousel__slide--active'));
  await root.scrollIntoViewIfNeeded();await settled(root);const initial=await state(root);assert.equal(initial.count,20);assert.equal(initial.visible,1);assert.equal(initial.decoded,true);
  const images=await root.locator('img').evaluateAll(async imgs=>{imgs.forEach(img=>img.loading='eager');await Promise.all(imgs.map(img=>img.decode()));return imgs.map(img=>({src:img.src,width:img.naturalWidth,height:img.naturalHeight}));});
  assert.equal(images.length,20);assert.ok(images.every(img=>img.width>1&&img.height>1));assert.equal(new Set(images.map(img=>img.src)).size,20);
  const inlineCycle=[];
  for(let i=0;i<20;i++){await root.locator('[data-carousel-next]').click();await settled(root);const frame=await state(root);assert.equal(frame.index,(initial.index+i+1)%20);assert.equal(frame.visible,1);assert.equal(frame.decoded,true);assert.equal(frame.counter,`Slide ${frame.index+1} of 20`);inlineCycle.push(frame);}
  assert.equal(new Set(inlineCycle.map(frame=>frame.src)).size,20);
  await root.locator('[data-carousel-next]').click();await settled(root);const next=await state(root);assert.equal(next.index,(initial.index+1)%20);assert.notEqual(next.src,initial.src);
  const binding=await root.evaluate(el=>[...document.querySelectorAll('dialog[data-blocks-engine-gallery-selection]')].flatMap(dialog=>JSON.parse(dialog.getAttribute('data-blocks-engine-gallery-selection'))).find(item=>item.triggerId===el.id));
  if(!binding){
   await root.locator('[data-carousel-previous]').click();await settled(root);assert.equal((await state(root)).index,initial.index);
   result.widths.push({width,initial,next,inlineCycle,inlineDecoded:images.length,lightbox:'not observed in this emitted source viewport'});
   continue;
  }
  await root.locator('.blocks-engine-authored-carousel__slide--active img').click();const dialog=page.locator('dialog[open]');await dialog.waitFor();
  const full=dialog.locator('.blocks-engine-authored-carousel');await settled(full);const opened=await state(full);
  assert.equal(opened.decoded,true);assert.equal(opened.count,20);
  assert.equal(opened.index,binding.indices[next.index],'clicked native image opens the full image selected by the consumed observed identity mapping');
  const fullImages=await full.locator('img').evaluateAll(async imgs=>{imgs.forEach(img=>img.loading='eager');await Promise.all(imgs.map(img=>img.decode()));return imgs.map(img=>({src:img.src,width:img.naturalWidth,height:img.naturalHeight}));});
  assert.equal(fullImages.length,20);assert.ok(fullImages.every(img=>img.width>1&&img.height>1));assert.equal(new Set(fullImages.map(img=>img.src)).size,20);
  const cycle=[];
  for(let i=0;i<20;i++){await full.locator('[data-carousel-next]').click();await settled(full);const frame=await state(full);assert.equal(frame.index,(opened.index+i+1)%20);assert.equal(frame.visible,1);assert.equal(frame.decoded,true);assert.equal(frame.counter,`Slide ${frame.index+1} of 20`);cycle.push(frame);}
  await full.locator('[data-carousel-previous]').click();await settled(full);const previous=await state(full);assert.equal(previous.index,(opened.index+19)%20);assert.notEqual(previous.src,opened.src);
  await dialog.getByRole('button',{name:/Close/}).click();assert.equal(await page.locator('dialog[open]').count(),0);
  await root.locator('[data-carousel-previous]').click();await settled(root);assert.equal((await state(root)).index,initial.index);
  await page.screenshot({path:`${evidence}/frontend-${width}.png`,fullPage:true});result.widths.push({width,initial,next,opened,previous,cycle,inlineCycle,binding,inlineDecoded:images.length,fullDecoded:fullImages.length});
 }
 assert.ok(result.widths.some(row=>row.fullDecoded===20),'The actual capture supplies at least one complete observed image lightbox');
 await page.unroute('**/*');await page.goto(`${base}/wp-login.php`,{waitUntil:'domcontentloaded'});
 await page.getByLabel('Username or Email Address').fill(process.env.BE_EDITOR_USER);await page.getByRole('textbox',{name:'Password'}).fill(process.env.BE_EDITOR_PASSWORD);await page.getByRole('button',{name:'Log In'}).click();
 await page.goto(`${base}/wp-admin/post.php?post=${id}&action=edit`,{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.wp?.data?.select('core/block-editor')?.getBlocks().length>0);
 const read=()=>page.evaluate(()=>{const visit=blocks=>blocks.flatMap(b=>[{name:b.name,id:b.clientId,attrs:b.attributes,valid:b.isValid,registered:!!wp.blocks.getBlockType(b.name)},...visit(b.innerBlocks||[])]);return visit(wp.data.select('core/block-editor').getBlocks());});
 const before=await read();result.editor={before};
 result.editor.validation=await page.evaluate(()=>{const visit=blocks=>blocks.flatMap(b=>[{name:b.name,valid:b.isValid,children:b.innerBlocks?.length,issues:b.validationIssues,original:b.isValid?undefined:b.originalContent,generated:b.isValid?undefined:wp.blocks.getSaveContent(b.name,b.attributes,b.innerBlocks)},...visit(b.innerBlocks||[])]);return visit(wp.data.select('core/block-editor').getBlocks());});
 assert.ok(before.every(b=>b.valid&&b.registered),JSON.stringify(before.filter(b=>!b.valid||!b.registered).map(b=>({name:b.name,attrs:b.attrs}))));const images=before.filter(b=>b.name==='core/image');assert.ok(images.length>=40);
 await page.evaluate(id=>wp.data.dispatch('core/block-editor').updateBlockAttributes(id,{alt:'Edited captured gallery image'}),images[0].id);await page.evaluate(()=>wp.data.dispatch('core/editor').savePost());
 await page.waitForFunction(()=>{const s=wp.data.select('core/editor');return !s.isSavingPost()&&!s.isEditedPostDirty()&&s.didPostSaveRequestSucceed();});await page.reload({waitUntil:'domcontentloaded'});await page.waitForFunction(()=>wp?.data?.select('core/block-editor')?.getBlocks().length>0);
 const after=await read();assert.ok(after.every(b=>b.valid&&b.registered));assert.equal(after.filter(b=>b.name==='core/image').length,images.length);assert.equal(after.find(b=>b.name==='core/image').attrs.alt,'Edited captured gallery image');
 const validity=await page.evaluate(()=>{const visit=blocks=>blocks.flatMap(b=>[{name:b.name,valid:wp.blocks.validateBlock(b)[0]},...visit(b.innerBlocks||[])]);return visit(wp.blocks.parse(wp.data.select('core/editor').getEditedPostContent()));});assert.ok(validity.every(b=>b.valid));result.editor={nativeImages:images.length,validity};console.log(JSON.stringify({ok:true,widths:result.widths.length,editorImages:images.length,blockedRequests:blocked}));
}finally{await writeFile(`${evidence}/gallery-runtime-evidence.json`,JSON.stringify(result,null,2));await writeFile(`${evidence}/browser-console.json`,JSON.stringify(errors,null,2));await browser.close();}
