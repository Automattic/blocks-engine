<?php
/** Build the acceptance pages from transformer output and two real media-library images. */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

if ( ! isset( $args ) || 2 !== count( $args ) ) {
	throw new RuntimeException( 'Expected first and second attachment IDs.' );
}

$first_id = (int) $args[0];
$second_id = (int) $args[1];
$first_url = (string) wp_get_attachment_url( $first_id );
$second_url = (string) wp_get_attachment_url( $second_id );
if ( $first_id < 1 || $second_id < 1 || '' === $first_url || '' === $second_url ) {
	throw new RuntimeException( 'Fixture attachments are unavailable.' );
}

require_once WP_PLUGIN_DIR . '/blocks-engine-php-transformer/vendor/autoload.php';
require_once __DIR__ . '/editor-image-acceptance-candidate-autoload.php';
$candidate_class_files = blocks_engine_editor_acceptance_candidate_autoload();
$source = '<a href="/item"><img src="assets/first.jpg" width="960" height="720" alt="Original gallery image"></a>';
$result = ( new HtmlTransformer() )->transform( $source, array( 'context' => array( 'asset_metadata' => array(
	'assets/first.jpg' => array( 'id' => $first_id, 'url' => $first_url ),
	'assets/second.jpg' => array( 'id' => $second_id, 'url' => $second_url ),
) ) ) )->toArray();
$content = (string) ( $result['serialized_blocks'] ?? '' );
if ( ! str_contains( $content, '<!-- wp:image' ) || ! str_contains( $content, '"id":' . $first_id ) || ! str_contains( $content, 'href="/item"' ) || str_contains( $content, '<!-- wp:custom/responsive-media' ) ) {
	throw new RuntimeException( 'Transformer did not produce only the editable core image shape.' );
}

$post_id = wp_insert_post( array(
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'Blocks Engine editor image acceptance',
	'post_content' => $content,
), true );
if ( is_wp_error( $post_id ) || ! $post_id ) {
	throw new RuntimeException( 'Could not create acceptance page.' );
}
$image_evidence = array(
	'post_id' => (int) $post_id,
	'first_attachment' => array( 'id' => $first_id, 'url' => $first_url ),
	'second_attachment' => array( 'id' => $second_id, 'url' => $second_url ),
	'transformed_content' => $content,
	'expected_link' => '/item',
	'candidate_class_files' => $candidate_class_files['classes'],
);

$row_source = '<style>.flex{display:flex}.items-center{align-items:center}.gap-1\.5{gap:6px}.project-row:hover{color:#123456}</style>'
	. '<p class="blocks-engine-inline-layout-carrier"><a class="project-row" href="/projects">'
	. '<span class="flex items-center gap-1.5"><img src="assets/icon.png" width="32" height="32" alt="" />247</span>'
	. '</a></p>';
$row_result = ( new HtmlTransformer() )->transform( $row_source, array( 'context' => array( 'asset_metadata' => array(
	'assets/icon.png' => array( 'id' => $first_id, 'url' => $first_url ),
) ) ) )->toArray();
$row_content = (string) ( $row_result['serialized_blocks'] ?? '' );
$row_css = implode( "\n", array_map(
	static fn ( array $asset ): string => 'css' === ( $asset['kind'] ?? '' ) ? (string) ( $asset['content'] ?? '' ) : '',
	$row_result['assets'] ?? array()
) );
if ( ! str_contains( $row_content, '<!-- wp:group' ) || ! str_contains( $row_content, '<!-- wp:image' ) || ! str_contains( $row_content, '<!-- wp:paragraph' ) || ! str_contains( $row_content, '>247</a>' ) || str_contains( $row_content, '<!-- wp:html' ) || array() !== ( $row_result['fallbacks'] ?? array() ) ) {
	throw new RuntimeException( 'Transformer did not produce the native compact-row structure.' );
}
$row_post_id = wp_insert_post( array(
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'Blocks Engine compact row acceptance',
	'post_content' => $row_content,
), true );
if ( is_wp_error( $row_post_id ) || ! $row_post_id ) {
	throw new RuntimeException( 'Could not create compact-row acceptance page.' );
}

