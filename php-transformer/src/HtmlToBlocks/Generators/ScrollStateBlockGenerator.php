<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/**
 * Defines the editable companion block that reproduces a captured
 * scroll-driven class/style toggle (e.g. a header that gains a background,
 * or a logo that shrinks, once the page scrolls past some offset). The
 * threshold, classes, and style diff all ride in a single captured-evidence
 * attribute — the generated view script is entirely generic.
 */
final class ScrollStateBlockGenerator
{
    public const LOCAL_NAME = 'scroll-state';

    /** @return array<string, mixed> */
    public function definition(string $blockName): array
    {
        $attributes = array(
            'tagName' => array('type' => 'string', 'default' => 'div'),
            'anchor' => array('type' => 'string', 'default' => ''),
            'className' => array('type' => 'string', 'default' => ''),
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
            return createElement( props.attributes.tagName || 'div', { id: props.attributes.anchor || undefined, className: props.attributes.className || undefined }, createElement( InnerBlocks ) );
        },
        save: function( props ) {
            var attrs = props.attributes;
            return createElement( attrs.tagName || 'div', { id: attrs.anchor || undefined, className: attrs.className || undefined, 'data-blocks-engine-scroll-state': 'true', 'data-blocks-engine-scroll-state-config': attrs.config || '{}' }, createElement( InnerBlocks.Content ) );
        }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        $view = <<<'JS'
( function() {
    function parseConfig( element ) {
        try { return JSON.parse( element.getAttribute( 'data-blocks-engine-scroll-state-config' ) || '{}' ); } catch ( error ) { return null; }
    }
    function applyState( element, config, stuck ) {
        ( config.addClasses || [] ).forEach( function( name ) { element.classList.toggle( name, stuck ); } );
        ( config.removeClasses || [] ).forEach( function( name ) { element.classList.toggle( name, ! stuck ); } );
        ( config.styleTargets || [] ).forEach( function( target ) {
            var node = element.querySelector( target.selector );
            if ( ! node ) return;
            Object.keys( target.properties || {} ).forEach( function( property ) {
                var value = target.properties[ property ];
                if ( value ) node.style.setProperty( property, stuck ? value.scrolled : value.rest );
            } );
        } );
    }
    function mount( element ) {
        if ( element.dataset.blocksEngineScrollStateMounted ) return;
        var config = parseConfig( element );
        if ( ! config ) return;
        element.dataset.blocksEngineScrollStateMounted = 'true';
        var threshold = Number( config.thresholdPx ) || 0;
        var ticking = false;
        function update() {
            ticking = false;
            applyState( element, config, window.scrollY > threshold );
        }
        window.addEventListener( 'scroll', function() {
            if ( ticking ) return;
            ticking = true;
            window.requestAnimationFrame( update );
        }, { passive: true } );
        update();
    }
    function mountAll() {
        document.querySelectorAll( '[data-blocks-engine-scroll-state="true"]' ).forEach( mount );
    }
    if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', mountAll ); else mountAll();
} )();
JS;

        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => array(
                'apiVersion' => 3,
                'name' => $blockName,
                'title' => 'Scroll State',
                'category' => 'widgets',
                'description' => 'Editable container that reproduces a captured scroll-driven class or style toggle.',
                'editorScript' => 'file:./index.js',
                'viewScript' => 'file:./view.js',
                'attributes' => $attributes,
                'supports' => array('html' => false, 'customClassName' => false, 'anchor' => true),
            ),
            'assets' => array(
                'index.js' => str_replace(array('__BLOCK_NAME__', '__ATTRIBUTES__'), array($blockName, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $editor),
            ),
            'view_js' => $view,
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')),
        );
    }
}
