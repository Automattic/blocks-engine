<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Document-context paint for native, replaceable SVG images. */
final class InlineSvgImageRuntime
{
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
    // changes child selectors, grid placement and inherited paint.
    return $svg;
}, 10, 2 );
PHP;
    }
}
