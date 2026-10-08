<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds the editable companion that owns static browser-native GET forms. */
final class AuthoredNativeFormBlockGenerator
{
    public const LOCAL_NAME = 'authored-native-form';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Native GET Form',
            'category' => 'widgets',
            'description' => 'An editable browser-native GET form.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'action' => array( 'type' => 'string', 'default' => '' ),
                'method' => array( 'type' => 'string', 'default' => 'get' ),
                'methodDeclared' => array( 'type' => 'boolean', 'default' => false ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'id' => array( 'type' => 'string', 'default' => '' ),
                'name' => array( 'type' => 'string', 'default' => '' ),
                'ariaLabel' => array( 'type' => 'string', 'default' => '' ),
                'target' => array( 'type' => 'string', 'default' => '' ),
                'autocomplete' => array( 'type' => 'string', 'default' => '' ),
                'noValidate' => array( 'type' => 'boolean', 'default' => false ),
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
    var InnerBlocks = blockEditor.InnerBlocks;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var SelectControl = components.SelectControl;
    var ToggleControl = components.ToggleControl;
    var attributes = __BLOCK_ATTRIBUTES__;
    function edit( props ) { var attrs = props.attributes; return createElement( element.Fragment, null, createElement( InspectorControls, null, createElement( PanelBody, { title: 'Form settings' }, createElement( TextControl, { label: 'Action', value: attrs.action || '', onChange: function( action ) { props.setAttributes( { action: action } ); } } ), createElement( SelectControl, { label: 'Method', value: attrs.method || 'get', options: [ { label: 'GET', value: 'get' } ], onChange: function( method ) { props.setAttributes( { method: method, methodDeclared: true } ); } } ), createElement( TextControl, { label: 'Form name', value: attrs.name || '', onChange: function( name ) { props.setAttributes( { name: name } ); } } ), createElement( ToggleControl, { label: 'Disable validation', checked: !!attrs.noValidate, onChange: function( noValidate ) { props.setAttributes( { noValidate: noValidate } ); } } ) ) ), createElement( 'form', { action: attrs.action || undefined, method: attrs.method || 'get', className: attrs.className || undefined, id: attrs.id || undefined, name: attrs.name || undefined, 'aria-label': attrs.ariaLabel || undefined, target: attrs.target || undefined, autoComplete: attrs.autocomplete || undefined, noValidate: attrs.noValidate }, createElement( InnerBlocks, null ) ) ); }
    function save( props ) { var attrs = props.attributes; return createElement( 'form', { action: attrs.action || undefined, method: attrs.methodDeclared ? ( attrs.method || 'get' ) : undefined, className: attrs.className || undefined, id: attrs.id || undefined, name: attrs.name || undefined, 'aria-label': attrs.ariaLabel || undefined, target: attrs.target || undefined, autoComplete: attrs.autocomplete || undefined, noValidate: attrs.noValidate || undefined }, createElement( InnerBlocks.Content, null ) ); }
    blocks.registerBlockType( '__BLOCK_NAME__', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array( 'index.js' => str_replace(array('__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__'), array($namespace . '/' . self::LOCAL_NAME, json_encode($this->blockJson($namespace)['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $script) );
    }

    /** @param array<string, mixed> $attrs @return array{opening: string, closing: string} */
    public function markup(array $attrs): array
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<form';
        foreach ( array( 'action', 'className', 'id', 'name', 'ariaLabel', 'target', 'autocomplete' ) as $key ) {
            $value = (string) ($attrs[$key] ?? '');
            if ( '' !== $value ) {
                $html .= ' ' . ( 'className' === $key ? 'class' : ( 'ariaLabel' === $key ? 'aria-label' : $key ) ) . '="' . $escape($value) . '"';
            }
        }
        if ( ! empty($attrs['methodDeclared']) ) {
            $html .= ' method="get"';
        }
        if ( ! empty($attrs['noValidate']) ) {
            $html .= ' novalidate';
        }
        return array( 'opening' => $html . '>', 'closing' => '</form>' );
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array( 'name' => self::LOCAL_NAME, 'block_json' => $this->blockJson($namespace), 'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ), 'assets' => $this->assets($namespace) );
    }
}
