<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\WordPress\AuthoredControlStateScript;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CapturedChoiceGroupScript;

/** Front-end source shell and captured selection behavior for core Groups. */
final class CapturedChoiceGroupRuntime
{
    public static function bootstrap(): string
    {
        $script = AuthoredControlStateScript::viewScript() . "\n" . CapturedChoiceGroupScript::viewScript();
        return <<<'PHP'
add_filter( 'render_block_core/group', static function ( string $content, array $block ): string {
    $shell = $block['attrs']['metadata']['blocksEngineChoiceGroup'] ?? null;
    if ( ! is_array( $shell ) || ! is_string( $shell['tagName'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9-]*$/D', $shell['tagName'] ) ) return $content;
    if ( ! preg_match( '/^<div\b[^>]*>(.*)<\/div>$/s', trim( $content ), $match ) ) return $content;
    $opening = '<' . $shell['tagName'];
    foreach ( $shell['attributes'] ?? array() as $name => $value ) {
        if ( ! in_array( $name, array( 'id', 'class', 'aria-label', 'data-blocks-engine-choice-group', 'data-blocks-engine-choice-config' ), true ) || ! is_string( $value ) ) continue;
        $opening .= ' ' . $name . '="' . esc_attr( $value ) . '"';
    }
    return $opening . '>' . $match[1] . '</' . $shell['tagName'] . '>';
}, 20, 2 );
PHP
            . "\nadd_action( 'wp_enqueue_scripts', static function (): void { wp_register_script( 'blocks-engine-choice-groups', false, array(), null, true ); wp_enqueue_script( 'blocks-engine-choice-groups' ); wp_add_inline_script( 'blocks-engine-choice-groups', " . var_export($script, true) . " ); } );";
    }
}
