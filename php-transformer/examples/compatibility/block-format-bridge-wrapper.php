<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;

/*
 * Downstream-only compatibility example.
 *
 * Copy these wrappers into a Block Format Bridge consumer during migration.
 * The transformer package intentionally does not publish legacy global
 * functions itself.
 */

if ( ! function_exists( 'bfb_capabilities' ) ) {
    /**
     * Compatibility wrapper for Block Format Bridge capability discovery.
     *
     * @return array<string, mixed> Machine-readable conversion capability report.
     */
    function bfb_capabilities(): array {
        $bridge = new FormatBridge();
        $formats = array();

        foreach ( $bridge->supportedFormats() as $format ) {
            $formats[ $format ] = array(
                'slug'      => $format,
                'supported' => true,
            );
        }

        return array(
            'bridge'      => array(
                'provider' => FormatBridge::class,
            ),
            'formats'     => $formats,
            'conversions' => array(
                'html_to_blocks' => array(
                    'available' => in_array( 'html', $bridge->supportedFormats(), true ) && in_array( 'blocks', $bridge->supportedFormats(), true ),
                    'provider'  => 'automattic/blocks-engine-php-transformer',
                ),
            ),
        );
    }
}

if ( ! function_exists( 'bfb_convert' ) ) {
    /**
     * Compatibility wrapper for Block Format Bridge's universal converter.
     *
     * @param array<string, mixed> $options Per-call conversion options.
     */
    function bfb_convert( string $content, string $from, string $to, array $options = array() ): string {
        return ( new FormatBridge() )->convert( $content, $from, $to, $options );
    }
}

if ( ! function_exists( 'bfb_normalize' ) ) {
    /**
     * Compatibility wrapper for declared-format normalization.
     *
     * @param array<string, mixed> $options Normalization options.
     */
    function bfb_normalize( string $content, string $format, array $options = array() ): string {
        return ( new FormatBridge() )->normalize( $content, $format, $options );
    }
}

if ( ! function_exists( 'bfb_to_blocks' ) ) {
    /**
     * Compatibility wrapper for callers that need the block-array pivot.
     *
     * @param array<string, mixed> $options Per-call conversion options.
     * @return array<int, array<string, mixed>> parse_blocks()-compatible block arrays.
     */
    function bfb_to_blocks( string $content, string $from, array $options = array() ): array {
        return ( new FormatBridge() )->toBlocks( $content, $from, $options );
    }
}

if ( ! function_exists( 'bfb_convert_fragment' ) ) {
    /**
     * Compatibility wrapper for scoped HTML fragment conversion.
     *
     * @param array<string, mixed> $options Per-call conversion and provenance options.
     * @return array<string, mixed> Fragment conversion envelope.
     */
    function bfb_convert_fragment( string $html, array $options = array() ): array {
        $blocks = bfb_to_blocks( $html, 'html', $options );

        return array(
            'success'           => '' === trim( $html ) || array() !== $blocks,
            'status'            => array() === $blocks && '' !== trim( $html ) ? 'failed' : 'success',
            'from'              => 'html',
            'to'                => 'blocks',
            'scope'             => array(
                'type'            => 'fragment',
                'source_id'       => isset( $options['source_id'] ) ? (string) $options['source_id'] : '',
                'source_selector' => isset( $options['source_selector'] ) ? (string) $options['source_selector'] : '',
                'region_id'       => isset( $options['region_id'] ) ? (string) $options['region_id'] : '',
                'label'           => isset( $options['label'] ) ? (string) $options['label'] : '',
            ),
            'content'           => bfb_convert( $html, 'html', 'blocks', $options ),
            'serialized_blocks' => bfb_convert( $html, 'html', 'blocks', $options ),
            'blocks'            => $blocks,
            'diagnostics'       => array(),
            'provenance'        => array(
                'source_bytes' => strlen( $html ),
                'source_hash'  => hash( 'sha256', $html ),
            ),
        );
    }
}
