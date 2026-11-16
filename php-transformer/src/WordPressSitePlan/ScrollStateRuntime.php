<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\WordPress\ScrollStateScript;

/** Captured scroll behavior belongs to the source shell, not a custom block. */
final class ScrollStateRuntime
{
    public static function bootstrap(): string
    {
        return <<<'PHP'
add_filter( 'render_block_core/group', static function ( string $content, array $block ): string {
    $shell = $block['attrs']['metadata']['blocksEngineScrollState'] ?? null;
    if ( ! is_array( $shell ) || ! is_string( $shell['tagName'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9-]*$/D', $shell['tagName'] ) ) return $content;
    if ( ! preg_match( '/^<(div|header|footer|main|section|article|aside|nav)\b[^>]*>(.*)<\/\1>$/s', trim( $content ), $match ) ) return $content;
    $opening = '<' . $shell['tagName'];
    // Use the live Group attributes so editor changes to identity remain effective.
    foreach ( array( 'anchor' => 'id', 'className' => 'class' ) as $attr => $name ) {
        $value = $block['attrs'][$attr] ?? '';
        if ( is_string( $value ) && '' !== $value ) $opening .= ' ' . $name . '="' . esc_attr( $value ) . '"';
    }
    $config = is_string( $shell['config'] ?? null ) ? $shell['config'] : '{}';
    $opening .= ' data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config="' . esc_attr( $config ) . '">';
    return $opening . $match[2] . '</' . $shell['tagName'] . '>';
}, 20, 2 );
PHP
            . "\nadd_action( 'wp_enqueue_scripts', static function (): void { wp_register_script( 'blocks-engine-scroll-state', false, array(), null, true ); wp_enqueue_script( 'blocks-engine-scroll-state' ); wp_add_inline_script( 'blocks-engine-scroll-state', " . var_export(ScrollStateScript::viewScript(), true) . " ); } );";
    }
}
