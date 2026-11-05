<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds the typed companion block for SVGs that require document context. */
final class SvgArtworkBlockGenerator
{
    use \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\GeneratedBlockDefinition;

    public const LOCAL_NAME = 'svg-artwork';
    public const RENDERER = 'blocks-engine/svg-artwork/v1';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'blocksEngine' => \Automattic\BlocksEngine\PhpTransformer\Contract\GeneratedBlockEditingSurface::RAW_HTML,
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
( function( blocks, blockEditor, components, element ) {
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
            createElement( 'div', Object.assign( {}, blockEditor.useBlockProps(), { dangerouslySetInnerHTML: { __html: props.attributes.svg || '' } } ) )
        );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { svg: { type: 'string', default: '', role: 'content' } },
        supports: { html: false },
        edit: edit,
        save: function() { return null; }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array( 'index.js' => str_replace('__BLOCK_NAME__', $blockName, $script) );
    }

}
