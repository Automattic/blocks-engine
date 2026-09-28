<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use DOMElement;

/** A native canvas surface with editable, bounded element attributes. */
final class CanvasBlockGenerator
{
    public const LOCAL_NAME = 'canvas';

    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Canvas',
            'category' => 'media',
            'description' => 'An editable canvas surface for artwork or animation.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'canvasId' => array('type' => 'string', 'default' => ''),
                'width' => array('type' => 'string', 'default' => ''),
                'height' => array('type' => 'string', 'default' => ''),
                'className' => array('type' => 'string', 'default' => ''),
                'label' => array('type' => 'string', 'default' => ''),
                'ariaHidden' => array('type' => 'string', 'default' => ''),
                'fallbackText' => array('type' => 'string', 'default' => ''),
            ),
            'supports' => array('html' => false),
        );
    }

    /** @return array<string, string> */
    public function assets(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var el = element.createElement;
    var attributes = __BLOCK_ATTRIBUTES__;
    function canvasProps( attrs ) {
        var props = {};
        if ( attrs.canvasId ) props.id = attrs.canvasId;
        if ( attrs.className ) props.className = attrs.className;
        if ( attrs.width ) props.width = attrs.width;
        if ( attrs.height ) props.height = attrs.height;
        if ( attrs.label ) props[ 'aria-label' ] = attrs.label;
        if ( attrs.ariaHidden ) props[ 'aria-hidden' ] = attrs.ariaHidden;
        return props;
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: attributes, supports: { html: false },
        edit: function( props ) {
            var set = function( name ) { return function( value ) { props.setAttributes( { [ name ]: value } ); }; };
            return el( element.Fragment, {},
                el( blockEditor.InspectorControls, {}, el( components.PanelBody, { title: 'Canvas' },
                    el( components.TextControl, { label: 'Element ID', value: props.attributes.canvasId || '', onChange: set( 'canvasId' ) } ),
                    el( components.TextControl, { label: 'Width', value: props.attributes.width || '', onChange: set( 'width' ) } ),
                    el( components.TextControl, { label: 'Height', value: props.attributes.height || '', onChange: set( 'height' ) } ),
                    el( components.TextControl, { label: 'Accessible label', value: props.attributes.label || '', onChange: set( 'label' ) } ),
                    el( components.TextControl, { label: 'Fallback text', value: props.attributes.fallbackText || '', onChange: set( 'fallbackText' ) } )
                ) ),
                el( 'div', blockEditor.useBlockProps(), el( 'canvas', canvasProps( props.attributes ), props.attributes.fallbackText || '' ) )
            );
        },
        save: function( props ) { return el( 'canvas', canvasProps( props.attributes ), props.attributes.fallbackText || '' ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;
        return array('index.js' => str_replace(
            array('__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__'),
            array($blockName, json_encode($this->blockJson(strstr($blockName, '/', true) ?: '')['attributes'], JSON_THROW_ON_ERROR)),
            $script
        ));
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'assets' => $this->assets($namespace . '/' . self::LOCAL_NAME),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element')),
        );
    }

    /** @param array<string, string> $attrs */
    public function markup(array $attrs): string
    {
        $markup = '<canvas';
        foreach (array('canvasId' => 'id', 'className' => 'class', 'width' => 'width', 'height' => 'height', 'label' => 'aria-label', 'ariaHidden' => 'aria-hidden') as $key => $htmlName) {
            if ('' !== ($attrs[$key] ?? '')) $markup .= ' ' . $htmlName . '="' . htmlspecialchars($attrs[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $markup . '>' . htmlspecialchars($attrs['fallbackText'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</canvas>';
    }

    /** @return array<string, mixed> */
    public function convert(DOMElement $canvas): array
    {
        $registry = $this->session->generatedBlockRegistry();
        if (null === $registry) throw new \LogicException('Generated block registry has not been prepared for this transform.');
        $registry->register(self::class, $this->definition($registry->namespace()));
        $attrs = array();
        foreach (array('id' => 'canvasId', 'class' => 'className', 'aria-label' => 'label', 'aria-hidden' => 'ariaHidden') as $name => $key) {
            $value = trim($canvas->getAttribute($name));
            if ('' !== $value && strlen($value) <= 200 && !preg_match('/[\x00-\x1f<>]/', $value)) $attrs[$key] = $value;
        }
        foreach (array('width', 'height') as $name) {
            $value = trim($canvas->getAttribute($name));
            if (preg_match('/^[1-9][0-9]{0,4}$/', $value)) $attrs[$name] = $value;
        }
        $fallback = trim($canvas->textContent ?? '');
        if ('' !== $fallback) $attrs['fallbackText'] = mb_substr($fallback, 0, 1024);
        $block = $this->createBlock->createBlock($registry->blockName(self::LOCAL_NAME), $attrs, array(), $canvas);
        $block['innerHTML'] = $this->markup($attrs);
        $block['innerContent'] = array($block['innerHTML']);
        return $block;
    }
}
