<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\SourceAttribute;

/** A RichText label host for the association that core/group cannot serialize. */
final class AuthoredLabelBlockGenerator
{
    public const LOCAL_NAME = 'authored-label';
    public const HOST_ATTRIBUTES = array('htmlFor' => 'for', 'id' => 'id', 'className' => 'class', 'style' => 'style');

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        $attributes = array();
        foreach (array('htmlFor', 'id', 'className', 'style', 'content') as $name) {
            $attributes[$name] = array('type' => 'string', 'default' => '');
        }
        $attributes['sourceAttributes'] = array('type' => 'object', 'default' => array());
        $script = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var RichText = blockEditor.RichText;
    var hostAttributes = __HOST_ATTRIBUTES__;
    var staticPolicy = __STATIC_POLICY__;
    var staticPattern = new RegExp( staticPolicy.pattern );
    var booleanAttributes = __BOOLEAN_ATTRIBUTES__;
    function sourceAttributes( attrs ) {
        var safe = {};
        Object.keys( attrs.sourceAttributes || {} ).sort().forEach( function( name ) {
            var value = attrs.sourceAttributes[ name ];
            if ( typeof value !== 'string' || Object.values( hostAttributes ).indexOf( name ) !== -1 || staticPolicy.excludedNames.indexOf( name ) !== -1 || name.indexOf( staticPolicy.excludedPrefix ) === 0 ) return;
            if ( staticPolicy.names.indexOf( name ) !== -1 || staticPattern.test( name ) ) safe[ name ] = value;
        } );
        return safe;
    }
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function markup( attrs ) {
        var output = '<label';
        Object.keys( hostAttributes ).forEach( function( key ) {
            if ( attrs[ key ] ) output += ' ' + hostAttributes[ key ] + '="' + escapeAttribute( attrs[ key ] ) + '"';
        } );
        var source = sourceAttributes( attrs );
        Object.keys( source ).forEach( function( name ) { output += ' ' + name + '="' + escapeAttribute( source[ name ] ) + '"'; } );
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
        var source = sourceAttributes( attrs );
        if ( source.tabindex !== undefined ) { source.tabIndex = source.tabindex; delete source.tabindex; }
        Object.keys( source ).forEach( function( name ) { if ( booleanAttributes.indexOf( name ) !== -1 ) source[ name ] = true; } );
        return createElement( RichText, Object.assign( blockEditor.useBlockProps( Object.assign( source, { htmlFor: attrs.htmlFor || undefined, id: attrs.id || undefined, className: attrs.className || undefined, style: style } ) ), {
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
                array('__BLOCK_NAME__', '__ATTRIBUTES__', '__HOST_ATTRIBUTES__', '__STATIC_POLICY__', '__BOOLEAN_ATTRIBUTES__'),
                array($namespace . '/' . self::LOCAL_NAME, json_encode($attributes, JSON_THROW_ON_ERROR), json_encode(self::HOST_ATTRIBUTES, JSON_THROW_ON_ERROR), json_encode(SourceAttribute::staticPolicy(), JSON_THROW_ON_ERROR), json_encode(LayoutShellBlockGenerator::BOOLEAN_ATTRIBUTES, JSON_THROW_ON_ERROR)),
                $script
            )),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')),
        );
    }

    /** @param array<string, mixed> $attrs */
    public function markup(array $attrs): string
    {
        $html = '<label';
        foreach (self::HOST_ATTRIBUTES as $key => $name) {
            if ('' !== (string) ($attrs[$key] ?? '')) {
                $html .= ' ' . $name . '="' . htmlspecialchars((string) $attrs[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        $source = SourceAttribute::staticAttributes(is_array($attrs['sourceAttributes'] ?? null) ? $attrs['sourceAttributes'] : array(), array_values(self::HOST_ATTRIBUTES));
        return $html . SourceDom::htmlAttributeString($source) . '>' . ($attrs['content'] ?? '') . '</label>';
    }
}
