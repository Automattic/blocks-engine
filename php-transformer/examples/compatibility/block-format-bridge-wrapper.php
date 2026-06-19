<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;

if ( ! function_exists( 'bfb_convert' ) ) {
    /**
     * Compatibility wrapper for Block Format Bridge's universal converter.
     *
     * This skeleton preserves the old function name while the target class grows
     * the concrete conversion method in a later implementation slice.
     *
     * @param array<string, mixed> $options Per-call conversion options.
     */
    function bfb_convert( string $content, string $from, string $to, array $options = array() ): string {
        $bridge = new FormatBridge();

        if ( ! in_array( $from, $bridge->supportedFormats(), true ) || ! in_array( $to, $bridge->supportedFormats(), true ) ) {
            return '';
        }

        unset( $options );

        if ( $from === $to ) {
            return $content;
        }

        // Replace this branch with FormatBridge::convert() when the implementation lands.
        return '';
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
        $serialized = bfb_convert( $content, $from, 'blocks', $options );

        return function_exists( 'parse_blocks' ) ? parse_blocks( $serialized ) : array();
    }
}
