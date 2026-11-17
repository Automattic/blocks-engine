<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Front-end native labels for captions edited as core paragraphs. The
 * paragraph owns the editable RichText and its class; the source `<label>`
 * element, its association and passive host attributes are block metadata.
 */
final class NativeLabelRuntime
{
    public static function source(): string
    {
        return "add_filter( 'render_block_core/paragraph', " . self::callback() . ', 10, 2 );';
    }

    /** The render callback's PHP source, a closure expression. */
    public static function callback(): string
    {
        return <<<'PHP'
static function ( string $content, array $block ): string {
    $host = $block['attrs']['metadata']['blocksEngineLabel'] ?? null;
    if ( ! is_array( $host ) || ! preg_match( '/^\s*<p\b[^>]*>(.*)<\/p>\s*$/s', $content, $match ) ) return $content;
    $html = '<label';
    $class = trim( (string) ( $block['attrs']['className'] ?? '' ) );
    foreach ( array( 'for', 'id' ) as $name ) {
        if ( is_string( $host[ $name ] ?? null ) && '' !== $host[ $name ] ) $html .= ' ' . $name . '="' . esc_attr( $host[ $name ] ) . '"';
    }
    if ( '' !== $class ) $html .= ' class="' . esc_attr( $class ) . '"';
    foreach ( $host as $name => $value ) {
        // Passive attributes only: no event handlers, URLs or interactivity directives.
        if ( ! is_string( $name ) || ! is_string( $value ) || in_array( $name, array( 'for', 'id', 'class', 'data-action', 'data-on', 'data-event' ), true ) || str_starts_with( $name, 'data-wp-' ) || ! preg_match( '/^(?:style|dir|lang|hidden|role|title|tabindex|(?:data|aria)-[a-z0-9_-]+)$/D', $name ) ) continue;
        $html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
    }
    return $html . '>' . $match[1] . '</label>';
}
PHP;
    }
}
