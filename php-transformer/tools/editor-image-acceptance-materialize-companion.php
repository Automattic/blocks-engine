<?php
/** Materialize the genuine compiler companion payload through the pinned SSI API. */

if ( ! isset( $args ) || 1 !== count( $args ) || ! is_readable( (string) $args[0] ) ) {
	throw new RuntimeException( 'Expected the compiler-produced companion payload JSON.' );
}
if ( ! class_exists( 'Static_Site_Importer_Plugin_Materializer' ) ) {
	throw new RuntimeException( 'The pinned Static Site Importer plugin is not active.' );
}

$payload = json_decode( (string) file_get_contents( (string) $args[0] ), true );
if ( ! is_array( $payload ) || 'blocks-engine/wordpress-companion-plugin/v1' !== ( $payload['schema'] ?? null ) ) {
	throw new RuntimeException( 'Captured-source companion payload has an invalid producer schema.' );
}
wp_set_current_user( 1 );
$materialization = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload, null, true );
if ( is_wp_error( $materialization ) ) {
	throw new RuntimeException( 'SSI companion materialization failed: ' . $materialization->get_error_message() );
}
if ( ! in_array( $materialization['status'] ?? '', array( 'installed_activated', 'refreshed' ), true ) || empty( $materialization['active'] ) ) {
	throw new RuntimeException( 'SSI companion materialization did not install and activate: ' . wp_json_encode( $materialization ) );
}

$registry = WP_Block_Type_Registry::get_instance();
$registered_blocks = array();
$linked_content_block_name = '';
foreach ( $payload['blocks'] as $payload_block ) {
	if ( 'linked-responsive-content' === ( $payload_block['name'] ?? null ) ) {
		$linked_content_block_name = (string) ( $payload_block['block_json']['name'] ?? '' );
	}
}
if ( '' === $linked_content_block_name ) {
	throw new RuntimeException( 'SSI companion payload does not declare the captured linked-responsive-content block.' );
}
foreach ( $materialization['block_names'] ?? array() as $block_name ) {
	$block_name = (string) $block_name;
	$is_registered = $registry->is_registered( $block_name );
	$registered_blocks[ $block_name ] = $is_registered;
	if ( ! $is_registered ) {
		throw new RuntimeException( 'SSI companion did not register declared block ' . $block_name );
	}
}
if ( empty( $registered_blocks[ $linked_content_block_name ] ) ) {
	throw new RuntimeException( 'SSI-generated companion did not register ' . $linked_content_block_name . '.' );
}

echo wp_json_encode( array(
	'ssi_revision' => 'c46706f62cc2160afaee4d3faab551500a01d325',
	'materializer_source' => ( new ReflectionClass( 'Static_Site_Importer_Plugin_Materializer' ) )->getFileName(),
	'payload_schema' => $payload['schema'],
	'status' => $materialization['status'],
	'active' => (bool) $materialization['active'],
	'plugin_file' => $materialization['plugin_file'] ?? null,
	'block_names' => array_values( array_map( 'strval', $materialization['block_names'] ?? array() ) ),
	'registered_blocks' => $registered_blocks,
	'linked_content_block_name' => $linked_content_block_name,
	'registered_linked_responsive_content' => $registered_blocks[ $linked_content_block_name ],
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
