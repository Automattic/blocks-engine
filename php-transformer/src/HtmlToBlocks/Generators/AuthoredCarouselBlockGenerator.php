<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;
use LogicException;

/** Builds an editable companion block for bounded authored carousels. */
final class AuthoredCarouselBlockGenerator
{
    public const LOCAL_NAME = 'authored-carousel';

    /**
     * @param Closure(DOMElement): ?array<string, mixed> $convertImage
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier = new SourceElementClassifier(),
        private readonly ?StyleResolver $styleResolver = null,
        private readonly ?SourceBlockCreator $createBlock = null,
        private readonly ?BlockFactory $blockFactory = null,
        private readonly ?Runtime $runtime = null,
        private readonly ?HtmlTransformerSession $session = null,
        private readonly ?Closure $convertImage = null,
        private readonly ?Closure $convertChildren = null
    ) {
    }

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
            'thumbnails' => array('type' => 'array', 'default' => array()),
            'thumbnailPosition' => array('type' => 'string', 'default' => 'right'),
            'stageAspectRatio' => array('type' => 'string', 'default' => ''),
        );
        $editor = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var InnerBlocks = blockEditor.InnerBlocks;
    function normalizedItems( value ) { value = Math.round( Number( value ) || 4 ); return Math.min( 6, Math.max( 1, value ) ); }
    function normalizedCount( value ) { return Math.max( 0, Math.round( Number( value ) || 0 ) ); }
    function normalizedThumbnails( value ) { return Array.isArray( value ) ? value.filter( function( thumbnail ) { return thumbnail && thumbnail.url; } ) : []; }
    function normalizedPosition( value ) { return 'bottom' === value ? 'bottom' : 'right'; }
    function normalizedAspect( value ) { return 'string' === typeof value && /^[0-9]+(?:\.[0-9]+)?\/[0-9]+(?:\.[0-9]+)?$/.test( value ) ? value : ''; }
    function rootProps( attributes ) {
        var items = normalizedItems( attributes.itemsPerView );
        var thumbnails = normalizedThumbnails( attributes.thumbnails );
        var thumbnailModifier = thumbnails.length > 1 ? ' blocks-engine-authored-carousel--thumbnails blocks-engine-authored-carousel--thumbnails-' + normalizedPosition( attributes.thumbnailPosition ) : '';
        var presentation = 'slideshow' === attributes.presentation ? 'slideshow' : 'track';
        var initial = Math.min( Math.max( 0, Math.round( Number( attributes.initialSlide ) || 0 ) ), Math.max( 0, normalizedCount( attributes.slideCount ) - 1 ) );
        var aspect = attributes.viewportHeight > 0 ? '' : normalizedAspect( attributes.stageAspectRatio );
        var style = attributes.viewportHeight > 0 ? { '--blocks-engine-carousel-height': Math.round( attributes.viewportHeight ) + 'px', '--blocks-engine-carousel-transition': Math.max( 0, Math.round( Number( attributes.transitionDuration ) || 0 ) ) + 'ms' } : undefined;
        if ( aspect ) { style = { '--blocks-engine-carousel-stage-aspect': aspect, '--blocks-engine-carousel-transition': Math.max( 0, Math.round( Number( attributes.transitionDuration ) || 0 ) ) + 'ms' }; }
        return { className: 'blocks-engine-authored-carousel blocks-engine-authored-carousel--items-' + items + ' blocks-engine-authored-carousel--' + presentation + ( attributes.fullBleed ? ' blocks-engine-authored-carousel--full-bleed' : '' ) + thumbnailModifier + ( aspect ? ' blocks-engine-authored-carousel--stage-aspect' : '' ), style: style, role: 'region', 'aria-label': attributes.ariaLabel || 'Carousel', 'aria-roledescription': 'carousel', 'data-wrap': false === attributes.wrap ? 'false' : 'true', 'data-wp-interactive': 'blocks-engine/carousel', 'data-wp-context': JSON.stringify( { index: initial, wrap: false !== attributes.wrap, count: 0, visible: items, presentation: presentation, autoplayInterval: Math.max( 0, Math.round( Number( attributes.autoplayInterval ) || 0 ) ), paused: false } ), 'data-wp-init': 'callbacks.init', 'data-wp-on--mouseenter': 'actions.pause', 'data-wp-on--mouseleave': 'actions.resume', 'data-wp-on--focusin': 'actions.pause', 'data-wp-on--focusout': 'actions.resume' };
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__,
        supports: { html: false, customClassName: false },
        edit: function( props ) {
            return createElement( 'div', { className: 'blocks-engine-authored-carousel-editor' }, createElement( 'strong', null, props.attributes.ariaLabel || 'Carousel' ), createElement( InnerBlocks, { allowedBlocks: [ 'core/image', 'core/group' ], renderAppender: InnerBlocks.ButtonBlockAppender } ) );
        },
        save: function( props ) {
            var dotCount = props.attributes.showDots ? normalizedCount( props.attributes.slideCount ) : 0;
            var thumbnails = normalizedThumbnails( props.attributes.thumbnails );
            var dots = Array.from( { length: dotCount }, function( _, index ) { return createElement( 'button', { key: index, type: 'button', className: 'blocks-engine-authored-carousel__dot', 'aria-label': 'Show slide ' + ( index + 1 ), 'data-carousel-index': String( index ), 'data-wp-on--click': 'actions.goTo' } ); } );
            return createElement( 'div', rootProps( props.attributes ),
                createElement( 'button', { type: 'button', className: 'blocks-engine-authored-carousel__previous', 'data-carousel-previous': 'true', 'data-wp-on--click': 'actions.previous', 'data-wp-bind--disabled': 'state.atStart' }, 'Previous' ),
                createElement( 'div', { className: 'blocks-engine-authored-carousel__viewport', tabIndex: 0, 'data-wp-on--keydown': 'actions.keydown' }, createElement( 'div', { className: 'blocks-engine-authored-carousel__track' }, createElement( InnerBlocks.Content ) ) ),
                createElement( 'button', { type: 'button', className: 'blocks-engine-authored-carousel__next', 'data-carousel-next': 'true', 'data-wp-on--click': 'actions.next', 'data-wp-bind--disabled': 'state.atEnd' }, 'Next' ),
                dotCount > 0 ? createElement( 'div', { className: 'blocks-engine-authored-carousel__dots', role: 'group', 'aria-label': 'Choose slide' }, dots ) : null,
                thumbnails.length > 1 ? createElement( 'div', { className: 'blocks-engine-authored-carousel__thumbnails', role: 'group', 'aria-label': 'Choose slide' }, thumbnails.map( function( thumbnail, index ) {
                    return createElement( 'button', { key: index, type: 'button', className: 'blocks-engine-authored-carousel__thumbnail', 'aria-label': 'Show slide ' + ( index + 1 ), 'data-carousel-index': String( index ), 'data-wp-on--click': 'actions.goTo' }, createElement( 'img', { src: thumbnail.url, alt: thumbnail.alt || '', loading: 'lazy', decoding: 'async' } ) );
                } ) ) : null,
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
    [ 'dot', 'thumbnail' ].forEach( ( kind ) => {
        root.querySelectorAll( '.blocks-engine-authored-carousel__' + kind ).forEach( ( control, index ) => {
            const active = index === context.index;
            control.classList.toggle( 'blocks-engine-authored-carousel__' + kind + '--active', active );
            if ( active ) {
                control.setAttribute( 'aria-current', 'true' );
                if ( 'thumbnail' === kind && ! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
                    control.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
                }
            } else {
                control.removeAttribute( 'aria-current' );
            }
        } );
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
        $style .= '.blocks-engine-authored-carousel--full-bleed{width:100vw;max-width:none;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw)}.blocks-engine-authored-carousel--slideshow{position:relative;display:block;gap:0}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__viewport,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track{width:100%;height:var(--blocks-engine-carousel-height,auto)}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track{position:relative;display:block}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>*{position:absolute!important;inset:0!important;width:100%;opacity:0;visibility:hidden;transition:opacity var(--blocks-engine-carousel-transition,300ms) ease,visibility var(--blocks-engine-carousel-transition,300ms) ease}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>:first-child,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>.blocks-engine-authored-carousel__slide--active{position:relative!important;inset:auto!important;height:auto!important;opacity:1;visibility:visible;z-index:1}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track:has(>.blocks-engine-authored-carousel__slide--active)>:first-child:not(.blocks-engine-authored-carousel__slide--active){position:absolute!important;inset:0!important;height:auto!important;opacity:0;visibility:hidden;z-index:0}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>.wp-block-image img{width:100%;height:100%;aspect-ratio:auto;object-fit:cover}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__previous,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__next{position:absolute;top:50%;z-index:3;width:3rem;height:3rem;padding:0;border:0;border-radius:50%;background:rgba(0,0,0,.32);color:#fff;font-size:0;transform:translateY(-50%)}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__previous{left:1rem}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__next{right:1rem}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__previous::before,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__next::before{display:block;font-size:2rem;line-height:1;content:"\\2039"}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__next::before{content:"\\203a"}.blocks-engine-authored-carousel__dots{position:absolute;right:0;bottom:1.25rem;left:0;z-index:3;display:flex;justify-content:center;gap:.65rem}.blocks-engine-authored-carousel__dot{width:.75rem;height:.75rem;padding:0;border:1px solid currentColor;border-radius:50%;background:transparent;color:#fff;cursor:pointer}.blocks-engine-authored-carousel__dot--active{background:currentColor}@media(prefers-reduced-motion:reduce){.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>*{transition:none}}';
        $style .= '.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__viewport,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__previous,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__next,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__dot{pointer-events:auto}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>*{visibility:hidden!important}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>:first-child,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>.blocks-engine-authored-carousel__slide--active{visibility:visible!important}.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track:has(>.blocks-engine-authored-carousel__slide--active)>:first-child:not(.blocks-engine-authored-carousel__slide--active){visibility:hidden!important}';

        // The source sized its stage with a runtime script the artifact cannot
        // carry, but every slide declares a centered layer scaled to cover that
        // box. The recovered ratio keeps the stage a fixed frame that crops each
        // slide, and stays responsive instead of pinning the source pixel height.
        $style .= '.blocks-engine-authored-carousel--slideshow.blocks-engine-authored-carousel--stage-aspect .blocks-engine-authored-carousel__viewport{height:auto}'
            . '.blocks-engine-authored-carousel--slideshow.blocks-engine-authored-carousel--stage-aspect .blocks-engine-authored-carousel__track{height:auto;aspect-ratio:var(--blocks-engine-carousel-stage-aspect)}'
            . '.blocks-engine-authored-carousel--slideshow.blocks-engine-authored-carousel--stage-aspect .blocks-engine-authored-carousel__track>*{position:absolute!important;inset:0!important;height:auto!important}'
            . '.blocks-engine-authored-carousel--slideshow.blocks-engine-authored-carousel--stage-aspect .blocks-engine-authored-carousel__track>.wp-block-image{display:flex}'
            . '.blocks-engine-authored-carousel--slideshow.blocks-engine-authored-carousel--stage-aspect .blocks-engine-authored-carousel__track>.wp-block-image img{width:100%;height:100%;aspect-ratio:auto;object-fit:cover;object-position:center}';

        // A thumbnail pager is the source's own slide selector, so the rail is a
        // scrollable column beside the stage rather than a second slide track.
        $style .= '.blocks-engine-authored-carousel--thumbnails{--blocks-engine-carousel-thumbnail-size:80px}'
            . '.blocks-engine-authored-carousel--slideshow.blocks-engine-authored-carousel--thumbnails-right{display:grid;position:relative;grid-template-columns:minmax(0,1fr) var(--blocks-engine-carousel-thumbnail-size);gap:var(--blocks-engine-carousel-gap);align-items:start}'
            . '.blocks-engine-authored-carousel--slideshow.blocks-engine-authored-carousel--thumbnails-bottom{display:grid;position:relative;grid-template-rows:minmax(0,1fr) auto;gap:var(--blocks-engine-carousel-gap)}'
            . '.blocks-engine-authored-carousel--thumbnails .blocks-engine-authored-carousel__thumbnails{display:grid;gap:calc(var(--blocks-engine-carousel-gap)/2);min-width:0;margin:0;padding:0}'
            . '.blocks-engine-authored-carousel--thumbnails-right .blocks-engine-authored-carousel__thumbnails{grid-auto-flow:row;grid-auto-rows:max-content;max-height:var(--blocks-engine-carousel-height,100%);overflow-y:auto;overscroll-behavior:contain}'
            . '.blocks-engine-authored-carousel--thumbnails-bottom .blocks-engine-authored-carousel__thumbnails{grid-auto-flow:column;grid-auto-columns:var(--blocks-engine-carousel-thumbnail-size);overflow-x:auto;overscroll-behavior:contain}'
            . '.blocks-engine-authored-carousel__thumbnail{display:block;box-sizing:border-box;padding:0;border:0;background:none;cursor:pointer;opacity:.55;transition:opacity 150ms ease}'
            . '.blocks-engine-authored-carousel__thumbnail img{display:block;width:100%;height:100%;aspect-ratio:1;object-fit:cover}'
            . '.blocks-engine-authored-carousel__thumbnail:hover,.blocks-engine-authored-carousel__thumbnail:focus-visible,.blocks-engine-authored-carousel__thumbnail--active{opacity:1}'
            . '.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__thumbnails,.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__thumbnail{pointer-events:auto}'
            . '.blocks-engine-authored-carousel--thumbnails-right .blocks-engine-authored-carousel__next{right:calc(var(--blocks-engine-carousel-thumbnail-size) + var(--blocks-engine-carousel-gap))}'
            . '@media(max-width:600px){.blocks-engine-authored-carousel--slideshow.blocks-engine-authored-carousel--thumbnails-right{grid-template-columns:minmax(0,1fr)}.blocks-engine-authored-carousel--thumbnails-right .blocks-engine-authored-carousel__thumbnails{grid-auto-flow:column;grid-auto-columns:var(--blocks-engine-carousel-thumbnail-size);max-height:none;overflow-x:auto}.blocks-engine-authored-carousel--thumbnails-right .blocks-engine-authored-carousel__next{right:0}}'
            . '@media(prefers-reduced-motion:reduce){.blocks-engine-authored-carousel__thumbnail{transition:none}}';

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
        $thumbnails = $this->normalizedThumbnails($attributes['thumbnails'] ?? array());
        $thumbnailPosition = 'bottom' === ($attributes['thumbnailPosition'] ?? 'right') ? 'bottom' : 'right';
        $classes = 'blocks-engine-authored-carousel blocks-engine-authored-carousel--items-' . $items . ' blocks-engine-authored-carousel--' . $presentation . ($fullBleed ? ' blocks-engine-authored-carousel--full-bleed' : '')
            . (1 < count($thumbnails) ? ' blocks-engine-authored-carousel--thumbnails blocks-engine-authored-carousel--thumbnails-' . $thumbnailPosition : '');
        $stageAspect = 0 < $viewportHeight ? '' : $this->normalizedAspectRatio($attributes['stageAspectRatio'] ?? '');
        if ( '' !== $stageAspect ) {
            $classes .= ' blocks-engine-authored-carousel--stage-aspect';
        }
        $styleAttribute = '';
        if ( 0 < $viewportHeight ) {
            $styleAttribute = ' style="--blocks-engine-carousel-height:' . $viewportHeight . 'px;--blocks-engine-carousel-transition:' . $transitionDuration . 'ms"';
        } elseif ( '' !== $stageAspect ) {
            $styleAttribute = ' style="--blocks-engine-carousel-stage-aspect:' . $stageAspect . ';--blocks-engine-carousel-transition:' . $transitionDuration . 'ms"';
        }

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

        $rail = '';
        if ( 1 < count($thumbnails) ) {
            $rail = '<div class="blocks-engine-authored-carousel__thumbnails" role="group" aria-label="Choose slide">';
            foreach ( $thumbnails as $index => $thumbnail ) {
                $rail .= '<button type="button" class="blocks-engine-authored-carousel__thumbnail" aria-label="Show slide ' . ($index + 1) . '" data-carousel-index="' . $index . '" data-wp-on--click="actions.goTo">'
                    . '<img src="' . htmlspecialchars($thumbnail['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="' . htmlspecialchars($thumbnail['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" loading="lazy" decoding="async"></button>';
            }
            $rail .= '</div>';
        }

        return array(
            'opening' => '<div class="' . $classes . '"' . $styleAttribute . ' role="region" aria-label="' . $label . '" aria-roledescription="carousel" data-wrap="' . $wrap . '" data-wp-interactive="blocks-engine/carousel" data-wp-context="' . $context . '" data-wp-init="callbacks.init" data-wp-on--mouseenter="actions.pause" data-wp-on--mouseleave="actions.resume" data-wp-on--focusin="actions.pause" data-wp-on--focusout="actions.resume"><button type="button" class="blocks-engine-authored-carousel__previous" data-carousel-previous="true" data-wp-on--click="actions.previous" data-wp-bind--disabled="state.atStart">Previous</button><div class="blocks-engine-authored-carousel__viewport" tabindex="0" data-wp-on--keydown="actions.keydown"><div class="blocks-engine-authored-carousel__track">',
            'closing' => '</div></div><button type="button" class="blocks-engine-authored-carousel__next" data-carousel-next="true" data-wp-on--click="actions.next" data-wp-bind--disabled="state.atEnd">Next</button>' . $dots . $rail . '<span class="blocks-engine-authored-carousel__status" aria-live="polite" aria-atomic="true" data-wp-text="state.statusText"></span></div>',
        );
    }

    /** @return array<string, mixed>|null */
    public function convert(DOMElement $element): ?array
    {
        $styleResolver = $this->styleResolver ?? throw new LogicException('AuthoredCarouselBlockGenerator was not wired for conversion.');
        $createBlock = $this->createBlock ?? throw new LogicException('AuthoredCarouselBlockGenerator was not wired for conversion.');
        $blockFactory = $this->blockFactory ?? throw new LogicException('AuthoredCarouselBlockGenerator was not wired for conversion.');
        $runtime = $this->runtime ?? throw new LogicException('AuthoredCarouselBlockGenerator was not wired for conversion.');
        $session = $this->session ?? throw new LogicException('AuthoredCarouselBlockGenerator was not wired for conversion.');
        $convertImage = $this->convertImage ?? throw new LogicException('AuthoredCarouselBlockGenerator was not wired for conversion.');
        $convertChildren = $this->convertChildren ?? throw new LogicException('AuthoredCarouselBlockGenerator was not wired for conversion.');
        $registry = $session->generatedBlockRegistry()
            ?? throw new LogicException('Generated block registry has not been prepared for this transform.');

        if ( ! $this->sourceElementClassifier->hasCarouselIdentity($element) ) {
            return null;
        }

        $hasPrevious = false;
        $hasNext = false;
        foreach ( $element->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || ! in_array(strtolower($candidate->tagName), array('a', 'button'), true) ) {
                continue;
            }
            $metadataIdentity = strtolower((string) preg_replace(array('/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'), array('$1 $2', '$1 $2'), implode(' ', array(
                SourceDom::attr($candidate, 'aria-label'),
                SourceDom::attr($candidate, 'title'),
                SourceDom::attr($candidate, 'class'),
                SourceDom::attr($candidate, 'data-hook'),
                SourceDom::attr($candidate, 'data-testid'),
            ))));
            $text = strtolower(trim($candidate->textContent ?? ''));
            if ( 1 !== preg_match('/(?:^|[^a-z0-9])(?:slide|item|carousel|gallery|prev|previous|next|nav[^a-z0-9]*arrow|arrow[^a-z0-9]*nav)(?:[^a-z0-9]|$)/', $metadataIdentity)
                && 1 !== preg_match('/^(?:prev|previous|next)$/', $text)
            ) {
                continue;
            }
            $identity = $metadataIdentity . ' ' . $text;
            $hasPrevious = $hasPrevious || 1 === preg_match('/(?:^|[^a-z])(?:prev|previous)(?:[^a-z]|$)/', $identity);
            $hasNext = $hasNext || 1 === preg_match('/(?:^|[^a-z])next(?:[^a-z]|$)/', $identity);
        }
        [$list, $items] = $this->richestCarouselList($element);
        $localList = $list;
        if ( count($items) < 2 ) {
            foreach ( $element->ownerDocument?->getElementsByTagName('*') ?? array() as $counterpart ) {
                if ( ! $counterpart instanceof DOMElement || $counterpart === $element || ! $this->sharesCarouselIdentity($element, $counterpart) ) {
                    continue;
                }
                [$candidateList, $candidateItems] = $this->richestCarouselList($counterpart);
                if ( count($candidateItems) > count($items) ) {
                    $list = $candidateList;
                    $items = $candidateItems;
                }
            }
        }
        $paginationCount = $this->carouselPaginationCount($element);
        // An image-only control cluster beside the slides is the source's own
        // slide selector, so it is pagination even when the builder ships no
        // labelled previous/next control.
        $pagerItems = $list instanceof DOMElement ? $this->thumbnailPagerItems($element, $list) : array();
        if ( (! $hasPrevious && ! $hasNext && $paginationCount < 2 && count($pagerItems) < 2) || ! $list instanceof DOMElement || count($items) < 2 ) {
            return null;
        }

