<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use DOMElement;
use DOMText;

/** Builds editable media data while retaining captured selector topology. */
final class ResponsiveMediaBlockGenerator
{
    public const LOCAL_NAME = 'responsive-media';
    public const RENDERER = 'blocks-engine/responsive-media/v1';

    /** Convert sanitized media markup into data, never an HTML attribute. */
    public static function mediaNodes(string $html): array
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $convert = static function ($parent) use (&$convert): array {
            $nodes = array();
            foreach ($parent->childNodes as $child) {
                if ($child instanceof DOMText) {
                    $nodes[] = array('text' => $child->textContent);
                } elseif ($child instanceof DOMElement) {
                    $attributes = array();
                    foreach ($child->attributes as $attribute) $attributes[$attribute->name] = $attribute->value;
                    $nodes[] = array('tag' => $child->tagName, 'attributes' => $attributes, 'children' => $convert($child));
                }
            }
            return $nodes;
        };
        return $convert($document->getElementsByTagName('body')->item(0));
    }

    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Responsive Media',
            'category' => 'media',
            'description' => 'Editable captured media with its source wrappers and source selection.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'media' => array('type' => 'array', 'default' => array(), 'role' => 'content'),
                'kind' => array('type' => 'string', 'default' => 'media'),
            ),
            'supports' => array('html' => false),
        );
    }

    public function assets(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var createElement = element.createElement;
    var voidTags = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' ];
    function escape( value ) { return String( value ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ); }
    function markup( nodes ) {
        return ( nodes || [] ).map( function( node ) {
            if ( node.text !== undefined ) return escape( node.text );
            var tag = node.tag;
            if ( ! /^[a-z][a-z0-9:-]*$/i.test( tag ) || /^(script|iframe|object|embed)$/i.test( tag ) ) return '';
            var opening = '<' + tag;
            Object.keys( node.attributes || {} ).forEach( function( name ) {
                if ( /^[a-z_:][a-z0-9_.:-]*$/i.test( name ) && ! /^on/i.test( name ) ) opening += ' ' + name + '="' + escape( node.attributes[ name ] ) + '"';
            } );
            return opening + '>' + ( voidTags.indexOf( tag.toLowerCase() ) !== -1 ? '' : markup( node.children ) + '</' + tag + '>' );
        } ).join( '' );
    }
    function edit( props ) {
        var media = JSON.parse( JSON.stringify( props.attributes.media || [] ) );
        var fields = [];
        function visit( nodes ) {
            nodes.forEach( function( node ) {
                var attrs = node.attributes = Object.assign( {}, node.attributes || {} );
                function field( attribute, label ) {
                    fields.push( createElement( components.TextControl, {
                        key: fields.length, label: label, value: attrs[ attribute ] || '',
                        onChange: function( value ) { attrs[ attribute ] = value; props.setAttributes( { media: media } ); }
                    } ) );
                }
                switch ( node.tag ) {
                    case 'img':
                        field( 'src', 'Image URL' ); field( 'alt', 'Alternative text' );
                        if ( 'srcset' in attrs ) field( 'srcset', 'Image candidates' );
                        if ( 'sizes' in attrs ) field( 'sizes', 'Image sizes' );
                        break;
                    case 'source':
                        field( 'srcset' in attrs ? 'srcset' : 'src', 'Source URL or candidates' );
                        if ( 'media' in attrs ) field( 'media', 'Media condition' );
                        if ( 'sizes' in attrs ) field( 'sizes', 'Source sizes' );
                        break;
                    case 'video': field( 'poster', 'Poster URL' ); // Falls through.
                    case 'audio': field( 'src', 'Media URL' ); break;
                    case 'a': field( 'href', 'Link URL' ); break;
                }
                if ( node.text !== undefined && node.text.trim() ) fields.push( createElement( components.TextControl, {
                    key: fields.length, label: 'Caption', value: node.text,
                    onChange: function( value ) { node.text = value; props.setAttributes( { media: media } ); }
                } ) );
                visit( node.children || [] );
            } );
        }
        visit( media );
        return createElement( element.Fragment, {},
            props.isSelected ? createElement( blockEditor.InspectorControls, {}, createElement( components.PanelBody, { title: 'Media settings' }, fields ) ) : null,
            createElement( 'div', Object.assign( {}, blockEditor.useBlockProps(), { dangerouslySetInnerHTML: { __html: markup( media ) } } ) )
        );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { media: { type: 'array', default: [], role: 'content' }, kind: { type: 'string', default: 'media' } },
        supports: { html: false },
        edit: edit,
        save: function( props ) { return createElement( element.RawHTML, null, markup( props.attributes.media ) ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;
        return array('index.js' => str_replace('__BLOCK_NAME__', $blockName, $script));
    }

    public function definition(string $namespace): array
    {
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'assets' => $this->assets($namespace . '/' . self::LOCAL_NAME),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element')),
        );
    }
}
