<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/**
 * Builds the static companion block for compact, editable native button controls.
 */
final class AuthoredButtonBlockGenerator
{
    public const LOCAL_NAME = 'authored-button';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Button Control',
            'category' => 'widgets',
            'description' => 'An editable native button control.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'type' => array( 'type' => 'string', 'default' => 'submit' ),
                'id' => array( 'type' => 'string', 'default' => '' ),
                'name' => array( 'type' => 'string', 'default' => '' ),
                'ariaLabel' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'text' => array( 'type' => 'string', 'default' => '' ),
                'disabled' => array( 'type' => 'boolean', 'default' => false ),
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
    var SelectControl = components.SelectControl;
    var ToggleControl = components.ToggleControl;
    var attributes = __BLOCK_ATTRIBUTES__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function styleObject( value ) { if ( ! value ) return undefined; return String( value ).split( ';' ).reduce( function( output, declaration ) { var separator = declaration.indexOf( ':' ); if ( separator < 1 ) return output; var name = declaration.slice( 0, separator ).trim(); var property = name.indexOf( '--' ) === 0 ? name : name.replace( /-([a-z])/g, function( _, letter ) { return letter.toUpperCase(); } ); output[ property ] = declaration.slice( separator + 1 ).trim(); return output; }, {} ); }
    function buttonType( value ) { return [ 'button', 'reset', 'submit' ].indexOf( value ) !== -1 ? value : 'submit'; }
    function markup( attrs ) { var output = '<button'; [ 'type', 'id', 'name', 'ariaLabel', 'className', 'style' ].forEach( function( key ) { var value = 'type' === key ? buttonType( attrs.type ) : attrs[ key ]; if ( value ) output += ' ' + ( 'className' === key ? 'class' : ( 'ariaLabel' === key ? 'aria-label' : key ) ) + '="' + escapeAttribute( value ) + '"'; } ); if ( attrs.disabled ) output += ' disabled'; output += '>' + escapeAttribute( attrs.text ) + '</button>'; return output; }
    function edit( props ) { var attrs = props.attributes; var button = createElement( 'button', { type: buttonType( attrs.type ), id: attrs.id || undefined, name: attrs.name || undefined, 'aria-label': attrs.ariaLabel || undefined, className: attrs.className || undefined, style: styleObject( attrs.style ), disabled: attrs.disabled }, attrs.text || '' ); return createElement( element.Fragment, null, createElement( InspectorControls, null, createElement( PanelBody, { title: 'Button settings' }, createElement( TextControl, { label: 'Label', value: attrs.text || '', onChange: function( text ) { props.setAttributes( { text: text } ); } } ), createElement( TextControl, { label: 'Name', value: attrs.name || '', onChange: function( name ) { props.setAttributes( { name: name } ); } } ), createElement( SelectControl, { label: 'Type', value: buttonType( attrs.type ), options: [ 'submit', 'button', 'reset' ].map( function( type ) { return { label: type, value: type }; } ), onChange: function( type ) { props.setAttributes( { type: type } ); } } ), createElement( ToggleControl, { label: 'Disabled', checked: !!attrs.disabled, onChange: function( disabled ) { props.setAttributes( { disabled: disabled } ); } } ) ) ), button ); }
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
        $type = (string) ($attrs['type'] ?? 'submit');
        if ( ! in_array($type, array( 'button', 'reset', 'submit' ), true) ) {
            $type = 'submit';
        }
        $markup = '<button type="' . $escape($type) . '"';
        foreach ( array( 'id', 'name', 'ariaLabel', 'className', 'style' ) as $key ) {
            $value = (string) ($attrs[$key] ?? '');
            if ( '' !== $value ) {
                $markup .= ' ' . ( 'className' === $key ? 'class' : ( 'ariaLabel' === $key ? 'aria-label' : $key ) ) . '="' . $escape($value) . '"';
            }
        }
        if ( ! empty($attrs['disabled']) ) {
            $markup .= ' disabled';
        }

        return $markup . '>' . $escape($attrs['text'] ?? '') . '</button>';
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array( 'name' => self::LOCAL_NAME, 'block_json' => $this->blockJson($namespace), 'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ), 'assets' => $this->assets($namespace) );
    }
}
