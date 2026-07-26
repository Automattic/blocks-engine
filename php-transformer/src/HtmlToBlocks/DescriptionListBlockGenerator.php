<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/** Builds the static companion block that fills Gutenberg's description-list gap. */
final class DescriptionListBlockGenerator
{
    public const NAME = 'blocks-engine/description-list';

    /** @return array<string, mixed> */
    public function blockJson(): array
    {
        return array(
            'apiVersion' => 3,
            'name' => self::NAME,
            'title' => 'Description List',
            'category' => 'text',
            'description' => 'A semantic description list with terms and descriptions.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'groups' => array( 'type' => 'array', 'default' => array() ),
            ),
            'supports' => array( 'html' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(): array
    {
        return array(
            'index.asset.php' => <<<'PHP'
<?php return array( 'dependencies' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ), 'version' => '1.0.0' );
PHP,
            'index.js' => <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var RawHTML = element.RawHTML;
    var RichText = blockEditor.RichText;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function markupAttributes( item ) {
        var output = '';
        if ( item.className ) { output += ' class="' + escapeAttribute( item.className ) + '"'; }
        if ( item.style ) { output += ' style="' + escapeAttribute( item.style ) + '"'; }
        return output;
    }
    function markup( blockAttributes ) {
        var output = '<dl' + markupAttributes( blockAttributes ) + '>';
        ( blockAttributes.groups || [] ).forEach( function( group ) {
            ( group.terms || [] ).forEach( function( item ) { output += '<dt' + markupAttributes( item ) + '>' + ( item.content || '' ) + '</dt>'; } );
            ( group.descriptions || [] ).forEach( function( item ) { output += '<dd' + markupAttributes( item ) + '>' + ( item.content || '' ) + '</dd>'; } );
        } );
        return output + '</dl>';
    }
    function updateItem( props, groupIndex, collection, itemIndex, content ) {
        var groups = ( props.attributes.groups || [] ).map( function( group ) {
            return {
                terms: ( group.terms || [] ).map( function( item ) { return Object.assign( {}, item ); } ),
                descriptions: ( group.descriptions || [] ).map( function( item ) { return Object.assign( {}, item ); } )
            };
        } );
        groups[ groupIndex ][ collection ][ itemIndex ].content = content;
        props.setAttributes( { groups: groups } );
    }
    function edit( props ) {
        var children = [];
        ( props.attributes.groups || [] ).forEach( function( group, groupIndex ) {
            ( group.terms || [] ).forEach( function( item, itemIndex ) {
                children.push( createElement( RichText, {
                    tagName: 'dt',
                    value: item.content || '',
                    className: item.className || undefined,
                    key: 'term-' + groupIndex + '-' + itemIndex,
                    onChange: function( content ) { updateItem( props, groupIndex, 'terms', itemIndex, content ); }
                } ) );
            } );
            ( group.descriptions || [] ).forEach( function( item, itemIndex ) {
                children.push( createElement( RichText, {
                    tagName: 'dd',
                    value: item.content || '',
                    className: item.className || undefined,
                    key: 'description-' + groupIndex + '-' + itemIndex,
                    onChange: function( content ) { updateItem( props, groupIndex, 'descriptions', itemIndex, content ); }
                } ) );
            } );
        } );
        return createElement( 'dl', { className: props.attributes.className || undefined }, children );
    }
    function save( props ) { return createElement( RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( 'blocks-engine/description-list', { edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS
        );
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return array(
            'name' => 'description-list',
            'block_json' => $this->blockJson(),
            'assets' => $this->assets(),
        );
    }
}
