<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Native Core navigation owns events; only its generated icon is replaced. */
final class NavigationOpenerRuntime
{
    public static function source(): string
    {
        return <<<'PHP'
add_filter( 'render_block_core/navigation', static function ( string $content, array $block ): string {
    $svg = $block['attrs']['metadata']['blocksEngineNavigationOpener']['svg'] ?? null;
    if ( ! is_string( $svg ) || '' === $svg ) return $content;
    $attributes = array_fill_keys( array( 'xmlns', 'width', 'height', 'viewbox', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'points', 'transform', 'class', 'style' ), true );
    $allowed = array_fill_keys( array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon' ), $attributes );
    $svg = wp_kses( $svg, $allowed );
    if ( ! str_starts_with( $svg, '<svg' ) ) return $content;
    $svg = preg_replace( '/^<svg\b/', '<svg aria-hidden="true" focusable="false"', $svg, 1 );
    return preg_replace_callback( '/(<button\b[^>]*\bclass="[^"]*\bwp-block-navigation__responsive-container-open\b[^"]*"[^>]*>)(.*?)(<\/button>)/s', static function ( array $match ) use ( $svg ): string {
        return $match[1] . $svg . $match[3];
    }, $content, 1 ) ?? $content;
}, 10, 2 );
PHP;
    }
}
