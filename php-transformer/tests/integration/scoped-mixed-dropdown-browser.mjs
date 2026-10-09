import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const url = process.env.DROPDOWN_TEST_URL;
assert.ok(url, 'Actual WordPress HTTP runtime is required');
const capture = process.env.DROPDOWN_TEST_CAPTURE;
const source = capture ? await fs.readFile(path.join(capture, 'website/index.html'), 'utf8')
    : execFileSync('php', ['-r', 'echo require $argv[1];', new URL('../fixtures/scoped-mixed-dropdown.php', import.meta.url).pathname], {encoding:'utf8'});
const browser = await chromium.launch();
const rows = [];
const geometryDeltas = [];
const panelBox = async (page, selector) => page.locator(selector).evaluate(e => {
    const r=e.getBoundingClientRect(),s=getComputedStyle(e);
    return {x:r.x,y:r.y,width:r.width,height:r.height,display:s.display,padding:s.padding,background:s.backgroundColor,children:Array.from(e.firstElementChild?.children||[]).map(child=>({tag:child.tagName,height:child.getBoundingClientRect().height,border:getComputedStyle(child).border,margin:getComputedStyle(child).margin}))};
});
try {
    for (const width of [390,768,1440]) {
        const page = await browser.newPage({viewport:{width,height:900}});
        const original = await browser.newPage({viewport:{width,height:900}});
        await original.route('http://source.test/**', async route => {
            const relative = decodeURIComponent(new URL(route.request().url()).pathname).replace(/^\//,'');
            if (!relative || relative === 'index.html') return route.fulfill({contentType:'text/html',body:source});
            if (!capture) return route.abort();
            const file = path.resolve(capture,'website',relative);
            assert.ok(file.startsWith(path.resolve(capture,'website')+path.sep));
            try {
                const contentType=({'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml','.woff2':'font/woff2','.ttf':'font/ttf'})[path.extname(file)] || 'application/octet-stream';
                await route.fulfill({contentType,body:await fs.readFile(file)});
            } catch {await route.abort();}
        });
        await original.goto('http://source.test/',{waitUntil:'domcontentloaded'});
        await page.goto(url,{waitUntil:'domcontentloaded'});
        await Promise.all([original.evaluate(()=>document.fonts.ready),page.evaluate(()=>document.fonts.ready)]);
        assert.equal(await page.locator('script[data-dla-disclosure-runtime]').count(),0,'source disclosure interpreter is retired');
        const trigger = page.getByRole('button',{name:'Open menu',exact:true}).filter({visible:true});
        const sourceTrigger = original.getByRole('button',{name:'Open menu',exact:true}).filter({visible:true});
        assert.equal(await trigger.count(),await sourceTrigger.count(),`source opener visibility matches at ${width}`);
        if (await trigger.count()) {
            assert.equal(await trigger.count(),1,'one visible scoped opener');
            assert.equal(await trigger.locator('svg').count(),1,'original SVG remains in the native control');
            const binding = await trigger.evaluate(e => ({id:e.id,target:e.getAttribute('aria-controls')}));
            assert.ok(binding.id && binding.target,'explicit native IDs are bound');
            const panel = page.locator(`[id="${binding.target}"]`);
            assert.equal(await panel.count(),1,'exactly one emitted endpoint');
            assert.equal(await panel.getAttribute('data-blocks-engine-triggers'),binding.id,'endpoint binds the scoped opener');
            assert.equal(await panel.isVisible(),false,'native panel starts closed');
            const sourceTarget = await sourceTrigger.getAttribute('aria-controls');
            await sourceTrigger.click();
            await trigger.click();
            assert.equal(await panel.isVisible(),true,'native click opens mixed panel');
            assert.equal(await trigger.getAttribute('aria-expanded'),'true');
            const sourceBox = await panelBox(original,`[id="${sourceTarget}"]`);
            const nativeBox = await panelBox(page,`[id="${binding.target}"]`);
            for (const axis of ['x','y','width','height']) if (Math.abs(sourceBox[axis]-nativeBox[axis])>.05) geometryDeltas.push({width,axis,source:sourceBox[axis],native:nativeBox[axis]});
            assert.equal(await panel.locator('hr').count(),2,'both native separators survive');
            assert.equal(await panel.locator('a').count(),4,'all mixed destination links survive');
            if (!capture) assert.equal(await panel.getByRole('button',{name:'Ordinary action'}).count(),1,'ordinary native action survives');
            await page.keyboard.press('Escape');
            assert.equal(await panel.isVisible(),false,'Escape closes');
            assert.equal(await trigger.evaluate(e=>e===document.activeElement),true,'Escape returns focus');
            await trigger.focus();
            await page.keyboard.press('Enter');
            assert.equal(await panel.isVisible(),true,'role-button Enter opens');
            await page.keyboard.press('Space');
            assert.equal(await panel.isVisible(),false,'role-button Space toggles closed');
            await trigger.click();
            await page.setViewportSize({width:width<768?1440:390,height:900});
            if (!await trigger.isVisible()) assert.equal(await panel.getAttribute('open'),null,'resize closes the concealed source-scope endpoint');
            rows.push({width,binding,sourceBox,nativeBox,click:true,keyboard:true,escape:true,resize:true});
        } else {
            const names = await page.locator('header:visible a').evaluateAll(es=>es.filter(e=>e.getBoundingClientRect().width>0).map(e=>e.getAttribute('aria-label')||e.textContent.trim()));
            assert.ok(names.length>=4,'desktop retains its visible destinations');
            rows.push({width,desktop:true,names});
        }
        await page.close();await original.close();
    }
    const editor = await browser.newPage();
    await editor.goto(`${url}/wp-login.php`,{waitUntil:'domcontentloaded'});
    await editor.locator('#user_login').fill(process.env.DROPDOWN_TEST_USER || 'navigation-proof');
    await editor.locator('#user_pass').fill(process.env.DROPDOWN_TEST_PASSWORD || 'navigation-test-password');
    await editor.locator('#wp-submit').click({noWaitAfter:true});
    await editor.waitForURL(/wp-admin/,{waitUntil:'domcontentloaded'});
    const pages = await (await fetch(`${url}/wp-json/wp/v2/pages`)).json();
    const id = Number(process.env.DROPDOWN_TEST_PAGE_ID || pages.find(p=>p.slug==='anchor-home')?.id || pages[0]?.id);
    const editorUrl=`${url}/wp-admin/post.php?post=${id}&action=edit`;
    await editor.goto(editorUrl,{waitUntil:'domcontentloaded'});
    await editor.waitForFunction(()=>window.wp?.data?.select('core/block-editor')?.getBlocks().length>0);
    const saved = await editor.evaluate(async id => {
        const flatten=bs=>bs.flatMap(b=>[b,...flatten(b.innerBlocks||[])]);
        const current=await wp.apiFetch({path:`/wp/v2/pages/${id}?context=edit`});
        const parts=await wp.apiFetch({path:'/wp/v2/template-parts?context=edit'});
        const themes=await wp.apiFetch({path:'/wp/v2/themes?status=active'});
        const activeParts=parts.filter(p=>p.theme===themes[0].stylesheet);
        const all=[...flatten(wp.data.select('core/block-editor').getBlocks()),...activeParts.flatMap(p=>flatten(wp.blocks.parse(p.content.raw)))];
        const invalid=all.filter(b=>b.isValid===false||['core/html','core/freeform'].includes(b.name));
        if(invalid.length)throw new Error(JSON.stringify(invalid.map(b=>({name:b.name,errors:b.validationIssues}))));
        const dialogs=all.filter(b=>b.name.endsWith('/captured-dialog')&&b.attributes.presentation==='dropdown');
        if(!dialogs.length||dialogs.some(b=>!b.innerBlocks.length))throw new Error('Native mixed dropdown children missing');
        const part=activeParts.find(p=>flatten(wp.blocks.parse(p.content.raw)).some(b=>b.name.endsWith('/captured-dialog')));
        const kind=part?'wp_template_part':'page',entity=part||current;
        await wp.data.resolveSelect('core').getEntityRecord('postType',kind,entity.id);
        const original=entity.content.raw,blocks=wp.blocks.parse(original);
        const text=flatten(blocks).find(b=>['content','text','label'].some(key=>String(b.attributes[key]||'').includes('Contact')));
        if(!text)throw new Error('Editable native contact content missing: '+JSON.stringify(flatten(blocks).map(b=>({name:b.name,content:b.attributes.content,text:b.attributes.text,label:b.attributes.label}))));
        const key=['content','text','label'].find(key=>String(text.attributes[key]||'').includes('Contact'));
        text.attributes[key]=String(text.attributes[key]).replace('Contact','Contact edited once');
        wp.data.dispatch('core').editEntityRecord('postType',kind,entity.id,{content:wp.blocks.serialize(blocks)});
        await wp.data.dispatch('core').saveEditedEntityRecord('postType',kind,entity.id);
        return{kind,id:entity.id,original,validBlocks:all.length};
    },id);
    try {
        await editor.goto(editorUrl,{waitUntil:'domcontentloaded'});
        await editor.waitForFunction(()=>window.wp?.data?.select('core/block-editor')?.getBlocks().length>0);
        const reloaded=await editor.evaluate(async saved=>{
            const row=await wp.data.resolveSelect('core').getEntityRecord('postType',saved.kind,saved.id);
            const bs=wp.blocks.parse(row.content.raw);const flatten=xs=>xs.flatMap(b=>[b,...flatten(b.innerBlocks||[])]);
            return{edited:row.content.raw.includes('Contact edited once'),valid:flatten(bs).every(b=>b.isValid)};
        },saved);
        assert.deepEqual(reloaded,{edited:true,valid:true},'native child edit/save/reload stays valid');
    } finally {
        await editor.evaluate(async saved=>{
            await wp.data.resolveSelect('core').getEntityRecord('postType',saved.kind,saved.id);
            wp.data.dispatch('core').editEntityRecord('postType',saved.kind,saved.id,{content:saved.original});
            await wp.data.dispatch('core').saveEditedEntityRecord('postType',saved.kind,saved.id);
            const row=await wp.apiFetch({path:`/wp/v2/${saved.kind==='page'?'pages':'template-parts'}/${saved.id}?context=edit`});
            if(row.content.raw!==saved.original)throw new Error('Exact editor restoration failed');
        },saved);
    }
    const proof={rows,geometryDeltas,editor:{validBlocks:saved.validBlocks,reloaded:true,restored:true}};
    if(process.env.DROPDOWN_TEST_EVIDENCE)await fs.writeFile(process.env.DROPDOWN_TEST_EVIDENCE,JSON.stringify(proof,null,2));
    console.log(JSON.stringify(proof));
    assert.equal(geometryDeltas.length,0,'native dropdown panel geometry matches the source');
} finally {await browser.close();}
