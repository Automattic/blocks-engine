<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds an editable link when core/button cannot retain its distinct accessible name. */
final class AccessibleLinkBlockGenerator
{
    public const LOCAL_NAME = 'accessible-link';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Accessible Link',
            'category' => 'design',
            'description' => 'An editable link with separate visible and accessible labels.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'href' => array( 'type' => 'string', 'default' => '' ),
                'accessibleLabel' => array( 'type' => 'string', 'default' => '' ),
                'content' => array( 'type' => 'string', 'default' => '' ),
                'iconContent' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'id' => array( 'type' => 'string', 'default' => '' ),
                'linkTarget' => array( 'type' => 'string', 'default' => '' ),
                'rel' => array( 'type' => 'string', 'default' => '' ),
            ),
            'supports' => array( 'html' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var createElement = element.createElement;
    var RichText = blockEditor.RichText;
    var TextControl = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var attributes = __BLOCK_ATTRIBUTES__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function markup( attrs ) { var output = '<a href="' + escapeAttribute( attrs.href ) + '"'; [ [ 'accessibleLabel', 'aria-label' ], [ 'className', 'class' ], [ 'style', 'style' ], [ 'id', 'id' ], [ 'linkTarget', 'target' ], [ 'rel', 'rel' ] ].forEach( function( item ) { if ( attrs[ item[ 0 ] ] ) output += ' ' + item[ 1 ] + '="' + escapeAttribute( attrs[ item[ 0 ] ] ) + '"'; } ); return output + '>' + ( attrs.content || '' ) + ( attrs.iconContent || '' ) + '</a>'; }
    function edit( props ) { var attrs = props.attributes; return createElement( 'div', blockEditor.useBlockProps(), createElement( TextControl, { label: 'URL', value: attrs.href || '', onChange: function( value ) { props.setAttributes( { href: value } ); } } ), createElement( TextControl, { label: 'Accessible label', value: attrs.accessibleLabel || '', onChange: function( value ) { props.setAttributes( { accessibleLabel: value } ); } } ), createElement( RichText, { tagName: 'div', value: attrs.content || '', onChange: function( value ) { props.setAttributes( { content: value } ); }, placeholder: 'Link text' } ), createElement( TextareaControl, { label: 'Icon content', value: attrs.iconContent || '', onChange: function( value ) { props.setAttributes( { iconContent: value } ); } } ) ); }
    function save( props ) { return createElement( element.RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( '__BLOCK_NAME__', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array( 'index.js' => str_replace(array( '__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__' ), array( $blockName, json_encode($this->blockJson(substr($blockName, 0, strrpos($blockName, '/')))['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ), $script) );
    }

    /** @param array<string, mixed> $attrs */
    public function markup(array $attrs): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $markup = '<a href="' . $escape($attrs['href'] ?? '') . '"';
        foreach ( array( 'accessibleLabel' => 'aria-label', 'className' => 'class', 'style' => 'style', 'id' => 'id', 'linkTarget' => 'target', 'rel' => 'rel' ) as $key => $name ) {
            if ( '' !== (string) ($attrs[$key] ?? '') ) {
                $markup .= ' ' . $name . '="' . $escape($attrs[$key]) . '"';
            }
        }

        return $markup . '>' . (string) ($attrs['content'] ?? '') . (string) ($attrs['iconContent'] ?? '') . '</a>';
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ),
            'assets' => $this->assets($namespace . '/' . self::LOCAL_NAME),
        );
    }
}
