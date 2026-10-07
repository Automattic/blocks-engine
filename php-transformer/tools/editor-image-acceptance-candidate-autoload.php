<?php
/** Keep the mounted candidate package ahead of SSI's separately installed engine dependency. */

use Composer\Autoload\ClassLoader;

function blocks_engine_editor_acceptance_candidate_autoload(): array {
	$candidate_vendor = realpath( WP_PLUGIN_DIR . '/blocks-engine-php-transformer/vendor' );
	$candidate_root = realpath( WP_PLUGIN_DIR . '/blocks-engine-php-transformer' );
	if ( false === $candidate_vendor || false === $candidate_root ) {
		throw new RuntimeException( 'Candidate Blocks Engine Composer vendor directory is unavailable.' );
	}
	$loaders = ClassLoader::getRegisteredLoaders();
	$loader = $loaders[ $candidate_vendor ] ?? null;
	if ( ! $loader instanceof ClassLoader ) {
		throw new RuntimeException( 'Candidate Blocks Engine Composer loader is not registered.' );
	}

	$classes = array(
		Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler::class,
		Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::class,
		Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ListingFieldProjection::class,
	);
	$assert_candidate_class = static function ( string $class ) use ( $candidate_root ): string {
		if ( ! class_exists( $class ) ) {
			throw new RuntimeException( 'Candidate package class could not be autoloaded: ' . $class );
		}
		$file = realpath( (string) ( new ReflectionClass( $class ) )->getFileName() );
		if ( false === $file || ! str_starts_with( $file, $candidate_root . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'Acceptance loaded a non-candidate Blocks Engine class: ' . $class . ' from ' . (string) $file );
		}
		return $file;
	};

	$loader->unregister();
	$loader->register( true );
	$files = array();
	foreach ( $classes as $class ) {
		$files[ $class ] = $assert_candidate_class( $class );
	}
	return array( 'vendor_path' => $candidate_vendor, 'classes' => $files );
}
