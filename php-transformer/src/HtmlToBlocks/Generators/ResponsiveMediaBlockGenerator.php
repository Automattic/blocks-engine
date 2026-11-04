<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds the bounded companion block for responsive and linked image markup. */
final class ResponsiveMediaBlockGenerator
{
    public const LOCAL_NAME = 'responsive-media';
    public const RENDERER = 'blocks-engine/responsive-media/v1';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Responsive Media',
            'category' => 'media',
            'description' => 'An editable captured media or layout boundary.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'content' => array( 'type' => 'string', 'default' => '', 'role' => 'content' ),
                'kind' => array( 'type' => 'string', 'default' => 'media' ),
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
    function edit( props ) {
        // Keep the captured topology (including custom hosts and picture sources).
        // Only the selected field is changed; importing never reserializes this DOM.
        var document = new window.DOMParser().parseFromString( props.attributes.content || '', 'text/html' );
        var fields = [];
        function field( node, attribute, label ) {
            fields.push( createElement( components.TextControl, {
                key: fields.length,
                label: label,
                value: attribute ? node.getAttribute( attribute ) || '' : node.textContent,
                onChange: function( value ) {
                    if ( attribute ) { node.setAttribute( attribute, value ); }
                    else { node.textContent = value; }
                    props.setAttributes( { content: document.body.innerHTML } );
                }
            } ) );
        }
        Array.prototype.forEach.call( document.body.querySelectorAll( 'img, source, video, audio, a, figcaption' ), function( node, index ) {
            var label = ' ' + ( index + 1 );
            switch ( node.localName ) {
                case 'img':
                    field( node, 'src', 'Image URL' + label );
                    field( node, 'alt', 'Alternative text' + label );
                    // Candidate lists remain editable without discarding art direction.
                    if ( node.hasAttribute( 'srcset' ) ) { field( node, 'srcset', 'Image candidates' + label ); }
                    if ( node.hasAttribute( 'sizes' ) ) { field( node, 'sizes', 'Image sizes' + label ); }
                    break;
                case 'source':
                    field( node, node.hasAttribute( 'srcset' ) ? 'srcset' : 'src', 'Source URL or candidates' + label );
                    if ( node.hasAttribute( 'media' ) ) { field( node, 'media', 'Media condition' + label ); }
                    if ( node.hasAttribute( 'sizes' ) ) { field( node, 'sizes', 'Source sizes' + label ); }
                    break;
                case 'video':
                    field( node, 'poster', 'Poster URL' + label );
                    // Falls through: both video and audio can own a direct source.
                case 'audio':
                    field( node, 'src', 'Media URL' + label );
                    break;
                case 'a':
                    field( node, 'href', 'Link URL' + label );
                    break;
                case 'figcaption':
                    field( node, null, 'Caption' + label );
                    break;
            }
        } );
        var inspector = props.isSelected ? createElement( blockEditor.InspectorControls, {},
            createElement( components.PanelBody, { title: 'Media settings' }, fields )
        ) : null;
        return createElement( element.Fragment, {}, inspector,
            createElement( 'div', Object.assign( {}, blockEditor.useBlockProps(), { dangerouslySetInnerHTML: { __html: props.attributes.content || '' } } ) )
        );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { content: { type: 'string', default: '', role: 'content' }, kind: { type: 'string', default: 'media' } },
        supports: { html: false },
        edit: edit,
        save: function() { return null; }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array( 'index.js' => str_replace('__BLOCK_NAME__', $blockName, $script) );
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'renderer' => self::RENDERER,
            'assets' => $this->assets($namespace . '/' . self::LOCAL_NAME),
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ),
        );
    }
}
