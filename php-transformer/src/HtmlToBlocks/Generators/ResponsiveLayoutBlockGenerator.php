<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds the bounded companion block for captured static layout markup. */
final class ResponsiveLayoutBlockGenerator
{
    public const LOCAL_NAME = 'responsive-layout';
    public const RENDERER = 'blocks-engine/responsive-layout/v1';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
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
( function( blocks, blockEditor, components, element, ServerSideRender ) {
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
            createElement( 'div', blockEditor.useBlockProps(),
                createElement( ServerSideRender, { block: '__BLOCK_NAME__', attributes: props.attributes } )
            )
        );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { content: { type: 'string', default: '', role: 'content' } },
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
