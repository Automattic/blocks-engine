import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

// The editor canvas must paint the front end's resting state: hidden runtime
// blocks and closed dialogs stay hidden until selected, and a linked image
// keeps the anchor class and height its author rules size it by.
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./editor-resting-state-fixture.php', import.meta.url))], {encoding: 'utf8'}));
assert.match(fixture.script, /editor\.BlockListBlock/, 'the theme bootstrap enqueues the editor resting-state module');
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage();
    // core/image edit markup: no anchor class, an inline height:auto.
    await page.setContent('<style>.brand{display:flex}.brand img{height:58px;width:auto;border-radius:50%}</style>'
        + '<figure id="block-image" class="wp-block-image"><a href="/" aria-disabled="true" style="pointer-events:none;cursor:default;display:inline">'
        + '<img src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22150%22 height=%22150%22/%3E" width="150" height="150" style="height:auto"></a></figure>');
    const result = await page.evaluate(({script, blocks}) => {
        let filter = null; let selected = '';
        window.wp = {
            hooks: {addFilter: (name, namespace, callback) => { if ('editor.BlockListBlock' === name) filter = callback; }},
            element: {createElement: (type, props) => ({type, props}), useEffect: effect => effect()},
            data: {useSelect: read => read(() => ({isBlockSelected: id => id === selected, hasSelectedInnerBlock: () => false}))},
            compose: {createHigherOrderComponent: hoc => hoc},
        };
        new Function(script)();
        const render = filter('BlockListBlock');
        const hidden = (block, clientId) => { selected = ''; const rest = render({...block, clientId}).props.wrapperProps?.hidden === true; selected = clientId; const editing = render({...block, clientId}).props.wrapperProps?.hidden === true; return {rest, editing}; };
        const find = predicate => blocks.find(predicate);
        const runtime = find(block => 'string' === typeof block.attributes.metadata?.blocksEngineRuntimeAttributes?.hidden);
        const dialog = find(block => block.attributes.metadata?.blocksEngineDialog);
        const image = find(block => 'core/image' === block.name);
        render({...image, clientId: 'image'});
        const img = document.querySelector('img'); const style = getComputedStyle(img);
        return {
            runtime: hidden(runtime, 'runtime'),
            dialog: hidden(dialog, 'dialog'),
            image: {hidden: render({...image, clientId: 'image'}).props.wrapperProps?.hidden === true, linkClass: document.querySelector('a').className, height: style.height, radius: style.borderTopLeftRadius},
        };
    }, fixture);
    assert.deepEqual(result.runtime, {rest: true, editing: false}, 'a runtime-hidden block is hidden at rest and shown while selected');
    assert.deepEqual(result.dialog, {rest: true, editing: false}, 'a closed dialog is hidden at rest and shown while selected');
    assert.equal(result.image.hidden, false);
    assert.match(result.image.linkClass, /(?:^| )brand(?: |$)/, 'the editor link carries the saved anchor class');
    assert.equal(result.image.height, '58px', 'the author height sizes the image over the editor default');
    assert.equal(result.image.radius, '50%');
    console.log('Editor canvas paints the front end resting state.');
} finally {
    await browser.close();
}
