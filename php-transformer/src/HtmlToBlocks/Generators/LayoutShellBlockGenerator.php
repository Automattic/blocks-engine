<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds a bounded List View carrier for exact source wrapper chains. */
final class LayoutShellBlockGenerator
{
    /** @return array<string, mixed> */
    public function definition(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var InnerBlocks = blockEditor.InnerBlocks;
    var useBlockProps = blockEditor.useBlockProps;
    function reactStyle( value ) {
        var probe = document.createElement( 'div' );
        var style = {};
        probe.setAttribute( 'style', value || '' );
        for ( var index = 0; index < probe.style.length; index++ ) {
            var property = probe.style.item( index );
            var key = property.indexOf( '--' ) === 0 ? property : property.replace( /-([a-z])/g, function( _, letter ) { return letter.toUpperCase(); } );
            style[ key ] = probe.style.getPropertyValue( property );
        }
        return style;
    }
    function wrapperProps( attributes ) {
        var props = {};
        Object.keys( attributes || {} ).forEach( function( name ) {
            var value = attributes[ name ];
            if ( name === 'class' ) { props.className = value; }
            else if ( name === 'style' ) { props.style = reactStyle( value ); }
            else if ( name === 'tabindex' ) { props.tabIndex = value; }
            else { props[ name ] = value; }
        } );
        return props;
    }
    function savedContent( wrappers ) {
        var content = createElement( InnerBlocks.Content );
        for ( var index = ( wrappers || [] ).length - 1; index >= 0; index-- ) {
            content = createElement( wrappers[ index ].tagName || 'div', wrapperProps( wrappers[ index ].attributes ), content );
        }
        return content;
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { wrappers: { type: 'array', default: [] } },
        supports: { html: false, reusable: false },
        edit: function() { return createElement( 'div', useBlockProps(), createElement( InnerBlocks ) ); },
        save: function( props ) { return savedContent( props.attributes.wrappers ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        return array(
            'name' => 'layout-shell',
            'block_json' => array(
                'apiVersion' => 3,
                'name' => $blockName,
                'title' => 'Layout Shell',
                'category' => 'design',
                'editorScript' => 'file:./index.js',
                'attributes' => array('wrappers' => array('type' => 'array', 'default' => array())),
                'supports' => array('html' => false, 'reusable' => false),
            ),
            'assets' => array('index.js' => str_replace('__BLOCK_NAME__', $blockName, $script)),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')),
        );
    }
}
