<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Front-end native semantics for editable core preformatted blocks that hold a captured textarea's default value. */
final class NativeTextareaRuntime
{
    public static function source(): string
    {
        return <<<'PHP'
add_filter( 'render_block_core/preformatted', static function ( string $content, array $block ): string {
    $attrs = $block['attrs']['metadata']['blocksEngineTextarea'] ?? null;
    if ( ! is_array( $attrs ) ) return $content;
    // Read the saved core text, so editor changes are the native default value too.
    $inner = (string) preg_replace( '#^\s*<pre\b[^>]*>|</pre>\s*$#i', '', (string) ( $block['innerHTML'] ?? '' ) );
    $value = html_entity_decode( strip_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", $inner ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $html = '<textarea';
    foreach ( array( 'id', 'name', 'form', 'placeholder', 'ariaLabel', 'className', 'style', 'rows', 'cols', 'maxLength', 'minLength' ) as $key ) {
        if ( '' !== (string) ( $attrs[$key] ?? '' ) ) $html .= ' ' . ( array( 'className' => 'class', 'ariaLabel' => 'aria-label', 'maxLength' => 'maxlength', 'minLength' => 'minlength' )[$key] ?? $key ) . '="' . esc_attr( $attrs[$key] ) . '"';
    }
    foreach ( array( 'required', 'disabled', 'readOnly' ) as $key ) if ( ! empty( $attrs[$key] ) ) $html .= ' ' . ( 'readOnly' === $key ? 'readonly' : $key );
    $data = $attrs['dataAttributes'] ?? array();
    ksort( $data );
    foreach ( $data as $name => $data_value ) {
        if ( preg_match( '/^data-(?!wp-)[a-z0-9_.:-]+$/D', $name ) && ! str_starts_with( $name, 'data-blocks-engine-' ) ) $html .= ' ' . $name . '="' . esc_attr( $data_value ) . '"';
    }
    if ( array_key_exists( 'initialValue', $attrs ) ) $html .= ' data-blocks-engine-control-state="' . esc_attr( wp_json_encode( array( 'version' => 1, 'kind' => 'textarea', 'value' => (string) $attrs['initialValue'], 'defaultValue' => $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '"';
    $html .= '>' . ( str_starts_with( $value, "\n" ) ? "\n" : '' ) . htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</textarea>';
    if ( ! empty( $attrs['label'] ) ) $html = '<label' . ( ! empty( $attrs['labelClassName'] ) ? ' class="' . esc_attr( $attrs['labelClassName'] ) . '"' : '' ) . ( ! empty( $attrs['labelStyle'] ) ? ' style="' . esc_attr( $attrs['labelStyle'] ) . '"' : '' ) . '>' . wp_kses_post( ! empty( $attrs['labelMarkup'] ) ? $attrs['labelMarkup'] : esc_html( $attrs['label'] ) ) . $html . '</label>';
    return $html;
}, 10, 2 );
PHP;
    }
}
