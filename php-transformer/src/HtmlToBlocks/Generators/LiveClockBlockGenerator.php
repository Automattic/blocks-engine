<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Authorable clock behavior for existing, editable WordPress text targets. */
final class LiveClockBlockGenerator
{
    public const LOCAL_NAME = 'live-clock';

    /** @return array<string, mixed> */
    public function definition(string $blockName): array
    {
        $editor = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var el = element.createElement;
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { config: { type: 'object', default: {} } },
        supports: { html: false },
        edit: function( props ) {
            var config = props.attributes.config || {};
            function set( key ) { return function( value ) { props.setAttributes( { config: Object.assign( {}, config, { [ key ]: value } ) } ); }; }
            var fields = [
                [ 'hourSelector', 'Hours selector' ], [ 'minuteSelector', 'Minutes selector' ],
                [ 'ampmSelector', 'AM/PM selector (optional)' ], [ 'timezoneSelector', 'Timezone selector' ],
                [ 'dateSelector', 'Date selector (optional)' ], [ 'triggerSelector', 'Click to replay selector (optional)' ],
                [ 'colonSelector', 'Colon selector (optional)' ], [ 'rippleSelector', 'Ripple canvas selector (optional)' ]
            ];
            return el( element.Fragment, {},
                el( blockEditor.InspectorControls, {},
                    el( components.PanelBody, { title: 'Live clock' },
                        fields.map( function( field ) { return el( components.TextControl, { key: field[0], label: field[1], value: config[ field[0] ] || '', onChange: set( field[0] ) } ); } ),
                        el( components.SelectControl, { label: 'Hour format', value: config.hourCycle || '12', options: [ { label: '12-hour', value: '12' }, { label: '24-hour', value: '24' } ], onChange: set( 'hourCycle' ) } ),
                        el( components.TextControl, { label: 'Date locale', value: config.locale || 'en-US', onChange: set( 'locale' ) } )
                    ),
                    el( components.PanelBody, { title: 'Startup and replay', initialOpen: false },
                        el( components.TextControl, { label: 'Initial frame (optional)', value: config.initialFrame || '', onChange: set( 'initialFrame' ) } ),
                        el( components.TextControl, { label: 'Transition frames (comma-separated)', value: config.stages || '', onChange: set( 'stages' ) } ),
                        [ ['startDelayMs','First run delay (ms)'], ['stageDurationMs','Frame interval (ms)'], ['pauseMs','Pause after frames (ms)'], ['dateDelayMs','Date reveal delay (ms)'], ['characterIntervalMs','Date character interval (ms)'] ].map( function( field ) {
                            return el( components.TextControl, { key: field[0], label: field[1], type: 'number', min: 0, max: 1000, value: config[ field[0] ] || '', onChange: set( field[0] ) } );
                        } )
                    )
                ),
                el( 'div', blockEditor.useBlockProps(), 'Live clock: ' + ( config.hourSelector || 'configure its target selectors' ) )
            );
        },
        save: function( props ) {
            return el( 'span', { hidden: true, 'data-blocks-engine-live-clock': JSON.stringify( props.attributes.config || {} ) } );
        }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;
        $view = <<<'JS'
( function() {
    function select( selector ) {
        if ( typeof selector !== 'string' || selector.length > 120 ) return null;
        try { return document.querySelector( selector ); } catch ( error ) { return null; }
    }
    function bounded( value, fallback, max ) {
        if ( value === undefined || value === null || value === '' ) return fallback;
        var number = Number( value );
        return Number.isFinite( number ) && number >= 0 && number <= max ? number : fallback;
    }
    function wait( milliseconds ) { return new Promise( function( resolve ) { window.setTimeout( resolve, milliseconds ); } ); }
    function dateText( date, locale ) {
        try {
            var parts = new Intl.DateTimeFormat( locale, { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' } ).formatToParts( date );
            var part = function( type ) { return ( parts.find( function( item ) { return item.type === type; } ) || {} ).value || ''; };
            return ( part( 'weekday' ) + ', ' + part( 'month' ) + ' ' + part( 'day' ) + ', ' + part( 'year' ) ).toUpperCase();
        } catch ( error ) { return ''; }
    }
    function mount( marker ) {
        var config;
        try { config = JSON.parse( marker.getAttribute( 'data-blocks-engine-live-clock' ) || '{}' ); } catch ( error ) { return; }
        if ( ! config || typeof config !== 'object' || Array.isArray( config ) ) return;
        var hours = select( config.hourSelector );
        var minutes = select( config.minuteSelector );
        var timezone = select( config.timezoneSelector );
        if ( ! hours || ! minutes || ! timezone || hours.children.length || minutes.children.length || timezone.children.length ) return;
        var ampm = select( config.ampmSelector );
        var date = select( config.dateSelector );
        var colon = select( config.colonSelector );
        var trigger = select( config.triggerSelector );
        var ripple = select( config.rippleSelector );
        var stages = typeof config.stages === 'string' ? config.stages.split( ',' ).map( function( value ) { return value.trim(); } ).filter( Boolean ).slice( 0, 8 ) : [];
        var running = false;
        var showingTime = false;
        var initialFrame = typeof config.initialFrame === 'string' && config.initialFrame.length <= 8 ? config.initialFrame : '';
        function currentTime() {
            if ( ! showingTime ) return;
            var now = new Date();
            var hour = now.getHours();
            if ( ampm ) ampm.textContent = config.hourCycle === '24' ? '' : ( hour < 12 ? 'AM' : 'PM' );
            if ( config.hourCycle !== '24' ) hour = hour % 12 || 12;
            hours.textContent = String( hour ).padStart( 2, '0' );
            minutes.textContent = String( now.getMinutes() ).padStart( 2, '0' );
            var offset = -now.getTimezoneOffset() / 60;
            timezone.textContent = '(GMT ' + ( offset >= 0 ? '+' : '' ) + offset + ')';
        }
        async function replay() {
            if ( running ) return;
            running = true;
            showingTime = false;
            if ( colon ) colon.classList.remove( 'blink-colon' );
            if ( date && ! date.children.length ) date.textContent = '';
            var interval = bounded( config.stageDurationMs, 150, 1000 );
            for ( var index = 0; index < stages.length; index++ ) {
                hours.textContent = stages[ index ]; minutes.textContent = stages[ index ];
                await wait( interval );
            }
            await wait( bounded( config.pauseMs, 200, 1000 ) );
            showingTime = true; currentTime();
            if ( colon ) colon.classList.add( 'blink-colon' );
            if ( date && ! date.children.length ) {
                await wait( bounded( config.dateDelayMs, 500, 1000 ) );
                var text = Array.from( dateText( new Date(), config.locale || 'en-US' ) );
                var characterInterval = bounded( config.characterIntervalMs, 50, 500 );
                for ( var letter = 0; letter < text.length; letter++ ) {
                    date.textContent += text[ letter ];
                    await wait( characterInterval );
                }
            }
            if ( ripple && ripple.tagName === 'CANVAS' && trigger ) {
                var rect = trigger.getBoundingClientRect();
                ripple.dispatchEvent( new CustomEvent( 'blocks-engine-ripple', { detail: { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 } } ) );
            }
            running = false;
        }
        window.setInterval( currentTime, 1000 );
        if ( initialFrame ) { hours.textContent = initialFrame; minutes.textContent = initialFrame; }
        if ( ampm ) ampm.textContent = '';
        timezone.textContent = '';
        if ( date && ! date.children.length ) date.textContent = '';
        if ( colon ) colon.classList.remove( 'blink-colon' );
        var startupTimer = window.setTimeout( function() { void replay(); }, bounded( config.startDelayMs, 0, 5000 ) );
        if ( trigger ) trigger.addEventListener( 'click', function() { window.clearTimeout( startupTimer ); void replay(); } );
    }
    function mountAll() { document.querySelectorAll( '[data-blocks-engine-live-clock]' ).forEach( mount ); }
    if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', mountAll ); else mountAll();
} )();
JS;
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => array(
                'apiVersion' => 3,
                'name' => $blockName,
                'title' => 'Live Clock',
                'category' => 'widgets',
                'description' => 'Keep existing editable clock targets current, with optional replay.',
                'editorScript' => 'file:./index.js',
                'viewScript' => 'file:./view.js',
                'attributes' => array('config' => array('type' => 'object', 'default' => array())),
                'supports' => array('html' => false),
            ),
            'assets' => array('index.js' => str_replace('__BLOCK_NAME__', $blockName, $editor)),
            'view_js' => $view,
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element')),
        );
    }
}
