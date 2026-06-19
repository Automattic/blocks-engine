<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

if ( ! function_exists( 'html_to_blocks_raw_handler' ) ) {
    /**
     * Compatibility wrapper for html-to-blocks-converter consumers.
     *
     * @param array<string, mixed> $args Old raw-handler arguments. The `HTML` key carries source HTML.
     * @return array<int, array<string, mixed>> parse_blocks()-compatible block arrays.
     */
    function html_to_blocks_raw_handler( array $args ): array {
        $html = isset( $args['HTML'] ) ? (string) $args['HTML'] : '';

        if ( '' === trim( $html ) ) {
            return array();
        }

        $result = ( new HtmlTransformer() )->transform( $html );

        return $result->blocks;
    }
}

if ( ! function_exists( 'html_to_blocks_convert' ) ) {
    /**
     * Compatibility wrapper for direct HTML conversion callers.
     *
     * @param string $html Source HTML.
     * @param array<string, mixed> $args Conversion context retained for the future transformer options contract.
     * @return array<int, array<string, mixed>> parse_blocks()-compatible block arrays.
     */
    function html_to_blocks_convert( string $html, array $args = array() ): array {
        unset( $args );

        return html_to_blocks_raw_handler( array( 'HTML' => $html ) );
    }
}
