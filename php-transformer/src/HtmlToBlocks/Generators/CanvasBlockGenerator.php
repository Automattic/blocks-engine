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
            'title' => 'Drawing Surface',
            'category' => 'media',
            'description' => 'An editable HTML drawing surface for artwork or animation.',
            'editorScript' => 'file:./index.js',
            'viewScript' => 'file:./view.js',
            'attributes' => array(
                'canvasId' => array('type' => 'string', 'default' => ''),
                'width' => array('type' => 'string', 'default' => ''),
                'height' => array('type' => 'string', 'default' => ''),
                'className' => array('type' => 'string', 'default' => ''),
                'label' => array('type' => 'string', 'default' => ''),
                'ariaHidden' => array('type' => 'string', 'default' => ''),
                'fallbackText' => array('type' => 'string', 'default' => ''),
                'effect' => array('type' => 'string', 'default' => ''),
                'particleSpacing' => array('type' => 'string', 'default' => '90'),
                'particleSize' => array('type' => 'string', 'default' => '1.2'),
                'particleColor' => array('type' => 'string', 'default' => '#000000'),
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
        if ( attrs.effect === 'particle-ripple' ) {
            var spacing = Number( attrs.particleSpacing );
            var size = Number( attrs.particleSize );
            props[ 'data-blocks-engine-canvas-effect' ] = attrs.effect;
            props[ 'data-blocks-engine-canvas-spacing' ] = Number.isFinite( spacing ) && spacing >= 10 && spacing <= 300 ? attrs.particleSpacing : '90';
            props[ 'data-blocks-engine-canvas-size' ] = Number.isFinite( size ) && size >= 0.5 && size <= 10 ? attrs.particleSize : '1.2';
            props[ 'data-blocks-engine-canvas-color' ] = /^#[0-9a-f]{6}$/i.test( attrs.particleColor || '' ) ? attrs.particleColor : '#000000';
        }
        return props;
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: attributes, supports: { html: false },
        edit: function( props ) {
            var set = function( name ) { return function( value ) { props.setAttributes( { [ name ]: value } ); }; };
            return el( element.Fragment, {},
                el( blockEditor.InspectorControls, {}, el( components.PanelBody, { title: 'Drawing Surface' },
                    el( components.TextControl, { label: 'Element ID', value: props.attributes.canvasId || '', onChange: set( 'canvasId' ) } ),
                    el( components.TextControl, { label: 'Width', value: props.attributes.width || '', onChange: set( 'width' ) } ),
                    el( components.TextControl, { label: 'Height', value: props.attributes.height || '', onChange: set( 'height' ) } ),
                    el( components.TextControl, { label: 'Accessible label', value: props.attributes.label || '', onChange: set( 'label' ) } ),
                    el( components.TextControl, { label: 'Fallback text', value: props.attributes.fallbackText || '', onChange: set( 'fallbackText' ) } ),
                    el( components.SelectControl, { label: 'Animation', value: props.attributes.effect || '', options: [ { label: 'None', value: '' }, { label: 'Particle ripple', value: 'particle-ripple' } ], onChange: set( 'effect' ) } ),
                    props.attributes.effect === 'particle-ripple' && el( components.TextControl, { label: 'Particle spacing (px)', type: 'number', min: 10, max: 300, value: props.attributes.particleSpacing || '90', onChange: set( 'particleSpacing' ) } ),
                    props.attributes.effect === 'particle-ripple' && el( components.TextControl, { label: 'Particle size (px)', type: 'number', min: 0.5, max: 10, step: 0.1, value: props.attributes.particleSize || '1.2', onChange: set( 'particleSize' ) } ),
                    props.attributes.effect === 'particle-ripple' && el( components.TextControl, { label: 'Particle color', type: 'color', value: props.attributes.particleColor || '#000000', onChange: set( 'particleColor' ) } )
                ) ),
                el( 'div', blockEditor.useBlockProps(), el( 'canvas', canvasProps( props.attributes ), props.attributes.fallbackText || '' ) )
            );
        },
        save: function( props ) { return el( 'canvas', canvasProps( props.attributes ), props.attributes.fallbackText || '' ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;
        $view = <<<'JS'
( function() {
    function number( value, fallback, min, max ) {
        var parsed = Number( value );
        return Number.isFinite( parsed ) && parsed >= min && parsed <= max ? parsed : fallback;
    }
    function mount( canvas ) {
        if ( canvas.dataset.blocksEngineCanvasEffect !== 'particle-ripple' || canvas.dataset.blocksEngineCanvasMounted ) return;
        var context = canvas.getContext( '2d' );
        if ( ! context ) return;
        canvas.dataset.blocksEngineCanvasMounted = 'true';
        var spacing = number( canvas.dataset.blocksEngineCanvasSpacing, 90, 10, 300 );
        var size = number( canvas.dataset.blocksEngineCanvasSize, 1.2, 0.5, 10 );
        var color = /^#[0-9a-f]{6}$/i.test( canvas.dataset.blocksEngineCanvasColor || '' ) ? canvas.dataset.blocksEngineCanvasColor : '#000000';
        var red = parseInt( color.slice( 1, 3 ), 16 );
        var green = parseInt( color.slice( 3, 5 ), 16 );
        var blue = parseInt( color.slice( 5, 7 ), 16 );
        var points = [];
        var ripples = [];
        var width = 0;
        var height = 0;
        var lastX = 0;
        var lastY = 0;
        function resize() {
            var bounds = canvas.getBoundingClientRect();
            width = canvas.width = Math.round( bounds.width );
            height = canvas.height = Math.round( bounds.height );
            points = [];
            for ( var y = 0; y < height; y += spacing ) {
                for ( var x = 0; x < width; x += spacing ) {
                    points.push( { x: x + ( Math.random() - 0.5 ) * spacing * 0.4, y: y + ( Math.random() - 0.5 ) * spacing * 0.4, fade: 0.8 + Math.random() * 0.4 } );
                }
            }
        }
        function ripple( x, y, speed, lifetime, strength ) {
            ripples.push( { x: x, y: y, age: 0, speed: speed, lifetime: lifetime, strength: strength } );
            if ( ripples.length > 64 ) ripples.shift();
        }
        function onPointer( event ) {
            var bounds = canvas.getBoundingClientRect();
            var x = event.clientX - bounds.left;
            var y = event.clientY - bounds.top;
            var distance = Math.hypot( event.clientX - lastX, event.clientY - lastY );
            if ( distance <= 5 ) return;
            ripple( x, y, 5 + Math.min( distance * 0.2, 5 ), 60 + Math.min( distance * 4, 140 ) * ( 0.5 + Math.random() * 0.5 ), Math.min( distance / 5, 2 ) );
            lastX = event.clientX;
            lastY = event.clientY;
        }
        function draw() {
            context.clearRect( 0, 0, width, height );
            ripples = ripples.filter( function( ripple ) { return ++ripple.age <= ripple.lifetime; } );
            points.forEach( function( point ) {
                var amplitude = 0;
                ripples.forEach( function( wave ) {
                    var distance = Math.hypot( point.x - wave.x, point.y - wave.y );
                    var fromWave = Math.abs( distance - wave.age * wave.speed );
                    if ( fromWave >= 80 ) return;
                    var crest = ( Math.cos( fromWave / 80 * Math.PI ) + 1 ) / 2;
                    var fade = Math.min( wave.age / wave.lifetime * point.fade, 1 );
                    amplitude += crest * wave.strength * ( 1 - fade * fade );
                } );
                var opacity = Math.min( amplitude * 0.5, 0.8 );
                if ( opacity <= 0.01 ) return;
                context.beginPath();
                context.arc( point.x, point.y - amplitude * 3, size * ( 1 + amplitude * 0.05 ), 0, 2 * Math.PI );
                context.fillStyle = 'rgba(' + red + ',' + green + ',' + blue + ',' + opacity + ')';
                context.fill();
            } );
            window.requestAnimationFrame( draw );
        }
        window.addEventListener( 'resize', resize );
        window.addEventListener( 'pointermove', onPointer, { passive: true } );
        canvas.addEventListener( 'blocks-engine-ripple', function( event ) {
            var bounds = canvas.getBoundingClientRect();
            ripple( event.detail.x - bounds.left, event.detail.y - bounds.top, 8, 120, 4 );
        } );
        resize();
        draw();
    }
    function mountAll() {
        document.querySelectorAll( 'canvas[data-blocks-engine-canvas-effect="particle-ripple"]' ).forEach( mount );
    }
    if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', mountAll ); else mountAll();
} )();
JS;
        return array('index.js' => str_replace(
            array('__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__'),
            array($blockName, json_encode($this->blockJson(strstr($blockName, '/', true) ?: '')['attributes'], JSON_THROW_ON_ERROR)),
            $script
        ), 'view_js' => $view);
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        $assets = $this->assets($namespace . '/' . self::LOCAL_NAME);
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'assets' => array('index.js' => $assets['index.js']),
            'view_js' => $assets['view_js'],
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
        if ('particle-ripple' === ($attrs['effect'] ?? '')) {
            $markup .= ' data-blocks-engine-canvas-effect="particle-ripple"';
            foreach (array('particleSpacing' => array('spacing', '90', 10, 300), 'particleSize' => array('size', '1.2', 0.5, 10), 'particleColor' => array('color', '#000000')) as $key => $config) {
                $value = $attrs[$key] ?? $config[1];
                $valid = 'particleColor' === $key ? (bool) preg_match('/^#[0-9a-f]{6}$/i', $value) : is_numeric($value) && (float) $value >= $config[2] && (float) $value <= $config[3];
                $markup .= ' data-blocks-engine-canvas-' . $config[0] . '="' . htmlspecialchars($valid ? $value : $config[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
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
