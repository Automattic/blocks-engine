<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

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
        $view = <<<'JS'
( function() {
    function config( element ) {
        try { return JSON.parse( element.getAttribute( 'data-blocks-engine-choice-config' ) || '{}' ); } catch ( error ) { return null; }
    }
    function choices( element ) {
        return Array.prototype.slice.call( element.querySelectorAll( 'button,[role="radio"],[role="option"],[role="tab"],input[type="radio"],input[type="checkbox"]' ) );
    }
    function setSelection( element, state, data ) {
        var nodes = choices( element );
        nodes.forEach( function( node, index ) {
            node.setAttribute( 'data-blocks-engine-choice-index', String( index ) );
            node.setAttribute( 'data-blocks-engine-choice-active', index === state.selectedIndex ? 'true' : 'false' );
            if ( node.getAttribute( 'aria-pressed' ) !== null ) node.setAttribute( 'aria-pressed', index === state.selectedIndex ? 'true' : 'false' );
        } );
        var choice = ( data.choices || [] )[ state.selectedIndex ] || {};
        element.setAttribute( 'data-blocks-engine-choice-selection', JSON.stringify( { observed_choice_key: choice.observed_choice_key || ( 'choice-' + state.selectedIndex ), source_value: Object.prototype.hasOwnProperty.call( choice, 'source_value' ) ? choice.source_value : null, selected_index: state.selectedIndex } ) );
    }
    function mount( element ) {
        if ( element.dataset.blocksEngineChoiceMounted ) return;
        var data = config( element );
        if ( ! data || ! Array.isArray( data.states ) || ! data.states.length ) return;
        element.dataset.blocksEngineChoiceMounted = 'true';
        var states = {};
        data.states.forEach( function( state ) { states[ String( state.selectedIndex ) ] = state; } );
        var current = data.states[ 0 ];
        function apply( index, focus ) {
            var state = states[ String( index ) ];
            if ( ! state ) return;
            element.innerHTML = state.html;
            current = state;
            setSelection( element, state, data );
            if ( focus ) {
                var node = choices( element )[ index ];
                if ( node && node.focus ) node.focus();
            }
        }
        function bind() {
            choices( element ).forEach( function( node, index ) {
                node.addEventListener( 'click', function() { apply( index, false ); } );
                node.addEventListener( 'keydown', function( event ) {
                    var next = index;
                    if ( event.key === 'ArrowRight' || event.key === 'ArrowDown' ) next = ( index + 1 ) % choices( element ).length;
                    else if ( event.key === 'ArrowLeft' || event.key === 'ArrowUp' ) next = ( index - 1 + choices( element ).length ) % choices( element ).length;
                    else if ( event.key === 'Home' ) next = 0;
                    else if ( event.key === 'End' ) next = choices( element ).length - 1;
                    else return;
                    event.preventDefault(); apply( next, true ); bind();
                } );
            } );
        }
        setSelection( element, current, data );
        bind();
        element.addEventListener( 'click', function( event ) {
            var node = event.target && event.target.closest ? event.target.closest( '[data-blocks-engine-choice-index]' ) : null;
            if ( node && element.contains( node ) ) apply( Number( node.getAttribute( 'data-blocks-engine-choice-index' ) ) || 0, false );
        } );
    }
    function mountAll() { document.querySelectorAll( '[data-blocks-engine-choice-group="true"]' ).forEach( mount ); }
    if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', mountAll ); else mountAll();
} )();
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
            'view_js' => $view,
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')),
        );
    }
}
