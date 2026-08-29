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
        $view = <<<'JS'
( function() {
    function mount( root ) {
        if ( root.dataset.carouselMounted ) return;
        var viewport = root.querySelector( '.blocks-engine-authored-carousel__viewport' );
        var track = root.querySelector( '.blocks-engine-authored-carousel__track' );
        var previous = root.querySelector( '[data-carousel-previous]' );
        var next = root.querySelector( '[data-carousel-next]' );
        var status = root.querySelector( '.blocks-engine-authored-carousel__status' );
        if ( ! viewport || ! track || ! previous || ! next ) return;
        var slides = Array.prototype.slice.call( track.children );
        if ( slides.length < 2 ) return;
        var index = 0;
        var wraps = 'false' !== root.dataset.wrap;
        var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
        function visibleItems() {
            var width = slides[ 0 ].getBoundingClientRect().width;
            return width > 0 ? Math.max( 1, Math.min( slides.length, Math.round( viewport.clientWidth / width ) ) ) : 1;
        }
        function maximumIndex() { return Math.max( 0, slides.length - visibleItems() ); }
        function update() {
            var maximum = maximumIndex();
            index = Math.min( index, maximum );
            previous.disabled = 0 === maximum || ( ! wraps && 0 === index );
            next.disabled = 0 === maximum || ( ! wraps && index === maximum );
            if ( status ) status.textContent = 'Slide ' + ( index + 1 ) + ' of ' + slides.length;
        }
        function show( requested ) {
            var maximum = maximumIndex();
            index = wraps ? ( requested < 0 ? maximum : requested > maximum ? 0 : requested ) : Math.max( 0, Math.min( maximum, requested ) );
            viewport.scrollTo( { left: slides[ index ].offsetLeft, behavior: reducedMotion ? 'auto' : 'smooth' } );
            update();
        }
        previous.addEventListener( 'click', function() { show( index - 1 ); } );
        next.addEventListener( 'click', function() { show( index + 1 ); } );
        viewport.addEventListener( 'keydown', function( event ) { if ( 'ArrowLeft' === event.key || 'ArrowRight' === event.key ) { event.preventDefault(); show( index + ( 'ArrowLeft' === event.key ? -1 : 1 ) ); } } );
        if ( window.ResizeObserver ) new ResizeObserver( update ).observe( viewport );
        root.dataset.carouselMounted = 'true';
        update();
    }
    function mountAll() { document.querySelectorAll( '.blocks-engine-authored-carousel' ).forEach( mount ); }
    if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', mountAll ); else mountAll();
} )();
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
                'viewScript' => 'file:./view.js',
                'style' => 'file:./style.css',
                'attributes' => $attributes,
                'supports' => array('html' => false, 'customClassName' => false),
            ),
            'assets' => array(
                'index.js' => str_replace(array('__BLOCK_NAME__', '__ATTRIBUTES__'), array($blockName, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $editor),
                'style.css' => $style,
            ),
            'view_js' => $view,
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')),
        );
    }

    /** @param array<string, mixed> $attributes @return array{opening: string, closing: string} */
    public function shell(array $attributes): array
    {
        $label = htmlspecialchars((string) ($attributes['ariaLabel'] ?? 'Carousel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $items = min(6, max(1, (int) ($attributes['itemsPerView'] ?? 4)));
        $wrap = false === ($attributes['wrap'] ?? true) ? 'false' : 'true';

        return array(
            'opening' => '<div class="blocks-engine-authored-carousel blocks-engine-authored-carousel--items-' . $items . '" role="region" aria-label="' . $label . '" aria-roledescription="carousel" data-wrap="' . $wrap . '"><button type="button" class="blocks-engine-authored-carousel__previous" data-carousel-previous="true">Previous</button><div class="blocks-engine-authored-carousel__viewport" tabindex="0"><div class="blocks-engine-authored-carousel__track">',
            'closing' => '</div></div><button type="button" class="blocks-engine-authored-carousel__next" data-carousel-next="true">Next</button><span class="blocks-engine-authored-carousel__status" aria-live="polite" aria-atomic="true"></span></div>',
        );
    }
}
