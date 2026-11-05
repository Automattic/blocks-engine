<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds the bounded companion block for captured static layout markup. */
final class ResponsiveLayoutBlockGenerator
{
    use \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\GeneratedBlockDefinition;

    public const LOCAL_NAME = 'responsive-layout';
    public const RENDERER = 'blocks-engine/responsive-layout/v1';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'blocksEngine' => \Automattic\BlocksEngine\PhpTransformer\Contract\GeneratedBlockEditingSurface::RAW_HTML,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Responsive Layout',
            'category' => 'design',
            'description' => 'An editable captured static layout boundary.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'content' => array( 'type' => 'string', 'default' => '', 'role' => 'content' ),
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
            createElement( components.PanelBody, { title: 'Captured layout HTML' },
                createElement( components.TextareaControl, {
                    label: 'HTML',
                    value: props.attributes.content || '',
                    onChange: function( content ) { props.setAttributes( { content: content } ); }
                } )
            )
        ) : null;
        return createElement( element.Fragment, {}, inspector,
            createElement( 'div', Object.assign( {}, blockEditor.useBlockProps(), { dangerouslySetInnerHTML: { __html: props.attributes.content || '' } } ) )
        );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { content: { type: 'string', default: '', role: 'content' } },
        supports: { html: false },
        edit: edit,
        save: function() { return null; }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array( 'index.js' => str_replace('__BLOCK_NAME__', $blockName, $script) );
    }

}
