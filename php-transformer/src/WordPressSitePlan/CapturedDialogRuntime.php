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
    if ( is_array( $shell ) && ! empty( $shell['adopt'] ) ) {
        // An adopted source panel is the dialog: the Group renders no element,
        // and its bindings join the panel's own opening tag.
        if ( ! preg_match( '/^<div\b[^>]*>(.*)<\/div>$/s', trim( $content ), $match ) ) return $content;
        return preg_replace_callback( '/^(\s*)<([a-z][a-z0-9-]*)\b([^>]*?)(\/?)>/i', static function ( array $tag ) use ( $shell ): string {
            $added = '';
            foreach ( $shell['attributes'] ?? array() as $name => $value ) {
                if ( ! preg_match( '/^(?:id|data-blocks-engine-[a-z-]+)$/D', $name ) || ! is_string( $value ) || preg_match( '/\s' . $name . '\s*=/i', $tag[3] ) ) continue;
                $added .= ' ' . $name . '="' . esc_attr( $value ) . '"';
            }
            return $tag[1] . '<' . $tag[2] . $tag[3] . $added . $tag[4] . '>';
        }, $match[1], 1 ) ?? $content;
    }
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
