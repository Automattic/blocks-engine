<?php
/** Import the exact captured Nick Diego source snapshot into the disposable WP 7.1 site. */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

if ( ! isset( $args ) || 2 !== count( $args ) || ! is_readable( (string) $args[0] ) || (int) $args[1] < 1 ) {
	throw new RuntimeException( 'Expected a readable captured source archive JSON and synthetic acceptance home ID.' );
}
require_once WP_PLUGIN_DIR . '/blocks-engine-php-transformer/vendor/autoload.php';

$archive = json_decode( (string) file_get_contents( (string) $args[0] ), true );
if ( ! is_array( $archive ) || ! is_array( $archive['pages'] ?? null ) || ! is_array( $archive['resources'] ?? null ) || ! isset( $archive['pages']['index.html'] ) ) {
	throw new RuntimeException( 'Captured source archive has an invalid pages/resources contract.' );
}

$files = array();
$comment_evidence = array();
$local_resource_paths = array_fill_keys( array_keys( $archive['resources'] ), true );
foreach ( $archive['pages'] as $path => $html ) {
	$document = new DOMDocument();
	$document->preserveWhiteSpace = true;
	@$document->loadHTML( (string) $html, LIBXML_NONET );
	$xpath = new DOMXPath( $document );
	$comments = array();
	foreach ( $xpath->query( '//comment()' ) as $comment ) {
		$comments[] = array(
			'value' => $comment->data,
			'parent' => $comment->parentNode instanceof DOMElement ? $comment->parentNode->tagName : null,
			'in_footer' => 0 < $xpath->query( 'ancestor::footer', $comment )->length,
		);
	}
	$comment_evidence[ (string) $path ] = array(
		'total' => count( $comments ),
		'empty' => count( array_filter( $comments, static fn ( array $comment ): bool => '' === trim( $comment['value'] ) ) ),
		'comments' => $comments,
	);
	foreach ( iterator_to_array( $document->getElementsByTagName( 'script' ) ) as $script ) {
		$script->parentNode?->removeChild( $script );
	}
	foreach ( iterator_to_array( $document->getElementsByTagName( 'next-route-announcer' ) ) as $announcer ) {
		$announcer->parentNode?->removeChild( $announcer );
	}
	foreach ( $xpath->query( '//link[@href]' ) as $link ) {
		$href = $link->getAttribute( 'href' );
		$parsed = parse_url( $href );
		$resource_path = ltrim( (string) ( $parsed['path'] ?? '' ), '/' );
		if ( isset( $local_resource_paths[ $resource_path ] ) ) {
			$link->setAttribute( 'href', $resource_path );
		}
	}
	$files[ (string) $path ] = $document->saveHTML();
	$sanitized_document = new DOMDocument();
	$sanitized_document->preserveWhiteSpace = true;
	@$sanitized_document->loadHTML( $files[ (string) $path ], LIBXML_NONET );
	if ( $xpath->query( '//comment()' )->length !== ( new DOMXPath( $sanitized_document ) )->query( '//comment()' )->length ) {
		throw new RuntimeException( 'Source comment nodes changed during capture sanitization: ' . $path );
	}
	$comment_evidence[ (string) $path ]['preserved_in_compiler_input'] = true;
}
foreach ( $archive['resources'] as $path => $payload ) {
	$bytes = base64_decode( (string) $payload, true );
	if ( false === $bytes ) {
		throw new RuntimeException( 'Captured resource is not valid base64: ' . $path );
	}
	$files[ (string) $path ] = $bytes;
}

$compiled = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => (string) ( $archive['entrypoint'] ?? 'index.html' ),
	'files' => $files,
) )->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'] ?? array();
$pages = $plan['pages'] ?? array();
$home = current( array_filter( $pages, static fn ( array $page ): bool => 'index.html' === ( $page['source_path'] ?? '' ) ) );
if ( ! is_array( $home ) || 'failed' === ( $compiled['status'] ?? 'failed' ) ) {
	throw new RuntimeException( 'Captured source did not produce a usable WordPress site plan: ' . wp_json_encode( $compiled['diagnostics'] ?? array() ) );
}
$home_markup = (string) ( $home['canonical_block_markup'] ?? '' );
foreach ( array( 'wp:query', 'wp:read-more', 'blocks-engine-listing-overlay', 'blocks-engine-listing-bound-meta' ) as $required_role ) {
	if ( ! str_contains( $home_markup, $required_role ) ) {
		throw new RuntimeException( 'Captured source-derived home page is missing expected query editor role: ' . $required_role );
	}
}