        $slides = array();
        foreach ( $items as $sourceItem ) {
            [$item, $temporary] = $this->carouselItemInRoot($sourceItem, $element, $localList);
            $image = $item->getElementsByTagName('img')->item(0);
            if ( $image instanceof DOMElement ) {
                $slide = $convertImage($image);
                if ( null === $slide || 'core/image' !== ($slide['blockName'] ?? null) ) {
                    if ( $temporary ) {
                        $item->parentNode?->removeChild($item);
                    }
                    return null;
                }
                $caption = $this->carouselItemCaption($item, $runtime);
                if ( '' !== $caption ) {
                    $slide['attrs']['caption'] = $caption;
                    $slide = $blockFactory->create('core/image', $slide['attrs'], array());
                }
            } else {
                $slideFallbacks = array();
                $children = $convertChildren($item, $slideFallbacks);
                if ( array() === $children || array() !== $slideFallbacks ) {
                    if ( $temporary ) {
                        $item->parentNode?->removeChild($item);
                    }
                    return null;
                }
                $slide = $createBlock->createBlock('core/group', $styleResolver->presentationAttributes($item), $children, $item);
            }
            $slides[] = $slide;
            if ( $temporary ) {
                $item->parentNode?->removeChild($item);
            }
        }

        $listIdentity = strtolower(implode(' ', array($list->tagName, SourceDom::attr($list, 'class'), SourceDom::attr($list, 'role'), SourceDom::attr($list, 'data-hook'))));
        $rootIdentity = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', implode(' ', array($element->tagName, SourceDom::attr($element, 'id'), SourceDom::attr($element, 'class'), SourceDom::attr($element, 'data-testid')))));
        $isTrackList = 1 === preg_match('/(?:^|[^a-z0-9])(?:track|rail|scroll(?:er)?)(?:[^a-z0-9]|$)/', $listIdentity);
        $presentation = 1 === preg_match('/(?:^|[^a-z0-9])slideshow(?:[^a-z0-9]|$)/', $listIdentity . ' ' . $rootIdentity) ? 'slideshow' : 'track';
        // Selecting a slide from a thumbnail means one slide is on stage.
        if ( 1 < count($pagerItems) ) {
            $presentation = 'slideshow';
        }
        $initialSlide = 0;
        foreach ( $items as $index => $item ) {
            if ( '' !== SourceDom::attr($item, 'data-slideshow-slide') || (! $isTrackList && '' !== SourceDom::attr($item, 'aria-hidden')) ) {
                $presentation = 'slideshow';
            }
            if ( ('slideshow' === $presentation && 'false' === strtolower(trim(SourceDom::attr($item, 'aria-hidden')))) || str_contains(' ' . strtolower(SourceDom::attr($item, 'class')) . ' ', ' active ') ) {
                $initialSlide = $index;
            }
        }

