<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Front-end native semantics for editable core option lists. */
final class NativeSelectRuntime
{
    public static function source(): string
    {
        return <<<'PHP'
add_filter( 'render_block_core/list', static function ( string $content, array $block ): string {
    $attrs = $block['attrs']['metadata']['blocksEngineSelect'] ?? null;
    if ( ! is_array( $attrs ) ) return $content;
    $html = '<select';
    foreach ( array( 'id', 'name', 'form', 'size', 'ariaLabel', 'placeholder', 'className', 'style' ) as $key ) {
        if ( '' !== (string) ( $attrs[$key] ?? '' ) ) $html .= ' ' . ( array( 'className' => 'class', 'ariaLabel' => 'aria-label' )[$key] ?? $key ) . '="' . esc_attr( $attrs[$key] ) . '"';
    }
    foreach ( array( 'required', 'disabled', 'multiple' ) as $key ) if ( ! empty( $attrs[$key] ) ) $html .= ' ' . $key;
    foreach ( $attrs['dataAttributes'] ?? array() as $name => $value ) {
        if ( preg_match( '/^data-(?!wp-)[a-z0-9_.:-]+$/D', $name ) && ! str_starts_with( $name, 'data-blocks-engine-' ) ) $html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
    }
    $options = ''; $state = array(); $has_state = false; $group = 0;
    foreach ( $block['innerBlocks'] ?? array() as $item ) {
        if ( 'core/list-item' !== ( $item['blockName'] ?? '' ) ) continue;
        $option = $item['attrs']['metadata']['blocksEngineSelectOption'] ?? array();
        // Read the saved core label, so editor changes are the native label too.
        $label = html_entity_decode( wp_strip_all_tags( $item['innerHTML'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $next = $option['group']['index'] ?? 0;
        if ( $next !== $group ) {
            if ( $group ) $options .= '</optgroup>';
            if ( $next ) $options .= '<optgroup label="' . esc_attr( $option['group']['label'] ?? '' ) . '"' . ( ! empty( $option['group']['disabled'] ) ? ' disabled' : '' ) . '>';
            $group = $next;
        }
        $options .= '<option' . ( false === ( $option['valueDeclared'] ?? true ) ? '' : ' value="' . esc_attr( $option['value'] ?? $label ) . '"' ) . ( ! empty( $option['selected'] ) ? ' selected' : '' ) . ( ! empty( $option['disabled'] ) ? ' disabled' : '' ) . '>' . esc_html( $label ) . '</option>';
        $has_state = $has_state || array_key_exists( 'initialSelected', $option );
        $state[] = array( 'selected' => $option['initialSelected'] ?? ! empty( $option['selected'] ), 'defaultSelected' => ! empty( $option['selected'] ) );
    }
    if ( $group ) $options .= '</optgroup>';
    if ( $has_state ) $html .= ' data-blocks-engine-control-state="' . esc_attr( wp_json_encode( array( 'version' => 1, 'kind' => 'select', 'options' => $state ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '"';
    $html .= '>' . $options . '</select>';
    if ( ! empty( $attrs['label'] ) ) {
        $caption = '<label' . ( ! empty( $attrs['labelFor'] ) ? ' for="' . esc_attr( $attrs['labelFor'] ) . '"' : '' ) . ( ! empty( $attrs['labelClassName'] ) ? ' class="' . esc_attr( $attrs['labelClassName'] ) . '"' : '' ) . ( ! empty( $attrs['labelStyle'] ) ? ' style="' . esc_attr( $attrs['labelStyle'] ) . '"' : '' ) . '>' . wp_kses_post( ! empty( $attrs['labelMarkup'] ) ? $attrs['labelMarkup'] : esc_html( $attrs['label'] ) );
        $html = ! empty( $attrs['labelFor'] )
            ? ( ! empty( $attrs['labelAfterControl'] ) ? $html . $caption . '</label>' : $caption . '</label>' . $html )
            : $caption . $html . '</label>';
    }
    return $html;
}, 10, 2 );
PHP;
    }
}
