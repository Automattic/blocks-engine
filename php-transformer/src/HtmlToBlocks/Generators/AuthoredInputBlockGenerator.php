<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/**
 * Builds the static companion block for compact, editable native input controls.
 */
final class AuthoredInputBlockGenerator
{
    public const NAME = 'blocks-engine/authored-input';

    /** @return array<string, mixed> */
    public function blockJson(): array
    {
        return array(
            'apiVersion' => 3,
            'name' => self::NAME,
            'title' => 'Input Field',
            'category' => 'widgets',
            'description' => 'An editable native input field.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'type' => array( 'type' => 'string', 'default' => 'text' ),
                'id' => array( 'type' => 'string', 'default' => '' ),
                'name' => array( 'type' => 'string', 'default' => '' ),
                'value' => array( 'type' => 'string', 'default' => '' ),
                'placeholder' => array( 'type' => 'string', 'default' => '' ),
                'ariaLabel' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'min' => array( 'type' => 'string', 'default' => '' ),
                'max' => array( 'type' => 'string', 'default' => '' ),
                'step' => array( 'type' => 'string', 'default' => '' ),
                'required' => array( 'type' => 'boolean', 'default' => false ),
                'disabled' => array( 'type' => 'boolean', 'default' => false ),
                'readOnly' => array( 'type' => 'boolean', 'default' => false ),
                'checked' => array( 'type' => 'boolean', 'default' => false ),
                'dataAttributes' => array( 'type' => 'object', 'default' => array() ),
                'label' => array( 'type' => 'string', 'default' => '' ),
                'labelClassName' => array( 'type' => 'string', 'default' => '' ),
                'labelStyle' => array( 'type' => 'string', 'default' => '' ),
            ),
            'supports' => array( 'html' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(): array
    {
        $script = <<<'JS'
( function( blocks, element ) {
    var createElement = element.createElement;
    var attributes = __BLOCK_ATTRIBUTES__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function styleObject( value ) { if ( ! value ) return undefined; return String( value ).split( ';' ).reduce( function( output, declaration ) { var separator = declaration.indexOf( ':' ); if ( separator < 1 ) return output; var name = declaration.slice( 0, separator ).trim(); var property = name.indexOf( '--' ) === 0 ? name : name.replace( /-([a-z])/g, function( _, letter ) { return letter.toUpperCase(); } ); output[ property ] = declaration.slice( separator + 1 ).trim(); return output; }, {} ); }
    function dataAttributes( attrs ) { return Object.keys( attrs.dataAttributes || {} ).reduce( function( output, name ) { if ( /^data-(?!wp-)[a-z0-9_.:-]+$/.test( name ) ) output[ name ] = attrs.dataAttributes[ name ]; return output; }, {} ); }
    function inputProps( attrs ) { return Object.assign( { type: attrs.type || 'text', id: attrs.id || undefined, name: attrs.name || undefined, value: attrs.value || undefined, placeholder: attrs.placeholder || undefined, 'aria-label': attrs.ariaLabel || undefined, className: attrs.className || undefined, style: styleObject( attrs.style ), min: attrs.min || undefined, max: attrs.max || undefined, step: attrs.step || undefined, required: attrs.required, disabled: attrs.disabled, readOnly: attrs.readOnly, checked: attrs.checked }, dataAttributes( attrs ) ); }
    function markup( attrs ) { var output = '<input'; [ 'type', 'id', 'name', 'value', 'placeholder', 'ariaLabel', 'className', 'style', 'min', 'max', 'step' ].forEach( function( key ) { if ( attrs[ key ] ) output += ' ' + ( 'className' === key ? 'class' : ( 'ariaLabel' === key ? 'aria-label' : key ) ) + '="' + escapeAttribute( attrs[ key ] ) + '"'; } ); Object.keys( dataAttributes( attrs ) ).sort().forEach( function( name ) { output += ' ' + name + '="' + escapeAttribute( attrs.dataAttributes[ name ] ) + '"'; } ); [ 'required', 'disabled', 'readOnly', 'checked' ].forEach( function( key ) { if ( attrs[ key ] ) output += ' ' + ( 'readOnly' === key ? 'readonly' : key ); } ); output += '>'; if ( attrs.label ) output = '<label' + ( attrs.labelClassName ? ' class="' + escapeAttribute( attrs.labelClassName ) + '"' : '' ) + ( attrs.labelStyle ? ' style="' + escapeAttribute( attrs.labelStyle ) + '"' : '' ) + '>' + escapeAttribute( attrs.label ) + output + '</label>'; return output; }
    function edit( props ) { var attrs = props.attributes; var input = createElement( 'input', Object.assign( inputProps( attrs ), { onChange: function( event ) { var next = { value: event.target.value }; if ( 'checkbox' === attrs.type || 'radio' === attrs.type ) next.checked = event.target.checked; props.setAttributes( next ); } } ) ); return attrs.label ? createElement( 'label', { className: attrs.labelClassName || undefined, style: styleObject( attrs.labelStyle ) }, attrs.label, input ) : input; }
    function save( props ) { return createElement( element.RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( 'blocks-engine/authored-input', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.element );
JS;

        return array(
            'index.js' => str_replace('__BLOCK_ATTRIBUTES__', json_encode($this->blockJson()['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $script),
        );
    }

    /** @param array<string, mixed> $attrs */
    public function markup(array $attrs): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $markup = '<input';
        foreach ( array( 'type', 'id', 'name', 'value', 'placeholder', 'ariaLabel', 'className', 'style', 'min', 'max', 'step' ) as $key ) {
            $value = (string) ($attrs[$key] ?? '');
            if ( '' !== $value ) {
                $markup .= ' ' . ( 'className' === $key ? 'class' : ( 'ariaLabel' === $key ? 'aria-label' : $key ) ) . '="' . $escape($value) . '"';
            }
        }
        foreach ( array( 'required', 'disabled', 'readOnly', 'checked' ) as $key ) {
            if ( ! empty($attrs[$key]) ) {
                $markup .= ' ' . ( 'readOnly' === $key ? 'readonly' : $key );
            }
        }

        $dataAttributes = is_array($attrs['dataAttributes'] ?? null) ? $attrs['dataAttributes'] : array();
        ksort($dataAttributes);
        foreach ( $dataAttributes as $name => $value ) {
            if ( 1 === preg_match('/^data-(?!wp-)[a-z0-9_.:-]+$/', (string) $name) ) {
                $markup .= ' ' . $name . '="' . $escape($value) . '"';
            }
        }

        $markup .= '>';
        if ( '' !== (string) ($attrs['label'] ?? '') ) {
            $labelAttributes = '';
            if ( '' !== (string) ($attrs['labelClassName'] ?? '') ) {
                $labelAttributes .= ' class="' . $escape($attrs['labelClassName']) . '"';
            }
            if ( '' !== (string) ($attrs['labelStyle'] ?? '') ) {
                $labelAttributes .= ' style="' . $escape($attrs['labelStyle']) . '"';
            }
            $markup = '<label' . $labelAttributes . '>' . $escape($attrs['label']) . $markup . '</label>';
        }

        return $markup;
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return array( 'name' => 'authored-input', 'block_json' => $this->blockJson(), 'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ), 'assets' => $this->assets() );
    }
}
