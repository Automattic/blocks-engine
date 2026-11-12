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
    $opener = $block['attrs']['metadata']['blocksEngineNavigationOpener'] ?? array();
    if ( ! empty( $opener['classes'] ) ) {
        $content = preg_replace_callback( '/(<button\\b[^>]*\\bclass=")([^"]*\\bwp-block-navigation__responsive-container-open\\b[^"]*)(")/', static function ( array $match ) use ( $opener ): string {
            return $match[1] . $match[2] . ' ' . esc_attr( $opener['classes'] ) . $match[3];
        }, $content, 1 ) ?? $content;
    }
    if ( ! empty( $opener['slot'] ) && is_numeric( $opener['breakpoint'] ?? null ) ) {
        $slot = wp_json_encode( $opener['slot'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        $boundary = (float) $opener['breakpoint'];
        $content = preg_replace( '/<nav\\b/', '<nav data-blocks-engine-navigation-slot="' . esc_attr( $opener['slot'] ) . '"', $content, 1 ) ?? $content;
        // Move the whole native host, never clone its IDs or interactivity directives.
        // On wide screens its source nav identity returns to the inline slot.
        $content .= '<script>(function(){function init(){var id=' . $slot . ',nav=document.querySelector(\'[data-blocks-engine-navigation-slot="\'+id+\'"]\'),inline=document.querySelector(\'[data-blocks-engine-navigation-inline-slot="\'+id+\'"]\');if(!nav||!inline)return;var slot=nav.parentElement,mq=matchMedia("(max-width:' . $boundary . 'px)");function place(){(mq.matches?slot:inline).appendChild(nav)}place();mq.addEventListener("change",place)}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",init);else init()})();</script>';
    }
    $svg = $opener['svg'] ?? null;
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
