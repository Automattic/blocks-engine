<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/**
 * Builds the static companion block for compact, editable native textarea controls.
 */
final class AuthoredTextareaBlockGenerator
{
    public const LOCAL_NAME = 'authored-textarea';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Textarea Field',
            'category' => 'widgets',
            'description' => 'An editable native textarea field.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'id' => array( 'type' => 'string', 'default' => '' ),
                'name' => array( 'type' => 'string', 'default' => '' ),
                'value' => array( 'type' => 'string', 'default' => '' ),
                'placeholder' => array( 'type' => 'string', 'default' => '' ),
                'ariaLabel' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'rows' => array( 'type' => 'string', 'default' => '' ),
                'cols' => array( 'type' => 'string', 'default' => '' ),
                'maxLength' => array( 'type' => 'string', 'default' => '' ),
                'required' => array( 'type' => 'boolean', 'default' => false ),
                'disabled' => array( 'type' => 'boolean', 'default' => false ),
                'readOnly' => array( 'type' => 'boolean', 'default' => false ),
                'label' => array( 'type' => 'string', 'default' => '' ),
                'labelClassName' => array( 'type' => 'string', 'default' => '' ),
                'labelStyle' => array( 'type' => 'string', 'default' => '' ),
            ),
            'supports' => array( 'html' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(string $namespace): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var createElement = element.createElement;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var ToggleControl = components.ToggleControl;
    var attributes = __BLOCK_ATTRIBUTES__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function styleObject( value ) { if ( ! value ) return undefined; return String( value ).split( ';' ).reduce( function( output, declaration ) { var separator = declaration.indexOf( ':' ); if ( separator < 1 ) return output; var name = declaration.slice( 0, separator ).trim(); var property = name.indexOf( '--' ) === 0 ? name : name.replace( /-([a-z])/g, function( _, letter ) { return letter.toUpperCase(); } ); output[ property ] = declaration.slice( separator + 1 ).trim(); return output; }, {} ); }
    function markup( attrs ) { var output = '<textarea'; [ 'id', 'name', 'placeholder', 'ariaLabel', 'className', 'style', 'rows', 'cols', 'maxLength' ].forEach( function( key ) { if ( attrs[ key ] ) output += ' ' + ( 'className' === key ? 'class' : ( 'ariaLabel' === key ? 'aria-label' : ( 'maxLength' === key ? 'maxlength' : key ) ) ) + '="' + escapeAttribute( attrs[ key ] ) + '"'; } ); [ 'required', 'disabled', 'readOnly' ].forEach( function( key ) { if ( attrs[ key ] ) output += ' ' + ( 'readOnly' === key ? 'readonly' : key ); } ); output += '>' + escapeAttribute( attrs.value ) + '</textarea>'; if ( attrs.label ) output = '<label' + ( attrs.labelClassName ? ' class="' + escapeAttribute( attrs.labelClassName ) + '"' : '' ) + ( attrs.labelStyle ? ' style="' + escapeAttribute( attrs.labelStyle ) + '"' : '' ) + '>' + escapeAttribute( attrs.label ) + output + '</label>'; return output; }
    function edit( props ) { var attrs = props.attributes; var textarea = createElement( 'textarea', { id: attrs.id || undefined, name: attrs.name || undefined, value: attrs.value || '', placeholder: attrs.placeholder || undefined, 'aria-label': attrs.ariaLabel || undefined, className: attrs.className || undefined, style: styleObject( attrs.style ), rows: attrs.rows || undefined, cols: attrs.cols || undefined, maxLength: attrs.maxLength || undefined, required: attrs.required, disabled: attrs.disabled, readOnly: attrs.readOnly, onChange: function( event ) { props.setAttributes( { value: event.target.value } ); } } ); var field = attrs.label ? createElement( 'label', { className: attrs.labelClassName || undefined, style: styleObject( attrs.labelStyle ) }, attrs.label, textarea ) : textarea; return createElement( element.Fragment, null, createElement( InspectorControls, null, createElement( PanelBody, { title: 'Field settings' }, createElement( TextControl, { label: 'Label', value: attrs.label || '', onChange: function( label ) { props.setAttributes( { label: label } ); } } ), createElement( TextControl, { label: 'Field name', value: attrs.name || '', onChange: function( name ) { props.setAttributes( { name: name } ); } } ), createElement( TextControl, { label: 'Placeholder', value: attrs.placeholder || '', onChange: function( placeholder ) { props.setAttributes( { placeholder: placeholder } ); } } ), createElement( TextControl, { label: 'Rows', value: attrs.rows || '', onChange: function( rows ) { props.setAttributes( { rows: rows } ); } } ), createElement( ToggleControl, { label: 'Required', checked: !!attrs.required, onChange: function( required ) { props.setAttributes( { required: required } ); } } ), createElement( ToggleControl, { label: 'Disabled', checked: !!attrs.disabled, onChange: function( disabled ) { props.setAttributes( { disabled: disabled } ); } } ) ) ), field ); }
    function save( props ) { return createElement( element.RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( '__BLOCK_NAME__', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array(
            'index.js' => str_replace(array('__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__'), array($namespace . '/' . self::LOCAL_NAME, json_encode($this->blockJson($namespace)['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $script),
        );
    }

    /** @param array<string, mixed> $attrs */
    public function markup(array $attrs): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $markup = '<textarea';
        foreach ( array( 'id', 'name', 'placeholder', 'ariaLabel', 'className', 'style', 'rows', 'cols', 'maxLength' ) as $key ) {
            $value = (string) ($attrs[$key] ?? '');
            if ( '' !== $value ) {
                $markup .= ' ' . ( 'className' === $key ? 'class' : ( 'ariaLabel' === $key ? 'aria-label' : ( 'maxLength' === $key ? 'maxlength' : $key ) ) ) . '="' . $escape($value) . '"';
            }
        }
        foreach ( array( 'required', 'disabled', 'readOnly' ) as $key ) {
            if ( ! empty($attrs[$key]) ) {
                $markup .= ' ' . ( 'readOnly' === $key ? 'readonly' : $key );
            }
        }

        $markup .= '>' . $escape($attrs['value'] ?? '') . '</textarea>';
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
    public function definition(string $namespace): array
    {
        return array( 'name' => self::LOCAL_NAME, 'block_json' => $this->blockJson($namespace), 'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ), 'assets' => $this->assets($namespace) );
    }
}
