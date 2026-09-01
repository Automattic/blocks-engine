<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds an editable companion block for bounded authored carousels. */
final class AuthoredCarouselBlockGenerator
{
    public const LOCAL_NAME = 'authored-carousel';

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        $blockName = $namespace . '/' . self::LOCAL_NAME;
        $attributes = array(
            'ariaLabel' => array('type' => 'string', 'default' => 'Carousel'),
            'itemsPerView' => array('type' => 'number', 'default' => 4),
            'wrap' => array('type' => 'boolean', 'default' => true),
            'mode' => array('type' => 'string', 'default' => 'manual'),
            'autoplay' => array('type' => 'boolean', 'default' => false),
            'interval' => array('type' => 'number', 'default' => 4),
            'transitionDuration' => array('type' => 'number', 'default' => 0.5),
            'pauseOnHover' => array('type' => 'boolean', 'default' => false),
            'pauseOnFocus' => array('type' => 'boolean', 'default' => false),
            'navigation' => array('type' => 'boolean', 'default' => true),
        );
        $editor = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var InnerBlocks = blockEditor.InnerBlocks;
    function items( value ) { return Math.min( 6, Math.max( 1, Math.round( Number( value ) || 4 ) ) ); }
    function slideshow( attributes ) { return 'slideshow' === attributes.mode; }
    function rootProps( attributes ) {
        var isSlideshow = slideshow( attributes );
        return { className: 'blocks-engine-authored-carousel blocks-engine-authored-carousel--items-' + ( isSlideshow ? 1 : items( attributes.itemsPerView ) ) + ( isSlideshow ? ' blocks-engine-authored-carousel--slideshow' : '' ), role: 'region', 'aria-label': attributes.ariaLabel || 'Carousel', 'aria-roledescription': 'carousel', 'data-wrap': false === attributes.wrap ? 'false' : 'true', 'data-autoplay': isSlideshow && false !== attributes.autoplay ? 'true' : 'false', 'data-interval': Math.min( 60, Math.max( 1, Number( attributes.interval ) || 4 ) ), 'data-transition-duration': Math.min( 10, Math.max( 0, Number( attributes.transitionDuration ) || 0 ) ), 'data-pause-on-hover': isSlideshow && false !== attributes.pauseOnHover ? 'true' : 'false', 'data-pause-on-focus': isSlideshow && false !== attributes.pauseOnFocus ? 'true' : 'false' };
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__, supports: { html: false, customClassName: false },
        edit: function( props ) { return createElement( 'div', { className: 'blocks-engine-authored-carousel-editor' }, createElement( 'strong', null, props.attributes.ariaLabel || 'Carousel' ), createElement( InnerBlocks, { allowedBlocks: [ 'core/image', 'core/group' ], renderAppender: InnerBlocks.ButtonBlockAppender } ) ); },
        save: function( props ) {
            var isSlideshow = slideshow( props.attributes );
            return createElement( 'div', rootProps( props.attributes ),
                ! isSlideshow && createElement( 'button', { type: 'button', className: 'blocks-engine-authored-carousel__previous', 'data-carousel-previous': 'true' }, 'Previous' ),
                createElement( 'div', { className: 'blocks-engine-authored-carousel__viewport', tabIndex: 0 }, createElement( 'div', { className: 'blocks-engine-authored-carousel__track' }, createElement( InnerBlocks.Content ) ) ),
                ! isSlideshow && createElement( 'button', { type: 'button', className: 'blocks-engine-authored-carousel__next', 'data-carousel-next': 'true' }, 'Next' ),
                isSlideshow && createElement( 'button', { type: 'button', className: 'blocks-engine-authored-carousel__pause', 'data-carousel-pause': 'true', 'aria-pressed': 'false' }, 'Pause slideshow' ),
                createElement( 'span', { className: 'blocks-engine-authored-carousel__status', 'aria-live': isSlideshow ? 'off' : 'polite', 'aria-atomic': 'true' } )
            );
        }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        $view = <<<'JS'
( function() {
    function mount( root ) {
        if ( root.dataset.carouselMounted ) return;
        var viewport = root.querySelector( '.blocks-engine-authored-carousel__viewport' );
        var track = root.querySelector( '.blocks-engine-authored-carousel__track' );
        var previous = root.querySelector( '[data-carousel-previous]' );
        var next = root.querySelector( '[data-carousel-next]' );
        var pause = root.querySelector( '[data-carousel-pause]' );
        var status = root.querySelector( '.blocks-engine-authored-carousel__status' );
        var slides = track ? Array.prototype.slice.call( track.children ) : [];
        var isSlideshow = root.classList.contains( 'blocks-engine-authored-carousel--slideshow' );
        if ( ! viewport || ! track || slides.length < 2 || ( ! isSlideshow && ( ! previous || ! next ) ) ) return;
        var index = 0, timer = null, userPaused = false;
        var wraps = 'false' !== root.dataset.wrap;
        var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
        var interval = Math.min( 60000, Math.max( 1000, ( Number( root.dataset.interval ) || 4 ) * 1000 ) );
        if ( isSlideshow ) root.style.setProperty( '--blocks-engine-carousel-transition', Math.min( 10, Math.max( 0, Number( root.dataset.transitionDuration ) || 0 ) ) + 's' );
        function visibleItems() { var width = slides[ 0 ].getBoundingClientRect().width; return width > 0 ? Math.max( 1, Math.min( slides.length, Math.round( viewport.clientWidth / width ) ) ) : 1; }
        function maximumIndex() { return isSlideshow ? slides.length - 1 : Math.max( 0, slides.length - visibleItems() ); }
        function update( announce ) {
            var maximum = maximumIndex(); index = Math.min( index, maximum );
            if ( isSlideshow ) slides.forEach( function( slide, position ) { slide.classList.toggle( 'is-active', position === index ); } );
            else { previous.disabled = 0 === maximum || ( ! wraps && 0 === index ); next.disabled = 0 === maximum || ( ! wraps && index === maximum ); }
            if ( announce && status ) status.textContent = 'Slide ' + ( index + 1 ) + ' of ' + slides.length;
        }
        function show( requested, announce ) {
            var maximum = maximumIndex(); index = wraps ? ( requested < 0 ? maximum : requested > maximum ? 0 : requested ) : Math.max( 0, Math.min( maximum, requested ) );
            if ( isSlideshow ) update( announce ); else { viewport.scrollTo( { left: slides[ index ].offsetLeft, behavior: reducedMotion ? 'auto' : 'smooth' } ); update( announce ); }
        }
        function stop() { if ( timer ) { window.clearInterval( timer ); timer = null; } }
        function start() { if ( isSlideshow && ! reducedMotion && ! userPaused && ! document.hidden && 'true' === root.dataset.autoplay && ! timer ) timer = window.setInterval( function() { show( index + 1, false ); }, interval ); }
        function pauseForUser() { userPaused = true; stop(); if ( pause ) { pause.setAttribute( 'aria-pressed', 'true' ); pause.textContent = 'Resume slideshow'; } }
        if ( isSlideshow ) {
            slides.forEach( function( slide, position ) { slide.classList.toggle( 'is-active', 0 === position ); } );
            if ( 'true' === root.dataset.pauseOnHover ) { root.addEventListener( 'mouseenter', stop ); root.addEventListener( 'mouseleave', start ); }
            if ( 'true' === root.dataset.pauseOnFocus ) { root.addEventListener( 'focusin', stop ); root.addEventListener( 'focusout', function() { window.setTimeout( start ); } ); }
            document.addEventListener( 'visibilitychange', function() { if ( document.hidden ) stop(); else start(); } );
            root.addEventListener( 'pointerdown', function( event ) { if ( event.target !== pause ) pauseForUser(); } );
            if ( pause ) pause.addEventListener( 'click', function( event ) { event.stopPropagation(); if ( userPaused ) { userPaused = false; pause.setAttribute( 'aria-pressed', 'false' ); pause.textContent = 'Pause slideshow'; start(); } else pauseForUser(); } );
        } else {
            previous.addEventListener( 'click', function() { show( index - 1, true ); } ); next.addEventListener( 'click', function() { show( index + 1, true ); } );
        }
        viewport.addEventListener( 'keydown', function( event ) { if ( 'ArrowLeft' === event.key || 'ArrowRight' === event.key ) { event.preventDefault(); if ( isSlideshow ) pauseForUser(); show( index + ( 'ArrowLeft' === event.key ? -1 : 1 ), ! isSlideshow ); } } );
        if ( window.ResizeObserver ) new ResizeObserver( function() { update( false ); } ).observe( viewport );
        root.dataset.carouselMounted = 'true'; update( false ); start();
    }
    function mountAll() { document.querySelectorAll( '.blocks-engine-authored-carousel' ).forEach( mount ); }
    if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', mountAll ); else mountAll();
} )();
JS;
        $style = <<<'CSS'
.blocks-engine-authored-carousel{--blocks-engine-carousel-gap:1rem;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:var(--blocks-engine-carousel-gap);align-items:center;max-width:100%;min-width:0}.blocks-engine-authored-carousel__viewport{min-width:0;overflow:hidden;scroll-behavior:smooth}.blocks-engine-authored-carousel__track{display:grid;grid-auto-flow:column;grid-auto-columns:calc((100% - 3rem)/4);gap:var(--blocks-engine-carousel-gap)}.blocks-engine-authored-carousel--items-1 .blocks-engine-authored-carousel__track{grid-auto-columns:100%}.blocks-engine-authored-carousel--items-2 .blocks-engine-authored-carousel__track{grid-auto-columns:calc((100% - 1rem)/2)}.blocks-engine-authored-carousel--items-3 .blocks-engine-authored-carousel__track{grid-auto-columns:calc((100% - 2rem)/3)}.blocks-engine-authored-carousel--items-5 .blocks-engine-authored-carousel__track{grid-auto-columns:calc((100% - 4rem)/5)}.blocks-engine-authored-carousel--items-6 .blocks-engine-authored-carousel__track{grid-auto-columns:calc((100% - 5rem)/6)}.blocks-engine-authored-carousel__track>*{box-sizing:border-box;min-width:0;margin:0}.blocks-engine-authored-carousel__track>.wp-block-image img{display:block;width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:inherit}.blocks-engine-authored-carousel__previous,.blocks-engine-authored-carousel__next{cursor:pointer}.blocks-engine-authored-carousel__previous:disabled,.blocks-engine-authored-carousel__next:disabled{cursor:default;opacity:.45}.blocks-engine-authored-carousel__status{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.blocks-engine-authored-carousel--slideshow{display:block;position:relative}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track{display:block;position:relative}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>*{display:block;position:absolute;inset:0;opacity:0;pointer-events:none;transition:opacity var(--blocks-engine-carousel-transition, .5s) ease}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>*.is-active{opacity:1;pointer-events:auto;position:relative}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>.wp-block-image img{aspect-ratio:auto}.blocks-engine-authored-carousel__pause{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.blocks-engine-authored-carousel__pause:focus{width:auto;height:auto;clip:auto;z-index:1;top:.5rem;right:.5rem;padding:.5rem;margin:0}@media(max-width:900px){.blocks-engine-authored-carousel .blocks-engine-authored-carousel__track{grid-auto-columns:calc((100% - 1rem)/2)}}@media(max-width:600px){.blocks-engine-authored-carousel .blocks-engine-authored-carousel__track{grid-auto-columns:100%}}@media(prefers-reduced-motion:reduce){.blocks-engine-authored-carousel__viewport{scroll-behavior:auto}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>*{transition:none}}
CSS;

        return array('name' => self::LOCAL_NAME, 'block_json' => array('apiVersion' => 3, 'name' => $blockName, 'title' => 'Carousel', 'category' => 'media', 'description' => 'An editable carousel with bounded navigation or autoplay.', 'editorScript' => 'file:./index.js', 'viewScript' => 'file:./view.js', 'style' => 'file:./style.css', 'attributes' => $attributes, 'supports' => array('html' => false, 'customClassName' => false)), 'assets' => array('index.js' => str_replace(array('__BLOCK_NAME__', '__ATTRIBUTES__'), array($blockName, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $editor), 'style.css' => $style), 'view_js' => $view, 'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')));
    }

    /** @param array<string, mixed> $attributes @return array{opening: string, closing: string} */
    public function shell(array $attributes): array
    {
        $label = htmlspecialchars((string) ($attributes['ariaLabel'] ?? 'Carousel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $slideshow = 'slideshow' === ($attributes['mode'] ?? 'manual');
        $items = $slideshow ? 1 : min(6, max(1, (int) ($attributes['itemsPerView'] ?? 4)));
        $wrap = false === ($attributes['wrap'] ?? true) ? 'false' : 'true';
        $interval = min(60, max(1, (float) ($attributes['interval'] ?? 4)));
        $duration = min(10, max(0, (float) ($attributes['transitionDuration'] ?? 0.5)));
        $class = 'blocks-engine-authored-carousel blocks-engine-authored-carousel--items-' . $items . ( $slideshow ? ' blocks-engine-authored-carousel--slideshow' : '' );
        $data = ' data-wrap="' . $wrap . '" data-autoplay="' . ( $slideshow && false !== ($attributes['autoplay'] ?? true) ? 'true' : 'false' ) . '" data-interval="' . $interval . '" data-transition-duration="' . $duration . '" data-pause-on-hover="' . ( $slideshow && false !== ($attributes['pauseOnHover'] ?? true) ? 'true' : 'false' ) . '" data-pause-on-focus="' . ( $slideshow && false !== ($attributes['pauseOnFocus'] ?? true) ? 'true' : 'false' ) . '"';
        $opening = '<div class="' . $class . '" role="region" aria-label="' . $label . '" aria-roledescription="carousel"' . $data . '>';
        if ( ! $slideshow ) $opening .= '<button type="button" class="blocks-engine-authored-carousel__previous" data-carousel-previous="true">Previous</button>';
        $opening .= '<div class="blocks-engine-authored-carousel__viewport" tabindex="0"><div class="blocks-engine-authored-carousel__track">';
        $closing = '</div></div>';
        if ( ! $slideshow ) $closing .= '<button type="button" class="blocks-engine-authored-carousel__next" data-carousel-next="true">Next</button>';
        if ( $slideshow ) $closing .= '<button type="button" class="blocks-engine-authored-carousel__pause" data-carousel-pause="true" aria-pressed="false">Pause slideshow</button>';
        $closing .= '<span class="blocks-engine-authored-carousel__status" aria-live="' . ( $slideshow ? 'off' : 'polite' ) . '" aria-atomic="true"></span></div>';
        return array('opening' => $opening, 'closing' => $closing);
    }
}
