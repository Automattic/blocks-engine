<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds the typed companion block for SVGs that require document context. */
final class SvgArtworkBlockGenerator
{
    public const LOCAL_NAME = 'svg-artwork';
    public const RENDERER = 'blocks-engine/svg-artwork/v1';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'SVG Artwork',
            'category' => 'media',
            'description' => 'Editable SVG artwork that retains parent-document styling.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'svg' => array( 'type' => 'string', 'default' => '', 'role' => 'content' ),
            ),
            'supports' => array( 'html' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, components, element, ServerSideRender ) {
    var createElement = element.createElement;
    function edit( props ) {
        var inspector = props.isSelected ? createElement( blockEditor.InspectorControls, {},
            createElement( components.PanelBody, { title: 'SVG artwork' },
                createElement( components.TextareaControl, {
                    label: 'SVG',
                    value: props.attributes.svg || '',
                    onChange: function( svg ) { props.setAttributes( { svg: svg } ); }
                } )
            )
        ) : null;
        return createElement( element.Fragment, {}, inspector,
            createElement( 'div', blockEditor.useBlockProps(),
                createElement( ServerSideRender, { block: '__BLOCK_NAME__', attributes: props.attributes, httpMethod: 'POST' } )
            )
        );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { svg: { type: 'string', default: '', role: 'content' } },
        supports: { html: false },
        edit: edit,
        save: function() { return null; }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.serverSideRender );
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
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-server-side-render' ) ),
        );
    }
}
