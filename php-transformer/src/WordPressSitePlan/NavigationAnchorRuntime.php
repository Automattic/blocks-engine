<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Keep explicit anchor inline priority on Core's dynamic anchor, not its LI. */
final class NavigationAnchorRuntime
{
    public static function source(): string
    {
        return <<<'PHP'
add_filter( 'render_block_core/navigation-link', static function ( string $content, array $block ): string {
    $presentation = $block['attrs']['metadata']['blocksEngineNavigationAnchor'] ?? null;
    if ( ! is_array( $presentation ) ) return $content;
    $processor = new WP_HTML_Tag_Processor( $content );
    if ( $processor->next_tag( array( 'tag_name' => 'A', 'class_name' => 'wp-block-navigation-item__content' ) ) ) {
        if ( is_string( $presentation['id'] ?? null ) && preg_match( '/^[A-Za-z][A-Za-z0-9_.:-]*$/D', $presentation['id'] ) ) $processor->set_attribute( 'id', $presentation['id'] );
        foreach ( preg_split( '/\s+/', trim( is_string( $presentation['className'] ?? null ) ? $presentation['className'] : '' ) ) ?: array() as $class ) {
            if ( '' !== $class ) $processor->add_class( $class );
        }
        $existing = $processor->get_attribute( 'style' );
        if ( is_string( $presentation['style'] ?? null ) && '' !== $presentation['style'] ) $processor->set_attribute( 'style', ( is_string( $existing ) ? $existing . ';' : '' ) . safecss_filter_attr( $presentation['style'] ) );
    }
    $content = $processor->get_updated_html();
    if ( is_string( $presentation['svg'] ?? null ) && '' !== $presentation['svg'] ) {
        $attributes = array_fill_keys( array( 'xmlns', 'width', 'height', 'viewbox', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'points', 'transform', 'class', 'style' ), true );
        $svg = wp_kses( $presentation['svg'], array_fill_keys( array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon' ), $attributes ) );
        if ( str_starts_with( $svg, '<svg' ) ) {
            $svg = preg_replace( '/^<svg\b/', '<svg aria-hidden="true" focusable="false"', $svg, 1 );
            $content = preg_replace_callback( '/(<span\b[^>]*\bclass="[^"]*\bwp-block-navigation-item__label\b[^"]*"[^>]*>)(.*?)(<\/span>)/s', static function ( array $match ) use ( $svg ): string {
                return $svg . '<span class="wp-block-navigation-item__label" style="font-size:0;line-height:0">' . $match[2] . $match[3];
            }, $content, 1 ) ?? $content;
        }
    }
    if ( is_string( $presentation['itemStyle'] ?? null ) && '' !== $presentation['itemStyle'] ) {
        $item = new WP_HTML_Tag_Processor( $content );
        if ( $item->next_tag( array( 'tag_name' => 'LI', 'class_name' => 'wp-block-navigation-item' ) ) ) {
            $existing = $item->get_attribute( 'style' );
            $item->set_attribute( 'style', safecss_filter_attr( $presentation['itemStyle'] ) . ( is_string( $existing ) ? ';' . $existing : '' ) );
        }
        $content = $item->get_updated_html();
    }
    $open = ''; $close = '';
    foreach ( is_array( $presentation['boxes'] ?? null ) ? $presentation['boxes'] : array() as $box ) {
        if ( ! is_array( $box ) || ! in_array( $box['tag'] ?? null, array( 'div', 'span' ), true ) || ! preg_match( '/^blocks-engine-navigation-box-[a-f0-9]{12}-\d+$/D', $box['marker'] ?? '' ) ) return $content;
        $style = is_string( $box['style'] ?? null ) ? safecss_filter_attr( $box['style'] ) : '';
        $classes = $box['marker'] . ( is_string( $box['className'] ?? null ) ? ' ' . $box['className'] : '' );
        $open .= '<' . $box['tag'] . ' class="' . esc_attr( $classes ) . '"' . ( '' === $style ? '' : ' style="' . esc_attr( $style ) . '"' ) . '>';
        $close = '</' . $box['tag'] . '>' . $close;
    }
    if ( '' === $open ) return $content;
    return preg_replace_callback( '/(<a\b[^>]*\bclass="[^"]*\bwp-block-navigation-item__content\b[^"]*"[^>]*>.*?<\/a>)/s', static fn ( array $match ): string => $open . $match[1] . $close, $content, 1 ) ?? $content;
}, 10, 2 );
PHP;
    }
}
