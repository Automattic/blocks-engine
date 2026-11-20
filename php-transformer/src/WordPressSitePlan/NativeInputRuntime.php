<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Front-end native semantics for text-entry fields edited as core search blocks. */
final class NativeInputRuntime
{
    public static function source(): string
    {
        return <<<'PHP'
add_filter( 'render_block_core/search', static function ( string $content, array $block ): string {
    $attrs = $block['attrs']['metadata']['blocksEngineInput'] ?? null;
    if ( ! is_array( $attrs ) ) return $content;
    // Read the saved core placeholder and label, so editor changes reach the native field.
    $attrs['placeholder'] = (string) ( $block['attrs']['placeholder'] ?? '' );
    $label = ! empty( $block['attrs']['showLabel'] ) ? (string) ( $block['attrs']['label'] ?? '' ) : '';
    $names = array( 'className' => 'class', 'ariaLabel' => 'aria-label', 'minLength' => 'minlength', 'maxLength' => 'maxlength', 'inputMode' => 'inputmode' );
    $html = '<input';
    foreach ( array( 'type', 'id', 'name', 'form', 'value', 'placeholder', 'ariaLabel', 'className', 'style', 'min', 'max', 'step', 'minLength', 'maxLength', 'pattern', 'autocomplete', 'inputMode' ) as $key ) {
        $value = (string) ( $attrs[ $key ] ?? '' );
        if ( '' !== $value || ( 'value' === $key && ! empty( $attrs['valueDeclared'] ) ) ) $html .= ' ' . ( $names[ $key ] ?? $key ) . '="' . esc_attr( $value ) . '"';
    }
    $data = is_array( $attrs['dataAttributes'] ?? null ) ? $attrs['dataAttributes'] : array();
    ksort( $data );
    foreach ( $data as $name => $value ) {
        if ( is_string( $name ) && preg_match( '/^data-(?!wp-)[a-z0-9_.:-]+$/D', $name ) && ! str_starts_with( $name, 'data-blocks-engine-' ) ) $html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
    }
    foreach ( array( 'required' => 'required', 'disabled' => 'disabled', 'readOnly' => 'readonly' ) as $key => $name ) if ( ! empty( $attrs[ $key ] ) ) $html .= ' ' . $name;
    $html .= '>';
    if ( '' === $label ) return $html;
    $label = wp_kses_post( $label );
    // An explicit association keeps two sibling boxes. Wrapping the input in
    // its formerly separate label drops sibling spacing and inherits the
    // caption's line-height into the field's surrounding line box.
    if ( ! empty( $attrs['labelFor'] ) ) {
        $caption = '<label for="' . esc_attr( $attrs['labelFor'] ) . '"' . ( ! empty( $attrs['labelId'] ) ? ' id="' . esc_attr( $attrs['labelId'] ) . '"' : '' ) . ( ! empty( $attrs['labelClassName'] ) ? ' class="' . esc_attr( $attrs['labelClassName'] ) . '"' : '' ) . ( ! empty( $attrs['labelStyle'] ) ? ' style="' . esc_attr( $attrs['labelStyle'] ) . '"' : '' ) . '>' . $label . '</label>';
        return ! empty( $attrs['labelAfterControl'] ) ? $html . $caption : $caption . $html;
    }
    return '<label' . ( ! empty( $attrs['labelId'] ) ? ' id="' . esc_attr( $attrs['labelId'] ) . '"' : '' ) . ( ! empty( $attrs['labelClassName'] ) ? ' class="' . esc_attr( $attrs['labelClassName'] ) . '"' : '' ) . ( ! empty( $attrs['labelStyle'] ) ? ' style="' . esc_attr( $attrs['labelStyle'] ) . '"' : '' ) . '>' . ( ! empty( $attrs['labelAfterControl'] ) ? $html . $label : $label . $html ) . '</label>';
}, 10, 2 );
PHP;
    }
}
