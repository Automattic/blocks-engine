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
     * Compile a website artifact bundle and return SSI's current BAC result shape.
     *
     * @param array<string, mixed> $artifact Website artifact input.
     * @param array<string, mixed> $options  Compiler options retained for future transformer support.
     * @return array<string, mixed> BAC-compatible compiler result envelope.
     */
    public function compile_website_artifact( array $artifact, array $options = array() ): array {
        unset( $options );

        return $this->transformer_result_to_bac_result( ( new ArtifactCompiler() )->compile( $artifact )->toArray() );
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
     * Summarize the BAC-compatible result shape SSI records in import reports.
     *
     * @param array<string, mixed> $compiled BAC-compatible compiler result envelope.
     * @return array<string, mixed> Compact report summary.
     */
    public function summarize_result( array $compiled ): array {
        $artifacts   = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
        $block_tree  = isset( $artifacts['block_tree'] ) && is_array( $artifacts['block_tree'] ) ? $artifacts['block_tree'] : array();
        $block_types = isset( $artifacts['block_types'] ) && is_array( $artifacts['block_types'] ) ? $artifacts['block_types'] : array();
        $components  = isset( $artifacts['components'] ) && is_array( $artifacts['components'] ) ? $artifacts['components'] : array();
        $files       = isset( $artifacts['files'] ) && is_array( $artifacts['files'] ) ? $artifacts['files'] : array();
        $diagnostics = isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? $compiled['diagnostics'] : array();
        $source      = isset( $compiled['input']['source_report'] ) && is_array( $compiled['input']['source_report'] ) ? $compiled['input']['source_report'] : array();

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
     * @param array<string, mixed> $result TransformerResult::toArray() output.
     * @return array<string, mixed> BAC-compatible compiler result envelope consumed by SSI.
     */
    private function transformer_result_to_bac_result( array $result ): array {
        $source_report = isset( $result['source_reports']['artifact'] ) && is_array( $result['source_reports']['artifact'] ) ? $result['source_reports']['artifact'] : array();
        $entry_path    = isset( $source_report['entry_path'] ) ? (string) $source_report['entry_path'] : '';
        $provenance    = isset( $result['provenance'][0] ) && is_array( $result['provenance'][0] ) ? $result['provenance'][0] : array();
        $blocks        = isset( $result['blocks'] ) && is_array( $result['blocks'] ) ? $result['blocks'] : array();
        $serialized    = isset( $result['serialized_blocks'] ) ? (string) $result['serialized_blocks'] : '';

        return array(
            'schema'              => 'block-artifact-compiler/result/v1',
            'status'              => isset( $result['status'] ) ? (string) $result['status'] : 'failed',
            'input'               => array(
                'schema'          => isset( $source_report['schema'] ) ? (string) $source_report['schema'] : 'block-artifact-compiler/website-artifact/v1',
                'entry_path'      => $entry_path,
                'entrypoints'     => isset( $source_report['entrypoints'] ) && is_array( $source_report['entrypoints'] ) ? $source_report['entrypoints'] : array(),
                'file_count'      => (int) ( $source_report['file_count'] ?? 0 ),
                'accepted_count'  => (int) ( $source_report['accepted_count'] ?? 0 ),
                'rejected_count'  => (int) ( $source_report['rejected_count'] ?? 0 ),
                'bytes'           => (int) ( $source_report['bytes'] ?? 0 ),
                'files_by_kind'   => isset( $source_report['files_by_kind'] ) && is_array( $source_report['files_by_kind'] ) ? $source_report['files_by_kind'] : array(),
                'files_by_role'   => isset( $source_report['files_by_role'] ) && is_array( $source_report['files_by_role'] ) ? $source_report['files_by_role'] : array(),
                'files_by_mime'   => isset( $source_report['files_by_mime'] ) && is_array( $source_report['files_by_mime'] ) ? $source_report['files_by_mime'] : array(),
                'original_schema' => isset( $source_report['original_schema'] ) ? (string) $source_report['original_schema'] : '',
                'source_report'   => $source_report,
            ),
            'wordpress_artifacts' => array(
                'block_markup' => $serialized,
                'blocks'       => $blocks,
                'block_tree'   => $this->block_tree_report( $blocks ),
                'block_types'  => isset( $result['block_types'] ) && is_array( $result['block_types'] ) ? $result['block_types'] : array(),
                'components'   => isset( $result['components'] ) && is_array( $result['components'] ) ? $result['components'] : array(),
                'documents'    => isset( $result['documents'] ) && is_array( $result['documents'] ) ? $result['documents'] : array(),
                'files'        => isset( $result['assets'] ) && is_array( $result['assets'] ) ? $result['assets'] : array(),
            ),
            'provenance'          => array(
                'source_hash' => isset( $provenance['source_hash'] ) ? (string) $provenance['source_hash'] : (string) ( $source_report['source_hash'] ?? '' ),
                'source'      => '' !== $entry_path ? $entry_path : 'website_artifact',
            ),
            'diagnostics'         => isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array(),
            'bfb_report'          => array(
                'status'            => isset( $result['status'] ) ? (string) $result['status'] : 'failed',
                'serialized_blocks' => $serialized,
                'diagnostics'       => isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array(),
                'fallbacks'         => isset( $result['fallbacks'] ) && is_array( $result['fallbacks'] ) ? $result['fallbacks'] : array(),
            ),
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
