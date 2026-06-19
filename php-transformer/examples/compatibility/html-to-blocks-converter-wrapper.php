<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/*
 * Downstream-only compatibility example.
 *
 * Copy these wrappers into a downstream consumer during migration. They are
 * example code for consumer PRs, not product API shipped by this package.
 */

if ( ! function_exists( 'html_to_blocks_transformer' ) ) {
    function html_to_blocks_transformer(): HtmlTransformer {
        static $transformer = null;

        if ( ! $transformer instanceof HtmlTransformer ) {
            $transformer = new HtmlTransformer();
        }

        return $transformer;
    }
}

if ( ! function_exists( 'html_to_blocks_create_unsupported_html_fallback_block' ) ) {
    /**
     * Downstream fallback bridge preserving the old hook and core/html shape.
     *
     * @param string $element_html Unsupported HTML fragment.
     * @param array<string, mixed> $context Fallback context.
     * @return array<string, mixed> parse_blocks()-compatible block array.
     */
    function html_to_blocks_create_unsupported_html_fallback_block( string $element_html, array $context = array() ): array {
        $block = array(
            'blockName'    => 'core/html',
            'attrs'        => array( 'content' => $element_html ),
            'innerBlocks'  => array(),
            'innerHTML'    => $element_html,
            'innerContent' => array( $element_html ),
        );

        if ( function_exists( 'do_action' ) ) {
            do_action( 'html_to_blocks_unsupported_html_fallback', $element_html, $context, $block );
        }

        return $block;
    }
}

if ( ! function_exists( 'html_to_blocks_raw_handler' ) ) {
    /**
     * Downstream wrapper example for consumers that keep an existing raw handler.
     *
     * @param array<string, mixed> $args Raw-handler arguments. The `HTML` key carries source HTML.
     * @return array<int, array<string, mixed>> parse_blocks()-compatible block arrays.
     */
    function html_to_blocks_raw_handler( array $args ): array {
        $html = isset( $args['HTML'] ) ? (string) $args['HTML'] : '';

        if ( '' === trim( $html ) ) {
            return array();
        }

        return html_to_blocks_convert( $html, $args );
    }
}

if ( ! function_exists( 'html_to_blocks_convert' ) ) {
    /**
     * Downstream wrapper example for direct HTML conversion callers.
     *
     * @param string $html Source HTML.
     * @param array<string, mixed> $args Conversion context retained for the future transformer options contract.
     * @return array<int, array<string, mixed>> parse_blocks()-compatible block arrays.
     */
    function html_to_blocks_convert( string $html, array $args = array() ): array {
        $result = html_to_blocks_transformer()->transform( $html );
        $blocks = $result->blocks;

        foreach ( $result->fallbacks as $fallback ) {
            if ( ! is_array( $fallback ) || empty( $fallback['html'] ) ) {
                continue;
            }

            $blocks[] = html_to_blocks_create_unsupported_html_fallback_block(
                (string) $fallback['html'],
                array(
                    'reason'               => 'no_transform',
                    'tag_name'             => strtoupper( (string) ( $fallback['tag'] ?? '' ) ),
                    'source'               => HtmlTransformer::class,
                    'transformer_fallback' => $fallback,
                    'args'                 => $args,
                )
            );
        }

        return $blocks;
    }
}
