import { mkdirSync, mkdtempSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const cli = process.env.WP_CODEBOX_CLI ?? '/home/chubes/.local/bin/wp-codebox';
const evidenceRoot = process.env.BLOCKS_ENGINE_TAXONOMY_HTTP_EVIDENCE;
if ( ! evidenceRoot ) throw new Error( 'Set BLOCKS_ENGINE_TAXONOMY_HTTP_EVIDENCE to a durable evidence directory.' );
if ( ! statSync( join( root, 'src/WordPressSitePlan/TaxonomyProjection.php' ), { throwIfNoEntry: false } )?.isFile() ) {
	throw new Error( 'Runner must execute from the producer php-transformer source tree.' );
}

const setupPhp = `
require_once '/wordpress/wp-content/plugins/blocks-engine-candidate/php-transformer.php';
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$active_plugins = get_option( 'active_plugins', array() );
$ssi_loaded      = class_exists( 'Static_Site_Importer_WordPress_Site_Plan_Materializer', false );
$assert( ! $ssi_loaded && ! in_array( 'static-site-importer/static-site-importer.php', $active_plugins, true ), 'The HTTP workload must run with SSI absent.' );
$files = array( 'index.html' => '<main><h1>Archive HTTP fixture</h1></main>' );
$archive_cards = '';
foreach ( range( 11, 1 ) as $index ) {
	$archive_cards .= '<article><h2><a href="/stories/field-note-' . $index . '">Field note ' . $index . '</a></h2><p>Summary ' . $index . '.</p></article>';
}
$files[] = array(
	'path'     => 'archives/field-notes.html',
	'content'  => '<main><h1>Field Notes</h1>' . $archive_cards . '</main>',
	'metadata' => array( 'route_path' => '/journal/category/field-notes' ),
);
foreach ( range( 1, 11 ) as $index ) {
	$files[] = array(
		'path'     => 'stories/field-note-' . $index . '.html',
		'content'  => '<article><h1>Field note ' . $index . '</h1><p>Full source article.</p><a href="/journal/category/field-notes">Field Notes</a></article>',
		'metadata' => array( 'post_type' => 'post' ),
	);
}
$artifact = array( 'entrypoint' => 'index.html', 'files' => $files );
$plan = ( new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler() )->compile( $artifact )->toArray()['source_reports']['wordpress_site_plan'];
$assert( 1 === count( $plan['taxonomy_entities'] ?? array() ), 'The captured archive must yield one corroborated taxonomy entity.' );
$theme = 'blocks-engine-taxonomy-http';
$theme_dir = get_theme_root() . '/' . $theme;
if ( ! wp_mkdir_p( $theme_dir ) ) throw new RuntimeException( 'Could not create the producer-only test theme.' );
$resolved = ( new Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => home_url( '/wp-content/themes/' . $theme ) ) );
foreach ( $resolved['writes'] as $write ) {
	$path = $theme_dir . '/' . $write['target_path'];
	if ( ! wp_mkdir_p( dirname( $path ) ) ) throw new RuntimeException( 'Could not create a generated theme write directory.' );
	$data = 'base64' === ( $write['payload']['encoding'] ?? '' ) ? base64_decode( $write['payload']['data'], true ) : ( $write['payload']['data'] ?? null );
	if ( ! is_string( $data ) || false === file_put_contents( $path, $data ) ) throw new RuntimeException( 'Could not write a generated theme artifact.' );
}
$term = wp_insert_term( 'Field Notes', 'category', array( 'slug' => 'field-notes' ) );
if ( is_wp_error( $term ) ) throw new RuntimeException( $term->get_error_message() );
$term_id = (int) $term['term_id'];
foreach ( range( 1, 11 ) as $index ) {
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Field note ' . $index,
			'post_name'   => 'field-note-' . $index,
			'post_date'   => sprintf( '2025-01-%02d 12:00:00', $index ),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) throw new RuntimeException( $post_id->get_error_message() );
	wp_set_object_terms( (int) $post_id, array( $term_id ), 'category', false );
}
$other_term = wp_insert_term( 'Other', 'category', array( 'slug' => 'other' ) );
if ( is_wp_error( $other_term ) ) throw new RuntimeException( $other_term->get_error_message() );
$other_post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Outside archive', 'post_name' => 'outside-archive' ), true );
if ( is_wp_error( $other_post ) ) throw new RuntimeException( $other_post->get_error_message() );
wp_set_object_terms( (int) $other_post, array( (int) $other_term['term_id'] ), 'category', false );
update_option( 'posts_per_page', 10 );
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
flush_rewrite_rules( false );
switch_theme( $theme );
wp_clean_themes_cache();
delete_option( 'rewrite_rules' );
echo wp_json_encode(
	array(
		'schema'             => 'blocks-engine/taxonomy-http-fixture/v1',
		'theme'              => $theme,
		'source_route'       => '/journal/category/field-notes/',
		'posts_per_page'     => 10,
		'post_count'         => 11,
		'ssi_active'         => in_array( 'static-site-importer/static-site-importer.php', $active_plugins, true ),
		'ssi_materializer_loaded' => class_exists( 'Static_Site_Importer_WordPress_Site_Plan_Materializer', false ),
		'persisted_rewrite_rules_cleared' => false === get_option( 'rewrite_rules', false ),
	)
) . "\\n";
`;

const traversalScript = `window.__blocksEngineTaxonomyHttp = (async () => {
const proof = { basePath: window.location.pathname, ssiGlobalsAbsent: !window.Static_Site_Importer_WordPress_Site_Plan_Materializer };
try {
  const next = document.querySelector('.wp-block-query-pagination-next');
  if (!next) throw new Error('The base archive has no native Next Page link.');
  proof.nextHrefPath = new URL(next.href, window.location.href).pathname;
  const nextResponse = await fetch(next.href, { credentials: 'same-origin' });
  const nextHtml = await nextResponse.text();
  const nextDocument = new DOMParser().parseFromString(nextHtml, 'text/html');
  proof.nextStatus = nextResponse.status;
  proof.nextFinalPath = new URL(nextResponse.url).pathname;
  const titles = Array.from(nextDocument.querySelectorAll('.wp-block-post-title a'), link => link.textContent.trim());
  proof.nextPageHasOldestMember = titles.includes('Field note 1');
  proof.nextPageExcludesNewestMember = !titles.includes('Field note 11');
  proof.nextPageExcludesUnrelatedPost = !nextDocument.body.textContent.includes('Outside archive');
  const previous = nextDocument.querySelector('.wp-block-query-pagination-previous');
  if (!previous) throw new Error('The actual page-2 HTTP response has no native Previous Page link.');
  proof.previousHrefPath = new URL(previous.href, nextResponse.url).pathname;
  proof.pageTwoHasNext = Boolean(nextDocument.querySelector('.wp-block-query-pagination-next'));
  const previousResponse = await fetch(previous.href, { credentials: 'same-origin' });
  const previousHtml = await previousResponse.text();
  const previousDocument = new DOMParser().parseFromString(previousHtml, 'text/html');
  proof.previousStatus = previousResponse.status;
  proof.previousFinalPath = new URL(previousResponse.url).pathname;
  const previousTitles = Array.from(previousDocument.querySelectorAll('.wp-block-post-title a'), link => link.textContent.trim());
  proof.previousReturnsToFirstPage = previousTitles.includes('Field note 11') && !previousTitles.includes('Field note 1');
  proof.previousExcludesUnrelatedPost = !previousDocument.body.textContent.includes('Outside archive');
  proof.success = '/journal/category/field-notes/' === proof.basePath
    && '/journal/category/field-notes/page/2/' === proof.nextHrefPath
    && '/journal/category/field-notes/page/2/' === proof.nextFinalPath
    && 200 === proof.nextStatus
    && proof.nextPageHasOldestMember
    && proof.nextPageExcludesNewestMember
    && proof.nextPageExcludesUnrelatedPost
    && '/journal/category/field-notes/' === proof.previousHrefPath
    && !proof.pageTwoHasNext
    && '/journal/category/field-notes/' === proof.previousFinalPath
    && 200 === proof.previousStatus
    && proof.previousReturnsToFirstPage
    && proof.previousExcludesUnrelatedPost
    && proof.ssiGlobalsAbsent;
} catch (error) {
  proof.error = error.message;
  proof.success = false;
}
document.title = 'BLOCKS-ENGINE-TAXONOMY-HTTP-PROOF:' + encodeURIComponent(JSON.stringify(proof));
return proof;
})();`;

const workload = {
	schema: 'wp-codebox/wordpress-workload-run/v1',
	wordpress_version: process.env.BLOCKS_ENGINE_TAXONOMY_HTTP_WORDPRESS_VERSION ?? '7.1',
	blueprint: { steps: [ { step: 'defineWpConfigConsts', consts: { BLOCKS_ENGINE_TAXONOMY_HTTP_TEST: true } } ] },
	mounts: [ { source: root, target: '/wordpress/wp-content/plugins/blocks-engine-candidate', mode: 'readonly' } ],
	steps: [
		{ command: 'wordpress.run-php', args: [ `code=${ setupPhp }` ] },
		{
			command: 'wordpress.browser-page-load',
			args: [ 'url=/journal/category/field-notes/', 'wait-for=domcontentloaded', `script=${ traversalScript }`, 'capture=html,console,errors,network,screenshot', 'duration=3s', 'timeout=120s', 'network-policy=block' ],
		},
	],
};

mkdirSync( evidenceRoot, { recursive: true } );
const sessionDir = mkdtempSync( join( tmpdir(), 'blocks-engine-taxonomy-http-' ) );
const workloadPath = join( sessionDir, 'workload.json' );
writeFileSync( workloadPath, JSON.stringify( workload, null, 2 ) );
writeFileSync( join( evidenceRoot, 'workload.json' ), JSON.stringify( workload, null, 2 ) );
const startedAt = Date.now();
const command = spawnSync( cli, [ 'run-wordpress-workload', '--input-file', workloadPath, '--artifacts', join( evidenceRoot, 'artifacts' ), '--format=json' ], {
	encoding: 'utf8',
	maxBuffer: 64 * 1024 * 1024,
	timeout: 20 * 60 * 1000,
} );
if ( command.error ) throw command.error;
let result;
try {
	result = JSON.parse( command.stdout );
} catch ( error ) {
	writeFileSync( join( evidenceRoot, 'stdout.log' ), command.stdout ?? '' );
	writeFileSync( join( evidenceRoot, 'stderr.log' ), command.stderr ?? '' );
	throw new Error( `WP Codebox returned non-JSON output: ${ error.message }` );
}
writeFileSync( join( evidenceRoot, 'result.json' ), JSON.stringify( result, null, 2 ) );
writeFileSync( join( evidenceRoot, 'stdout.log' ), command.stdout ?? '' );
writeFileSync( join( evidenceRoot, 'stderr.log' ), command.stderr ?? '' );
const setupStep = ( result.executions ?? [] ).find( step => 'wordpress.run-php' === step.command );
const browserStep = ( result.executions ?? [] ).find( step => 'wordpress.browser-page-load' === step.command );
let setup;
let browser;
try {
	setup = JSON.parse( String( setupStep?.stdout ?? '' ).split( '\n' ).find( line => line.includes( 'taxonomy-http-fixture' ) ) ?? '{}' );
} catch {
	setup = {};
}
try {
	browser = JSON.parse( browserStep?.stdout ?? '{}' );
} catch {
	browser = {};
}
const snapshots = [];
const visit = directory => {
	for ( const entry of readdirSync( directory, { withFileTypes: true } ) ) {
		const path = join( directory, entry.name );
		if ( entry.isDirectory() ) visit( path );
		else if ( 'snapshot.html' === entry.name && path.includes( '/files/browser/' ) ) snapshots.push( path );
	}
};
const artifactRoot = join( evidenceRoot, 'artifacts' );
if ( statSync( artifactRoot, { throwIfNoEntry: false } )?.isDirectory() ) visit( artifactRoot );
const currentSnapshots = snapshots.filter( path => statSync( path ).mtimeMs >= startedAt );
const snapshotPath = currentSnapshots.sort( ( left, right ) => statSync( right ).mtimeMs - statSync( left ).mtimeMs )[0] ?? '';
const snapshot = snapshotPath ? readFileSync( snapshotPath, 'utf8' ) : '';
let traversal;
try {
	const encoded = /<title>BLOCKS-ENGINE-TAXONOMY-HTTP-PROOF:([^<]+)<\/title>/.exec( snapshot )?.[1] ?? '';
	traversal = JSON.parse( decodeURIComponent( encoded ) );
} catch {
	traversal = {};
}
const assertions = {
	setupSucceeded: setupStep?.exitCode === 0 && setup.schema === 'blocks-engine/taxonomy-http-fixture/v1',
	ssiWasAbsent: setup.ssi_active === false && setup.ssi_materializer_loaded === false,
	baseRouteWasAnActualHttpRequest: browserStep?.exitCode === 0 && '/journal/category/field-notes/' === new URL( browser.finalUrl ?? 'http://invalid/' ).pathname,
	pageTwoWasFetchedFromNextLink: '/journal/category/field-notes/page/2/' === traversal.nextHrefPath && '/journal/category/field-notes/page/2/' === traversal.nextFinalPath && 200 === traversal.nextStatus,
	pageTwoContainsOnlyTheRemainingNativeMember: traversal.nextPageHasOldestMember === true && traversal.nextPageExcludesNewestMember === true && traversal.nextPageExcludesUnrelatedPost === true,
	previousLinkWasFetchedBackToBase: '/journal/category/field-notes/' === traversal.previousHrefPath && '/journal/category/field-notes/' === traversal.previousFinalPath && 200 === traversal.previousStatus,
	lastPageDoesNotRenderNext: traversal.pageTwoHasNext === false,
	returnRequestRendersFirstPage: traversal.previousReturnsToFirstPage === true && traversal.previousExcludesUnrelatedPost === true,
	noBrowserErrors: 0 === ( browser.summary?.errors ?? -1 ),
};
const success = result.success === true && command.status === 0 && setupStep?.exitCode === 0 && browserStep?.exitCode === 0 && traversal.success === true && Object.values( assertions ).every( Boolean );
const summary = { success, wordpressVersion: workload.wordpress_version, wpCodebox: spawnSync( cli, [ 'version' ], { encoding: 'utf8' } ).stdout.trim(), evidenceRoot, setup, traversal, assertions, snapshot: snapshotPath, failure: traversal.error ?? result.result?.failure_summary ?? null };
writeFileSync( join( evidenceRoot, 'browser-assertions.json' ), JSON.stringify( summary, null, 2 ) );
console.log( JSON.stringify( summary, null, 2 ) );
if ( ! success ) process.exitCode = 1;
