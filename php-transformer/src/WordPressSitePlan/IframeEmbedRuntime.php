<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\WordPress\EmbedFrameMetadata;

/**
 * Frontend frame for a core/embed whose URL no oEmbed provider serves.
 *
 * The transformer keeps such a source iframe as an editable core/embed of its
 * URL and records the frame's bounded attributes in block metadata. Core would
 * render the URL as a link, so the theme renders the source's own bare iframe
 * from the block's current URL and that metadata, and registers an embed
 * handler for the imported URLs so the editor previews them without a fetch.
 * The preview is the same recorded frame, not WordPress's default embed box
 * (content width by 1.5x its height), so the editor canvas keeps the source's
 * frame geometry around the blocks beside it.
 */
final class IframeEmbedRuntime
{
    /** @param array<int,array<string,mixed>> $documents */
    public static function source(array $documents): string
    {
        $frames = self::frames($documents);
        if (array() === $frames) return '';
        $pattern = '#^(?:' . implode('|', array_map(static fn(string $url): string => preg_quote($url, '#'), array_keys($frames))) . ')$#';
        $key = EmbedFrameMetadata::KEY;

        return str_replace(array('__PATTERN__', '__KEY__', '__FRAMES__'), array(var_export($pattern, true), var_export($key, true), var_export($frames, true)), <<<'PHP'
$blocks_engine_safe_frame_url = static function ( $url ): bool {
    $parts = is_string( $url ) ? wp_parse_url( $url ) : false;
    return is_array( $parts ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && '' !== (string) ( $parts['host'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
};
$blocks_engine_frame_html = static function ( string $url, $frame, string $class ): string {
    $frame = is_array( $frame ) ? $frame : array();
    $attributes = array( 'class' => $class, 'src' => $url );
    foreach ( array( 'title' => 'title', 'width' => 'width', 'height' => 'height', 'allow' => 'allow', 'loading' => 'loading', 'sandbox' => 'sandbox', 'referrerPolicy' => 'referrerpolicy' ) as $key => $name ) $attributes[ $name ] = is_string( $frame[ $key ] ?? null ) ? $frame[ $key ] : '';
    $attributes['allowfullscreen'] = true === ( $frame['allowFullScreen'] ?? false );
    $attributes['style'] = 'max-width:100%';
    $html = '<iframe';
    foreach ( $attributes as $name => $value ) {
        if ( true === $value ) $html .= ' ' . $name . '=""';
        elseif ( is_string( $value ) && '' !== trim( $value ) ) $html .= ' ' . $name . '="' . ( 'src' === $name ? esc_url( $value ) : esc_attr( trim( $value ) ) ) . '"';
    }
    return $html . '></iframe>';
};
add_action( 'init', static function () use ( $blocks_engine_safe_frame_url, $blocks_engine_frame_html ): void {
    $frames = __FRAMES__;
    // The editor preview of an imported frame URL is the recorded source frame.
    wp_embed_register_handler( 'blocks-engine-iframe', __PATTERN__, static function ( array $matches, array $attr, string $url ) use ( $blocks_engine_safe_frame_url, $blocks_engine_frame_html, $frames ) {
        if ( ! $blocks_engine_safe_frame_url( $url ) || ! isset( $frames[ $url ] ) ) return false;
        return $blocks_engine_frame_html( $url, $frames[ $url ], '' );
    } );
} );
add_filter( 'render_block_core/embed', static function ( string $content, array $block ) use ( $blocks_engine_safe_frame_url, $blocks_engine_frame_html ): string {
    $frame = $block['attrs']['metadata'][ __KEY__ ] ?? null;
    $url = $block['attrs']['url'] ?? '';
    if ( ! is_array( $frame ) || ! $blocks_engine_safe_frame_url( $url ) ) return $content;
    // The source frame itself, not Core's figure: a wrapper would change the
    // containing block and the selectors that size the frame.
    return $blocks_engine_frame_html( $url, $frame, is_string( $block['attrs']['className'] ?? null ) ? $block['attrs']['className'] : '' );
}, 10, 2 );
PHP);
    }

    /**
     * Recorded frame attributes of the core/embed frames the documents carry,
     * keyed by URL; the first recording of a URL wins.
     *
     * @param array<int,array<string,mixed>> $documents
     * @return array<string,array<string,string|bool>>
     */
    private static function frames(array $documents): array
    {
        $frames = array();
        foreach ($documents as $document) {
            $markup = (string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? '');
            if (!str_contains($markup, EmbedFrameMetadata::KEY)) continue;
            preg_match_all('/<!--\s+wp:embed\s+(\{.*?\})\s+\/?-->/s', $markup, $matches);
            foreach ($matches[1] as $json) {
                $attrs = json_decode($json, true);
                $frame = is_array($attrs) ? ($attrs['metadata'][EmbedFrameMetadata::KEY] ?? null) : null;
                $url = is_array($attrs) ? ($attrs['url'] ?? null) : null;
                if (!is_array($frame) || !is_string($url) || '' === $url || isset($frames[$url])) continue;
                $frames[$url] = array_filter($frame, static fn(mixed $value): bool => is_string($value) || is_bool($value));
            }
        }
        ksort($frames, SORT_STRING);
        return $frames;
    }
}
