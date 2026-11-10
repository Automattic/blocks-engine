<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\WordPress\CapturedDialogBlockContract;

/** Front-end native shell for editable core Group dialog content. */
final class CapturedDialogRuntime
{
    public static function bootstrap(): string
    {
        $definition = (new CapturedDialogBlockContract())->definition('unused/captured-dialog');
        $script = var_export($definition['view_js'], true);
        $style = var_export($definition['assets']['style.css'], true);
        return <<<'PHP'
add_filter( 'render_block_core/group', static function ( string $content, array $block ): string {
    $shell = $block['attrs']['metadata']['blocksEngineDialog'] ?? null;
    if ( ! is_array( $shell ) || ! in_array( $shell['tagName'] ?? '', array( 'dialog', 'div' ), true ) ) return $content;
    $tag = $shell['tagName'];
    $opening = '<' . $tag;
    foreach ( $shell['attributes'] ?? array() as $name => $value ) {
        if ( ! preg_match( '/^(?:id|class|style|aria-[a-z-]+|data-blocks-engine-[a-z-]+)$/D', $name ) || ! is_string( $value ) ) continue;
        $opening .= ' ' . $name . '="' . esc_attr( $value ) . '"';
    }
    $opening .= '>';
    if ( ! empty( $shell['addCloseButton'] ) ) $opening .= '<button type="button" data-blocks-engine-dialog-close="true" aria-label="Close">Close</button>';
    // Replace only the canonical Group's outer div; leave all child markup intact.
    if ( ! preg_match( '/^<div\b[^>]*>(.*)<\/div>$/s', trim( $content ), $match ) ) return $content;
    return $opening . $match[1] . '</' . $tag . '>';
}, 20, 2 );
PHP
            . "\nadd_action( 'wp_enqueue_scripts', static function (): void {\n"
            . "wp_register_script( 'blocks-engine-dialog', false, array(), null, true );\nwp_enqueue_script( 'blocks-engine-dialog' );\nwp_add_inline_script( 'blocks-engine-dialog', {$script} );\n"
            . "wp_register_style( 'blocks-engine-dialog', false );\nwp_enqueue_style( 'blocks-engine-dialog' );\nwp_add_inline_style( 'blocks-engine-dialog', {$style} );\n} );";
    }
}
