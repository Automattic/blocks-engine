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
                'contentMode' => array( 'type' => 'string', 'default' => 'rich-text' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'id' => array( 'type' => 'string', 'default' => '' ),
                'linkTarget' => array( 'type' => 'string', 'default' => '' ),
                'rel' => array( 'type' => 'string', 'default' => '' ),
                'sourceAttributes' => array( 'type' => 'object', 'default' => array() ),
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
    var InspectorControls = blockEditor.InspectorControls;
    var useBlockProps = blockEditor.useBlockProps;
    var TextControl = components.TextControl;
    var PanelBody = components.PanelBody;
    var RawHTML = element.RawHTML;
    var attributes = __BLOCK_ATTRIBUTES__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function linkProps( attrs ) { return Object.assign( {}, attrs.sourceAttributes || {}, { href: attrs.href || undefined, 'aria-label': attrs.accessibleLabel || undefined, className: attrs.className || undefined, style: attrs.style || undefined, id: attrs.id || undefined, target: attrs.linkTarget || undefined, rel: attrs.rel || undefined } ); }
    function markup( attrs ) { var output = '<a'; Object.keys( attrs.sourceAttributes || {} ).sort().forEach( function( name ) { output += ' ' + name + '="' + escapeAttribute( attrs.sourceAttributes[ name ] ) + '"'; } ); [ [ 'href', 'href' ], [ 'accessibleLabel', 'aria-label' ], [ 'className', 'class' ], [ 'style', 'style' ], [ 'id', 'id' ], [ 'linkTarget', 'target' ], [ 'rel', 'rel' ] ].forEach( function( item ) { if ( attrs[ item[ 0 ] ] ) output += ' ' + item[ 1 ] + '="' + escapeAttribute( attrs[ item[ 0 ] ] ) + '"'; } ); return output + '>' + ( 'raw-source' === attrs.contentMode ? ( attrs.content || '' ) : '<span>' + ( attrs.content || '' ) + '</span>' ) + '</a>'; }
    function settings( props ) { var attrs = props.attributes; return createElement( InspectorControls, {}, createElement( PanelBody, { title: 'Link settings' }, createElement( TextControl, { label: 'URL', value: attrs.href || '', onChange: function( value ) { props.setAttributes( { href: value } ); } } ), createElement( TextControl, { label: 'Accessible label', value: attrs.accessibleLabel || '', onChange: function( value ) { props.setAttributes( { accessibleLabel: value } ); } } ) ) ); }
    function edit( props ) { var content = 'raw-source' === props.attributes.contentMode ? createElement( RawHTML, null, props.attributes.content || '' ) : createElement( RichText, { tagName: 'span', value: props.attributes.content || '', onChange: function( value ) { props.setAttributes( { content: value } ); } } ); return createElement( 'a', useBlockProps( linkProps( props.attributes ) ), settings( props ), content ); }
    function save( props ) { if ( 'raw-source' === props.attributes.contentMode ) return createElement( RawHTML, null, markup( props.attributes ) ); return createElement( 'a', useBlockProps.save( linkProps( props.attributes ) ), createElement( RichText.Content, { tagName: 'span', value: props.attributes.content || '' } ) ); }
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
        $sourceAttributes = is_array($attrs['sourceAttributes'] ?? null) ? $attrs['sourceAttributes'] : array();
        ksort($sourceAttributes);
        foreach ( $sourceAttributes as $name => $value ) {
            $markup .= ' ' . $name . '="' . $escape($value) . '"';
        }
        foreach ( array( 'accessibleLabel' => 'aria-label', 'className' => 'class', 'style' => 'style', 'id' => 'id', 'linkTarget' => 'target', 'rel' => 'rel' ) as $key => $name ) {
            if ( '' !== (string) ($attrs[$key] ?? '') ) {
                $markup .= ' ' . $name . '="' . $escape($attrs[$key]) . '"';
            }
        }

        return $markup . '>' . ('raw-source' === ($attrs['contentMode'] ?? '') ? (string) ($attrs['content'] ?? '') : '<span>' . (string) ($attrs['content'] ?? '') . '</span>') . '</a>';
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
