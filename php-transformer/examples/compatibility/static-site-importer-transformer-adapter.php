<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * Static Site Importer-owned adapter sketch.
 *
 * This class should live in Static Site Importer when adopted. It keeps product
 * workflows insulated from whether conversion is served by legacy wrappers or
 * native php-transformer classes.
 */
final class Static_Site_Importer_Transformer_Adapter {
    public function html_to_block_markup( string $html, array $options = array() ): string {
        unset( $options );

        $result = ( new HtmlTransformer() )->transform( $html );

        if ( '' !== $result->serializedBlocks ) {
            return $result->serializedBlocks;
        }

        return function_exists( 'serialize_blocks' ) ? serialize_blocks( $result->blocks ) : '';
    }

    /**
     * @param array<string, mixed> $artifact Website artifact input.
     * @param array<string, mixed> $options Compiler options retained for future transformer support.
     * @return array<string, mixed> Static Site Importer import-report-compatible compiler result.
     */
    public function compile_website_artifact( array $artifact, array $options = array() ): array {
        unset( $options );

        return ( new ArtifactCompiler() )->compile( $artifact )->toArray();
    }

    /**
     * @param array<string, mixed> $compiled Compiler result envelope.
     * @return array<string, mixed> Static Site Importer report summary.
     */
    public function summarize_result( array $compiled ): array {
        return array(
            'schema'           => isset( $compiled['schema'] ) ? (string) $compiled['schema'] : '',
            'block_count'      => isset( $compiled['blocks'] ) && is_array( $compiled['blocks'] ) ? count( $compiled['blocks'] ) : 0,
            'diagnostic_count' => isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? count( $compiled['diagnostics'] ) : 0,
        );
    }
}
