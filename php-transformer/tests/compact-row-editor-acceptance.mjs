#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '../tools/visual-parity/node_modules/playwright/index.mjs';

const names = [ 'BE_EDITOR_WP_URL', 'BE_EDITOR_USER', 'BE_EDITOR_PASSWORD', 'BE_EDITOR_EVIDENCE_DIR', 'BE_EDITOR_COMPACT_POST_ID' ];
for ( const name of names ) assert.ok( process.env[ name ], `Missing ${ name }` );
const baseUrl = process.env.BE_EDITOR_WP_URL.replace( /\/$/, '' );
const postId = process.env.BE_EDITOR_COMPACT_POST_ID;
const evidence = process.env.BE_EDITOR_EVIDENCE_DIR;
const source = JSON.parse( await readFile( `${ evidence }/source-and-page.json`, 'utf8' ) );
const browser = await chromium.launch( { headless: true } );
const page = await browser.newPage( { viewport: { width: 1440, height: 1000 } } );
const saveResponses = [];
const errors = [];
page.on( 'pageerror', ( error ) => errors.push( error.message ) );
page.on( 'response', ( response ) => {
	if ( [ 'POST', 'PUT', 'PATCH' ].includes( response.request().method() ) ) {
		saveResponses.push( { status: response.status(), url: response.url() } );
	}
} );
const blocks = () => page.evaluate( () => {
	const visit = ( children ) => children.flatMap( ( block ) => [ block, ...visit( block.innerBlocks || [] ) ] );
	return visit( window.wp.data.select( 'core/block-editor' ).getBlocks() ).map( ( block ) => ( { clientId: block.clientId, name: block.name, attributes: block.attributes } ) );
} );
try {
	await page.goto( `${ baseUrl }/wp-login.php`, { waitUntil: 'networkidle' } );
	await page.getByLabel( 'Username or Email Address' ).fill( process.env.BE_EDITOR_USER );
	await page.getByRole( 'textbox', { name: 'Password' } ).fill( process.env.BE_EDITOR_PASSWORD );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ postId }&action=edit`, { waitUntil: 'domcontentloaded' } );
	await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
	const welcome = page.locator( '.components-modal__screen-overlay' );
	if ( await welcome.isVisible() ) {
		await welcome.getByRole( 'button', { name: /Close|Get started/ } ).first().click();
		await welcome.waitFor( { state: 'hidden' } );
	}
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.locator( '[data-type="core/image"]' ).waitFor();
	const initial = await blocks();
	const image = initial.find( ( block ) => block.name === 'core/image' );
	const paragraph = initial.find( ( block ) => block.name === 'core/paragraph' );
	assert.equal( image.attributes.id, source.first_attachment.id, 'the compact row exposes a replaceable real media block' );
	assert.equal( image.attributes.alt, '', 'the decorative source icon remains hidden from assistive technology' );
	assert.equal( paragraph.attributes.content.toString(), '<a href="/projects">247</a>', 'the compact row exposes linked RichText separately' );
	await canvas.locator( '[data-type="core/image"]' ).click();
	await page.getByRole( 'button', { name: 'Replace' } ).click();
	await page.getByRole( 'menuitem', { name: /Open Media Library/ } ).click();
	const media = page.locator( '.media-modal' );
	await media.waitFor();
	await media.getByText( 'Media Library', { exact: true } ).click();
	await media.locator( '[aria-label*="BE editor second"]' ).waitFor();
	await media.locator( '[aria-label*="BE editor second"]' ).click();
	await media.getByRole( 'button', { name: 'Select', exact: true } ).click();
	await page.waitForFunction( ( id ) => {
		const visit = ( children ) => children.flatMap( ( block ) => [ block, ...visit( block.innerBlocks || [] ) ] );
		return visit( window.wp.data.select( 'core/block-editor' ).getBlocks() ).some( ( block ) => block.name === 'core/image' && block.attributes.id === id );
	}, source.second_attachment.id );
	await page.evaluate( ( { id, href } ) => {
		window.wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( id, { href, linkDestination: 'custom', link: '' } );
	}, { id: image.clientId, href: '/projects-updated' } );
	await page.evaluate( ( id ) => {
		window.wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( id, { content: '<a href="/projects-updated">248</a>' } );
	}, paragraph.clientId );
	await page.waitForFunction( ( clientId ) => window.wp.data.select( 'core/block-editor' ).getBlock( clientId )?.attributes.content?.toString() === '<a href="/projects-updated">248</a>', paragraph.clientId );
	const edited = await blocks();
	const editedImage = edited.find( ( block ) => block.name === 'core/image' );
	const editedParagraph = edited.find( ( block ) => block.name === 'core/paragraph' );
	assert.equal( editedImage.attributes.id, source.second_attachment.id, 'icon replacement commits in Gutenberg' );
	assert.equal( editedImage.attributes.href, '/projects-updated', 'the replaced icon remains part of the edited linked row' );
	assert.equal( editedParagraph.attributes.content.toString(), '<a href="/projects-updated">248</a>', 'text and link edits commit in Gutenberg RichText' );
	const preSaveState = await page.evaluate( () => ( { dirty: window.wp.data.select( 'core/editor' ).isEditedPostDirty(), buttons: Array.from( document.querySelectorAll( 'button' ) ).map( ( button ) => ( { label: button.getAttribute( 'aria-label' ), text: button.textContent, classes: button.className } ) ).filter( ( button ) => /save|update/i.test( `${ button.label || '' } ${ button.text || '' }` ) ) } ) );
	await writeFile( `${ evidence }/compact-row-pre-save.json`, JSON.stringify( preSaveState, null, 2 ) + '\n' );
	assert.ok( preSaveState.dirty, 'Gutenberg recognizes row edits as unsaved changes' );
	const save = page.waitForResponse( ( response ) => response.ok() && [ 'POST', 'PUT', 'PATCH' ].includes( response.request().method() ) );
	await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).savePost() );
	await save;
	await page.waitForFunction( () => !window.wp.data.select( 'core/editor' ).isSavingPost() && !window.wp.data.select( 'core/editor' ).isEditedPostDirty() );
	assert.ok( saveResponses.some( ( response ) => response.status >= 200 && response.status < 300 ), 'Gutenberg saves the edited post through its REST endpoint' );
	const saved = await page.evaluate( () => window.wp.data.select( 'core/editor' ).getEditedPostContent() );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.locator( 'iframe[name="editor-canvas"]' ).waitFor();
	const reloadedBlocks = await blocks();
	const reloadedImage = reloadedBlocks.find( ( block ) => block.name === 'core/image' );
	const reloadedParagraph = reloadedBlocks.find( ( block ) => block.name === 'core/paragraph' );
	assert.equal( reloadedImage.attributes.id, source.second_attachment.id );
	assert.equal( reloadedImage.attributes.alt, '' );
	assert.equal( reloadedImage.attributes.href, '/projects-updated' );
	assert.equal( reloadedParagraph.attributes.content.toString(), '<a href="/projects-updated">248</a>' );
	const validation = await page.evaluate( () => {
		const visit = ( items ) => items.flatMap( ( block ) => [ { name: block.name, valid: window.wp.blocks.validateBlock( block )[ 0 ] }, ...visit( block.innerBlocks || [] ) ] );
		return visit( window.wp.blocks.parse( window.wp.data.select( 'core/editor' ).getEditedPostContent() ) );
	} );
	assert.ok( validation.length >= 3 && validation.every( ( block ) => block.valid ), 'all saved native blocks validate after reload' );
	await page.goto( `${ baseUrl }/?page_id=${ postId }`, { waitUntil: 'networkidle' } );
	assert.equal( await page.locator( '.wp-block-group.project-row' ).count(), 1 );
	assert.equal( await page.locator( '.wp-block-group.project-row p a[href="/projects-updated"]' ).textContent(), '248' );
	assert.equal( await page.locator( '.wp-block-group.project-row figure a[href="/projects-updated"] img' ).getAttribute( 'src' ), source.second_attachment.url );
	const desktopGeometry = await page.locator( '.wp-block-group.project-row' ).evaluate( ( group ) => {
		const image = group.querySelector( 'figure' ).getBoundingClientRect();
		const text = group.querySelector( 'p a' ).getBoundingClientRect();
		return { display: getComputedStyle( group ).display, gap: getComputedStyle( group ).gap, image: { x: image.x, y: image.y, width: image.width, height: image.height }, text: { x: text.x, y: text.y, width: text.width, height: text.height } };
	} );
	await page.locator( '.wp-block-group.project-row' ).hover();
	const hoverColor = await page.locator( '.wp-block-group.project-row' ).evaluate( ( group ) => getComputedStyle( group ).color );
	assert.equal( hoverColor, 'rgb(18, 52, 86)', 'authored hover paint survives selector projection' );
	assert.equal( desktopGeometry.display, 'flex', 'authored inline layout remains a native flex row' );
	assert.ok( desktopGeometry.text.x >= desktopGeometry.image.x + desktopGeometry.image.width, 'icon precedes text in the desktop row' );
	await page.screenshot( { path: `${ evidence }/compact-row-frontend.png`, fullPage: true } );
	await page.setViewportSize( { width: 375, height: 812 } );
	const mobileGeometry = await page.locator( '.wp-block-group.project-row' ).evaluate( ( group ) => {
		const image = group.querySelector( 'figure' ).getBoundingClientRect();
		const text = group.querySelector( 'p a' ).getBoundingClientRect();
		return { display: getComputedStyle( group ).display, image: { x: image.x, y: image.y, width: image.width, height: image.height }, text: { x: text.x, y: text.y, width: text.width, height: text.height } };
	} );
	assert.equal( mobileGeometry.display, 'flex', 'narrow viewport retains the authored row geometry' );
	assert.ok( mobileGeometry.text.x >= mobileGeometry.image.x + mobileGeometry.image.width, 'icon still precedes text on mobile' );
	await page.screenshot( { path: `${ evidence }/compact-row-frontend-mobile.png`, fullPage: true } );
	await writeFile( `${ evidence }/compact-row-edit.json`, JSON.stringify( { postId, initial, edited, reloadedBlocks, saved, validation, saveResponses, frontend: { text: '248', href: '/projects-updated', iconUrl: source.second_attachment.url, desktopGeometry, mobileGeometry }, errors }, null, 2 ) + '\n' );
	assert.deepEqual( errors, [], 'browser has no page errors' );
	console.log( JSON.stringify( { ok: true, postId, validation, saveResponses } ) );
} finally {
	await browser.close();
}