$theme = 'blocks-engine-captured-source';
$theme_dir = WP_CONTENT_DIR . '/themes/' . $theme;
$resolved = ( new WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => home_url( '/wp-content/themes/' . $theme ) ) );
if ( ! is_dir( $theme_dir ) && ! wp_mkdir_p( $theme_dir ) ) {
	throw new RuntimeException( 'Could not create the captured source theme directory.' );
}
foreach ( $resolved['writes'] as $write ) {
	$target = $theme_dir . '/' . $write['target_path'];
	if ( ! is_dir( dirname( $target ) ) && ! wp_mkdir_p( dirname( $target ) ) ) {
		throw new RuntimeException( 'Could not create a captured source theme path.' );
	}
	$payload = $write['payload'];
	$bytes = 'base64' === ( $payload['encoding'] ?? null ) ? base64_decode( $payload['data'], true ) : $payload['data'];
	if ( false === file_put_contents( $target, $bytes ) ) {
		throw new RuntimeException( 'Could not write a captured source theme file.' );
	}
}
wp_clean_themes_cache();
if ( ! wp_get_theme( $theme )->exists() ) {
	throw new RuntimeException( 'WordPress did not recognize the captured source theme.' );
}

$post_ids = array();
$captured_post_ids = array();
$home_id = 0;
foreach ( $pages as $page ) {
	$is_post = 'post' === ( $page['post_type'] ?? null );
	$post_type = $is_post ? 'post' : 'page';
	$source_dom = new DOMDocument();
	@$source_dom->loadHTML( (string) $archive['pages'][ (string) ( $page['source_path'] ?? '' ) ], LIBXML_NONET );
	$published_at = ( new DOMXPath( $source_dom ) )->query( '//meta[@property="article:published_time"]/@content' )->item( 0 )?->nodeValue;
	$post_date = $published_at ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $published_at ) ) : null;
	$post_id = wp_insert_post( array(
		'post_type' => $post_type,
		'post_status' => 'publish',
		'post_title' => (string) ( $page['title'] ?? 'Captured source page' ),
		'post_name' => sanitize_title( (string) ( $page['slug'] ?? '' ) ),
		'post_content' => wp_slash( (string) ( $page['canonical_block_markup'] ?? '' ) ),
		'post_excerpt' => (string) ( $page['metadata']['excerpt'] ?? '' ),
		...( $post_date ? array( 'post_date' => $post_date, 'post_date_gmt' => $post_date ) : array() ),
	), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	foreach ( (array) ( $page['metadata']['post_meta'] ?? array() ) as $meta_key => $meta_value ) {
		update_post_meta( $post_id, (string) $meta_key, wp_slash( $meta_value ) );
	}
	$post_ids[ (string) $page['source_path'] ] = (int) $post_id;
	if ( $is_post ) {
		$captured_post_ids[] = (int) $post_id;
	}
	if ( 'index.html' === ( $page['source_path'] ?? '' ) ) {
		$home_id = (int) $post_id;
	}
}
if ( ! $home_id ) {
	throw new RuntimeException( 'Captured source home page was not persisted.' );
}

