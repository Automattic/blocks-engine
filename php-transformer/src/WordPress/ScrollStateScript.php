<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

/** Replays captured scroll-driven class and style differences. */
final class ScrollStateScript
{
    public static function viewScript(): string
    {
        return <<<'JS'
( function() {
    function parseConfig( element ) {
        try { return JSON.parse( element.getAttribute( 'data-blocks-engine-scroll-state-config' ) || '{}' ); } catch ( error ) { return null; }
    }
    function applyState( element, config, stuck ) {
        ( config.addClasses || [] ).forEach( function( name ) { element.classList.toggle( name, stuck ); } );
        ( config.removeClasses || [] ).forEach( function( name ) { element.classList.toggle( name, ! stuck ); } );
        ( config.styleTargets || [] ).forEach( function( target ) {
            var selector = target.selector || '';
            var node = ( ':scope' === selector || '' === selector ) ? element : element.querySelector( selector );
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
            // Only restoration needs to bypass the rendering clock. Keep the
            // captured scrolled pose frame-scheduled as before; replaying it
            // earlier can override other scroll-driven presentation.
            if ( window.scrollY <= threshold ) {
                applyState( element, config, false );
                return;
            }
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
    }
}
