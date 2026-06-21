<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * Static Site Importer-owned adapter contract example.
 *
 * This class should live in Static Site Importer when adopted. It keeps product
 * workflows insulated from whether conversion is served by consumer wrappers or
 * native php-transformer classes, and it maps TransformerResult arrays into a
 * product-owned report envelope. The transformer package intentionally does not
 * publish this downstream adapter itself.
 */
final class Static_Site_Importer_Transformer_Adapter {
    /**
     * Convert an SSI source fragment to the current BFB fragment envelope shape.
     *
     * @param string               $html    Standalone HTML fragment.
     * @param array<string, mixed> $options Per-fragment conversion and provenance options.
     * @return array<string, mixed> BFB-compatible fragment envelope.
     */
    public function convert_fragment( string $html, array $options = array() ): array {
        $scope  = $this->fragment_scope( $options );
        $result = ( new HtmlTransformer() )->transform( $html )->toArray();

        $serialized  = isset( $result['serialized_blocks'] ) ? (string) $result['serialized_blocks'] : '';
        $blocks      = isset( $result['blocks'] ) && is_array( $result['blocks'] ) ? $result['blocks'] : array();
        $diagnostics = $this->scope_diagnostics( isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array(), $scope );
        $status      = isset( $result['status'] ) ? (string) $result['status'] : 'failed';

        return array(
            'success'           => 'failed' !== $status,
            'status'            => $status,
            'from'              => 'html',
            'to'                => 'blocks',
            'scope'             => $scope,
            'content'           => $serialized,
            'serialized_blocks' => $serialized,
            'blocks'            => $blocks,
            'diagnostics'       => $diagnostics,
            'provenance'        => array(
                'scope'        => $scope,
                'source_bytes' => strlen( $html ),
                'source_hash'  => hash( 'sha256', $html ),
            ),
            'report'            => array(
                'status'            => $status,
                'serialized_blocks' => $serialized,
                'diagnostics'       => $diagnostics,
                'fallbacks'         => isset( $result['fallbacks'] ) && is_array( $result['fallbacks'] ) ? $result['fallbacks'] : array(),
            ),
        );
    }

    /**
     * Compile a site artifact bundle and return the canonical transformer result shape.
     *
     * @param array<string, mixed> $artifact Site artifact input.
     * @param array<string, mixed> $options  Compiler options retained for future transformer support.
     * @return array<string, mixed> Canonical transformer result envelope.
     */
    public function compile_website_artifact( array $artifact, array $options = array() ): array {
        unset( $options );

        return ( new ArtifactCompiler() )->compile( $artifact )->toArray();
    }

    /**
     * Render serialized block markup, or parse-block arrays, back to HTML.
     *
     * @param array<int|string, array<string, mixed>>|string $blocks  Parsed blocks or serialized block markup.
     * @param array<string, mixed>                          $options Render options.
     */
    public function blocks_to_html( array|string $blocks, array $options = array() ): string {
        $bridge = new FormatBridge();

        if ( is_string( $blocks ) ) {
            return $bridge->convert( $blocks, 'blocks', 'html', $options );
        }

        return ( new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime() )->renderBlocks( array_values( $blocks ) );
    }

    /**
     * Summarize the canonical transformer result shape SSI records in import reports.
     *
     * @param array<string, mixed> $compiled Canonical transformer result envelope.
     * @return array<string, mixed> Compact report summary.
     */
    public function summarize_result( array $compiled ): array {
        $blocks      = isset( $compiled['blocks'] ) && is_array( $compiled['blocks'] ) ? $compiled['blocks'] : array();
        $block_tree  = $this->block_tree_report( $blocks );
        $block_types = isset( $compiled['block_types'] ) && is_array( $compiled['block_types'] ) ? $compiled['block_types'] : array();
        $components  = isset( $compiled['components'] ) && is_array( $compiled['components'] ) ? $compiled['components'] : array();
        $files       = isset( $compiled['assets'] ) && is_array( $compiled['assets'] ) ? $compiled['assets'] : array();
        $diagnostics = isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? $compiled['diagnostics'] : array();
        $source      = isset( $compiled['source_reports']['artifact'] ) && is_array( $compiled['source_reports']['artifact'] ) ? $compiled['source_reports']['artifact'] : array();

        return array(
            'schema'                    => isset( $compiled['schema'] ) ? (string) $compiled['schema'] : '',
            'status'                    => isset( $compiled['status'] ) ? (string) $compiled['status'] : '',
            'source'                    => isset( $compiled['provenance']['source'] ) ? (string) $compiled['provenance']['source'] : '',
            'source_element_count'      => (int) ( $source['html']['element_count'] ?? 0 ),
            'source_class_count'        => (int) ( $source['html']['class_count'] ?? 0 ),
            'source_css_selector_count' => (int) ( $source['css']['selector_count'] ?? 0 ),
            'block_count'               => (int) ( $block_tree['block_count'] ?? 0 ),
            'block_depth'               => (int) ( $block_tree['max_depth'] ?? 0 ),
            'block_type_count'          => count( $block_types ),
            'component_count'           => count( $components ),
            'file_count'                => count( $files ),
            'diagnostic_count'          => count( $diagnostics ),
        );
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
     * @return array<string, int>
     */
    private function block_tree_report( array $blocks ): array {
        $report = array(
            'block_count' => 0,
            'max_depth'   => 0,
        );

        $walk = function ( array $items, int $depth ) use ( &$walk, &$report ): void {
            foreach ( $items as $block ) {
                if ( ! is_array( $block ) ) {
                    continue;
                }

                ++$report['block_count'];
                $report['max_depth'] = max( $report['max_depth'], $depth );
                if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                    $walk( $block['innerBlocks'], $depth + 1 );
                }
            }
        };

        $walk( $blocks, 1 );

        return $report;
    }

    /**
     * @param array<string, mixed> $options Fragment conversion options.
     * @return array<string, string>
     */
    private function fragment_scope( array $options ): array {
        $scope = array( 'type' => 'fragment' );

        foreach ( array( 'source_id', 'source_selector', 'region_id', 'label' ) as $key ) {
            if ( isset( $options[ $key ] ) && is_scalar( $options[ $key ] ) && '' !== trim( (string) $options[ $key ] ) ) {
                $scope[ $key ] = trim( (string) $options[ $key ] );
            }
        }

        return $scope;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics Diagnostics.
     * @param array<string, string>            $scope       Fragment scope.
     * @return array<int, array<string, mixed>> Scoped diagnostics.
     */
    private function scope_diagnostics( array $diagnostics, array $scope ): array {
        foreach ( $diagnostics as $index => $diagnostic ) {
            $details             = isset( $diagnostic['details'] ) && is_array( $diagnostic['details'] ) ? $diagnostic['details'] : array();
            $details['scope']    = $scope;
            $diagnostic['scope'] = $scope;
            $diagnostic['details'] = $details;
            $diagnostics[ $index ] = $diagnostic;
        }

        return $diagnostics;
    }
}