$first_excerpt = 'First source excerpt with independently editable card text.';
$second_excerpt = 'Second source excerpt from a different post.';
$card = static fn (string $slug, string $title, string $date, string $excerpt, string $label): string => '<article class="card"><a class="cover-link" href="/' . $slug . '.html" aria-hidden="true" tabindex="-1"></a><div class="metadata"><div class="labels"><a href="/category/topic/">' . $label . '</a></div><time datetime="' . date('Y-m-d', strtotime($date)) . '">' . $date . '</time></div><h2><a class="active-link" href="/' . $slug . '.html">' . $title . '</a></h2><p class="description" style="font-size:14px;line-height:22px">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p></article>';
$source_index = '<style>body{margin:0}a{text-decoration:inherit}.cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0;width:min(100%,411px);margin:0 auto}.metadata{display:flex;align-items:center;justify-content:space-between}.labels{display:flex}.cover-link{position:absolute;inset:0}.card{position:relative;border:1px solid #8a8a8a;padding:20px}.description{margin:0}@media(max-width:600px){.cards{grid-template-columns:1fr;width:auto}}</style><main><section class="cards">' . $card('first', 'First acceptance title', 'Jan 2, 2026', $first_excerpt, 'Topic') . '<!-- -->' . $card('second', 'Second acceptance title', 'Jan 1, 2026', $second_excerpt, 'Other label') . '</section></main>';
$article = static fn (string $title, string $description, string $date): string => '<html><head><title>' . $title . '</title><meta property="og:type" content="article"><meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES) . '"><meta property="article:published_time" content="' . $date . '"></head><body><main><h1>' . $title . '</h1><p>' . str_repeat('ARTICLE BODY. ', 80) . '</p></main></body></html>';
$compiled = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files' => array(
		'index.html' => $source_index,
		'first.html' => $article('First acceptance title', $first_excerpt, '2026-01-02T00:00:00Z'),
		'second.html' => $article('Second acceptance title', $second_excerpt, '2026-01-01T00:00:00Z'),
		'category/topic/index.html' => '<main><h1>Topic</h1></main>',
	),
))->toArray();
$theme = 'blocks-engine-listing-acceptance';
$theme_dir = WP_CONTENT_DIR . '/themes/' . $theme;
$site_plan = ( new WordPressSitePlanResolver() )->resolve(
	$compiled['source_reports']['wordpress_site_plan'] ?? array(),
	array( 'theme_uri' => home_url( '/wp-content/themes/' . $theme ) )
);
if ( ! is_dir( $theme_dir ) && ! wp_mkdir_p( $theme_dir ) ) {
	throw new RuntimeException( 'Could not create listing acceptance theme.' );
}
foreach ( $site_plan['writes'] as $write ) {
	$path = $theme_dir . '/' . $write['target_path'];
	if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
		throw new RuntimeException( 'Could not create listing acceptance theme path.' );
	}
	$bytes = 'base64' === ( $write['payload']['encoding'] ?? null ) ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data'];
	if ( false === file_put_contents( $path, $bytes ) ) {
		throw new RuntimeException( 'Could not write listing acceptance theme file.' );
	}
}
wp_clean_themes_cache();
if ( ! wp_get_theme( $theme )->exists() ) {
	throw new RuntimeException( 'WordPress did not recognize the generated listing theme.' );
}
switch_theme( $theme );
if ( '' !== $row_css ) {
	wp_update_custom_css_post( $row_css );
}
wp_delete_post( 1, true );
$home_id = 0;
$post_ids = array();
foreach ( $site_plan['pages'] as $page ) {
	$post_type = 'post' === ( $page['post_type'] ?? null ) ? 'post' : 'page';
	$post_date = 'first.html' === ( $page['source_path'] ?? null ) ? '2026-01-02 12:00:00' : ( 'second.html' === ( $page['source_path'] ?? null ) ? '2026-01-01 12:00:00' : '2026-01-02 12:00:00' );
	$id = wp_insert_post( array(
		'post_type' => $post_type,
		'post_status' => 'publish',
		'post_title' => (string) ( $page['title'] ?? 'Imported page' ),
		'post_name' => sanitize_title( (string) ( $page['slug'] ?? '' ) ),
		'post_content' => wp_slash( (string) ( $page['canonical_block_markup'] ?? '' ) ),
		'post_excerpt' => (string) ( $page['metadata']['excerpt'] ?? '' ),
		'post_date' => $post_date,
	), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	foreach ( (array) ( $page['metadata']['post_meta'] ?? array() ) as $meta_key => $meta_value ) {
		update_post_meta( $id, (string) $meta_key, wp_slash( $meta_value ) );
	}
	$post_ids[ (string) ( $page['source_path'] ?? '' ) ] = (int) $id;
	if ( 'index.html' === ( $page['source_path'] ?? '' ) ) {
		$home_id = (int) $id;
	}
}
if ( ! $home_id ) {
	throw new RuntimeException( 'Generated entry page was not persisted.' );
}
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home_id );
$neutral_markup = '<!-- wp:group {"tagName":"article","className":"card be-neutral-card"} --><article class="wp-block-group card be-neutral-card"><!-- wp:heading {"level":2} --><h2 class="wp-block-heading"><a href="/first">Neutral Card Title</a></h2><!-- /wp:heading --><!-- wp:paragraph --><p>Neutral card excerpt for editor selection.</p><!-- /wp:paragraph --><!-- wp:read-more {"className":"cover-link blocks-engine-listing-overlay","content":"<span aria-hidden=\"true\"></span>"} /--></article><!-- /wp:group -->';
$neutral_id = wp_insert_post( array(
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'Neutral role-marked card',
	'post_name' => 'neutral-role-marked-card',
	'post_content' => wp_slash( $neutral_markup ),
), true );
if ( is_wp_error( $neutral_id ) ) {
	throw new RuntimeException( $neutral_id->get_error_message() );
}
$home_page = current( array_filter( $site_plan['pages'], static fn ( array $page ): bool => 'index.html' === ( $page['source_path'] ?? '' ) ) );
$home_markup = (string) ( $home_page['canonical_block_markup'] ?? '' );
$fallback_count = preg_match_all( '/<!--\s*wp:(?:html|freeform)\b/', $home_markup );
if ( ! str_contains( $home_markup, 'blocks-engine-listing-overlay' ) || 0 !== $fallback_count || ! str_contains( $home_markup, 'wp:query' ) ) {
	throw new RuntimeException( 'Generated listing entry is missing its native role-marked query overlay or contains fallback blocks.' );
}

echo wp_json_encode( array(
	'image' => $image_evidence,
	'compact_row_post_id' => (int) $row_post_id,
	'compact_row_content' => $row_content,
	'compact_row_fallbacks' => $row_result['fallbacks'] ?? array(),
	'first_attachment' => array( 'id' => $first_id, 'url' => $first_url ),
	'second_attachment' => array( 'id' => $second_id, 'url' => $second_url ),
	'transformed_content' => $content,
	'expected_link' => '/item',
	'listing' => array(
		'post_id' => $home_id,
		'neutral_post_id' => (int) $neutral_id,
		'neutral_block_markup' => $neutral_markup,
		'source_markup' => $source_index,
		'post_ids' => $post_ids,
		'theme' => $theme,
		'canonical_block_markup' => $home_markup,
		'fallback_blocks' => $fallback_count,
	),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
