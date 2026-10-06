#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from './visual-parity/node_modules/playwright/index.mjs';

const evidence = process.env.BE_EDITOR_EVIDENCE_DIR;
if ( ! evidence ) throw new Error( 'Set BE_EDITOR_EVIDENCE_DIR to a directory outside the repository.' );
await mkdir( evidence, { recursive: true } );
const browser = await chromium.launch( { headless: true } );
const context = await browser.newContext( { recordHar: { path: `${ evidence }/source-capture.har.zip`, mode: 'full', content: 'embed' } } );
const page = await context.newPage();
const pages = {};
const resources = {};
const sourceUrls = [];
const sha256 = ( bytes ) => createHash( 'sha256' ).update( bytes ).digest( 'hex' );

try {
	await page.goto( 'https://nickdiego.com', { waitUntil: 'networkidle', timeout: 60000 } );
	const articleUrls = await page.locator( 'article a[href]' ).evaluateAll( ( links ) => [ ...new Set( links.map( ( link ) => link.href ).filter( ( href ) => {
		const url = new URL( href );
		return url.hostname === 'nickdiego.com' && url.pathname.split( '/' ).filter( Boolean ).length === 1;
	} ) ) ].slice( 0, 3 ) );
	if ( articleUrls.length < 2 ) throw new Error( `Expected at least two captured article routes; found ${ articleUrls.length }.` );
	const rootHtml = await page.content();
	pages[ 'index.html' ] = rootHtml;
	sourceUrls.push( { path: 'index.html', url: page.url(), sha256: sha256( Buffer.from( rootHtml ) ) } );
	const resourceUrls = await page.locator( 'link[rel="stylesheet"],link[rel="icon"],link[rel="apple-touch-icon"],link[as="font"]' ).evaluateAll( ( links ) => [ ...new Set( links.map( ( link ) => link.href ).filter( ( href ) => href.startsWith( 'https://nickdiego.com/' ) ) ) ] );
	const stylesheetUrls = resourceUrls.filter( ( url ) => new URL( url ).pathname.endsWith( '.css' ) );
	for ( const url of stylesheetUrls ) {
		const response = await context.request.get( url );
		if ( ! response.ok() ) throw new Error( `Could not capture source stylesheet ${ url }: HTTP ${ response.status() }` );
		const bytes = await response.body();
		const path = new URL( url ).pathname.replace( /^\/+/, '' );
		resources[ path ] = bytes.toString( 'base64' );
		for ( const match of bytes.toString().matchAll( /url\(([^)]+)\)/g ) ) {
			const reference = match[ 1 ].trim().replace( /^['"]|['"]$/g, '' );
			if ( reference.startsWith( 'data:' ) ) continue;
			const assetUrl = new URL( reference, url );
			if ( assetUrl.hostname !== 'nickdiego.com' ) continue;
			const assetResponse = await context.request.get( assetUrl.toString() );
			if ( ! assetResponse.ok() ) throw new Error( `Could not capture source stylesheet asset ${ assetUrl }: HTTP ${ assetResponse.status() }` );
			resources[ assetUrl.pathname.replace( /^\/+/, '' ) ] = ( await assetResponse.body() ).toString( 'base64' );
		}
	}
	for ( const url of resourceUrls.filter( ( value ) => ! stylesheetUrls.includes( value ) ) ) {
		const response = await context.request.get( url );
		if ( ! response.ok() ) throw new Error( `Could not capture source asset ${ url }: HTTP ${ response.status() }` );
		resources[ new URL( url ).pathname.replace( /^\/+/, '' ) ] = ( await response.body() ).toString( 'base64' );
	}
	for ( const url of articleUrls ) {
		await page.goto( url, { waitUntil: 'networkidle', timeout: 60000 } );
		const html = await page.content();
		const slug = new URL( url ).pathname.split( '/' ).filter( Boolean ).at( -1 );
		const path = `${ slug }/index.html`;
		pages[ path ] = html;
		sourceUrls.push( { path, url: page.url(), sha256: sha256( Buffer.from( html ) ) } );
	}
	const archive = {
		captured_at: new Date().toISOString(),
		entrypoint: 'index.html',
		source_origin: 'https://nickdiego.com',
		pages,
		resources,
		lineage: {
			capture_method: 'Playwright page.content() after DOM hydration; original source comments are retained in the captured HTML.',
			har: 'source-capture.har.zip',
			pages: sourceUrls,
			resources: Object.entries( resources ).map( ( [ path, base64 ] ) => ( { path, sha256: sha256( Buffer.from( base64, 'base64' ) ), bytes: Buffer.from( base64, 'base64' ).byteLength } ) ),
		},
	};
	await writeFile( `${ evidence }/source-capture.json`, JSON.stringify( archive, null, 2 ) + '\n' );
	console.log( JSON.stringify( { ok: true, captured_pages: sourceUrls, resource_count: Object.keys( resources ).length, har: `${ evidence }/source-capture.har.zip` } ) );
} finally {
	await context.close();
	await browser.close();
}
