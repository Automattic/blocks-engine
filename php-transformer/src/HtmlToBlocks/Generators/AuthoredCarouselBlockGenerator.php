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
            'presentation' => array('type' => 'string', 'default' => 'track'),
            'slideCount' => array('type' => 'number', 'default' => 0),
            'initialSlide' => array('type' => 'number', 'default' => 0),
            'viewportHeight' => array('type' => 'number', 'default' => 0),
            'transitionDuration' => array('type' => 'number', 'default' => 300),
            'autoplayInterval' => array('type' => 'number', 'default' => 0),
            'showDots' => array('type' => 'boolean', 'default' => false),
            'fullBleed' => array('type' => 'boolean', 'default' => false),
        );
        $editor = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var InnerBlocks = blockEditor.InnerBlocks;
    function normalizedItems( value ) { value = Math.round( Number( value ) || 4 ); return Math.min( 6, Math.max( 1, value ) ); }
    function normalizedCount( value ) { return Math.max( 0, Math.round( Number( value ) || 0 ) ); }
    function rootProps( attributes ) {
        var items = normalizedItems( attributes.itemsPerView );
        var presentation = 'slideshow' === attributes.presentation ? 'slideshow' : 'track';
        var initial = Math.min( Math.max( 0, Math.round( Number( attributes.initialSlide ) || 0 ) ), Math.max( 0, normalizedCount( attributes.slideCount ) - 1 ) );
        return { className: 'blocks-engine-authored-carousel blocks-engine-authored-carousel--items-' + items + ' blocks-engine-authored-carousel--' + presentation + ( attributes.fullBleed ? ' blocks-engine-authored-carousel--full-bleed' : '' ), style: attributes.viewportHeight > 0 ? { '--blocks-engine-carousel-height': Math.round( attributes.viewportHeight ) + 'px', '--blocks-engine-carousel-transition': Math.max( 0, Math.round( Number( attributes.transitionDuration ) || 0 ) ) + 'ms' } : undefined, role: 'region', 'aria-label': attributes.ariaLabel || 'Carousel', 'aria-roledescription': 'carousel', 'data-wrap': false === attributes.wrap ? 'false' : 'true', 'data-wp-interactive': 'blocks-engine/carousel', 'data-wp-context': JSON.stringify( { index: initial, wrap: false !== attributes.wrap, count: 0, visible: items, presentation: presentation, autoplayInterval: Math.max( 0, Math.round( Number( attributes.autoplayInterval ) || 0 ) ), paused: false } ), 'data-wp-init': 'callbacks.init', 'data-wp-on--mouseenter': 'actions.pause', 'data-wp-on--mouseleave': 'actions.resume', 'data-wp-on--focusin': 'actions.pause', 'data-wp-on--focusout': 'actions.resume' };
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__,
        supports: { html: false, customClassName: false },
        edit: function( props ) {
            return createElement( 'div', { className: 'blocks-engine-authored-carousel-editor' }, createElement( 'strong', null, props.attributes.ariaLabel || 'Carousel' ), createElement( InnerBlocks, { allowedBlocks: [ 'core/image', 'core/group' ], renderAppender: InnerBlocks.ButtonBlockAppender } ) );
        },
        save: function( props ) {
            var dotCount = props.attributes.showDots ? normalizedCount( props.attributes.slideCount ) : 0;
            var dots = Array.from( { length: dotCount }, function( _, index ) { return createElement( 'button', { key: index, type: 'button', className: 'blocks-engine-authored-carousel__dot', 'aria-label': 'Show slide ' + ( index + 1 ), 'data-carousel-index': String( index ), 'data-wp-on--click': 'actions.goTo' } ); } );
            return createElement( 'div', rootProps( props.attributes ),
                createElement( 'button', { type: 'button', className: 'blocks-engine-authored-carousel__previous', 'data-carousel-previous': 'true', 'data-wp-on--click': 'actions.previous', 'data-wp-bind--disabled': 'state.atStart' }, 'Previous' ),
                createElement( 'div', { className: 'blocks-engine-authored-carousel__viewport', tabIndex: 0, 'data-wp-on--keydown': 'actions.keydown' }, createElement( 'div', { className: 'blocks-engine-authored-carousel__track' }, createElement( InnerBlocks.Content ) ) ),
                createElement( 'button', { type: 'button', className: 'blocks-engine-authored-carousel__next', 'data-carousel-next': 'true', 'data-wp-on--click': 'actions.next', 'data-wp-bind--disabled': 'state.atEnd' }, 'Next' ),
                dotCount > 0 ? createElement( 'div', { className: 'blocks-engine-authored-carousel__dots', role: 'group', 'aria-label': 'Choose slide' }, dots ) : null,
                createElement( 'span', { className: 'blocks-engine-authored-carousel__status', 'aria-live': 'polite', 'aria-atomic': 'true', 'data-wp-text': 'state.statusText' } )
            );
        }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        // The Interactivity API is WordPress's own front-end runtime for blocks,
        // so the behavior is declared on the markup and the module carries only
        // the state the directives read.
        $view = <<<'JS'
import { store, getContext, getElement, withScope } from '@wordpress/interactivity';

const slidesOf = ( ref ) => Array.from( ref.querySelectorAll( '.blocks-engine-authored-carousel__track > *' ) );

const rootOf = ( ref ) => ref.closest( '.blocks-engine-authored-carousel' );

const visibleCount = ( ref ) => {
    const viewport = ref.querySelector( '.blocks-engine-authored-carousel__viewport' );
    const slides = slidesOf( ref );
    if ( ! viewport || 0 === slides.length ) {
        return 1;
    }
    const width = slides[ 0 ].getBoundingClientRect().width;
    return width > 0 ? Math.max( 1, Math.min( slides.length, Math.round( viewport.clientWidth / width ) ) ) : 1;
};

const maximumIndex = ( context ) => Math.max( 0, context.count - context.visible );

const syncSlideshow = ( root, context ) => {
    if ( 'slideshow' !== context.presentation ) {
        return;
    }
    slidesOf( root ).forEach( ( slide, index ) => {
        const active = index === context.index;
        slide.classList.toggle( 'blocks-engine-authored-carousel__slide--active', active );
        slide.setAttribute( 'aria-hidden', active ? 'false' : 'true' );
        slide.toggleAttribute( 'inert', ! active );
    } );
    root.querySelectorAll( '.blocks-engine-authored-carousel__dot' ).forEach( ( dot, index ) => {
        dot.classList.toggle( 'blocks-engine-authored-carousel__dot--active', index === context.index );
        if ( index === context.index ) {
            dot.setAttribute( 'aria-current', 'true' );
        } else {
            dot.removeAttribute( 'aria-current' );
        }
    } );
};

const show = ( requested ) => {
    const context = getContext();
    const { ref } = getElement();
    const root = rootOf( ref );
    if ( ! root ) {
        return;
    }
    const maximum = maximumIndex( context );
    context.index = context.wrap
        ? ( requested < 0 ? maximum : requested > maximum ? 0 : requested )
        : Math.max( 0, Math.min( maximum, requested ) );
    syncSlideshow( root, context );
    if ( 'slideshow' === context.presentation ) {
        return;
    }
    const viewport = root.querySelector( '.blocks-engine-authored-carousel__viewport' );
    const slide = slidesOf( root )[ context.index ];
    if ( viewport && slide ) {
        viewport.scrollTo( {
            left: slide.offsetLeft,
            behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth',
        } );
    }
};

store( 'blocks-engine/carousel', {
    state: {
        get atStart() {
            const context = getContext();
            return 0 === maximumIndex( context ) || ( ! context.wrap && 0 === context.index );
        },
        get atEnd() {
            const context = getContext();
            const maximum = maximumIndex( context );
            return 0 === maximum || ( ! context.wrap && context.index === maximum );
        },
        get statusText() {
            const context = getContext();
            return 'Slide ' + ( context.index + 1 ) + ' of ' + context.count;
        },
    },
    callbacks: {
        init() {
            const context = getContext();
            const { ref } = getElement();
            context.count = slidesOf( ref ).length;
            context.visible = 'slideshow' === context.presentation ? 1 : visibleCount( ref );
            context.index = Math.min( context.index, maximumIndex( context ) );
            syncSlideshow( ref, context );
            if ( 'slideshow' !== context.presentation || context.autoplayInterval <= 0 || context.count < 2 || window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
                return;
            }
            const advance = withScope( () => {
                if ( ! context.paused ) {
                    show( context.index + 1 );
                }
            } );
            const timer = window.setInterval( advance, context.autoplayInterval );
            return () => window.clearInterval( timer );
        },
    },
    actions: {
        previous() {
            show( getContext().index - 1 );
        },
        next() {
            show( getContext().index + 1 );
        },
        goTo( event ) {
            show( Number( event.currentTarget.dataset.carouselIndex ) || 0 );
        },
        pause() {
            getContext().paused = true;
        },
        resume() {
            getContext().paused = false;
        },
        keydown( event ) {
            if ( 'ArrowLeft' !== event.key && 'ArrowRight' !== event.key ) {
                return;
            }
            event.preventDefault();
            show( getContext().index + ( 'ArrowLeft' === event.key ? -1 : 1 ) );
        },
    },
} );
JS;
        $style = '.blocks-engine-authored-carousel{--blocks-engine-carousel-gap:1rem;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:var(--blocks-engine-carousel-gap);align-items:center;max-width:100%;min-width:0}.blocks-engine-authored-carousel__viewport{min-width:0;overflow:hidden;scroll-behavior:smooth}.blocks-engine-authored-carousel__track{display:grid;grid-auto-flow:column;grid-auto-columns:calc((100% - 3rem)/4);gap:var(--blocks-engine-carousel-gap)}.blocks-engine-authored-carousel--items-1 .blocks-engine-authored-carousel__track{grid-auto-columns:100%}.blocks-engine-authored-carousel--items-2 .blocks-engine-authored-carousel__track{grid-auto-columns:calc((100% - 1rem)/2)}.blocks-engine-authored-carousel--items-3 .blocks-engine-authored-carousel__track{grid-auto-columns:calc((100% - 2rem)/3)}.blocks-engine-authored-carousel--items-5 .blocks-engine-authored-carousel__track{grid-auto-columns:calc((100% - 4rem)/5)}.blocks-engine-authored-carousel--items-6 .blocks-engine-authored-carousel__track{grid-auto-columns:calc((100% - 5rem)/6)}.blocks-engine-authored-carousel__track>*{box-sizing:border-box;min-width:0;margin:0}.blocks-engine-authored-carousel__track>.wp-block-image img{display:block;width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:inherit}.blocks-engine-authored-carousel__previous,.blocks-engine-authored-carousel__next{cursor:pointer}.blocks-engine-authored-carousel__previous:disabled,.blocks-engine-authored-carousel__next:disabled{cursor:default;opacity:.45}.blocks-engine-authored-carousel__status{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:900px){.blocks-engine-authored-carousel .blocks-engine-authored-carousel__track{grid-auto-columns:calc((100% - 1rem)/2)}}@media(max-width:600px){.blocks-engine-authored-carousel .blocks-engine-authored-carousel__track{grid-auto-columns:100%}}@media(prefers-reduced-motion:reduce){.blocks-engine-authored-carousel__viewport{scroll-behavior:auto}}';
        $style .= '.blocks-engine-authored-carousel--full-bleed{width:100vw;max-width:none;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw)}.blocks-engine-authored-carousel--slideshow{position:relative;display:block;gap:0}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__viewport,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track{height:var(--blocks-engine-carousel-height);width:100%}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track{position:relative;display:block}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>*{position:absolute;inset:0;width:100%;height:100%;opacity:0;visibility:hidden;transition:opacity var(--blocks-engine-carousel-transition,300ms) ease,visibility var(--blocks-engine-carousel-transition,300ms) ease}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>.blocks-engine-authored-carousel__slide--active{opacity:1;visibility:visible;z-index:1}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>.wp-block-image img{width:100%;height:100%;aspect-ratio:auto;object-fit:cover}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__previous,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__next{position:absolute;top:50%;z-index:3;width:3rem;height:3rem;padding:0;border:0;border-radius:50%;background:rgba(0,0,0,.32);color:#fff;font-size:0;transform:translateY(-50%)}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__previous{left:1rem}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__next{right:1rem}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__previous::before,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__next::before{display:block;font-size:2rem;line-height:1;content:"\\2039"}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__next::before{content:"\\203a"}.blocks-engine-authored-carousel__dots{position:absolute;right:0;bottom:1.25rem;left:0;z-index:3;display:flex;justify-content:center;gap:.65rem}.blocks-engine-authored-carousel__dot{width:.75rem;height:.75rem;padding:0;border:1px solid currentColor;border-radius:50%;background:transparent;color:#fff;cursor:pointer}.blocks-engine-authored-carousel__dot--active{background:currentColor}@media(prefers-reduced-motion:reduce){.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>*{transition:none}}';

        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => array(
                'apiVersion' => 3,
                'name' => $blockName,
                'title' => 'Carousel',
                'category' => 'media',
                'description' => 'An editable carousel with bounded previous and next navigation.',
                'editorScript' => 'file:./index.js',
                'viewScriptModule' => 'file:./view.js',
                'style' => 'file:./style.css',
                'attributes' => $attributes,
                'supports' => array('html' => false, 'customClassName' => false, 'interactivity' => true),
            ),
            'assets' => array(
                'index.js' => str_replace(array('__BLOCK_NAME__', '__ATTRIBUTES__'), array($blockName, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $editor),
                'style.css' => $style,
            ),
            'view_js' => $view,
            'script_dependencies' => array(
                'index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element'),
                'view.js' => array('@wordpress/interactivity'),
            ),
        );
    }

    /** @param array<string, mixed> $attributes @return array{opening: string, closing: string} */
    public function shell(array $attributes): array
    {
        $label = htmlspecialchars((string) ($attributes['ariaLabel'] ?? 'Carousel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $items = min(6, max(1, (int) ($attributes['itemsPerView'] ?? 4)));
        $wrap = false === ($attributes['wrap'] ?? true) ? 'false' : 'true';
        $presentation = 'slideshow' === ($attributes['presentation'] ?? 'track') ? 'slideshow' : 'track';
        $slideCount = max(0, (int) ($attributes['slideCount'] ?? 0));
        $initialSlide = min(max(0, (int) ($attributes['initialSlide'] ?? 0)), max(0, $slideCount - 1));
        $viewportHeight = max(0, (int) ($attributes['viewportHeight'] ?? 0));
        $transitionDuration = max(0, (int) ($attributes['transitionDuration'] ?? 300));
        $autoplayInterval = max(0, (int) ($attributes['autoplayInterval'] ?? 0));
        $fullBleed = true === ($attributes['fullBleed'] ?? false);
        $showDots = true === ($attributes['showDots'] ?? false) && 1 < $slideCount;
        $classes = 'blocks-engine-authored-carousel blocks-engine-authored-carousel--items-' . $items . ' blocks-engine-authored-carousel--' . $presentation . ($fullBleed ? ' blocks-engine-authored-carousel--full-bleed' : '');
        $styleAttribute = 0 < $viewportHeight ? ' style="--blocks-engine-carousel-height:' . $viewportHeight . 'px;--blocks-engine-carousel-transition:' . $transitionDuration . 'ms"' : '';

        $context = htmlspecialchars(
            (string) json_encode(array('index' => $initialSlide, 'wrap' => 'true' === $wrap, 'count' => 0, 'visible' => $items, 'presentation' => $presentation, 'autoplayInterval' => $autoplayInterval, 'paused' => false), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $dots = '';
        if ( $showDots ) {
            $dots = '<div class="blocks-engine-authored-carousel__dots" role="group" aria-label="Choose slide">';
            for ( $index = 0; $index < $slideCount; ++$index ) {
                $dots .= '<button type="button" class="blocks-engine-authored-carousel__dot" aria-label="Show slide ' . ($index + 1) . '" data-carousel-index="' . $index . '" data-wp-on--click="actions.goTo"></button>';
            }
            $dots .= '</div>';
        }

        return array(
            'opening' => '<div class="' . $classes . '"' . $styleAttribute . ' role="region" aria-label="' . $label . '" aria-roledescription="carousel" data-wrap="' . $wrap . '" data-wp-interactive="blocks-engine/carousel" data-wp-context="' . $context . '" data-wp-init="callbacks.init" data-wp-on--mouseenter="actions.pause" data-wp-on--mouseleave="actions.resume" data-wp-on--focusin="actions.pause" data-wp-on--focusout="actions.resume"><button type="button" class="blocks-engine-authored-carousel__previous" data-carousel-previous="true" data-wp-on--click="actions.previous" data-wp-bind--disabled="state.atStart">Previous</button><div class="blocks-engine-authored-carousel__viewport" tabindex="0" data-wp-on--keydown="actions.keydown"><div class="blocks-engine-authored-carousel__track">',
            'closing' => '</div></div><button type="button" class="blocks-engine-authored-carousel__next" data-carousel-next="true" data-wp-on--click="actions.next" data-wp-bind--disabled="state.atEnd">Next</button>' . $dots . '<span class="blocks-engine-authored-carousel__status" aria-live="polite" aria-atomic="true" data-wp-text="state.statusText"></span></div>',
        );
    }
}
