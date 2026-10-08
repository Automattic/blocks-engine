import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { createHash } from 'node:crypto';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const root = resolve(new URL('..', import.meta.url).pathname);
const evidence = process.env.CONTROL_STATE_EVIDENCE;
const site = process.env.CONTROL_STATE_WP_PATH;
const wpUrl = process.env.CONTROL_STATE_WP_URL;
assert.ok(evidence && site && wpUrl, 'isolated WordPress path, URL and evidence directory required');
await mkdir(evidence, {recursive: true});
const css = '.control-scope label{font:400 16px/24px sans-serif;color:black}.control-scope input:checked+label{font-weight:600}.control-scope input:default+label{color:red}.control-scope input:placeholder-shown{background:rgb(220,230,240)}.control-scope option{color:black}.control-scope option:default{color:red}';
const source = `<!doctype html><meta charset="utf-8"><style>${css}</style><main class="control-scope">
<form id="first" action="/search">
<input id="on" type="checkbox" data-passive="choice"><label for="on" data-label="passive">Selected</label>
<input id="off" type="checkbox" checked><label for="off">Unselected</label>
<input id="radio-a" type="radio" name="shared" checked><label for="radio-a">Alpha</label>
<input id="radio-b" type="radio" name="shared"><label for="radio-b">Beta</label>
<input id="text" value="Default β" placeholder="Value">
<input id="empty" value="Default" placeholder="Empty" readonly>
<input id="disabled" value="Default" disabled><input id="number" type="number" value="1">
<input id="hidden" type="hidden" value="default">
<input id="invalid" type="checkbox"><label for="invalid">Invalid payload</label>
<select id="select"><optgroup label="Group β"><option selected> Alpha β </option><option value="beta">Beta 🐴</option></optgroup><optgroup label="Group γ" disabled><option>Disabled group</option></optgroup><option value="">Empty</option></select><label for="select" data-caption="select">Select caption</label>
<select id="multiple" multiple><option selected>A</option><option>B</option><option selected>C</option></select>
<select id="none"><option selected>A</option><option>B</option></select>
<button type="reset">Reset</button></form>
<input id="file" type="file" form="first"><textarea id="textarea" form="first" placeholder="Message">Default</textarea>
<form id="second" action="/search"><input id="other-radio" type="radio" name="shared" checked><label for="other-radio">Other owner</label><input id="unowned-radio" type="radio" name="shared" checked data-unowned="yes"></form>
<input id="external" type="checkbox" form="first"><label for="external">External owner</label>
<form id="choice-form" action="/filter"><div id="choices" data-blocks-engine-choice-group="true"><input id="choice-a" type="radio" name="choice"><label for="choice-a">Choice A</label><input id="choice-b" type="radio" name="choice"><label for="choice-b">Choice B</label></div></form>
</main><script>
window.sourceExecuted=true;
document.getElementById('on').checked=true;
document.getElementById('off').checked=false;
document.getElementById('radio-b').checked=true;
document.getElementById('text').value='Current café 🐴 <&" \\u2028';
document.getElementById('empty').value='';
document.getElementById('disabled').value='Observed disabled';
document.getElementById('number').value='42';
document.getElementById('hidden').value='observed';
document.getElementById('textarea').defaultValue='\\nDefault β <&"';
document.getElementById('textarea').value='\\nCurrent café 🐴 <&"\\nsecond line';
document.getElementById('select').selectedIndex=1;
document.getElementById('multiple').options[0].selected=false;
document.getElementById('multiple').options[1].selected=true;
document.getElementById('none').selectedIndex=-1;
document.getElementById('external').checked=true;
document.getElementById('choice-a').checked=true;
</script>`;
const server = createServer((_request,response) => {response.setHeader('content-type','text/html; charset=utf-8');response.end(source);});
await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
const browser = await chromium.launch();
const context = await browser.newContext({viewport:{width:1440,height:1000}});
let serverClosed = false;
const state = page => page.evaluate(() => Array.from(document.querySelectorAll('.control-scope input,.control-scope select,.control-scope textarea'), node => {
    const common={id:node.id,form:node.form?.id||null,value:node.value,disabled:node.disabled,placeholder:node.matches(':placeholder-shown')};
    if(node instanceof HTMLInputElement)return {...common,checked:node.checked,defaultChecked:node.defaultChecked,defaultValue:node.defaultValue,default:node.matches(':default'),readOnly:node.readOnly,weight:node.nextElementSibling?.tagName==='LABEL'?getComputedStyle(node.nextElementSibling).fontWeight:null,color:node.nextElementSibling?.tagName==='LABEL'?getComputedStyle(node.nextElementSibling).color:null,background:getComputedStyle(node).backgroundColor};
    if(node instanceof HTMLTextAreaElement)return {...common,defaultValue:node.defaultValue,readOnly:node.readOnly};
    return {...common,multiple:node.multiple,index:node.selectedIndex,options:Array.from(node.options,option=>({text:option.textContent,value:option.value,selected:option.selected,defaultSelected:option.defaultSelected,default:option.matches(':default'),color:getComputedStyle(option).color,disabled:option.matches(':disabled'),group:option.parentElement.tagName==='OPTGROUP'?option.parentElement.label:null}))};
}));
try {
    const sourcePage=await context.newPage();
    await sourcePage.goto(`http://127.0.0.1:${server.address().port}/`);
    const baseline=await state(sourcePage);
    const snapshot=await sourcePage.evaluate(() => {
        const clone=document.documentElement.cloneNode(true);
        const copies=clone.querySelectorAll('input,textarea,select');
        document.querySelectorAll('input,textarea,select').forEach((control,index)=>{
            if(control.hasAttribute('data-unowned'))return;
            let state;
            if(control instanceof HTMLInputElement)state=control.type==='file'?{version:1,kind:'file'}:{version:1,kind:'input',type:control.type,value:control.value,defaultValue:control.defaultValue,checked:control.checked,defaultChecked:control.defaultChecked,indeterminate:control.indeterminate};
            else if(control instanceof HTMLTextAreaElement)state={version:1,kind:'textarea',value:control.value,defaultValue:control.defaultValue};
            else state={version:1,kind:'select',options:Array.from(control.options,option=>({selected:option.selected,defaultSelected:option.defaultSelected}))};
            if(control.id==='invalid')state.checked='true';
            copies[index].setAttribute('data-dla-native-control-state',JSON.stringify(state));
        });
        const group=clone.querySelector('#choices');
        const next=group.cloneNode(true);
        next.querySelectorAll('input').forEach((control,index)=>{const state=JSON.parse(control.getAttribute('data-dla-native-control-state'));state.checked=index===1;control.setAttribute('data-dla-native-control-state',JSON.stringify(state));});
        group.setAttribute('data-blocks-engine-choice-config',JSON.stringify({choices:[{source_value:'a'},{source_value:'b'}],states:[{selectedIndex:0,selected:[true,false],html:group.innerHTML},{selectedIndex:1,selected:[false,true],html:next.innerHTML}]}));
        clone.querySelectorAll('script').forEach(script=>script.remove());
        return clone.outerHTML;
    });
    await sourcePage.locator('#choice-b').click();
    const transition=await state(sourcePage);
    await sourcePage.evaluate(()=>document.querySelectorAll('form').forEach(form=>form.reset()));
    const reset=await state(sourcePage);
    await writeFile(join(evidence,'source.html'),snapshot.replace('</body>','<script data-dla-native-control-runtime>window.capturedReplayExecuted=true;document.querySelector("input[data-dla-native-control-state]").checked=true;</script></body>'));
    await writeFile(join(evidence,'source-native-facts.json'),JSON.stringify({baseline,reset,transition},null,2));
    server.closeAllConnections();await new Promise(resolve=>server.close(resolve));serverClosed=true;
    const compiled=JSON.parse(execFileSync('php',['-r',String.raw`require $argv[1].'/vendor/autoload.php'; echo json_encode((new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array('block_namespace'=>'neutral','site_slug'=>'native-control-proof','files'=>array('index.html'=>file_get_contents($argv[2]))))->toArray(),JSON_THROW_ON_ERROR);`,root,join(evidence,'source.html')],{encoding:'utf8',maxBuffer:20*1024*1024}));
    await writeFile(join(evidence,'compiled.json'),JSON.stringify(compiled,null,2));
    assert.deepEqual(compiled.fallbacks,[],'neutral controls lower without fallback');
    const payload=compiled.source_reports.companion_plugin_payload;
    assert.deepEqual(payload.preserved_js,[],'no raw source scripts in companion');
    const plugin=join(site,'wp-content/plugins/native-control-proof');
    const codeFiles=[];
    await mkdir(plugin,{recursive:true});
    for(const block of payload.blocks){
        const name=block.block_json.name.split('/')[1]; const path=join(plugin,name);
        await mkdir(path,{recursive:true});
        await writeFile(join(path,'block.json'),JSON.stringify(block.block_json));
        codeFiles.push(join(path,'block.json'));
        for(const [file,content] of Object.entries(block.assets||{})){await writeFile(join(path,file),content);codeFiles.push(join(path,file));}
        if(block.view_js){await writeFile(join(path,'view.js'),block.view_js);codeFiles.push(join(path,'view.js'));}
        for(const [file,dependencies] of Object.entries(block.script_dependencies||{})){
            await writeFile(join(path,file.replace(/\.js$/,'.asset.php')),`<?php return ${phpArray({dependencies,version:'2590'})};`);
        }
    }
    const styles=(compiled.assets||[]).filter(asset=>asset.kind==='css').map(asset=>asset.content||'').join('\n');
    await writeFile(join(plugin,'style.css'),styles);
    await writeFile(join(plugin,'native-control-proof.php'),`<?php /* Plugin Name: Native Control Proof */
add_action('init',static function(){foreach(glob(__DIR__.'/*/block.json') as $file)register_block_type(dirname($file));});
add_action('wp_enqueue_scripts',static function(){wp_enqueue_style('native-control-proof',plugins_url('style.css',__FILE__));});`);
    const hashes=async()=>Object.fromEntries(await Promise.all(codeFiles.map(async file=>[file,createHash('sha256').update(await readFile(file)).digest('hex')])));
    const initialHashes=await hashes();
    await writeFile(join(site,'control-state-content.json'),JSON.stringify({content:compiled.serialized_blocks}));
    await writeFile(join(site,'control-state-install.php'),`<?php $input=json_decode(file_get_contents(__DIR__.'/control-state-content.json'),true); $id=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Native Control Proof','post_content'=>wp_slash($input['content']))); echo $id;`);
    const cli=(args)=>execFileSync('studio',['wp',`--path=${site}`,...args],{encoding:'utf8',maxBuffer:20*1024*1024});
    if(process.env.CONTROL_STATE_SSI_PATH){
        await writeFile(join(site,'control-state-companion-payload.json'),JSON.stringify(payload));
        await writeFile(join(site,'control-state-ssi-probe.php'),`<?php require ${phpArray(join(process.env.CONTROL_STATE_SSI_PATH,'includes/class-static-site-importer-companion-plugin.php'))}; $payload=json_decode(file_get_contents(__DIR__.'/control-state-companion-payload.json'),true); $scaffold=Static_Site_Importer_Companion_Plugin::scaffold($payload); if(is_wp_error($scaffold))throw new RuntimeException($scaffold->get_error_message()); echo wp_json_encode($scaffold);`);
        const scaffold=JSON.parse(cli(['eval-file',join(site,'control-state-ssi-probe.php')]));
        for(const block of payload.blocks){
            if(!block.view_js)continue;
            const view=Object.entries(scaffold.files).find(([path])=>path.endsWith(`/blocks/${block.name}/view.js`));
            assert.ok(view,'existing SSI scaffold delivers declared block view code');
            assert.equal(view[1],block.view_js,'SSI retains exact first-party fixed interpreter bytes');
        }
        await writeFile(join(evidence,'ssi-companion-proof.json'),JSON.stringify(scaffold,null,2));
    }
    cli(['plugin','activate','native-control-proof']);
    const postId=Number(cli(['eval-file',join(site,'control-state-install.php')]).trim());
    assert.ok(postId>0,'fixture page persisted through WordPress');
    await writeFile(join(evidence,'wordpress-fixture.json'),JSON.stringify({postId,wpUrl,plugin,payloadProvenance:payload.provenance},null,2));
    const page=await context.newPage(); const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(`${wpUrl}/?page_id=${postId}`,{waitUntil:'networkidle'});
    const actual=await state(page);
    await writeFile(join(evidence,'wordpress-baseline.json'),JSON.stringify(actual,null,2));
    assert.deepEqual(actual,baseline,'WordPress current/default properties, predicates, CSS and ownership match closed source');
    for(const width of [390,768,1440]){await page.setViewportSize({width,height:1000});assert.deepEqual(await state(page),baseline,`native baseline CSS/predicates at ${width}px`);}
    assert.equal(await page.evaluate(()=>window.sourceExecuted),undefined,'source executable did not reach destination');
    assert.equal(await page.evaluate(()=>window.capturedReplayExecuted),undefined,'producer replay descriptor is replaced with owned view code, never copied');
    assert.equal(await page.locator('#on').getAttribute('checked'),null,'default attribute remains absent');
    assert.equal(await page.locator('label[for=on]').getAttribute('data-label'),'passive','label passive data stays on clickable host');
    assert.equal(await page.locator('#select').count(),1,'native select owns its unique label target');
    await page.locator('label[for=select]').click();
    assert.equal(await page.locator('select#select').evaluate(node=>document.activeElement===node),true,'associated select label focuses the native field');
    await page.locator('#choice-b').click();
    assert.deepEqual(await state(page),transition,'existing captured-choice runtime replays native current properties without changing defaults');
    await page.locator('label[for=on]').click(); assert.equal(await page.locator('#on').isChecked(),false,'native label toggles control');
    await page.setViewportSize({width:390,height:1000}); assert.equal(await page.locator('#on').isChecked(),false,'resize never reapplies captured baseline');
    await page.addScriptTag({url:`${wpUrl}/wp-content/plugins/native-control-proof/authored-input/view.js`});
    assert.equal(await page.locator('#on').isChecked(),false,'reloaded owned script never resets an active control');
    await page.evaluate(()=>{
        const valid={version:1,kind:'input',type:'checkbox',value:'on',defaultValue:'',checked:true,defaultChecked:false,indeterminate:false};
        for(const [index,state] of [Object.assign({},valid,{checked:'true'}),Object.assign({},valid,{value:42}),Object.assign({},valid,{onclick:'window.payloadExecuted=true'})].entries()){
            const input=document.createElement('input');input.type='checkbox';input.id='invalid-view-'+index;input.setAttribute('data-blocks-engine-control-state',JSON.stringify(state));document.body.append(input);
        }
    });
    await page.addScriptTag({url:`${wpUrl}/wp-content/plugins/native-control-proof/authored-input/view.js`});
    assert.deepEqual(await page.locator('[id^=invalid-view-]').evaluateAll(nodes=>nodes.map(node=>({checked:node.checked,defaultChecked:node.defaultChecked,handler:node.onclick}))),Array.from({length:3},()=>({checked:false,defaultChecked:false,handler:null})),'closed view payload validation rejects types and arbitrary property names');
    await page.evaluate(()=>document.querySelectorAll('.control-scope form').forEach(form=>form.reset()));
    assert.deepEqual(await state(page),reset,'native reset agrees with source');
    const validBlocks=await editorProof(page,postId,cli);
    assert.deepEqual(await hashes(),initialHashes,'editor/save/reopen and browser runtime leave the first-party declared codebase immutable');
    await writeFile(join(evidence,'immutable-codebase.json'),JSON.stringify({unchanged:true,sha256:initialHashes},null,2));
    assert.deepEqual(errors,[],'owned WordPress browser/editor code has no uncaught errors');
    await writeFile(join(evidence,'browser-errors.json'),JSON.stringify(errors));
    await writeFile(join(evidence,'acceptance-summary.json'),JSON.stringify({pass:true,sourceShutDown:serverClosed,controls:baseline.length,widths:[390,768,1440],invalidSourcePayloads:1,invalidViewPayloads:3,resetForms:3,registeredEditorEdits:5,validBlocks,immutableCodebase:true,declaredFiles:codeFiles.length,generatedBlockNames:payload.blocks.map(block=>block.block_json.name)},null,2));
    console.log('PASS: closed-source WordPress native properties/defaults/CSS/reset/label/group ownership and registered editor save/reopen');
} finally {if(!serverClosed){server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}await browser.close();}

