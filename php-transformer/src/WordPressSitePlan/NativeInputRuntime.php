<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Front-end native semantics for editable core text-entry fields. */
final class NativeInputRuntime
{
    public static function source(): string
    {
        return <<<'PHP'
add_filter( 'render_block_core/paragraph', static function ( string $content, array $block ): string {
    $attrs = $block['attrs']['metadata']['blocksEngineInput'] ?? null;
    if ( ! is_array( $attrs ) ) return $content;
    // Read the saved core text, so editor changes are the native field text too.
    $text_attribute = 'value' === ( $attrs['textAttribute'] ?? '' ) ? 'value' : 'placeholder';
    $attrs[ $text_attribute ] = html_entity_decode( wp_strip_all_tags( $block['innerHTML'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    if ( 'value' === $text_attribute ) $attrs['valueDeclared'] = true;
    $names = array( 'className' => 'class', 'ariaLabel' => 'aria-label', 'minLength' => 'minlength', 'maxLength' => 'maxlength', 'inputMode' => 'inputmode' );
    $html = '<input';
    foreach ( array( 'type', 'id', 'name', 'form', 'value', 'placeholder', 'ariaLabel', 'className', 'style', 'min', 'max', 'step', 'minLength', 'maxLength', 'pattern', 'autocomplete', 'inputMode' ) as $key ) {
        $value = (string) ( $attrs[ $key ] ?? '' );
        if ( '' !== $value || ( 'value' === $key && ! empty( $attrs['valueDeclared'] ) ) ) $html .= ' ' . ( $names[ $key ] ?? $key ) . '="' . esc_attr( $value ) . '"';
    }
    $data = is_array( $attrs['dataAttributes'] ?? null ) ? $attrs['dataAttributes'] : array();
    ksort( $data );
    foreach ( $data as $name => $value ) {
        if ( is_string( $name ) && preg_match( '/^data-(?!wp-)[a-z0-9_.:-]+$/D', $name ) && ! in_array( $name, array( 'data-blocks-engine-capture-native-control-state', 'data-blocks-engine-control-state' ), true ) ) $html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
    }
    foreach ( array( 'required' => 'required', 'disabled' => 'disabled', 'readOnly' => 'readonly' ) as $key => $name ) if ( ! empty( $attrs[ $key ] ) ) $html .= ' ' . $name;
    $html .= '>';
    if ( empty( $attrs['label'] ) ) return $html;
    $label = ! empty( $attrs['labelMarkup'] ) ? wp_kses_post( $attrs['labelMarkup'] ) : esc_html( $attrs['label'] );
    return '<label' . ( ! empty( $attrs['labelId'] ) ? ' id="' . esc_attr( $attrs['labelId'] ) . '"' : '' ) . ( ! empty( $attrs['labelClassName'] ) ? ' class="' . esc_attr( $attrs['labelClassName'] ) . '"' : '' ) . ( ! empty( $attrs['labelStyle'] ) ? ' style="' . esc_attr( $attrs['labelStyle'] ) . '"' : '' ) . '>' . ( ! empty( $attrs['labelAfterControl'] ) ? $html . $label : $label . $html ) . '</label>';
}, 10, 2 );
PHP;
    }
}
