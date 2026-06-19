<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/*
 * Downstream-only compatibility example.
 *
 * Copy these wrappers into a downstream consumer during migration. They are
 * example code for consumer PRs, not product API shipped by this package.
 */

if ( ! function_exists( 'bac_compile_website_artifact' ) ) {
    /**
     * Downstream wrapper example for a website artifact compiler function.
     *
     * @param array<string, mixed> $artifact Website artifact input.
     * @param array<string, mixed> $options Compiler options retained for the future transformer options contract.
     * @return array<string, mixed> Consumer-owned result envelope.
     */
    function bac_compile_website_artifact( array $artifact, array $options = array() ): array {
        unset( $options );

        return ( new ArtifactCompiler() )->compile( $artifact )->toArray();
    }
}

if ( ! function_exists( 'bac_compile_fragment' ) ) {
    /**
     * Downstream wrapper example for single-fragment compilation.
     *
     * @param array<string, mixed> $options Compiler options.
     * @return array<string, mixed> Consumer-owned result envelope.
     */
    function bac_compile_fragment( string $content, string $source = 'fragment', string $format = 'html', array $options = array() ): array {
        $path = trim( $source ) !== '' ? $source : 'fragment';

        return bac_compile_website_artifact(
            array(
                'files' => array(
                    array(
                        'path'    => $path . '.' . $format,
                        'kind'    => $format,
                        'content' => $content,
                    ),
                ),
            ),
            $options
        );
    }
}

if ( ! function_exists( 'bac_summarize_result' ) ) {
    /**
     * Downstream wrapper example for compact compiler summaries.
     *
     * @param array<string, mixed> $compiled Compiler result envelope.
     * @return array<string, mixed> Compact summary.
     */
    function bac_summarize_result( array $compiled ): array {
        return array(
            'schema'           => isset( $compiled['schema'] ) ? (string) $compiled['schema'] : '',
            'block_count'      => isset( $compiled['blocks'] ) && is_array( $compiled['blocks'] ) ? count( $compiled['blocks'] ) : 0,
            'diagnostic_count' => isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? count( $compiled['diagnostics'] ) : 0,
        );
    }
}
