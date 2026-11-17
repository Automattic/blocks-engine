<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\WordPress\CapturedChoiceGroupScript;

/** Defines the editable companion block for captured choice transitions. */
final class CapturedChoiceGroupBlockGenerator
{
    public const LOCAL_NAME = 'captured-choice-group';

    /** @return array<string, mixed> */
    public function definition(string $blockName): array
    {
        $attributes = array(
            'tagName' => array('type' => 'string', 'default' => 'div'),
            'anchor' => array('type' => 'string', 'default' => ''),
            'className' => array('type' => 'string', 'default' => ''),
            'ariaLabel' => array('type' => 'string', 'default' => ''),
            'config' => array('type' => 'string', 'default' => '{}'),
        );
        $editor = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var InnerBlocks = blockEditor.InnerBlocks;
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__,
        supports: { html: false, customClassName: false, anchor: true },
        edit: function( props ) {
            var attrs = props.attributes;
            return createElement( attrs.tagName || 'div', { id: attrs.anchor || undefined, className: attrs.className || undefined, 'aria-label': attrs.ariaLabel || undefined }, createElement( InnerBlocks ) );
        },
        save: function( props ) {
            var attrs = props.attributes;
            return createElement( attrs.tagName || 'div', { id: attrs.anchor || undefined, className: attrs.className || undefined, 'aria-label': attrs.ariaLabel || undefined, 'data-blocks-engine-choice-group': 'true', 'data-blocks-engine-choice-config': attrs.config || '{}' }, createElement( InnerBlocks.Content ) );
        }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;


        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => array(
                'apiVersion' => 3,
                'name' => $blockName,
                'title' => 'Choice Group',
                'category' => 'widgets',
                'description' => 'Editable choice controls with captured, replayable state transitions.',
                'editorScript' => 'file:./index.js',
                'viewScript' => 'file:./view.js',
                'attributes' => $attributes,
                'supports' => array('html' => false, 'customClassName' => false, 'anchor' => true),
            ),
            'assets' => array(
                'index.js' => str_replace(array('__BLOCK_NAME__', '__ATTRIBUTES__'), array($blockName, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $editor),
            ),
            'view_js' => AuthoredControlState::viewScript() . "\n" . CapturedChoiceGroupScript::viewScript(),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')),
        );
    }
}
