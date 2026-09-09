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
$source = '<media-frame style="display:block"><img src="assets/first.jpg" width="960" height="720" alt="Original gallery image"></media-frame>';
$result = ( new HtmlTransformer() )->transform( $source, array( 'context' => array( 'asset_metadata' => array(
	'assets/first.jpg' => array( 'id' => $first_id, 'url' => $first_url ),
	'assets/second.jpg' => array( 'id' => $second_id, 'url' => $second_url ),
) ) ) )->toArray();
$content = (string) ( $result['serialized_blocks'] ?? '' );
if ( ! str_contains( $content, '<!-- wp:image' ) || ! str_contains( $content, '"id":' . $first_id ) || str_contains( $content, '<!-- wp:custom/responsive-media' ) ) {
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

echo wp_json_encode( array(
	'post_id' => (int) $post_id,
	'first_attachment' => array( 'id' => $first_id, 'url' => $first_url ),
	'second_attachment' => array( 'id' => $second_id, 'url' => $second_url ),
	'transformed_content' => $content,
	'unpromotable_reason' => 'Linked custom image wrappers retain responsive media because WordPress crop mutations discard core/image link presentation.',
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
