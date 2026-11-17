<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\VisualIframeBlockGenerator;

/**
 * Frontend frame for a core/embed whose URL no oEmbed provider serves.
 *
 * The transformer keeps such a source iframe as an editable core/embed of its
 * URL and records the frame's bounded attributes in block metadata. Core would
 * render the URL as a link, so the theme renders the source's own bare iframe
 * from the block's current URL and that metadata, and registers an embed
 * handler for the imported URLs so the editor previews them without a fetch.
 */
final class IframeEmbedRuntime
{
    /** @param array<int,array<string,mixed>> $documents */
    public static function source(array $documents): string
    {
        $urls = self::frameUrls($documents);
        if (array() === $urls) return '';
        $pattern = '#^(?:' . implode('|', array_map(static fn(string $url): string => preg_quote($url, '#'), $urls)) . ')$#';
        $key = VisualIframeBlockGenerator::FRAME_METADATA;

        return str_replace(array('__PATTERN__', '__KEY__'), array(var_export($pattern, true), var_export($key, true)), <<<'PHP'
$blocks_engine_safe_frame_url = static function ( $url ): bool {
    $parts = is_string( $url ) ? wp_parse_url( $url ) : false;
    return is_array( $parts ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && '' !== (string) ( $parts['host'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
};
$blocks_engine_frame_html = static function ( array $attributes ): string {
    $html = '<iframe';
    foreach ( $attributes as $name => $value ) {
        if ( true === $value ) $html .= ' ' . $name . '=""';
        elseif ( is_string( $value ) && '' !== trim( $value ) ) $html .= ' ' . $name . '="' . ( 'src' === $name ? esc_url( $value ) : esc_attr( trim( $value ) ) ) . '"';
    }
    return $html . '></iframe>';
};
add_action( 'init', static function () use ( $blocks_engine_safe_frame_url, $blocks_engine_frame_html ): void {
    wp_embed_register_handler( 'blocks-engine-iframe', __PATTERN__, static function ( array $matches, array $attr, string $url ) use ( $blocks_engine_safe_frame_url, $blocks_engine_frame_html ) {
        if ( ! $blocks_engine_safe_frame_url( $url ) ) return false;
        return $blocks_engine_frame_html( array( 'src' => $url, 'width' => (string) ( $attr['width'] ?? '' ), 'height' => (string) ( $attr['height'] ?? '' ), 'style' => 'max-width:100%;border:0' ) );
    } );
} );
add_filter( 'render_block_core/embed', static function ( string $content, array $block ) use ( $blocks_engine_safe_frame_url, $blocks_engine_frame_html ): string {
    $frame = $block['attrs']['metadata'][ __KEY__ ] ?? null;
    $url = $block['attrs']['url'] ?? '';
    if ( ! is_array( $frame ) || ! $blocks_engine_safe_frame_url( $url ) ) return $content;
    $attributes = array( 'class' => $block['attrs']['className'] ?? '', 'src' => $url );
    foreach ( array( 'title' => 'title', 'width' => 'width', 'height' => 'height', 'allow' => 'allow', 'loading' => 'loading', 'sandbox' => 'sandbox', 'referrerPolicy' => 'referrerpolicy' ) as $key => $name ) $attributes[ $name ] = is_string( $frame[ $key ] ?? null ) ? $frame[ $key ] : '';
    $attributes['allowfullscreen'] = true === ( $frame['allowFullScreen'] ?? false );
    $attributes['style'] = 'max-width:100%';
    // The source frame itself, not Core's figure: a wrapper would change the
    // containing block and the selectors that size the frame.
    return $blocks_engine_frame_html( $attributes );
}, 10, 2 );
PHP);
    }

    /**
     * URLs of the core/embed frames the documents carry.
     *
     * @param array<int,array<string,mixed>> $documents
     * @return list<string>
     */
    private static function frameUrls(array $documents): array
    {
        $urls = array();
        foreach ($documents as $document) {
            $markup = (string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? '');
            if (!str_contains($markup, VisualIframeBlockGenerator::FRAME_METADATA)) continue;
            preg_match_all('/<!--\s+wp:embed\s+(\{.*?\})\s+\/?-->/s', $markup, $matches);
            foreach ($matches[1] as $json) {
                $attrs = json_decode($json, true);
                if (is_array($attrs) && is_array($attrs['metadata'][VisualIframeBlockGenerator::FRAME_METADATA] ?? null) && is_string($attrs['url'] ?? null) && '' !== $attrs['url']) $urls[$attrs['url']] = true;
            }
        }
        $urls = array_keys($urls);
        sort($urls, SORT_STRING);
        return $urls;
    }
}
