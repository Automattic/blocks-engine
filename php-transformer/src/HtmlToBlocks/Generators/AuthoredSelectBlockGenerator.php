<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/**
 * Builds the static companion block for compact, editable native select controls.
 */
final class AuthoredSelectBlockGenerator
{
    public const LOCAL_NAME = 'authored-select';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Select Field',
            'category' => 'widgets',
            'description' => 'An editable native select field.',
            'editorScript' => 'file:./index.js',
            'style' => 'file:./style.css',
            'attributes' => array(
                'id' => array( 'type' => 'string', 'default' => '' ),
                'name' => array( 'type' => 'string', 'default' => '' ),
                'ariaLabel' => array( 'type' => 'string', 'default' => '' ),
                'placeholder' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'label' => array( 'type' => 'string', 'default' => '' ),
                'labelClassName' => array( 'type' => 'string', 'default' => '' ),
                'labelStyle' => array( 'type' => 'string', 'default' => '' ),
                'required' => array( 'type' => 'boolean', 'default' => false ),
                'disabled' => array( 'type' => 'boolean', 'default' => false ),
                'options' => array( 'type' => 'array', 'default' => array() ),
                // Compatibility metadata for consumers of the former readable
                // approximation. It has no rendered geometry.
                'selectedSummary' => array( 'type' => 'string', 'default' => '' ),
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
    var RichText = blockEditor.RichText;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var attributes = __BLOCK_ATTRIBUTES__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function selectAttributes( attrs ) { var output = ''; [ 'id', 'name', 'ariaLabel', 'placeholder', 'className', 'style' ].forEach( function( key ) { if ( attrs[ key ] ) { output += ' ' + ( 'className' === key ? 'class' : ( 'ariaLabel' === key ? 'aria-label' : key ) ) + '="' + escapeAttribute( attrs[ key ] ) + '"'; } } ); return output; }
    function markup( attrs ) { var output = '<select' + selectAttributes( attrs ) + '>'; ( attrs.options || [] ).forEach( function( option ) { var value = Object.prototype.hasOwnProperty.call( option, 'value' ) ? option.value : option.label; output += '<option value="' + escapeAttribute( value ) + '"' + ( option.selected ? ' selected' : '' ) + ( option.disabled ? ' disabled' : '' ) + '>' + ( option.label || '' ) + '</option>'; } ); output += '</select>'; return attrs.label ? '<label' + ( attrs.labelClassName ? ' class="' + escapeAttribute( attrs.labelClassName ) + '"' : '' ) + ( attrs.labelStyle ? ' style="' + escapeAttribute( attrs.labelStyle ) + '"' : '' ) + '>' + escapeAttribute( attrs.label ) + output + '</label>' : output; }
    function optionText( options ) { return ( options || [] ).map( function( option ) { return String( option.value || '' ) + '|' + String( option.label || '' ) + ( option.selected ? '|selected' : '' ) + ( option.disabled ? '|disabled' : '' ); } ).join( '\n' ); }
    function parseOptions( value ) { return String( value || '' ).split( /\n/ ).map( function( line ) { var parts = line.split( '|' ); if ( !parts[ 1 ] ) return null; return { value: parts[ 0 ], label: parts[ 1 ], selected: 'selected' === parts[ 2 ] || 'selected' === parts[ 3 ], disabled: 'disabled' === parts[ 2 ] || 'disabled' === parts[ 3 ] }; } ).filter( Boolean ); }
    function edit( props ) { var attrs = props.attributes; var options = ( attrs.options || [] ).map( function( option ) { return createElement( 'option', { value: option.value || option.label, disabled: option.disabled, selected: option.selected, key: option.value || option.label }, option.label ); } ); var select = createElement( 'select', { id: attrs.id || undefined, name: attrs.name || undefined, className: attrs.className || undefined, style: attrs.style || undefined, onChange: function( event ) { props.setAttributes( { options: ( attrs.options || [] ).map( function( option ) { return Object.assign( {}, option, { selected: option.value === event.target.value } ); } ) } ); } }, options ); return createElement( element.Fragment, null, createElement( InspectorControls, null, createElement( PanelBody, { title: 'Select settings' }, createElement( TextControl, { label: 'Label', value: attrs.label || '', onChange: function( label ) { props.setAttributes( { label: label } ); } } ), createElement( TextControl, { label: 'Field name', value: attrs.name || '', onChange: function( name ) { props.setAttributes( { name: name } ); } } ), createElement( TextControl, { label: 'Placeholder', value: attrs.placeholder || '', onChange: function( placeholder ) { props.setAttributes( { placeholder: placeholder } ); } } ), createElement( TextareaControl, { label: 'Options', help: 'One per line: value|label|selected|disabled', value: optionText( attrs.options ), onChange: function( value ) { props.setAttributes( { options: parseOptions( value ) } ); } } ) ) ), attrs.label ? createElement( 'label', { className: attrs.labelClassName || undefined, style: attrs.labelStyle || undefined }, attrs.label, select ) : select ); }
    function save( props ) { return createElement( element.RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( '__BLOCK_NAME__', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array(
            'index.js' => str_replace(array('__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__'), array($namespace . '/' . self::LOCAL_NAME, json_encode($this->blockJson($namespace)['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $script),
            // The legacy core/group boundary is retained for compatibility without
            // introducing a layout box around the authored native control.
            'style.css' => '.wp-block-group.blocks-engine-authored-select-wrapper{display:contents}',
        );
    }

    /** @param array<string, mixed> $attrs */
    public function markup(array $attrs): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $select = '';
        foreach ( array( 'id', 'name', 'ariaLabel', 'placeholder', 'className', 'style' ) as $key ) {
            $value = trim((string) ($attrs[$key] ?? ''));
            if ( '' !== $value ) {
                $select .= ' ' . ( 'className' === $key ? 'class' : ( 'ariaLabel' === $key ? 'aria-label' : $key ) ) . '="' . $escape($value) . '"';
            }
        }
        $markup = '<select' . $select . ( ! empty($attrs['required']) ? ' required' : '' ) . ( ! empty($attrs['disabled']) ? ' disabled' : '' ) . '>';
        foreach ( $attrs['options'] ?? array() as $option ) {
            if ( ! is_array($option) || '' === trim((string) ($option['label'] ?? '')) ) {
                continue;
            }
            $value = array_key_exists('value', $option) ? (string) $option['value'] : (string) $option['label'];
            $markup .= '<option value="' . $escape($value) . '"'
                . ( ! empty($option['selected']) ? ' selected' : '' )
                . ( ! empty($option['disabled']) ? ' disabled' : '' )
                . '>' . $escape($option['label']) . '</option>';
        }

        $markup .= '</select>';
        if ( '' !== (string) ($attrs['label'] ?? '') ) {
            $markup = '<label'
                . ( '' !== (string) ($attrs['labelClassName'] ?? '') ? ' class="' . $escape($attrs['labelClassName']) . '"' : '' )
                . ( '' !== (string) ($attrs['labelStyle'] ?? '') ? ' style="' . $escape($attrs['labelStyle']) . '"' : '' )
                . '>' . $escape($attrs['label']) . $markup . '</label>';
        }
        return $markup;
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array( 'name' => self::LOCAL_NAME, 'block_json' => $this->blockJson($namespace), 'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ), 'assets' => $this->assets($namespace) );
    }
}
