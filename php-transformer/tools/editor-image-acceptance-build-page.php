<?php
/**
 * Build the acceptance page from the transformer output after WordPress has
 * assigned the two fixture images real media-library identities.
 *
 * Run through wp eval-file with: <first attachment id> <second attachment id>.
 */

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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
) );
if ( is_wp_error( $post_id ) || ! $post_id ) {
	throw new RuntimeException( 'Could not create acceptance page.' );
}

$rowSource = '<style>.flex{display:flex}.items-center{align-items:center}.gap-1\.5{gap:6px}.project-row:hover{color:#123456}</style>'
	. '<p class="blocks-engine-inline-layout-carrier"><a class="project-row" href="/projects">'
	. '<span class="flex items-center gap-1.5"><img src="assets/icon.png" width="32" height="32" alt="" />247</span>'
	. '</a></p>';
$rowResult = ( new HtmlTransformer() )->transform( $rowSource, array( 'context' => array( 'asset_metadata' => array(
    'assets/icon.png' => array( 'id' => $first_id, 'url' => $first_url ),
) ) ) )->toArray();
$rowContent = (string) ( $rowResult['serialized_blocks'] ?? '' );
$rowCss = implode( "\n", array_map(
	static fn ( array $asset ): string => 'css' === ( $asset['kind'] ?? '' ) ? (string) ( $asset['content'] ?? '' ) : '',
	$rowResult['assets'] ?? array()
) );
if ( '' !== $rowCss ) {
	wp_update_custom_css_post( $rowCss );
}
if ( ! str_contains( $rowContent, '<!-- wp:group' ) || ! str_contains( $rowContent, '<!-- wp:image' ) || ! str_contains( $rowContent, '<!-- wp:paragraph' ) || ! str_contains( $rowContent, '>247</a>' ) || str_contains( $rowContent, '<!-- wp:html' ) || array() !== ( $rowResult['fallbacks'] ?? array() ) ) {
	throw new RuntimeException( 'Transformer did not produce the native compact-row structure.' );
}
$row_post_id = wp_insert_post( array(
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'Blocks Engine compact row acceptance',
	'post_content' => $rowContent,
) );
if ( is_wp_error( $row_post_id ) || ! $row_post_id ) {
	throw new RuntimeException( 'Could not create compact-row acceptance page.' );
}

echo wp_json_encode( array(
	'post_id' => (int) $post_id,
	'compact_row_post_id' => (int) $row_post_id,
	'first_attachment' => array( 'id' => $first_id, 'url' => $first_url ),
	'second_attachment' => array( 'id' => $second_id, 'url' => $second_url ),
	'transformed_content' => $content,
	'compact_row_content' => $rowContent,
	'compact_row_fallbacks' => $rowResult['fallbacks'] ?? array(),
	'expected_link' => '/item',
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
