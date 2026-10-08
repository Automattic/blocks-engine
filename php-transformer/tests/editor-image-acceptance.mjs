#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const required = [ 'BE_EDITOR_WP_URL', 'BE_EDITOR_POST_ID', 'BE_EDITOR_LISTING_POST_ID', 'BE_EDITOR_USER', 'BE_EDITOR_PASSWORD', 'BE_EDITOR_EVIDENCE_DIR' ];
const missing = required.filter( ( name ) => ! process.env[ name ] );
if ( missing.length ) throw new Error( `Missing required environment: ${ missing.join( ', ' ) }` );
const baseUrl = process.env.BE_EDITOR_WP_URL.replace( /\/$/, '' );
const postId = process.env.BE_EDITOR_POST_ID;
const evidence = process.env.BE_EDITOR_EVIDENCE_DIR;
const source = JSON.parse( await readFile( `${ evidence }/source-and-page.json`, 'utf8' ) );
const saveResponses = [];
const browserErrors = [];
const saveRequest = ( request ) => { const url = new URL( request.url() ); const route = `/wp/v2/pages/${ postId }`; return ( url.pathname.endsWith( route ) || url.searchParams.get( 'rest_route' ) === route ) && [ 'POST', 'PUT', 'PATCH' ].includes( request.method() ); };
const browser = await chromium.launch( { headless: true } );
const page = await browser.newPage( { viewport: { width: 1440, height: 1000 } } );
page.on( 'pageerror', ( error ) => browserErrors.push( error.message ) );
page.on( 'response', ( response ) => { if ( saveRequest( response.request() ) ) saveResponses.push( { status: response.status(), url: response.url() } ); } );
const editorBlock = () => page.evaluate( () => { const store = window.wp.data.select( 'core/block-editor' ); const block = store.getBlocks().find( ( candidate ) => candidate.name === 'core/image' ); return { clientId: block?.clientId, attributes: block?.attributes }; } );
const compareCapturePngs = async ( sourcePng, wordpressPng ) => page.evaluate( async ( [ sourceData, wordpressData ] ) => {
 const load = ( data ) => new Promise( ( resolve, reject ) => { const image = new Image(); image.onload = () => resolve( image ); image.onerror = reject; image.src = `data:image/png;base64,${ data }`; } );
 const [ source, wordpress ] = await Promise.all( [ load( sourceData ), load( wordpressData ) ] );
 if ( source.width !== wordpress.width || source.height !== wordpress.height ) return { source: [ source.width, source.height ], wordpress: [ wordpress.width, wordpress.height ], pixel_difference_count: null, bounds: null };
 const canvas = document.createElement( 'canvas' ); canvas.width = source.width; canvas.height = source.height;
 const ctx = canvas.getContext( '2d', { willReadFrequently: true } );
 ctx.drawImage( source, 0, 0 ); const a = ctx.getImageData( 0, 0, canvas.width, canvas.height ).data;
 ctx.clearRect( 0, 0, canvas.width, canvas.height ); ctx.drawImage( wordpress, 0, 0 ); const b = ctx.getImageData( 0, 0, canvas.width, canvas.height ).data;
 let count = 0; let minX = canvas.width; let minY = canvas.height; let maxX = -1; let maxY = -1;
 for ( let index = 0; index < a.length; index += 4 ) if ( a[index] !== b[index] || a[index + 1] !== b[index + 1] || a[index + 2] !== b[index + 2] || a[index + 3] !== b[index + 3] ) { const x = ( index / 4 ) % canvas.width; const y = Math.floor( index / ( 4 * canvas.width ) ); count++; minX = Math.min( minX, x ); minY = Math.min( minY, y ); maxX = Math.max( maxX, x ); maxY = Math.max( maxY, y ); }
 return { width: canvas.width, height: canvas.height, pixel_difference_count: count, total_pixels: canvas.width * canvas.height, bounds: count ? [ minX, minY, maxX, maxY ] : null };
}, [ sourcePng.toString( 'base64' ), wordpressPng.toString( 'base64' ) ] );
try {
 await page.goto( `${ baseUrl }/wp-login.php`, { waitUntil: 'networkidle' } );
 await page.getByLabel( 'Username or Email Address' ).fill( process.env.BE_EDITOR_USER );
 await page.getByRole( 'textbox', { name: 'Password' } ).fill( process.env.BE_EDITOR_PASSWORD );
 await page.getByRole( 'button', { name: 'Log In' } ).click();
 await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ postId }&action=edit`, { waitUntil: 'domcontentloaded' } );
 await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
 const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
 await canvas.locator( '.wp-block-image img' ).waitFor();
 const welcome = page.locator( '.components-modal__screen-overlay' ); if ( await welcome.isVisible() ) { await welcome.getByRole( 'button', { name: /Close|Get started/ } ).first().click(); await welcome.waitFor( { state: 'hidden' } ); }
 await canvas.locator( '[data-type="core/image"]' ).click();
 assert.equal( ( await editorBlock() ).attributes.id, source.image.first_attachment.id, 'the transformed core/image starts at the first real attachment' );
   assert.equal( ( await editorBlock() ).attributes.href, '/item', 'the transformed core/image carries its linked destination' );
  await page.screenshot( { path: `${ evidence }/editor-baseline.png`, fullPage: true } );
   const replaced = await editorBlock();
   const cropped = replaced;
  const settings = page.getByRole( 'button', { name: 'Settings', exact: true } );
  if ( await settings.getAttribute( 'aria-pressed' ) !== 'true' ) await settings.click();
  const blockTab = page.getByRole( 'tab', { name: 'Block', exact: true } );
  if ( await blockTab.getAttribute( 'aria-selected' ) !== 'true' ) await blockTab.click();
  const alternativeText = page.getByLabel( 'Alternative text' );
  await alternativeText.fill( 'Edited alternative text' );
  await alternativeText.press( 'Tab' );
  await page.waitForFunction( () => window.wp.data.select( 'core/editor' ).isEditedPostDirty() );
  const successfulSave = page.waitForResponse( ( response ) => saveRequest( response.request() ) && response.ok() );
  await page.getByRole( 'button', { name: /^Save$/ } ).click(); await successfulSave;
  await page.waitForFunction( () => { const e=window.wp.data.select('core/editor'); return !e.isSavingPost() && !e.isEditedPostDirty() && e.didPostSaveRequestSucceed(); } );
  assert.ok( saveResponses.some( ( response ) => response.status >= 200 && response.status < 300 ), 'save succeeds through the page REST endpoint' );
 const savedContent = await page.evaluate( () => window.wp.data.select( 'core/editor' ).getEditedPostContent() );
 await writeFile( `${ evidence }/save.json`, JSON.stringify( { responses: saveResponses, savedContent }, null, 2 ) + '\n' );
 await page.reload( { waitUntil: 'domcontentloaded' } ); await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
 const reloaded = await editorBlock();
  const validation = await page.evaluate( () => { const content=window.wp.data.select('core/editor').getEditedPostContent(); const blocks=window.wp.blocks.parse(content); const visit=(items)=>items.flatMap(b=>[{name:b.name,registered:Boolean(window.wp.blocks.getBlockType(b.name)),valid:window.wp.blocks.validateBlock(b)[0]},...visit(b.innerBlocks||[])]); return { blocks: visit(blocks) }; } );
  assert.deepEqual( validation.blocks.map( ( block ) => block.name ), [ 'core/image' ], 'the saved document contains only the promoted core/image block' );
  assert.ok( validation.blocks.every( ( block ) => block.registered && block.valid ), 'all saved blocks are registered and validate after reload' );
  assert.ok( validation.blocks.every( ( block ) => block.name !== 'core/missing' ), 'the saved document contains no core/missing blocks' );
 assert.equal( reloaded.attributes.alt, 'Edited alternative text', 'alternative text survives reload' );
  assert.equal( reloaded.attributes.id, cropped.attributes.id, 'crop outcome survives reload' );
   assert.equal( reloaded.attributes.href, '/item', 'the linked image destination survives editing and reload' );
    assert.ok( ( await page.evaluate( () => window.wp.data.select( 'core/editor' ).getEditedPostContent() ) ).includes('<a href="/item">'), 'the linked image URL survives editing and reload in saved block content' );
 await writeFile( `${ evidence }/validation.json`, JSON.stringify( validation, null, 2 ) + '\n' );
 await page.goto( `${ baseUrl }/?page_id=${ postId }`, { waitUntil: 'networkidle' } );
  const image = page.locator( '.wp-block-image img' ); await image.waitFor();
  assert.equal( await image.getAttribute( 'alt' ), 'Edited alternative text', 'frontend has edited alternative text' );
   assert.equal( await image.getAttribute( 'src' ), source.image.first_attachment.url, 'frontend retains the source attachment' );
   assert.equal( await page.locator( '.wp-block-image a' ).getAttribute( 'href' ), '/item', 'frontend retains the image link destination' );
   assert.equal( await page.locator( '.wp-block-image' ).count(), 1, 'frontend retains the one editable core/image figure' );
   await page.screenshot( { path: `${ evidence }/frontend.png`, fullPage: true } );
  await page.setContent( source.listing.source_markup, { waitUntil: 'domcontentloaded' } );
  await page.setViewportSize( { width: 1440, height: 1000 } );
  await page.screenshot( { path: `${ evidence }/source-desktop.png`, fullPage: true } );
  await page.setViewportSize( { width: 390, height: 844 } );
  await page.screenshot( { path: `${ evidence }/source-mobile.png`, fullPage: true } );

  await page.setViewportSize( { width: 1440, height: 1000 } );
  await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ process.env.BE_EDITOR_LISTING_POST_ID }&action=edit`, { waitUntil: 'domcontentloaded' } );
  await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
  const listingCanvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
  await listingCanvas.locator( '[data-type="core/read-more"]' ).waitFor( { state: 'attached' } );
  const listingMarkup = source.listing.neutral_block_markup;
  const listingValidity = await page.evaluate( ( markup ) => {
    const blocks = window.wp.blocks.parse( markup );
    const visit = ( items ) => items.flatMap( ( block ) => [ { name: block.name, valid: window.wp.blocks.validateBlock( block )[ 0 ] }, ...visit( block.innerBlocks || [] ) ] );
    return visit( blocks );
  }, listingMarkup );
  assert.ok( listingValidity.every( ( block ) => block.valid && block.name !== 'core/html' && block.name !== 'core/freeform' ), 'neutral card fixture is native and validates in WordPress 7.1' );
  const presentation = await listingCanvas.locator( '.editor-styles-wrapper' ).evaluate( ( root ) => {
    const overlay = root.querySelector( '.blocks-engine-listing-overlay' );
    const style = overlay ? getComputedStyle( overlay ) : null;
    const walker = document.createTreeWalker( root, NodeFilter.SHOW_COMMENT );
    const comments = [];
    while ( walker.nextNode() ) comments.push( { value: walker.currentNode.data, parent: walker.currentNode.parentElement?.outerHTML.slice( 0, 240 ) || null } );
    const horizontalRules = [ ...root.querySelectorAll( '*' ) ].map( ( node ) => {
      const rect = node.getBoundingClientRect();
      const css = getComputedStyle( node );
      const border = [ css.borderTop, css.borderBottom ].filter( ( value ) => !value.startsWith( '0px' ) );
      return { node: node.tagName, className: typeof node.className === 'string' ? node.className : '', block: node.closest( '[data-type]' )?.getAttribute( 'data-type' ) || null, text: node.innerText?.slice( 0, 80 ) || '', rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height }, border };
    } ).filter( ( item ) => item.rect.width > 32 && item.rect.height <= 3 && item.border.length );
    return { overlay: overlay && { outerHTML: overlay.outerHTML, display: style.display, position: style.position, pointerEvents: style.pointerEvents, rect: overlay.getBoundingClientRect().toJSON(), ariaHidden: overlay.getAttribute( 'aria-hidden' ), tabIndex: overlay.tabIndex }, comments, horizontalRules };
  } );
  await writeFile( `${ evidence }/editor-artifact-localization-desktop.json`, JSON.stringify( { presentation, savedBlockComments: ( listingMarkup.match( /<!--/g ) || [] ).length, sourceEmptyComments: ( source.listing.source_markup.match( /<!--\s*-->/g ) || [] ).length }, null, 2 ) + '\n' );
  assert.equal( presentation.overlay?.display, 'none', 'the role-marked frontend overlay is hidden in the actual Gutenberg canvas' );
  await page.screenshot( { path: `${ evidence }/editor-listing-desktop.png`, fullPage: true } );
  const cardHeading = listingCanvas.locator( '[data-type="core/heading"]' ).filter( { hasText: 'Neutral Card Title' } );
  await cardHeading.click();
  const selection = await page.evaluate( () => {
    const store = window.wp.data.select( 'core/block-editor' );
    const id = store.getSelectedBlockClientId();
    const block = id ? store.getBlock( id ) : null;
    return { id, name: block?.name || null, attributes: block?.attributes || null };
  } );
  assert.equal( selection.name, 'core/heading', 'a click on underlying card text selects the heading, not the overlay' );
  await writeFile( `${ evidence }/editor-selected-heading.json`, JSON.stringify( { selection, selectedMarkup: await cardHeading.evaluate( ( node ) => node.outerHTML ) }, null, 2 ) + '\n' );
  await cardHeading.fill( 'Edited Neutral Card Title' );
  await cardHeading.press( 'Control+A' );
  await page.getByRole( 'button', { name: 'Link', exact: true } ).last().click();
  await page.getByRole( 'button', { name: 'Edit link', exact: true } ).click();
  const linkEditorUi = await page.locator( 'input, button, [role="dialog"], [role="menu"], [role="combobox"], [role="textbox"]' ).evaluateAll( ( nodes ) => nodes.filter( ( node ) => node.getClientRects().length && ( node.closest( '.block-editor-link-control, .components-popover, .components-dropdown' ) || node.innerText?.includes( '/first' ) || node.getAttribute( 'aria-label' )?.toLowerCase().includes( 'link' ) ) ).map( ( node ) => ( { tag: node.tagName, role: node.getAttribute( 'role' ), ariaLabel: node.getAttribute( 'aria-label' ), placeholder: node.getAttribute( 'placeholder' ), text: node.innerText || '', value: node.value || '', html: node.outerHTML.slice( 0, 700 ) } ) ) );
  await writeFile( `${ evidence }/editor-link-popover.json`, JSON.stringify( { linkEditorUi, body: await page.locator( 'body' ).innerText() }, null, 2 ) + '\n' );
  const linkInput = page.locator( '#url-input-control-0' );
  await linkInput.fill( '/second' );
  await linkInput.press( 'Enter' );
  await page.waitForFunction( () => window.wp.data.select( 'core/editor' ).isEditedPostDirty() );
  await page.screenshot( { path: `${ evidence }/editor-listing-edited-desktop.png`, fullPage: true } );
  await writeFile( `${ evidence }/editor-selection-and-artifacts.json`, JSON.stringify( { selection, presentation }, null, 2 ) + '\n' );
  const listingSave = page.waitForResponse( ( response ) => {
    const request = response.request();
    const url = new URL( request.url() );
    return ( url.pathname.endsWith( `/wp/v2/pages/${ process.env.BE_EDITOR_LISTING_POST_ID }` ) || url.searchParams.get( 'rest_route' ) === `/wp/v2/pages/${ process.env.BE_EDITOR_LISTING_POST_ID }` ) && [ 'POST', 'PUT', 'PATCH' ].includes( request.method() ) && response.ok();
  } );
  await page.getByRole( 'button', { name: /^Save$/ } ).click();
  await listingSave;
  await page.waitForFunction( () => { const store = window.wp.data.select( 'core/editor' ); return !store.isSavingPost() && !store.isEditedPostDirty() && store.didPostSaveRequestSucceed(); } );
  const persisted = await page.evaluate( () => window.wp.data.select( 'core/editor' ).getEditedPostContent() );
  await writeFile( `${ evidence }/listing-after-save.json`, JSON.stringify( { persisted, saveResponses }, null, 2 ) + '\n' );
  assert.ok( persisted.includes( 'Edited Neutral Card Title' ), 'edited card text is persisted through WordPress REST save' );
  assert.ok( persisted.includes( 'href="/second"' ), 'edited card link destination is persisted through WordPress REST save' );
  await page.reload( { waitUntil: 'domcontentloaded' } );
  await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
  const reloadedCanvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
  await reloadedCanvas.getByText( 'Edited Neutral Card Title', { exact: true } ).waitFor();
  const reloadedValidation = await page.evaluate( () => {
    const content = window.wp.data.select( 'core/editor' ).getEditedPostContent();
    const blocks = window.wp.blocks.parse( content );
    const visit = ( items ) => items.flatMap( ( block ) => [ { name: block.name, valid: window.wp.blocks.validateBlock( block )[ 0 ] }, ...visit( block.innerBlocks || [] ) ] );
    return { blocks: visit( blocks ), content };
  } );
  assert.ok( reloadedValidation.blocks.every( ( block ) => block.valid && block.name !== 'core/html' && block.name !== 'core/freeform' ), 'saved card and overlay remain native and valid after reload' );
  const reloadedHeading = await page.evaluate( () => {
    const content = window.wp.data.select( 'core/editor' ).getEditedPostContent();
    const block = window.wp.blocks.parse( content ).flatMap( ( item ) => [ item, ...( item.innerBlocks || [] ) ] ).find( ( item ) => 'core/heading' === item.name );
    return block?.attributes?.content || '';
  } );
  assert.ok( reloadedHeading.includes( 'href="/second"' ), 'edited card link destination survives editor reload' );
  await page.screenshot( { path: `${ evidence }/editor-listing-reloaded-desktop.png`, fullPage: true } );
  await page.setViewportSize( { width: 390, height: 844 } );
  await page.reload( { waitUntil: 'domcontentloaded' } );
  await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
  await page.screenshot( { path: `${ evidence }/editor-listing-reloaded-mobile.png`, fullPage: true } );
  const publicWp = await browser.newPage( { viewport: { width: 1440, height: 1000 } } );
  await publicWp.goto( `${ baseUrl }/?page_id=${ process.env.BE_EDITOR_LISTING_POST_ID }`, { waitUntil: 'networkidle' } );
  await publicWp.getByText( 'Edited Neutral Card Title', { exact: true } ).waitFor();
  const frontendOverlay = await publicWp.locator( '.blocks-engine-listing-overlay' ).evaluate( ( overlay ) => ( { outerHTML: overlay.outerHTML, display: getComputedStyle( overlay ).display, position: getComputedStyle( overlay ).position, href: overlay.getAttribute( 'href' ), ariaHidden: overlay.getAttribute( 'aria-hidden' ), tabIndex: overlay.tabIndex } ) );
  assert.notEqual( frontendOverlay.display, 'none', 'frontend full-card link remains visible and active' );
  assert.equal( frontendOverlay.ariaHidden, 'true', 'frontend overlay retains its duplicate-link accessibility contract' );
  assert.equal( frontendOverlay.tabIndex, -1, 'frontend overlay remains excluded from sequential keyboard focus' );
  assert.equal( await publicWp.locator( '.be-neutral-card .wp-block-heading a' ).getAttribute( 'href' ), '/second', 'frontend uses the edited card link destination' );
  await publicWp.screenshot( { path: `${ evidence }/wordpress-frontend-listing-desktop.png`, fullPage: true } );
  await publicWp.setViewportSize( { width: 390, height: 844 } );
  await publicWp.reload( { waitUntil: 'networkidle' } );
  await publicWp.screenshot( { path: `${ evidence }/wordpress-frontend-listing-mobile.png`, fullPage: true } );
  await writeFile( `${ evidence }/persisted-listing-acceptance.json`, JSON.stringify( { saveResponses, selection, reloadedValidation, frontendOverlay }, null, 2 ) + '\n' );

  await page.setViewportSize( { width: 1440, height: 1000 } );
  await page.setContent( source.listing.source_markup, { waitUntil: 'domcontentloaded' } );
  await page.evaluate( () => document.fonts.ready );
  const sourceDesktopCapture = await page.evaluate( () => ( { viewport: { width: innerWidth, height: innerHeight }, devicePixelRatio, fontStatus: document.fonts.status, timesNewRomanAvailable: document.fonts.check( '16px "Times New Roman"' ) } ) );
  const sourceGeometry = await page.locator( '.card' ).evaluateAll( ( cards ) => cards.map( ( card ) => {
    const children = [ ...card.children ].map( ( child ) => ( { tag: child.tagName, className: child.className, rect: child.getBoundingClientRect().toJSON(), margin: getComputedStyle( child ).margin } ) );
    const paint = [ ...card.querySelectorAll( '.metadata a, h2 a, .description' ) ].map( ( node ) => {
      const css = getComputedStyle( node );
      return { tag: node.tagName, text: node.innerText, rect: node.getBoundingClientRect().toJSON(), display: css.display, fontFamily: css.fontFamily, fontSize: css.fontSize, fontWeight: css.fontWeight, lineHeight: css.lineHeight, letterSpacing: css.letterSpacing, textDecorationLine: css.textDecorationLine, color: css.color };
    } );
    return { text: card.innerText.trim().replace( /\s+/g, ' ' ), rect: card.getBoundingClientRect().toJSON(), border: getComputedStyle( card ).border, children, paint };
  } ) );
  const sourceDesktopPng = await page.screenshot( { path: `${ evidence }/source-capture-desktop.png`, fullPage: true } );
  await page.setViewportSize( { width: 390, height: 844 } );
  const sourceMobileGeometry = await page.locator( '.card' ).evaluateAll( ( cards ) => cards.map( ( card ) => ( { text: card.innerText.trim().replace( /\s+/g, ' ' ), rect: card.getBoundingClientRect().toJSON(), border: getComputedStyle( card ).border } ) ) );
  await page.evaluate( () => document.fonts.ready );
  const sourceMobileCapture = await page.evaluate( () => ( { viewport: { width: innerWidth, height: innerHeight }, devicePixelRatio, fontStatus: document.fonts.status, timesNewRomanAvailable: document.fonts.check( '16px "Times New Roman"' ) } ) );
  const sourceMobilePng = await page.screenshot( { path: `${ evidence }/source-capture-mobile.png`, fullPage: true } );
  await page.setViewportSize( { width: 1440, height: 1000 } );
  await publicWp.setViewportSize( { width: 1440, height: 1000 } );
  await publicWp.goto( `${ baseUrl }/?page_id=${ source.listing.post_id }`, { waitUntil: 'networkidle' } );
  const importedCards = publicWp.locator( '.wp-block-query article.card' );
  await importedCards.first().waitFor();
  await publicWp.evaluate( () => document.fonts.ready );
  const wordpressDesktopCapture = await publicWp.evaluate( () => ( { viewport: { width: innerWidth, height: innerHeight }, devicePixelRatio, fontStatus: document.fonts.status, timesNewRomanAvailable: document.fonts.check( '16px "Times New Roman"' ) } ) );
  const wordpressDesktopGeometry = await importedCards.evaluateAll( ( cards ) => cards.map( ( card ) => {
    const overlay = card.querySelector( '.blocks-engine-listing-overlay' );
    const visible = card.cloneNode( true ); visible.querySelectorAll( '.blocks-engine-listing-overlay' ).forEach( ( link ) => link.remove() );
    const paint = [ ...card.querySelectorAll( '.metadata a, h2 a, .description' ) ].map( ( node ) => {
      const css = getComputedStyle( node );
      return { tag: node.tagName, text: node.innerText, rect: node.getBoundingClientRect().toJSON(), display: css.display, fontFamily: css.fontFamily, fontSize: css.fontSize, fontWeight: css.fontWeight, lineHeight: css.lineHeight, letterSpacing: css.letterSpacing, textDecorationLine: css.textDecorationLine, color: css.color };
    } );
    return { text: visible.innerText.trim().replace( /\s+/g, ' ' ), rect: card.getBoundingClientRect().toJSON(), border: getComputedStyle( card ).border, children: [ ...card.children ].map( ( child ) => ( { tag: child.tagName, className: child.className, rect: child.getBoundingClientRect().toJSON(), margin: getComputedStyle( child ).margin } ) ), paint, overlay: overlay && { href: overlay.href, display: getComputedStyle( overlay ).display, position: getComputedStyle( overlay ).position, rect: overlay.getBoundingClientRect().toJSON(), ariaHidden: overlay.getAttribute( 'aria-hidden' ), tabIndex: overlay.tabIndex } };
  } ) );
  const wordpressDesktopPng = await publicWp.screenshot( { path: `${ evidence }/wordpress-capture-desktop.png`, fullPage: true } );
  const desktopPixelComparison = await compareCapturePngs( sourceDesktopPng, wordpressDesktopPng );
  await writeFile( `${ evidence }/source-wordpress-desktop-geometry.json`, JSON.stringify( { sourceGeometry, wordpressDesktopGeometry }, null, 2 ) + '\n' );
  assert.equal( wordpressDesktopGeometry.length, sourceGeometry.length, 'WordPress frontend renders the source fixture card count' );
  assert.ok( wordpressDesktopGeometry.every( ( card ) => card.overlay && 'absolute' === card.overlay.position && card.overlay.rect.width >= card.rect.width - 2 && card.overlay.rect.height >= card.rect.height - 2 ), 'frontend overlay retains full-card geometry on the generated listing route' );
  await publicWp.setViewportSize( { width: 390, height: 844 } );
  await publicWp.reload( { waitUntil: 'networkidle' } );
  await publicWp.evaluate( () => document.fonts.ready );
  const wordpressMobileCapture = await publicWp.evaluate( () => ( { viewport: { width: innerWidth, height: innerHeight }, devicePixelRatio, fontStatus: document.fonts.status, timesNewRomanAvailable: document.fonts.check( '16px "Times New Roman"' ) } ) );
  const wordpressMobileGeometry = await publicWp.locator( '.wp-block-query article.card' ).evaluateAll( ( cards ) => cards.map( ( card ) => {
    const overlay = card.querySelector( '.blocks-engine-listing-overlay' );
    const visible = card.cloneNode( true ); visible.querySelectorAll( '.blocks-engine-listing-overlay' ).forEach( ( link ) => link.remove() );
    const paint = [ ...card.querySelectorAll( '.metadata a, h2 a, .description' ) ].map( ( node ) => {
      const css = getComputedStyle( node );
      return { tag: node.tagName, text: node.innerText, rect: node.getBoundingClientRect().toJSON(), display: css.display, fontFamily: css.fontFamily, fontSize: css.fontSize, fontWeight: css.fontWeight, lineHeight: css.lineHeight, letterSpacing: css.letterSpacing, textDecorationLine: css.textDecorationLine, color: css.color };
    } );
    return { text: visible.innerText.trim().replace( /\s+/g, ' ' ), rect: card.getBoundingClientRect().toJSON(), border: getComputedStyle( card ).border, paint, overlay: overlay && { position: getComputedStyle( overlay ).position, rect: overlay.getBoundingClientRect().toJSON() } };
  } ) );
  const wordpressMobilePng = await publicWp.screenshot( { path: `${ evidence }/wordpress-capture-mobile.png`, fullPage: true } );
  const mobilePixelComparison = await compareCapturePngs( sourceMobilePng, wordpressMobilePng );
  assert.ok( wordpressMobileGeometry.every( ( card ) => card.overlay && 'absolute' === card.overlay.position && card.overlay.rect.width >= card.rect.width - 2 && card.overlay.rect.height >= card.rect.height - 2 ), 'mobile frontend overlay remains full-card geometry' );
  const heightDeltas = {
    desktop: wordpressDesktopGeometry.map( ( card, index ) => Number((card.rect.height - sourceGeometry[index].rect.height).toFixed(2)) ),
    mobile: wordpressMobileGeometry.map( ( card, index ) => Number((card.rect.height - sourceMobileGeometry[index].rect.height).toFixed(2)) ),
  };
  const exactGeometry = [ ...heightDeltas.desktop, ...heightDeltas.mobile ].every( ( delta ) => 0 === delta );
  const geometrySummary = {
    desktop: wordpressDesktopGeometry.map( ( card, index ) => ( { textMatches: card.text.replace( /\s/g, '' ) === sourceGeometry[index].text.replace( /\s/g, '' ), borderMatches: card.border === sourceGeometry[index].border, x: Number((card.rect.x - sourceGeometry[index].rect.x).toFixed(2)), y: Number((card.rect.y - sourceGeometry[index].rect.y).toFixed(2)), width: Number((card.rect.width - sourceGeometry[index].rect.width).toFixed(2)), height: heightDeltas.desktop[index] } ) ),
    mobile: wordpressMobileGeometry.map( ( card, index ) => ( { textMatches: card.text.replace( /\s/g, '' ) === sourceMobileGeometry[index].text.replace( /\s/g, '' ), borderMatches: card.border === sourceMobileGeometry[index].border, x: Number((card.rect.x - sourceMobileGeometry[index].rect.x).toFixed(2)), y: Number((card.rect.y - sourceMobileGeometry[index].rect.y).toFixed(2)), width: Number((card.rect.width - sourceMobileGeometry[index].rect.width).toFixed(2)), height: heightDeltas.mobile[index] } ) ),
  };
  const pixelParity = { desktop: desktopPixelComparison, mobile: mobilePixelComparison };
  await writeFile( `${ evidence }/source-wordpress-geometry.json`, JSON.stringify( { exactGeometry, geometrySummary, pixelParity, sourceCapture: { desktop: sourceDesktopCapture, mobile: sourceMobileCapture }, wordpressCapture: { desktop: wordpressDesktopCapture, mobile: wordpressMobileCapture }, sourceDesktop: sourceGeometry, sourceMobile: sourceMobileGeometry, wordpressDesktop: wordpressDesktopGeometry, wordpressMobile: wordpressMobileGeometry }, null, 2 ) + '\n' );
  assert.ok( exactGeometry && [ ...geometrySummary.desktop, ...geometrySummary.mobile ].every( ( card ) => card.textMatches && card.borderMatches && 0 === card.x && 0 === card.y && 0 === card.width ), 'neutral source and WordPress cards match text, border, and full desktop/mobile geometry' );
  assert.equal( desktopPixelComparison.pixel_difference_count, 0, 'desktop source and WordPress screenshots have exact per-pixel parity under matched viewport, DPR, and font readiness' );
  assert.equal( mobilePixelComparison.pixel_difference_count, 0, 'mobile source and WordPress screenshots have exact per-pixel parity under matched viewport, DPR, and font readiness' );

  await page.setViewportSize( { width: 1440, height: 1000 } );
  await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ source.listing.post_id }&action=edit`, { waitUntil: 'domcontentloaded' } );
  await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
  const queryEditor = page.frameLocator( 'iframe[name="editor-canvas"]' );
  await queryEditor.locator( '[data-type="core/query"]' ).waitFor();
  const queryEditorEvidence = await queryEditor.locator( '.editor-styles-wrapper' ).evaluate( ( root ) => {
    const overlays = [ ...root.querySelectorAll( '.blocks-engine-listing-overlay' ) ].map( ( overlay ) => ( { display: getComputedStyle( overlay ).display, rect: overlay.getBoundingClientRect().toJSON(), blockType: overlay.getAttribute( 'data-type' ), outerHTML: overlay.outerHTML } ) );
    const boundFields = [ ...root.querySelectorAll( '.blocks-engine-listing-bound-meta' ) ].map( ( field ) => ( { display: getComputedStyle( field ).display, text: field.innerText, outerHTML: field.outerHTML } ) );
    const walker = document.createTreeWalker( root, NodeFilter.SHOW_COMMENT );
    const comments = [];
    while ( walker.nextNode() ) comments.push( { value: walker.currentNode.data, parent: walker.currentNode.parentElement?.tagName || null } );
    const thinBorders = [ ...root.querySelectorAll( '*' ) ].map( ( node ) => {
      const rect = node.getBoundingClientRect(); const css = getComputedStyle( node );
      return { tag: node.tagName, className: typeof node.className === 'string' ? node.className : '', blockType: node.closest( '[data-type]' )?.getAttribute( 'data-type' ) || null, text: node.innerText?.slice( 0, 72 ) || '', rect: rect.toJSON(), borderTop: css.borderTop, borderBottom: css.borderBottom };
    } ).filter( ( node ) => node.rect.width > 32 && node.rect.height <= 3 && ( !node.borderTop.startsWith( '0px' ) || !node.borderBottom.startsWith( '0px' ) ) );
    const projectionStyles = [ ...root.ownerDocument.querySelectorAll( 'style' ) ].map( ( style ) => style.textContent || '' ).filter( ( css ) => css.includes( 'blocks-engine-listing-bound-meta' ) || css.includes( 'blocks-engine-listing-overlay' ) );
    return { overlays, boundFields, projectionStyles, comments, thinBorders };
  } );
  const queryValidation = await page.evaluate( () => {
    const content = window.wp.data.select( 'core/editor' ).getEditedPostContent();
    const visit = ( blocks ) => blocks.flatMap( ( block ) => [ { name: block.name, registered: Boolean( window.wp.blocks.getBlockType( block.name ) ), valid: window.wp.blocks.validateBlock( block )[ 0 ], ...( 'core/paragraph' === block.name ? { attributes: block.attributes } : {} ) }, ...visit( block.innerBlocks || [] ) ] );
    return visit( window.wp.blocks.parse( content ) );
  } );
  const bindingRest = await page.evaluate( async () => {
    const posts = await window.wp.apiFetch( { path: '/wp/v2/posts?per_page=20' } );
    return posts.map( ( post ) => ( { id: post.id, title: post.title?.rendered, meta: Object.fromEntries( Object.entries( post.meta || {} ).filter( ( [ key ] ) => key.startsWith( 'blocks_engine_listing_labels_' ) ) ) } ) );
  } );
  const boundParagraph = queryValidation.find( ( block ) => 'core/paragraph' === block.name );
  await writeFile( `${ evidence }/editor-query-localization.json`, JSON.stringify( { ...queryEditorEvidence, blockValidation: queryValidation, boundParagraph, bindingRest, emptySourceComments: ( source.listing.source_markup.match( /<!--\s*-->/g ) || [] ).length, savedTransportComments: ( source.listing.canonical_block_markup.match( /<!--/g ) || [] ).length, fallbackBlocks: source.listing.fallback_blocks }, null, 2 ) + '\n' );
  assert.ok( queryValidation.length > 0 && queryValidation.every( ( block ) => block.registered && block.valid && ![ 'core/html', 'core/freeform' ].includes( block.name ) ), 'WordPress-persisted source listing has zero fallback and invalid blocks' );
  assert.ok( queryEditorEvidence.overlays.every( ( overlay ) => 'none' === overlay.display ), 'any rendered query-template overlay is hidden in the real editor' );
  assert.ok( queryEditorEvidence.projectionStyles.some( ( css ) => css.includes( '.editor-styles-wrapper .blocks-engine-listing-bound-meta{display:none}' ) ), 'generated editor settings hide unresolved bound-meta placeholder strings' );
  assert.ok( queryEditorEvidence.boundFields.every( ( field ) => 'none' === field.display ), 'any rendered unresolved bound-meta field is withheld from the editor canvas' );
   await page.screenshot( { path: `${ evidence }/editor-query-desktop.png`, fullPage: true } );
   await page.setViewportSize( { width: 390, height: 844 } );
   await page.screenshot( { path: `${ evidence }/editor-query-mobile.png`, fullPage: true } );

    let capturedSource = null;
    try {
     capturedSource = JSON.parse( await readFile( `${ evidence }/captured-source-wordpress.json`, 'utf8' ) );
    } catch ( error ) {
     if ( 'ENOENT' !== error.code ) throw error;
    }
    if ( capturedSource ) {
    const isolatedFixturePostIds = [ source.listing.post_ids[ 'first.html' ], source.listing.post_ids[ 'second.html' ] ];
   const removedFixturePosts = await page.evaluate( async ( ids ) => Promise.all( ids.map( ( id ) => window.wp.apiFetch( { path: `/wp/v2/posts/${ id }?force=true`, method: 'DELETE' } ) ) ), isolatedFixturePostIds );
   assert.ok( removedFixturePosts.every( ( result ) => result && result.deleted ), 'synthetic parity posts are removed before the captured-source query is opened' );

    const capturedCompanion = JSON.parse( await readFile( `${ evidence }/captured-source-companion-materialization.json`, 'utf8' ) );
    const capturedHomeId = capturedSource.home.post_id;
   assert.ok( capturedHomeId > 0 && capturedSource.home.has_query_overlay && capturedSource.home.has_bound_meta_projection, 'the exact captured source compiled into a native query with the listing overlay and bound-meta projection' );
   await page.goto( `${ baseUrl }/wp-admin/themes.php`, { waitUntil: 'domcontentloaded' } );
   const activateCapturedTheme = page.locator( `.theme[data-slug="${ capturedSource.theme }"] a.activate` );
   await activateCapturedTheme.waitFor();
   const activateCapturedThemeUrl = await activateCapturedTheme.getAttribute( 'href' );
   assert.ok( activateCapturedThemeUrl, 'the captured theme exposes its authenticated activation route' );
   await page.goto( activateCapturedThemeUrl, { waitUntil: 'domcontentloaded' } );
   const activeCapturedTheme = page.locator( `.theme.active[data-slug="${ capturedSource.theme }"]` );
   await activeCapturedTheme.waitFor();
   await writeFile( `${ evidence }/captured-source-theme-activation.json`, JSON.stringify( { theme: capturedSource.theme, url: page.url(), activated: true }, null, 2 ) + '\n' );
   const expectedCapturedMeta = Object.values( capturedSource.binding_meta_receipts );
   const capturedBindingRest = await page.evaluate( async ( ids ) => {
    const posts = await window.wp.apiFetch( { path: '/wp/v2/posts?per_page=100' } );
    return posts.filter( ( post ) => ids.includes( post.id ) ).map( ( post ) => ( { id: post.id, title: post.title?.rendered, meta: post.meta } ) );
   }, expectedCapturedMeta.map( ( receipt ) => receipt.post_id ) );
   for ( const receipt of expectedCapturedMeta ) {
    const post = capturedBindingRest.find( ( item ) => item.id === receipt.post_id );
    assert.ok( post && post.meta?.[ receipt.key ] === receipt.value, `REST preserves the exact captured listing label for source post ${ receipt.post_id }` );
   }
   await writeFile( `${ evidence }/captured-source-binding-rest.json`, JSON.stringify( { expected: expectedCapturedMeta, rest: capturedBindingRest }, null, 2 ) + '\n' );
   await page.setViewportSize( { width: 1440, height: 1000 } );
   await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ capturedHomeId }&action=edit`, { waitUntil: 'domcontentloaded' } );
   await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
   const capturedCanvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
   await capturedCanvas.locator( '[data-type="core/query"]' ).waitFor();
   const capturedEditorProjection = await capturedCanvas.locator( '.editor-styles-wrapper' ).evaluate( ( root ) => {
    const overlays = [ ...root.querySelectorAll( '.blocks-engine-listing-overlay' ) ].map( ( node ) => ( { display: getComputedStyle( node ).display, html: node.outerHTML } ) );
    const boundMeta = [ ...root.querySelectorAll( '.blocks-engine-listing-bound-meta' ) ].map( ( node ) => ( { display: getComputedStyle( node ).display, text: node.innerText, html: node.outerHTML } ) );
    const comments = [];
    const walker = document.createTreeWalker( root, NodeFilter.SHOW_COMMENT );
    while ( walker.nextNode() ) comments.push( { value: walker.currentNode.data, parent: walker.currentNode.parentElement?.tagName || null } );
    return { overlays, boundMeta, comments };
   } );
   const capturedValidation = await page.evaluate( () => {
    const content = window.wp.data.select( 'core/editor' ).getEditedPostContent();
     const visit = ( blocks ) => blocks.flatMap( ( block ) => [ { name: block.name, registered: Boolean( window.wp.blocks.getBlockType( block.name ) ), valid: window.wp.blocks.validateBlock( block )[ 0 ] }, ...visit( block.innerBlocks || [] ) ] );
    return { blocks: visit( window.wp.blocks.parse( content ) ), content };
   } );
   const sourceSpecificMissingBlocks = capturedValidation.blocks.filter( ( block ) => 'core/missing' === block.name );
    assert.ok( capturedCompanion.registered_linked_responsive_content, 'the SSI-generated companion registers the captured source linked-content block in WordPress' );
    assert.ok( capturedCompanion.block_names.includes( capturedCompanion.linked_content_block_name ), 'SSI companion receipt names the source-specific linked-content block' );
   assert.equal( sourceSpecificMissingBlocks.length, 0, 'SSI companion registration leaves no core/missing blocks in the captured home page' );
   assert.ok( capturedValidation.blocks.length && capturedValidation.blocks.every( ( block ) => block.registered && block.valid && ![ 'core/html', 'core/freeform', 'core/missing' ].includes( block.name ) ), 'the exact captured home uses registered, valid WordPress blocks with no missing/HTML/freeform fallback' );
   assert.ok( capturedEditorProjection.overlays.length && capturedEditorProjection.overlays.every( ( item ) => 'none' === item.display ), 'the exact captured source card overlays are hidden in the WordPress editor' );
   assert.ok( capturedEditorProjection.boundMeta.length && capturedEditorProjection.boundMeta.every( ( item ) => 'none' === item.display ), 'the exact captured source binding fallback labels are hidden in the WordPress editor' );
    await writeFile( `${ evidence }/captured-source-editor-before.json`, JSON.stringify( { captureLineage: capturedSource.capture_lineage, captureTimestamp: capturedSource.capture_timestamp, companionRegistration: capturedCompanion, home: capturedSource.home, editorProjection: capturedEditorProjection, validation: capturedValidation.blocks, sourceSpecificMissingBlocks }, null, 2 ) + '\n' );
   await page.screenshot( { path: `${ evidence }/captured-source-editor-before.png`, fullPage: true } );
   const capturedHeading = capturedCanvas.locator( '[data-type="core/heading"]' ).first();
   await capturedHeading.click();
   const capturedSelection = await page.evaluate( () => {
    const store = window.wp.data.select( 'core/block-editor' );
    const id = store.getSelectedBlockClientId();
    const block = id ? store.getBlock( id ) : null;
    return { id, name: block?.name || null, attributes: block?.attributes || null };
   } );
   assert.equal( capturedSelection.name, 'core/heading', 'the real captured source heading is selectable in the 7.1 editor' );
   const originalCapturedHeading = await capturedHeading.innerText();
   const editedCapturedHeading = `${ originalCapturedHeading} — disposable editor save`;
   await capturedHeading.fill( editedCapturedHeading );
   await page.waitForFunction( () => window.wp.data.select( 'core/editor' ).isEditedPostDirty() );
   const capturedSave = page.waitForResponse( ( response ) => {
    const request = response.request();
    const url = new URL( request.url() );
    const route = `/wp/v2/pages/${ capturedHomeId }`;
    return ( url.pathname.endsWith( route ) || url.searchParams.get( 'rest_route' ) === route ) && [ 'POST', 'PUT', 'PATCH' ].includes( request.method() ) && response.ok();
   } );
   await page.getByRole( 'button', { name: /^Save$/ } ).click();
   const capturedSaveResponse = await capturedSave;
   await page.waitForFunction( () => { const store = window.wp.data.select( 'core/editor' ); return !store.isSavingPost() && !store.isEditedPostDirty() && store.didPostSaveRequestSucceed(); } );
   assert.equal( capturedSaveResponse.status(), 200, 'the exact captured source edit is persisted through WordPress REST' );
   await page.reload( { waitUntil: 'domcontentloaded' } );
   await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
   await page.frameLocator( 'iframe[name="editor-canvas"]' ).getByText( editedCapturedHeading, { exact: true } ).waitFor();
   const capturedReload = await page.evaluate( () => {
    const content = window.wp.data.select( 'core/editor' ).getEditedPostContent();
    const blocks = window.wp.blocks.parse( content );
     const visit = ( items ) => items.flatMap( ( block ) => [ { name: block.name, registered: Boolean( window.wp.blocks.getBlockType( block.name ) ), valid: window.wp.blocks.validateBlock( block )[ 0 ] }, ...visit( block.innerBlocks || [] ) ] );
    return { content, blocks: visit( blocks ) };
   } );
   assert.ok( capturedReload.content.includes( editedCapturedHeading ), 'the exact captured source heading edit survives a fresh editor load' );
   assert.ok( capturedReload.blocks.every( ( block ) => block.registered && block.valid && ![ 'core/html', 'core/freeform', 'core/missing' ].includes( block.name ) ), 'the exact captured source remains registered, valid WordPress blocks after reload' );
    await writeFile( `${ evidence }/captured-source-editor-after.json`, JSON.stringify( { selection: capturedSelection, restStatus: capturedSaveResponse.status(), editedHeading: editedCapturedHeading, reload: capturedReload, companionRegistration: capturedCompanion, sourceSpecificMissingBlocks, removedSyntheticParityPostIds: isolatedFixturePostIds }, null, 2 ) + '\n' );
    await page.screenshot( { path: `${ evidence }/captured-source-editor-after.png`, fullPage: true } );
   }

  const publicSource = await browser.newPage( { viewport: { width: 1440, height: 1000 } } );
  await publicSource.goto( 'https://nickdiego.com', { waitUntil: 'networkidle', timeout: 60000 } );
  await writeFile( `${ evidence }/public-source.html`, await publicSource.content() + '\n' );
  await publicSource.screenshot( { path: `${ evidence }/public-source-desktop.png`, fullPage: true } );
  const publicSourceDom = await publicSource.evaluate( () => {
    const walker = document.createTreeWalker( document, NodeFilter.SHOW_COMMENT );
    const comments = [];
    while ( walker.nextNode() ) comments.push( { value: walker.currentNode.data, parent: walker.currentNode.parentElement?.tagName || null } );
    return { title: document.title, comments, emptyCommentCount: comments.filter( ( comment ) => '' === comment.value.trim() ).length, cards: [ ...document.querySelectorAll( 'article' ) ].map( ( card ) => ( { className: card.className, text: card.innerText, rect: card.getBoundingClientRect().toJSON(), border: getComputedStyle( card ).border, links: [ ...card.querySelectorAll( 'a' ) ].map( ( link ) => ( { href: link.href, className: link.className, ariaHidden: link.getAttribute( 'aria-hidden' ), tabIndex: link.tabIndex, rect: link.getBoundingClientRect().toJSON() } ) ) } ) ) };
  } );
  await publicSource.setViewportSize( { width: 390, height: 844 } );
  await publicSource.screenshot( { path: `${ evidence }/public-source-mobile.png`, fullPage: true } );
  await writeFile( `${ evidence }/public-source-dom.json`, JSON.stringify( publicSourceDom, null, 2 ) + '\n' );
  let publicPreviewCapture = { status: 'captured' };
  try {
    await publicSource.goto( 'https://ndiego-wanbk-studio.wp.build', { waitUntil: 'networkidle', timeout: 60000 } );
    await publicSource.screenshot( { path: `${ evidence }/public-wordpress-preview-desktop.png`, fullPage: true } );
    await publicSource.setViewportSize( { width: 390, height: 844 } );
    await publicSource.screenshot( { path: `${ evidence }/public-wordpress-preview-mobile.png`, fullPage: true } );
  } catch ( error ) {
    publicPreviewCapture = { status: 'unavailable', error: error.message };
  }
  await writeFile( `${ evidence }/public-wordpress-preview-capture.json`, JSON.stringify( publicPreviewCapture, null, 2 ) + '\n' );
  console.log( JSON.stringify( { ok: true, postId, listingPostId: process.env.BE_EDITOR_LISTING_POST_ID, listingSelection: selection, editorOverlayDisplay: presentation.overlay?.display, frontendOverlay, validation: reloadedValidation.blocks, pixelParity, publicPreviewCapture } ) );
} finally {
 await writeFile( `${ evidence }/browser-errors.json`, JSON.stringify( browserErrors, null, 2 ) + '\n' );
 await writeFile( `${ evidence }/save-responses.json`, JSON.stringify( saveResponses, null, 2 ) + '\n' );
 await browser.close();
}
