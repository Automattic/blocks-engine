import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
const require=createRequire(new URL('../../tools/visual-parity/package.json',import.meta.url));
const {PNG}=require(process.env.PNG_MODULE || 'pngjs');
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const url = process.env.NAVIGATION_TEST_URL || process.env.STYLESHEET_TEST_URL;
assert.ok(url, 'Actual Core HTTP runtime is required');
const source = execFileSync('php', ['-r', 'echo require $argv[1];', new URL('../fixtures/navigation-anchor-subject.php', import.meta.url).pathname], {encoding:'utf8'});
const browser = await chromium.launch();
const rows = [];
const failures = [];
let editorProof = null;
const measure = async page => page.locator('header a').evaluateAll(anchors => anchors.map(a => {
    const info = e => {const s=getComputedStyle(e), r=e.getBoundingClientRect();return {rect:{x:r.x,y:r.y,width:r.width,height:r.height},padding:s.padding,margin:s.margin,border:s.borderLeft,borderRadius:s.borderRadius,background:s.backgroundColor,color:s.color,fontSize:s.fontSize,lineHeight:s.lineHeight,transform:s.transform,display:s.display,zIndex:s.zIndex,alignSelf:s.alignSelf};};
    return {name:a.getAttribute('aria-label') || a.textContent.trim(),anchor:info(a),item:info(a.closest('li'))};
}));
try {
    for (const width of [390,768,1440]) {
        const pages = [];
        for (const kind of ['source','candidate']) {
            const page = await browser.newPage({viewport:{width,height:900}});
            if (kind === 'source') await page.route('http://source.test/**', route=>route.fulfill({contentType:'text/html',body:source}));
            await page.goto(kind === 'source' ? 'http://source.test/' : url, {waitUntil:'domcontentloaded'});
            await page.evaluate(()=>document.fonts.ready);
            pages.push(page);
        }
        for (const state of ['rest','hover','focus','active']) {
            for (const page of pages) {
                const cta = page.getByRole('link',{name:'Contact',exact:true});
                if (state === 'hover') await cta.hover();
                if (state === 'focus') {await page.mouse.move(0,899);await cta.focus();}
                if (state === 'active') {await cta.blur();await cta.hover();await page.mouse.down();}
            }
            const [expected,actual] = await Promise.all(pages.map(measure));
            let changedPixels=null;
            if(state==='rest'){
                const screenshots=await Promise.all(pages.map(async page=>{
                    const box=await page.locator('header').boundingBox();
                    return PNG.sync.read(await page.screenshot({clip:{x:0,y:0,width,height:Math.ceil(box.height)}}));
                }));
                if(screenshots[0].width!==screenshots[1].width||screenshots[0].height!==screenshots[1].height) failures.push({width,state,property:'header paint dimensions',source:[screenshots[0].width,screenshots[0].height],candidate:[screenshots[1].width,screenshots[1].height]});
                changedPixels=0;
                for(let i=0;i<Math.max(screenshots[0].data.length,screenshots[1].data.length);i+=4) if([0,1,2,3].some(j=>screenshots[0].data[i+j]!==screenshots[1].data[i+j])) changedPixels++;
                if(changedPixels)failures.push({width,state,property:'header paint pixels',changedPixels});
            }
            rows.push({width,state,source:expected,candidate:actual,changedPixels});
            for (let i=0;i<expected.length;i++) {
                for (const subject of ['anchor','item']) {
                    for (const [property,value] of Object.entries(expected[i][subject])) {
                        const got=actual[i]?.[subject]?.[property];
                        if (property==='rect') {
                            for (const axis of ['x','y','width','height']) if (Math.abs(value[axis]-got?.[axis])>0.05) failures.push({width,state,name:expected[i].name,subject,property:axis,expected:value[axis],actual:got?.[axis]});
                        } else if (got!==value) failures.push({width,state,name:expected[i].name,subject,property,expected:value,actual:got});
                    }
                }
            }
            if (state === 'active') for (const page of pages) await page.mouse.up();
        }
        for (const page of pages) await page.close();
    }
    if(process.env.NAVIGATION_TEST_EVIDENCE) await fs.writeFile(process.env.NAVIGATION_TEST_EVIDENCE,JSON.stringify({rows,failures,passed:false},null,2));
    assert.equal(failures.length,0,JSON.stringify(failures.slice(0,25)));
    const editor = await browser.newPage();
    await editor.goto(`${url}/wp-login.php`,{waitUntil:'domcontentloaded'});
    await editor.locator('#user_login').fill(process.env.NAVIGATION_TEST_USER || 'navigation-proof');
    await editor.locator('#user_pass').fill(process.env.NAVIGATION_TEST_PASSWORD || 'navigation-test-password');
    await editor.locator('#wp-submit').click({noWaitAfter:true});
    await editor.waitForURL(/wp-admin/,{waitUntil:'domcontentloaded'});
    const pages = await (await fetch(`${url}/wp-json/wp/v2/pages?slug=anchor-home`)).json();
    const editorUrl=`${url}/wp-admin/post.php?post=${pages[0].id}&action=edit`;
    await editor.goto(editorUrl,{waitUntil:'domcontentloaded'});
    await editor.waitForFunction(()=>window.wp?.data?.select('core/block-editor')?.getBlocks().length>0);
    const saved=await editor.evaluate(async()=>{
        const flatten=blocks=>blocks.flatMap(block=>[block,...flatten(block.innerBlocks||[])]);
        const parts=await wp.apiFetch({path:'/wp/v2/template-parts?context=edit'});
        const themes=await wp.apiFetch({path:'/wp/v2/themes?status=active'});
        const blocks=[...flatten(wp.data.select('core/block-editor').getBlocks()),...parts.filter(part=>part.theme===themes[0].stylesheet).flatMap(part=>flatten(wp.blocks.parse(part.content.raw)))];
        if(blocks.some(block=>block.isValid===false||['core/html','core/freeform'].includes(block.name)))throw new Error('Invalid/fallback page or shell');
        const ref=blocks.find(block=>block.name==='core/navigation')?.attributes.ref;
        const menu=await wp.apiFetch({path:`/wp/v2/navigation/${ref}?context=edit`});
        const items=wp.blocks.parse(menu.content.raw);
        if(items.length!==4||items.some(block=>!block.isValid||block.name!=='core/navigation-link'))throw new Error('Invalid/non-native menu');
        const inline=items.find(block=>block.attributes.label==='Inline');
        if(!inline.attributes.metadata?.blocksEngineNavigationAnchor?.style.includes('padding:2px 4px'))throw new Error('Explicit anchor ownership lost');
        const icon=items.find(block=>block.attributes.label==='Social');
        if(icon.attributes.metadata?.blocksEngineNavigationAnchor?.boxes.length!==2)throw new Error('Independent icon box chain lost');
        items[0].attributes.label='Services edited once';
        await wp.data.resolveSelect('core').getEntityRecord('postType','wp_navigation',menu.id);
        wp.data.dispatch('core').editEntityRecord('postType','wp_navigation',menu.id,{content:wp.blocks.serialize(items)});
        await wp.data.dispatch('core').saveEditedEntityRecord('postType','wp_navigation',menu.id);
        return{id:menu.id,original:menu.content.raw,validBlocks:blocks.length+items.length};
    });
    try{
        await editor.goto(editorUrl,{waitUntil:'domcontentloaded'});
        await editor.waitForFunction(()=>window.wp?.data?.select('core/block-editor')?.getBlocks().length>0);
        const reload=await editor.evaluate(async id=>{
            const row=await wp.apiFetch({path:`/wp/v2/navigation/${id}?context=edit`});
            const blocks=wp.blocks.parse(row.content.raw);
            return{edited:blocks[0].attributes.label,valid:blocks.every(block=>block.isValid),metadata:blocks.find(block=>block.attributes.label==='Inline').attributes.metadata.blocksEngineNavigationAnchor};
        },saved.id);
        assert.equal(reload.edited,'Services edited once');assert.equal(reload.valid,true);assert.match(reload.metadata.style,/padding:2px 4px/);
        for(const route of ['/','/anchor-other/']){
            const front=await browser.newPage({viewport:{width:1440,height:900}});
            await front.goto(`${url}${route}`,{waitUntil:'domcontentloaded'});
            assert.equal(await front.getByRole('link',{name:'Services edited once',exact:true}).count(),1);
            const inline=await front.getByRole('link',{name:'Inline',exact:true}).evaluate(a=>({padding:getComputedStyle(a).padding,radius:getComputedStyle(a).borderRadius}));
            assert.deepEqual(inline,{padding:'2px 4px',radius:'5px'});
            await front.close();
        }
        editorProof={entity:saved.id,validBlocks:saved.validBlocks,reloaded:true,routes:2,restored:true};
    }finally{
        await editor.evaluate(async saved=>{
            await wp.data.resolveSelect('core').getEntityRecord('postType','wp_navigation',saved.id);
            wp.data.dispatch('core').editEntityRecord('postType','wp_navigation',saved.id,{content:saved.original});
            await wp.data.dispatch('core').saveEditedEntityRecord('postType','wp_navigation',saved.id);
            const restored=await wp.apiFetch({path:`/wp/v2/navigation/${saved.id}?context=edit`});
            if(restored.content.raw!==saved.original)throw new Error('Exact menu restoration failed');
        },saved);
    }
    const proof={rows,failures,editor:editorProof,passed:failures.length===0};
    if(process.env.NAVIGATION_TEST_EVIDENCE) await fs.writeFile(process.env.NAVIGATION_TEST_EVIDENCE,JSON.stringify(proof,null,2));
    assert.equal(failures.length,0,JSON.stringify(failures.slice(0,25)));
    console.log(JSON.stringify({widths:[390,768,1440],states:['rest','hover','focus','active'],editor:editorProof,failures:failures.length,passed:proof.passed}));
} finally {await browser.close();}
