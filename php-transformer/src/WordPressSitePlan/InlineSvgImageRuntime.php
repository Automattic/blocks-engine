<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Document-context paint for native, replaceable SVG images. */
final class InlineSvgImageRuntime
{
    /**
     * The engine-prefix test this runtime emits, and its form in a bootstrap
     * whose classes ClassPublisher published: the published class is read
     * back to its engine name before the prefix is tested.
     */
    public const PUBLISHED_CLASS_MATCHERS = array(
        "str_starts_with( \$class, 'be-inline-geometry-' )" => "str_starts_with( __PUBLISHED___engine_class( \$class ), 'be-inline-geometry-' )",
    );

    public static function source(): string
    {
        return <<<'PHP'
add_filter( 'render_block_core/image', static function ( string $content, array $block ): string {
    $source = $block['attrs']['metadata']['blocksEngineInlineSvg'] ?? null;
    if ( ! is_array( $source ) || ! is_string( $source['svg'] ?? null ) || ! is_string( $source['src'] ?? null ) ) return $content;
    $tag = new WP_HTML_Tag_Processor( $content );
    if ( ! $tag->next_tag( 'IMG' ) || $tag->get_attribute( 'src' ) !== $source['src'] ) return $content;
    $attributes = array_fill_keys( array( 'id', 'class', 'style', 'data-*', 'xmlns', 'xmlns:xlink', 'width', 'height', 'viewbox', 'preserveaspectratio', 'role', 'aria-hidden', 'aria-label', 'focusable', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit', 'opacity', 'clip-path', 'clip-rule', 'mask', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'points', 'transform', 'vector-effect' ), true );
    $svg = trim( wp_kses( $source['svg'], array_fill_keys( array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'title', 'desc' ), $attributes ) ) );
    if ( ! str_starts_with( $svg, '<svg' ) ) return $content;
    // Match the original inline root, not Core's figure: an extra wrapper
    // changes child selectors, grid placement and inherited paint. The figure
    // stood for that root, so its geometry carriers, which on a box-tree route
    // are the root's only styles, move onto it.
    $figure = new WP_HTML_Tag_Processor( $content );
    $carriers = $figure->next_tag() ? array_filter( preg_split( '/\s+/', (string) $figure->get_attribute( 'class' ) ), static fn ( string $class ): bool => str_starts_with( $class, 'be-inline-geometry-' ) ) : array();
    if ( array() !== $carriers ) {
        $root = new WP_HTML_Tag_Processor( $svg );
        if ( $root->next_tag() ) {
            foreach ( $carriers as $class ) $root->add_class( $class );
            $svg = $root->get_updated_html();
        }
    }
    return $svg;
}, 10, 2 );
PHP;
    }
}
