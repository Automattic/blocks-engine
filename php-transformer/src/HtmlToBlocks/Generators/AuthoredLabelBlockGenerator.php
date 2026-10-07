<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** A RichText label host for the association that core/group cannot serialize. */
final class AuthoredLabelBlockGenerator
{
    public const LOCAL_NAME = 'authored-label';

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        $attributes = array();
        foreach (array('htmlFor', 'id', 'className', 'style', 'content') as $name) {
            $attributes[$name] = array('type' => 'string', 'default' => '');
        }
        $script = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var RichText = blockEditor.RichText;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function markup( attrs ) {
        var output = '<label';
        [ 'htmlFor', 'id', 'className', 'style' ].forEach( function( key ) {
            if ( attrs[ key ] ) output += ' ' + ( key === 'htmlFor' ? 'for' : ( key === 'className' ? 'class' : key ) ) + '="' + escapeAttribute( attrs[ key ] ) + '"';
        } );
        return output + '>' + ( attrs.content || '' ) + '</label>';
    }
    function edit( props ) {
        var attrs = props.attributes;
        var style = {};
        // Use the browser's declaration parser for custom properties and quoted values.
        var probe = document.createElement( 'label' );
        probe.setAttribute( 'style', attrs.style || '' );
        Array.from( probe.style ).forEach( function( name ) {
            var key = name.indexOf( '--' ) === 0 ? name : name.replace( /-([a-z])/g, function( _, letter ) { return letter.toUpperCase(); } );
            style[ key ] = probe.style.getPropertyValue( name );
        } );
        return createElement( RichText, Object.assign( blockEditor.useBlockProps( { htmlFor: attrs.htmlFor || undefined, id: attrs.id || undefined, className: attrs.className || undefined, style: style } ), {
            tagName: 'label', value: attrs.content || '',
            onChange: function( content ) { props.setAttributes( { content: content } ); }
        } ) );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__, supports: { html: false }, edit: edit,
        save: function( props ) { return createElement( element.RawHTML, null, markup( props.attributes ) ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => array(
                'apiVersion' => 3,
                'name' => $namespace . '/' . self::LOCAL_NAME,
                'title' => 'Control Label',
                'category' => 'text',
                'editorScript' => 'file:./index.js',
                'attributes' => $attributes,
                'supports' => array('html' => false),
            ),
            'assets' => array('index.js' => str_replace(
                array('__BLOCK_NAME__', '__ATTRIBUTES__'),
                array($namespace . '/' . self::LOCAL_NAME, json_encode($attributes, JSON_THROW_ON_ERROR)),
                $script
            )),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')),
        );
    }

    /** @param array<string, mixed> $attrs */
    public function markup(array $attrs): string
    {
        $html = '<label';
        foreach (array('htmlFor' => 'for', 'id' => 'id', 'className' => 'class', 'style' => 'style') as $key => $name) {
            if ('' !== (string) ($attrs[$key] ?? '')) {
                $html .= ' ' . $name . '="' . htmlspecialchars((string) $attrs[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        return $html . '>' . ($attrs['content'] ?? '') . '</label>';
    }
}
