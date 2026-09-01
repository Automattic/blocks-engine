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
        );
        $editor = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var InnerBlocks = blockEditor.InnerBlocks;
    function normalizedItems( value ) { value = Math.round( Number( value ) || 4 ); return Math.min( 6, Math.max( 1, value ) ); }
    function rootProps( attributes ) {
        var items = normalizedItems( attributes.itemsPerView );
        return { className: 'blocks-engine-authored-carousel blocks-engine-authored-carousel--items-' + items, role: 'region', 'aria-label': attributes.ariaLabel || 'Carousel', 'aria-roledescription': 'carousel', 'data-wrap': false === attributes.wrap ? 'false' : 'true' };
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__,
        supports: { html: false, customClassName: false },
        edit: function( props ) {
            return createElement( 'div', { className: 'blocks-engine-authored-carousel-editor' }, createElement( 'strong', null, props.attributes.ariaLabel || 'Carousel' ), createElement( InnerBlocks, { allowedBlocks: [ 'core/image', 'core/group' ], renderAppender: InnerBlocks.ButtonBlockAppender } ) );
        },
        save: function( props ) {
            return createElement( 'div', rootProps( props.attributes ),
                createElement( 'button', { type: 'button', className: 'blocks-engine-authored-carousel__previous', 'data-carousel-previous': 'true' }, 'Previous' ),
                createElement( 'div', { className: 'blocks-engine-authored-carousel__viewport', tabIndex: 0 }, createElement( 'div', { className: 'blocks-engine-authored-carousel__track' }, createElement( InnerBlocks.Content ) ) ),
                createElement( 'button', { type: 'button', className: 'blocks-engine-authored-carousel__next', 'data-carousel-next': 'true' }, 'Next' ),
                createElement( 'span', { className: 'blocks-engine-authored-carousel__status', 'aria-live': 'polite', 'aria-atomic': 'true' } )
            );
        }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        // The Interactivity API is WordPress's own front-end runtime for blocks,
        // so the behavior is declared on the markup and the module carries only
        // the state the directives read.
        $view = <<<'JS'
import { store, getContext, getElement } from '@wordpress/interactivity';

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
            context.visible = visibleCount( ref );
        },
    },
    actions: {
        previous() {
            show( getContext().index - 1 );
        },
        next() {
            show( getContext().index + 1 );
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

        $context = htmlspecialchars(
            (string) json_encode(array('index' => 0, 'wrap' => 'true' === $wrap, 'count' => 0, 'visible' => $items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        return array(
            'opening' => '<div class="blocks-engine-authored-carousel blocks-engine-authored-carousel--items-' . $items . '" role="region" aria-label="' . $label . '" aria-roledescription="carousel" data-wrap="' . $wrap . '" data-wp-interactive="blocks-engine/carousel" data-wp-context="' . $context . '" data-wp-init="callbacks.init"><button type="button" class="blocks-engine-authored-carousel__previous" data-carousel-previous="true" data-wp-on--click="actions.previous" data-wp-bind--disabled="state.atStart">Previous</button><div class="blocks-engine-authored-carousel__viewport" tabindex="0" data-wp-on--keydown="actions.keydown"><div class="blocks-engine-authored-carousel__track">',
            'closing' => '</div></div><button type="button" class="blocks-engine-authored-carousel__next" data-carousel-next="true" data-wp-on--click="actions.next" data-wp-bind--disabled="state.atEnd">Next</button><span class="blocks-engine-authored-carousel__status" aria-live="polite" aria-atomic="true" data-wp-text="state.statusText"></span></div>',
        );
    }
}