$binding_meta_key = null;
if ( 1 !== preg_match( '/blocks_engine_listing_labels_[a-f0-9]+/', $home_markup, $binding_match ) ) {
	throw new RuntimeException( 'Could not identify the captured source listing-label binding key.' );
}
$binding_meta_key = $binding_match[0];
$source_document = new DOMDocument();
@$source_document->loadHTML( (string) $archive['pages']['index.html'], LIBXML_NONET );
$source_xpath = new DOMXPath( $source_document );
$binding_meta_receipts = array();
foreach ( $source_xpath->query( '//article' ) as $article ) {
	$article_path = null;
	$category_anchor = null;
	foreach ( $article->getElementsByTagName( 'a' ) as $anchor ) {
		$path = (string) ( parse_url( $anchor->getAttribute( 'href' ), PHP_URL_PATH ) ?: '' );
		if ( preg_match( '#^/writing/category/#', $path ) ) {
			$category_anchor = $anchor;
		}
		if ( '' !== $path && ! preg_match( '#^/writing/category/#', $path ) && ! preg_match( '#^/writing/?$#', $path ) ) {
			$source_post_path = trim( $path, '/' ) . '/index.html';
			if ( isset( $post_ids[ $source_post_path ] ) ) {
				$article_path = $source_post_path;
			}
		}
	}
	if ( null === $article_path || ! $category_anchor instanceof DOMElement ) {
		continue;
	}
	$category_href = (string) ( parse_url( $category_anchor->getAttribute( 'href' ), PHP_URL_PATH ) ?: '' );
	$category_anchor->setAttribute( 'href', $category_href );
	$category_html = $source_document->saveHTML( $category_anchor );
	$post_id = $post_ids[ $article_path ];
	update_post_meta( $post_id, $binding_meta_key, wp_slash( $category_html ) );
	$binding_meta_receipts[ $article_path ] = array(
		'post_id' => $post_id,
		'key' => $binding_meta_key,
		'value' => $category_html,
	);
}
if ( count( $binding_meta_receipts ) !== count( $captured_post_ids ) ) {
	throw new RuntimeException( 'Captured source listing labels did not map to every imported post: ' . wp_json_encode( $binding_meta_receipts ) );
}

$synthetic_home_id = (int) $args[1];
$synthetic_blocks = parse_blocks( (string) get_post_field( 'post_content', $synthetic_home_id ) );
$exclude_captured_query_posts = null;
$exclude_captured_query_posts = static function ( array &$blocks ) use ( $captured_post_ids, &$exclude_captured_query_posts ): void {
	foreach ( $blocks as &$block ) {
		if ( 'core/query' === ( $block['blockName'] ?? null ) ) {
			$attributes = (array) ( $block['attrs'] ?? array() );
			$query = (array) ( $attributes['query'] ?? array() );
			$query['exclude'] = array_values( array_unique( array_merge( (array) ( $query['exclude'] ?? array() ), $captured_post_ids ) ) );
			$attributes['query'] = $query;
			$block['attrs'] = $attributes;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$exclude_captured_query_posts( $block['innerBlocks'] );
		}
	}
	unset( $block );
};
$exclude_captured_query_posts( $synthetic_blocks );
$updated_synthetic_home = wp_update_post( array( 'ID' => $synthetic_home_id, 'post_content' => wp_slash( serialize_blocks( $synthetic_blocks ) ) ), true );
if ( is_wp_error( $updated_synthetic_home ) ) {
	throw new RuntimeException( 'Could not isolate synthetic parity query from captured posts: ' . $updated_synthetic_home->get_error_message() );
}

$block_types = array();
preg_match_all( '/<!-- wp:([^\s]+)/', $home_markup, $matches );
foreach ( $matches[1] as $type ) {
	$block_types[ $type ] = ( $block_types[ $type ] ?? 0 ) + 1;
}
echo wp_json_encode( array(
	'capture_lineage' => $archive['lineage'] ?? array(),
	'capture_timestamp' => $archive['captured_at'] ?? null,
	'compiler_status' => $compiled['status'] ?? null,
	'compiler_diagnostics' => $compiled['diagnostics'] ?? array(),
	'fallback_count' => count( $compiled['fallbacks'] ?? array() ),
	'source_comments' => $comment_evidence,
	'binding_meta_receipts' => $binding_meta_receipts,
	'home' => array(
		'post_id' => $home_id,
		'title' => $home['title'] ?? null,
		'block_types' => $block_types,
		'has_query_overlay' => str_contains( $home_markup, 'blocks-engine-listing-overlay' ),
		'has_bound_meta_projection' => str_contains( $home_markup, 'blocks-engine-listing-bound-meta' ),
		'has_wordpress_transport_comments' => str_contains( $home_markup, '<!-- wp:' ) && str_contains( $home_markup, '<!-- /wp:' ),
		'post_ids' => $post_ids,
		'canonical_block_markup' => $home_markup,
	),
	'theme' => $theme,
	'synthetic_fixture_isolation' => array( 'home_post_id' => $synthetic_home_id, 'excluded_source_post_ids' => $captured_post_ids ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
