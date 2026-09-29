<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** An authorable sequence that animates the current text of native blocks. */
final class MotionSequenceBlockGenerator
{
    public const LOCAL_NAME = 'motion-sequence';

    /** @return array<string, mixed> */
    public function definition(string $blockName): array
    {
        $editor = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var el = element.createElement;
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { steps: { type: 'array', default: [] } },
        supports: { html: false },
        edit: function( props ) {
            var steps = props.attributes.steps || [];
            function update( index, key, value ) {
                props.setAttributes( { steps: steps.map( function( step, position ) {
                    return position === index ? Object.assign( {}, step, { [ key ]: value } ) : step;
                } ) } );
            }
            return el( element.Fragment, {},
                el( blockEditor.InspectorControls, {}, el( components.PanelBody, { title: 'Text motion' },
                    steps.map( function( step, index ) {
                        return el( components.PanelBody, { title: 'Step ' + ( index + 1 ), key: index, initialOpen: false },
                            el( components.TextControl, { label: 'Text selector', value: step.selector || '', onChange: function( value ) { update( index, 'selector', value ); } } ),
                            el( components.TextControl, { label: 'Delay after previous step (ms)', type: 'number', min: 0, max: 5000, value: step.delayMs || '0', onChange: function( value ) { update( index, 'delayMs', value ); } } ),
                            el( components.TextControl, { label: 'Character interval (ms)', type: 'number', min: 10, max: 500, value: step.intervalMs || '50', onChange: function( value ) { update( index, 'intervalMs', value ); } } ),
                            el( components.TextControl, { label: 'Pending message before text', value: step.pendingText || '', onChange: function( value ) { update( index, 'pendingText', value ); } } ),
                            el( components.ToggleControl, { label: 'Animate trailing dots', checked: !! step.pendingDots, onChange: function( value ) { update( index, 'pendingDots', value ); } } ),
                            el( components.TextControl, { label: 'Dot interval (ms)', type: 'number', min: 100, max: 2000, value: step.pendingIntervalMs || '500', onChange: function( value ) { update( index, 'pendingIntervalMs', value ); } } ),
                            el( components.TextControl, { label: 'Replay delay (ms)', type: 'number', min: 0, max: 5000, value: step.replayDelayMs || '0', onChange: function( value ) { update( index, 'replayDelayMs', value ); } } ),
                            el( components.TextControl, { label: 'Click to replay (selector)', value: step.clickSelector || '', onChange: function( value ) { update( index, 'clickSelector', value ); } } ),
                            el( components.TextControl, { label: 'Ripple canvas (selector)', value: step.rippleSelector || '', onChange: function( value ) { update( index, 'rippleSelector', value ); } } ),
                            el( components.Button, { isDestructive: true, onClick: function() { props.setAttributes( { steps: steps.filter( function( _, position ) { return position !== index; } ) } ); } }, 'Remove step' )
                        );
                    } ),
                    el( components.Button, { variant: 'secondary', disabled: steps.length >= 8, onClick: function() { props.setAttributes( { steps: steps.concat( [ { selector: '', delayMs: '500', intervalMs: '50' } ] ) } ); } }, 'Add step' )
                ) ),
                el( 'div', blockEditor.useBlockProps(), 'Text motion: ' + steps.length + ' step' + ( steps.length === 1 ? '' : 's' ) )
            );
        },
        save: function( props ) {
            return el( 'span', { hidden: true, 'data-blocks-engine-motion-steps': JSON.stringify( props.attributes.steps || [] ) } );
        }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;
        $view = <<<'JS'
( function() {
    var activeSequences = 0;
    var previousBusy = null;
    function beginBusy() {
        if ( activeSequences++ === 0 ) {
            previousBusy = document.body.getAttribute( 'aria-busy' );
            document.body.setAttribute( 'aria-busy', 'true' );
        }
    }
    function endBusy() {
        if ( --activeSequences === 0 ) {
            if ( previousBusy === null ) document.body.removeAttribute( 'aria-busy' );
            else document.body.setAttribute( 'aria-busy', previousBusy );
        }
    }
    function select( selector ) {
        if ( typeof selector !== 'string' || selector.length > 120 ) return null;
        try { return document.querySelector( selector ); } catch ( error ) { return null; }
    }
    function textTarget( selector ) {
        var element = select( selector );
        if ( ! element ) return null;
        if ( ! element.children.length ) return element;
        var child = element.firstElementChild;
        return element.children.length === 1 && child.tagName === 'P' && ! child.children.length ? child : null;
    }
    function bounded( value, fallback, min, max ) {
        var number = Number( value );
        return Number.isFinite( number ) && number >= min && number <= max ? number : fallback;
    }
    function wait( duration ) {
        return new Promise( function( resolve ) { window.setTimeout( resolve, duration ); } );
    }
    function mount( marker ) {
        var input;
        try { input = JSON.parse( marker.getAttribute( 'data-blocks-engine-motion-steps' ) || '[]' ); } catch ( error ) { return; }
        if ( ! Array.isArray( input ) ) return;
        var steps = input.slice( 0, 8 ).map( function( item ) {
            if ( ! item || typeof item !== 'object' ) return null;
            var target = textTarget( item.selector );
            if ( ! target ) return null;
            var text = target.textContent || '';
            if ( text.length > 500 ) return null;
            return {
                target: target,
                text: Array.from( text ),
                delay: bounded( item.delayMs, 500, 0, 5000 ),
                interval: bounded( item.intervalMs, 50, 10, 500 ),
                pending: typeof item.pendingText === 'string' ? item.pendingText.slice( 0, 120 ) : '',
                pendingDots: item.pendingDots === true,
                pendingInterval: bounded( item.pendingIntervalMs, 500, 100, 2000 ),
                replayDelay: bounded( item.replayDelayMs, 0, 0, 5000 ),
                click: select( item.clickSelector ),
                ripple: select( item.rippleSelector ),
                pendingTimer: null,
                running: false
            };
        } ).filter( Boolean );
        if ( steps.length === 0 ) return;
        beginBusy();

        function showPending( step ) {
            if ( step.pendingTimer !== null ) window.clearInterval( step.pendingTimer );
            step.target.textContent = step.pending;
            if ( step.pending && step.pendingDots ) {
                var dots = 0;
                step.pendingTimer = window.setInterval( function() {
                    dots = ( dots + 1 ) % 4;
                    step.target.textContent = step.pending + '.'.repeat( dots );
                }, step.pendingInterval );
            } else step.pendingTimer = null;
        }

        function endPending( step ) {
            if ( step.pendingTimer !== null ) window.clearInterval( step.pendingTimer );
            step.pendingTimer = null;
            step.target.textContent = '';
        }

        async function play( step, delay ) {
            if ( step.running ) return;
            step.running = true;
            showPending( step );
            var waitMs = delay ? step.delay : step.replayDelay;
            if ( waitMs ) await wait( waitMs );
            endPending( step );
            for ( var index = 0; index < step.text.length; index++ ) {
                step.target.textContent += step.text[ index ];
                await wait( step.interval );
            }
            if ( step.ripple && step.ripple.tagName === 'CANVAS' ) {
                var rect = step.target.getBoundingClientRect();
                step.ripple.dispatchEvent( new CustomEvent( 'blocks-engine-ripple', { detail: { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 } } ) );
            }
            step.running = false;
        }
        steps.forEach( function( step ) {
            if ( step.click ) step.click.addEventListener( 'click', function() {
                if ( ! marker.dataset.blocksEngineMotionReady ) return;
                beginBusy();
                void play( step, false ).finally( endBusy );
            } );
            showPending( step );
        } );
        ( async function() {
            try {
                for ( var index = 0; index < steps.length; index++ ) await play( steps[ index ], true );
                marker.dataset.blocksEngineMotionReady = 'true';
            } finally { endBusy(); }
        } )();
    }
    function mountAll() {
        document.querySelectorAll( '[data-blocks-engine-motion-steps]' ).forEach( mount );
    }
    if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', mountAll ); else mountAll();
} )();
JS;

        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => array(
                'apiVersion' => 3,
                'name' => $blockName,
                'title' => 'Text Motion Sequence',
                'category' => 'widgets',
                'description' => 'Animate editable text in order, with optional click replay and canvas ripples.',
                'editorScript' => 'file:./index.js',
                'viewScript' => 'file:./view.js',
                'attributes' => array('steps' => array('type' => 'array', 'default' => array())),
                'supports' => array('html' => false),
            ),
            'assets' => array('index.js' => str_replace('__BLOCK_NAME__', $blockName, $editor)),
            'view_js' => $view,
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element')),
        );
    }
}