function phpArray(value){if(Array.isArray(value))return `array(${value.map(phpArray).join(',')})`;if(value&&typeof value==='object')return `array(${Object.entries(value).map(([key,value])=>`${phpArray(key)}=>${phpArray(value)}`).join(',')})`;return JSON.stringify(value).replace(/^"|"$/g,"'");}

async function editorProof(page,postId,cli){
    await page.goto(`${wpUrl}/wp-login.php`);
    await page.getByLabel('Username or Email Address').fill(process.env.CONTROL_STATE_USER||'controltest');
    await page.getByRole('textbox',{name:'Password'}).fill(process.env.CONTROL_STATE_PASSWORD||'controltest-local-fixture');
    await page.getByRole('button',{name:'Log In'}).click();
    await page.goto(`${wpUrl}/wp-admin/post.php?post=${postId}&action=edit`,{waitUntil:'domcontentloaded'});
    await page.waitForFunction(()=>window.wp?.data?.select('core/block-editor')?.getBlocks().length>0);
    const changed=await page.evaluate(()=>{
        const flat=blocks=>blocks.flatMap(block=>[block,...flat(block.innerBlocks||[])]);
        const nodes=flat(wp.data.select('core/block-editor').getBlocks());
        for(const id of ['on','text','textarea','select']){
            const block=nodes.find(block=>block.attributes.id===id);if(!block)throw new Error('Missing editor control '+id);
            const definition=wp.blocks.getBlockType(block.name);if(!definition?.edit||!definition?.save)throw new Error('Unregistered control');
            const attrs={...block.attributes};
            const type=definition.edit({attributes:attrs,setAttributes:next=>Object.assign(attrs,next)});
            const find=node=>{if(!node||typeof node!=='object')return null;if(node.props?.onChange&&node.props?.label===(id==='select'?'Options':id==='on'?'Default checked':'Default value'))return node;for(const child of [].concat(node.props?.children||[])){const hit=find(child);if(hit)return hit;}return null;};
            const field=find(type);if(!field)throw new Error('Missing actual editor callback '+id);
            field.props.onChange(id==='on'?true:id==='select'?'a|Alpha\nb|Beta|selected':'Edited café 🐴');
            wp.data.dispatch('core/block-editor').updateBlockAttributes(block.clientId,attrs);
        }
        // Radio edits go through the real store so same-owner peers are cleared by the registered editor.
        const radio=nodes.find(block=>block.attributes.id==='radio-a');
        const radioType=wp.blocks.getBlockType(radio.name).edit({clientId:radio.clientId,name:radio.name,attributes:radio.attributes,setAttributes:next=>wp.data.dispatch('core/block-editor').updateBlockAttributes(radio.clientId,next)});
        const findToggle=node=>{if(!node||typeof node!=='object')return null;if(node.props?.onChange&&node.props?.label==='Default checked')return node;for(const child of [].concat(node.props?.children||[])){const hit=findToggle(child);if(hit)return hit;}return null;};
        findToggle(radioType).props.onChange(true);
        return flat(wp.data.select('core/block-editor').getBlocks()).filter(block=>['on','text','textarea','select','radio-a','radio-b','other-radio'].includes(block.attributes.id)).map(block=>({name:block.name,attrs:block.attributes}));
    });
    await writeFile(join(evidence,'editor-changes.json'),JSON.stringify(changed,null,2));
    await page.evaluate(async()=>{await wp.data.dispatch('core/editor').savePost();});
    await page.waitForFunction(()=>!wp.data.select('core/editor').isSavingPost()&&!wp.data.select('core/editor').isEditedPostDirty());
    await page.reload({waitUntil:'domcontentloaded'});
    await page.waitForFunction(()=>window.wp?.data?.select('core/block-editor')?.getBlocks().length>0);
    const validation=await page.evaluate(()=>{const flat=blocks=>blocks.flatMap(block=>[block,...flat(block.innerBlocks||[])]);return flat(wp.blocks.parse(wp.data.select('core/editor').getEditedPostContent())).map(block=>({name:block.name,attrs:block.attributes,valid:wp.blocks.validateBlock(block)[0]}));});
    await writeFile(join(evidence,'editor-reopen.json'),JSON.stringify(validation,null,2));
    assert.ok(validation.every(block=>block.valid),'all actual registered blocks validate after persisted editor changes');
    const byId=Object.fromEntries(validation.filter(block=>block.attrs.id).map(block=>[block.attrs.id,block.attrs]));
    assert.equal(byId.on.checked,true);assert.equal(byId.on.initialChecked,true);
    for(const id of ['text','textarea']){assert.equal(byId[id].value,'Edited café 🐴');assert.equal(byId[id].initialValue,'Edited café 🐴');}
    assert.deepEqual(byId.select.options.map(option=>[option.selected,option.initialSelected]),[[false,false],[true,true]]);
    assert.deepEqual([byId['radio-a'].checked,byId['radio-a'].initialChecked,byId['radio-b'].checked,byId['radio-b'].initialChecked],[true,true,false,false],'edited radio wins its same-owner group on reopen');
    assert.equal(byId['other-radio'].checked,true,'radio in another form owner keeps its default');
    const persisted=cli(['post','get',String(postId),'--field=post_content']);await writeFile(join(evidence,'persisted-blocks.html'),persisted);
    await page.goto(`${wpUrl}/?page_id=${postId}`,{waitUntil:'networkidle'});
    assert.equal(await page.locator('#on').isChecked(),true);assert.equal(await page.locator('#on').evaluate(node=>node.defaultChecked),true);
    assert.equal(await page.locator('#text').inputValue(),'Edited café 🐴');assert.equal(await page.locator('#textarea').inputValue(),'Edited café 🐴');
    assert.equal(await page.locator('#select').inputValue(),'b');
    assert.deepEqual(await page.evaluate(()=>['radio-a','radio-b','other-radio'].map(id=>[document.getElementById(id).checked,document.getElementById(id).defaultChecked])),[[true,true],[false,false],[false,true]],'edited radio wins on the frontend');
    await page.evaluate(()=>document.getElementById('first').reset());
    assert.equal(await page.locator('#on').isChecked(),true);assert.equal(await page.locator('#text').inputValue(),'Edited café 🐴');assert.equal(await page.locator('#select').inputValue(),'b');
    assert.equal(await page.locator('#radio-a').isChecked(),true,'edited radio remains the reset default');
    return validation.length;
}