        $showDots = $paginationCount >= 2;
        foreach ( $element->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement ) {
                continue;
            }
            foreach ( array('data-slide', 'data-slide-index', 'data-carousel-index', 'data-slideshow-item', 'data-uk-slideshow-item') as $attribute ) {
                if ( ctype_digit(trim(SourceDom::attr($candidate, $attribute))) ) {
                    $showDots = true;
                    break 2;
                }
            }
        }

        $durationMilliseconds = static function (string $value): int {
            if ( 1 !== preg_match('/^([0-9]+(?:\.[0-9]+)?)(ms|s)$/', strtolower(trim($value)), $matches) ) {
                return 0;
            }
            $milliseconds = (float) $matches[1] * ('s' === $matches[2] ? 1000 : 1);
            return (int) round($milliseconds);
        };
        $transitionDuration = 0;
        $autoplayInterval = 0;
        foreach ( $items as $item ) {
            $transitionDuration = max($transitionDuration, $durationMilliseconds((string) ($styleResolver->cssDeclarations(SourceDom::attr($item, 'style'))['animation-duration'] ?? '')));
            foreach ( $item->getElementsByTagName('*') as $descendant ) {
                if ( ! $descendant instanceof DOMElement ) {
                    continue;
                }
                $autoplayInterval = max($autoplayInterval, $durationMilliseconds((string) ($styleResolver->cssDeclarations(SourceDom::attr($descendant, 'style'))['animation-duration'] ?? '')));
            }
        }
        if ( $autoplayInterval <= $transitionDuration ) {
            $autoplayInterval = 0;
        }
        if ( 0 === $transitionDuration ) {
            $transitionDuration = 300;
        }

        $geometryList = $localList instanceof DOMElement ? $localList : $list;
        $listHeight = (string) ($styleResolver->structuralPresentationDeclarations($geometryList)['height'] ?? '');
        $rootHeight = (string) ($styleResolver->structuralPresentationDeclarations($element)['height'] ?? '');
        $height = 1 === preg_match('/^[0-9]+(?:\.[0-9]+)?px$/', trim($listHeight)) ? $listHeight : $rootHeight;
        $viewportHeight = 1 === preg_match('/^([0-9]+(?:\.[0-9]+)?)px$/', trim($height), $heightMatch) ? (int) round((float) $heightMatch[1]) : 0;
        $rootDeclarations = $styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));
        $rootWidth = strtolower((string) preg_replace('/\s+/', '', (string) ($rootDeclarations['width'] ?? '')));
        $fullBleed = ('100vw' === $rootWidth || 1 === preg_match('/^[0-9]+(?:\.[0-9]+)?px$/', $rootWidth))
            && 1 === preg_match('/^-\s*(?:[0-9]+|[0-9]*\.[0-9]+)(?:px|rem|em|%)$/', strtolower(trim((string) ($rootDeclarations['left'] ?? ''))));

        // Each thumbnail selects the slide at its own index, so a pager that
        // does not line up with the captured slides is not a usable selector.
        $thumbnails = array();
        foreach ( array_slice($pagerItems, 0, count($slides)) as $pagerItem ) {
            $thumbnailImage = $pagerItem->getElementsByTagName('img')->item(0);
            $url = $thumbnailImage instanceof DOMElement ? trim(SourceDom::attr($thumbnailImage, 'src')) : '';
            if ( '' === $url ) {
                continue;
            }
            $thumbnails[] = array('url' => $url, 'alt' => trim(SourceDom::attr($thumbnailImage, 'alt')));
        }
        if ( count($thumbnails) < 2 || count($thumbnails) !== count($slides) ) {
            $thumbnails = array();
        }
        $thumbnailPosition = array() === $thumbnails
            ? 'bottom'
            : $this->thumbnailPagerPosition($this->commonAncestor($pagerItems), $element, $styleResolver);

        $registry->register(self::class, $this->definition($registry->namespace()));
        $attributes = array(
            'ariaLabel' => trim(SourceDom::attr($element, 'aria-label')) ?: 'Carousel',
            'itemsPerView' => 'slideshow' === $presentation ? 1 : min(4, count($slides)),
            'wrap' => $hasPrevious && $hasNext,
            'presentation' => $presentation,
            'slideCount' => count($slides),
            'initialSlide' => $initialSlide,
            'viewportHeight' => 'slideshow' === $presentation ? $viewportHeight : 0,
            'transitionDuration' => 'slideshow' === $presentation ? $transitionDuration : 300,
            'autoplayInterval' => 'slideshow' === $presentation ? $autoplayInterval : 0,
            'showDots' => 'slideshow' === $presentation && $showDots && array() === $thumbnails,
            'fullBleed' => 'slideshow' === $presentation && $fullBleed,
            'thumbnails' => $thumbnails,
            'thumbnailPosition' => $thumbnailPosition,
            'stageAspectRatio' => 'slideshow' === $presentation && 0 === $viewportHeight ? $this->stageAspectRatioForItems($items, $styleResolver) : '',
        );
        $shell = $this->shell($attributes);
        $innerContent = array($shell['opening']);
        foreach ( $slides as $_ ) {
            $innerContent[] = null;
        }
        $innerContent[] = $shell['closing'];

        return array(
            'blockName' => $registry->blockName(self::LOCAL_NAME),
            'attrs' => $attributes,
            'innerBlocks' => $slides,
            'innerHTML' => $shell['opening'] . $shell['closing'],
            'innerContent' => $innerContent,
        );
    }

    /**
     * The stage box a slideshow clipped its slides into, recovered from the
     * slides themselves.
     *
     * A builder that crops with overflow rather than `object-fit` scales each
     * slide's layer to cover a shared box and centres it by pulling the layer
     * back half its own size. Every slide therefore reports a layer at least as
     * large as the box on both axes, and exactly equal to it on the axis that
     * constrained the cover fit, so the smallest width and the smallest height
     * across those layers reconstruct the box.
     *
     * @param array<int, DOMElement> $items
     */
    private function stageAspectRatioForItems(array $items, StyleResolver $styleResolver): string
    {
        $width = null;
        $height = null;
        foreach ( $items as $item ) {
            $layer = $this->centeredCoverLayer($item, $styleResolver);
            if ( null === $layer ) {
                continue;
            }
            $width = null === $width ? $layer['width'] : min($width, $layer['width']);
            $height = null === $height ? $layer['height'] : min($height, $layer['height']);
        }
        if ( null === $width || null === $height || 0.0 >= $width || 0.0 >= $height ) {
            return '';
        }

        return $this->normalizedAspectRatio(
            rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.') . '/' . rtrim(rtrim(number_format($height, 2, '.', ''), '0'), '.')
        );
    }

    /** @return array{width: float, height: float}|null */
    private function centeredCoverLayer(DOMElement $item, StyleResolver $styleResolver): ?array
    {
        $candidates = array($item);
        foreach ( $item->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement ) {
                $candidates[] = $descendant;
            }
        }
        foreach ( $candidates as $candidate ) {
            $declarations = $styleResolver->cssDeclarations(SourceDom::attr($candidate, 'style'));
            $width = $this->pixelLength((string) ($declarations['width'] ?? ''));
            $left = $this->pixelLength((string) ($declarations['left'] ?? ''));
            $top = $this->pixelLength((string) ($declarations['top'] ?? ''));
            if ( null === $width || null === $left || null === $top || 0.0 >= $width || 0.0 <= $left || 0.0 <= $top ) {
                continue;
            }
            // The layer is pulled back half its own width, which is what centres
            // it on the box; the same offset on the block axis reports the
            // rendered layer height the box clipped.
            if ( 1.0 < abs(abs($left) - $width / 2) ) {
                continue;
            }

            return array('width' => $width, 'height' => abs($top) * 2);
        }

        return null;
    }

    private function pixelLength(string $value): ?float
    {
        return 1 === preg_match('/^(-?[0-9]+(?:\.[0-9]+)?)px$/', strtolower(trim($value)), $matches)
            ? (float) $matches[1]
            : null;
    }

    private function normalizedAspectRatio(mixed $value): string
    {
        return is_string($value) && 1 === preg_match('/^[0-9]+(?:\.[0-9]+)?\/[0-9]+(?:\.[0-9]+)?$/', trim($value))
            ? trim($value)
            : '';
    }

    /**
     * Controls that carry only an image and no label are thumbnail pagination:
     * the source renders one slide on stage and lets a visitor pick the next
     * one from its own picture. Detection is structural, so any builder's
     * image-only selector is recognized without naming its classes.
     *
     * @return array<int, DOMElement>
     */
    private function thumbnailPagerItems(DOMElement $root, DOMElement $stageList): array
    {
        $items = array();
        foreach ( $root->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement
                || ! in_array(strtolower($candidate->tagName), array('a', 'button'), true)
                || SourceDom::elementContains($stageList, $candidate)
                || 1 !== $candidate->getElementsByTagName('img')->length
                || '' !== trim(str_replace("\xc2\xa0", ' ', $candidate->textContent ?? ''))
            ) {
                continue;
            }
            $image = $candidate->getElementsByTagName('img')->item(0);
            if ( $image instanceof DOMElement && '' !== trim(SourceDom::attr($image, 'src')) ) {
                $items[] = $candidate;
            }
        }

        return $items;
    }

    /**
     * A pager box narrow enough to sit beside the stage is a side rail; a pager
     * that spans the carousel is a strip underneath it. The declared width can
     * live on any wrapper between the controls and the carousel root, so the
     * search walks that bounded chain.
     */
    private function thumbnailPagerPosition(?DOMElement $pagerRoot, DOMElement $carouselRoot, StyleResolver $styleResolver): string
    {
        for ( $ancestor = $pagerRoot; $ancestor instanceof DOMElement && $ancestor !== $carouselRoot; $ancestor = $ancestor->parentNode ) {
            $width = trim((string) ($styleResolver->structuralPresentationDeclarations($ancestor)['width'] ?? ''));
            if ( 1 === preg_match('/^([0-9]+(?:\.[0-9]+)?)px$/', $width, $matches) ) {
                return 260.0 >= (float) $matches[1] ? 'right' : 'bottom';
            }
        }

        return 'bottom';
    }

    /** @param array<int, DOMElement> $elements */
    private function commonAncestor(array $elements): ?DOMElement
    {
        $first = $elements[0] ?? null;
        if ( ! $first instanceof DOMElement ) {
            return null;
        }
        for ( $ancestor = $first->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            foreach ( $elements as $element ) {
                if ( ! SourceDom::elementContains($ancestor, $element) ) {
                    continue 2;
                }
            }
            return $ancestor;
        }

        return null;
    }

    /** @return array<int, array{url: string, alt: string}> */
    private function normalizedThumbnails(mixed $value): array
    {
        if ( ! is_array($value) ) {
            return array();
        }
        $thumbnails = array();
        foreach ( $value as $thumbnail ) {
            $url = is_array($thumbnail) ? trim((string) ($thumbnail['url'] ?? '')) : '';
            if ( '' === $url ) {
                continue;
            }
            $thumbnails[] = array('url' => $url, 'alt' => is_array($thumbnail) ? trim((string) ($thumbnail['alt'] ?? '')) : '');
        }

        return $thumbnails;
    }

    /** @return array{0: DOMElement|null, 1: array<int, DOMElement>} */
    private function richestCarouselList(DOMElement $root): array
    {
        $list = null;
        $items = array();
        foreach ( $root->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || ! $this->sourceElementClassifier->isCarouselList($candidate) || $this->sourceElementClassifier->isExpandedCarouselState($candidate, $root) ) {
                continue;
            }
            $candidateItems = $this->carouselListItems($candidate);
            if ( count($candidateItems) > count($items) && $this->carouselItemsHaveContent($candidateItems) ) {
                $list = $candidate;
                $items = $candidateItems;
            }
        }
        return array($list, $items);
    }

    /** @param array<int, DOMElement> $items */
    private function carouselItemsHaveContent(array $items): bool
    {
        foreach ( $items as $item ) {
            if ( 0 === $item->getElementsByTagName('img')->length
                && '' === trim(str_replace("\xc2\xa0", ' ', $item->textContent ?? ''))
            ) {
                return false;
            }
        }
        return true;
    }

    private function carouselPaginationCount(DOMElement $root): int
    {
        $count = 0;
        foreach ( $root->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || ! in_array(strtolower($candidate->tagName), array('a', 'button'), true) ) {
                continue;
            }
            $identity = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', implode(' ', array(
                SourceDom::attr($candidate, 'aria-label'),
                SourceDom::attr($candidate, 'class'),
                SourceDom::attr($candidate, 'data-testid'),
            ))));
            $indexed = false;
            foreach ( array('data-slide', 'data-slide-index', 'data-carousel-index', 'data-slideshow-item', 'data-uk-slideshow-item') as $attribute ) {
                $indexed = $indexed || ctype_digit(trim(SourceDom::attr($candidate, $attribute)));
            }
            if ( $indexed || 1 === preg_match('/(?:^|[^a-z0-9])(?:slide|item)[^a-z0-9]*[0-9]+(?:[^a-z0-9]|$)/', $identity) ) {
                ++$count;
            }
        }
        return $count;
    }

    /** @return array{0: DOMElement, 1: bool} */
    private function carouselItemInRoot(DOMElement $item, DOMElement $root, ?DOMElement $localList): array
    {
        for ( $ancestor = $item; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            if ( $ancestor === $root ) {
                return array($item, false);
            }
        }
        if ( $localList instanceof DOMElement ) {
            $itemId = trim(SourceDom::attr($item, 'id'));
            if ( '' !== $itemId ) {
                foreach ( $this->carouselListItems($localList) as $localItem ) {
                    if ( $itemId === trim(SourceDom::attr($localItem, 'id')) ) {
                        return array($localItem, false);
                    }
                }
            }
        }

        $clone = $item->cloneNode(true);
        if ( ! $clone instanceof DOMElement ) {
            return array($item, false);
        }
        ($localList ?? $root)->appendChild($clone);
        return array($clone, true);
    }

    private function sharesCarouselIdentity(DOMElement $left, DOMElement $right): bool
    {
        if ( ! $this->sourceElementClassifier->hasCarouselIdentity($right) ) {
            return false;
        }
        $leftId = trim(SourceDom::attr($left, 'id'));
        if ( '' !== $leftId && $leftId === trim(SourceDom::attr($right, 'id')) ) {
            return true;
        }

        $identityClasses = static function (string $classes): array {
            $matches = array();
            foreach ( preg_split('/\s+/', trim($classes)) ?: array() as $class ) {
                $words = strtolower((string) preg_replace(array('/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'), array('$1 $2', '$1 $2'), $class));
                if ( 1 === preg_match('/(?:^|[^a-z0-9])(?:carousel|gallery|slider|slideshow)(?:[^a-z0-9]|$)/', $words) ) {
                    $matches[] = $class;
                }
            }
            return $matches;
        };
        return array() !== array_intersect($identityClasses(SourceDom::attr($left, 'class')), $identityClasses(SourceDom::attr($right, 'class')));
    }

    /** @return array<int, DOMElement> */
    private function carouselListItems(DOMElement $list): array
    {
        $items = array();
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( 'listitem' === strtolower(trim(SourceDom::attr($child, 'role'))) || 'li' === strtolower($child->tagName) ) {
                $items[] = $child;
                continue;
            }

            $identity = strtolower(implode(' ', array(
                $child->tagName,
                SourceDom::attr($child, 'class'),
                SourceDom::attr($child, 'data-hook'),
                SourceDom::attr($child, 'data-testid'),
            )));
            if ( 1 === preg_match('/(?:^|[^a-z0-9])(?:slide|item|group)(?:[^a-z0-9]|$)/', $identity)
                && 0 < $child->getElementsByTagName('img')->length
            ) {
                $items[] = $child;
                continue;
            }
            if ( '' !== trim(str_replace("\xc2\xa0", ' ', $child->textContent ?? ''))
                && ! in_array(strtolower($child->tagName), array('a', 'button', 'nav'), true)
            ) {
                $items[] = $child;
            }
        }

        return $items;
    }

    private function carouselItemCaption(DOMElement $item, Runtime $runtime): string
    {
        $title = '';
        $description = '';
        foreach ( $item->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement ) {
                continue;
            }
            $identity = strtolower(SourceDom::attr($candidate, 'class') . ' ' . SourceDom::attr($candidate, 'data-hook'));
            if ( '' === $title && 1 === preg_match('/(?:^|[^a-z0-9])title(?:[^a-z0-9]|$)/', $identity) ) {
                $title = trim($candidate->textContent ?? '');
            }
            if ( '' === $description && 1 === preg_match('/(?:^|[^a-z0-9])description(?:[^a-z0-9]|$)/', $identity) ) {
                $description = trim($candidate->textContent ?? '');
            }
        }
        if ( '' === $title ) {
            $title = trim(SourceDom::attr($item, 'aria-label'));
        }
        if ( '' === $title && '' === $description ) {
            $description = trim($item->textContent ?? '');
        }

        $title = '' === $title ? '' : '<strong>' . $runtime->escapeHtml($title) . '</strong>';
        $description = $runtime->escapeHtml($description);
        return trim($title . ('' !== $title && '' !== $description ? '<br>' : '') . $description);
    }
}
