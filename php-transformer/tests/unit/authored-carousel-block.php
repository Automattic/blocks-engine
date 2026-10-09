<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$source = '<div class="service-carousel"><button aria-label="Previous slide">Previous</button><div role="list"><div role="listitem" aria-label="First"><img src="one.jpg" alt="First"><div class="title">First</div><div class="description">First description.</div></div><div role="listitem" aria-label="Second"><img src="two.jpg" alt="Second"><div class="title">Second</div><div class="description">Second description.</div></div></div><button aria-label="Next slide">Next</button><div class="expanded-gallery"><div role="list"><div role="listitem"><img src="expanded-one.jpg"></div><div role="listitem"><img src="expanded-two.jpg"></div></div></div></div>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$block = $result['blocks'][0] ?? array();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert('custom/authored-carousel' === ($block['blockName'] ?? null), 'bounded previous/list/next topology uses the generic carousel companion');
$assert(2 === count($block['innerBlocks'] ?? array()) && 'core/image' === ($block['innerBlocks'][0]['blockName'] ?? null), 'carousel slides remain ordinary editable inner image blocks');
$assert(str_contains($markup, 'First description.') && !str_contains($markup, 'expanded-one.jpg'), 'the primary rail keeps captions and excludes expanded-state duplicates');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'carousel serialization is editor-valid');
$serialized = (new Runtime())->serializeBlocks(array($block));
$assert('custom/authored-carousel' === ((new Runtime())->parseBlocks($serialized)[0]['blockName'] ?? null), 'the carousel and its inner blocks persist through parse and serialize');

$portableGallerySource = '<script data-dla-gallery-runtime="">document.querySelectorAll("[data-dla-gallery]");</script>'
    . '<div class="neutral-gallery" id="neutral-gallery" data-dla-gallery-capture-width="1440" data-dla-gallery="" data-dla-gallery-source="#neutral-gallery" data-dla-gallery-sequence="[&quot;/media/photo-a.jpeg&quot;,&quot;/media/photo-b.jpeg&quot;,&quot;/media/photo-c.jpeg&quot;,&quot;/media/photo-d.jpeg&quot;,&quot;/media/photo-e.jpeg&quot;]" data-dla-gallery-index="0">'
    . '<!-- leading slot --><div class="gallery-side gallery-side-left" id="left-slot" aria-hidden="true"><img id="left-image" alt="" src="/media/photo-e.jpeg" data-dla-gallery-slot="-1"></div>'
    . '<!-- previous control --><button class="gallery-arrow gallery-arrow-left" id="previous-control" type="button" aria-label="Previous image" data-dla-gallery-direction="-1"><svg viewBox="0 0 10 10" aria-hidden="true"><path d="M8 2 3 5l5 3"></path></svg></button>'
    . '<!-- primary slot --><figure class="gallery-main" id="main-slot"><img id="main-image" alt="Gallery photo" src="/media/photo-a.jpeg" data-dla-gallery-slot="0"></figure>'
    . '<!-- next control --><button class="gallery-arrow gallery-arrow-right" id="next-control" type="button" aria-label="Next image" data-dla-gallery-direction="1"><svg viewBox="0 0 10 10" aria-hidden="true"><path d="m2 2 5 3-5 3"></path></svg></button>'
    . '<!-- trailing slot --><div class="gallery-side gallery-side-right" id="right-slot" aria-hidden="true"><img id="right-image" alt="" src="/media/photo-b.jpeg" data-dla-gallery-slot="1"></div></div>';
$portableGalleryResult = (new HtmlTransformer())->transform($portableGallerySource)->toArray();
$portableGallery = $portableGalleryResult['blocks'][0] ?? array();
$portableGalleryMarkup = (string) ($portableGalleryResult['serialized_blocks'] ?? '');
$portableImageUrls = array_map(static fn(array $slide): string => (string) ($slide['attrs']['url'] ?? ''), $portableGallery['innerBlocks'] ?? array());
$portableBlockNames = array_column($portableGalleryResult['blocks'] ?? array(), 'blockName');
$assert(
    'custom/authored-carousel' === ($portableGallery['blockName'] ?? null)
        && 5 === count($portableGallery['innerBlocks'] ?? array())
        && array('/media/photo-a.jpeg', '/media/photo-b.jpeg', '/media/photo-c.jpeg', '/media/photo-d.jpeg', '/media/photo-e.jpeg') === $portableImageUrls,
    'portable src-swap gallery slots project to all editable image slides in sequence order'
);
$assert(
    array_reduce($portableGallery['innerBlocks'] ?? array(), static fn(bool $valid, array $slide): bool => $valid && 'core/image' === ($slide['blockName'] ?? null) && str_starts_with((string) ($slide['attrs']['url'] ?? ''), '/media/'), true)
        && array_reduce($portableGallery['innerBlocks'] ?? array(), static fn(bool $valid, array $slide): bool => $valid && !isset($slide['attrs']['anchor']), true)
        && str_contains($portableGalleryMarkup, 'actions.previous') && str_contains($portableGalleryMarkup, 'actions.next')
        && !str_contains($portableGalleryMarkup, 'core/html') && !str_contains($portableGalleryMarkup, 'wp-block-freeform')
        && !in_array('core/html', $portableBlockNames, true) && !in_array('core/freeform', $portableBlockNames, true)
        && str_contains($portableGalleryMarkup, 'data-dla-gallery=""'),
    'portable gallery keeps Media Library image attachments and functional carousel controls without fallback blocks'
);

$scopedPresentation = (new HtmlTransformer())->transform(
    '<style>@supports (--test-custom-property:true){.review-frame[data-section-id="review-42"]{--title-font-size-value:1.6}}'
        . '.review-frame[data-section-id="review-42"] .quote{font-size:calc((var(--title-font-size-value) - 1) * 1.2vw + 1rem);text-align:center}'
        . '.runtime-slideshow .slide{transform:translateX(-9999px)}</style>'
        . '<div class="review-frame runtime-slideshow" data-section-id="review-42"><button aria-label="Previous slide">Previous</button>'
        . '<ul class="slides"><li class="slide"><h2 class="quote">First review</h2></li>'
        . '<li class="slide" aria-hidden="true"><h2 class="quote">Second review</h2></li></ul>'
        . '<button aria-label="Next slide">Next</button></div>'
)->toArray();
$scopedCarousel = $scopedPresentation['blocks'][0] ?? array();
$scopedMarkup = (string) ($scopedPresentation['serialized_blocks'] ?? '');
$scopedRoot = preg_match('/<div class="blocks-engine-authored-carousel[^>]+>/', $scopedMarkup, $scopedRootMatch) ? $scopedRootMatch[0] : '';
$assert(
    'custom/authored-carousel' === ($scopedCarousel['blockName'] ?? null)
        && str_contains($scopedMarkup, 'class="blocks-engine-authored-carousel')
        && str_contains($scopedRoot, 'review-frame')
        && str_contains($scopedRoot, 'data-section-id="review-42"')
        && ! str_contains($scopedMarkup, '--title-font-size-value:1.6')
        && ! str_contains($scopedRoot, 'runtime-slideshow'),
    'the converter transfers source identity on the carousel root while keeping stylesheet custom properties under their original conditional cascade'
);

$isolationInstance = static fn (string $first, string $second): string => '<div class="service-carousel"><button aria-label="Previous slide">Previous</button><div role="list"><div role="listitem"><img src="' . $first . '"></div><div role="listitem"><img src="' . $second . '"></div></div><button aria-label="Next slide">Next</button></div>';
$isolatedInstances = (new HtmlTransformer())->transform($isolationInstance('one.jpg', 'two.jpg') . $isolationInstance('three.jpg', 'four.jpg'))->toArray();
$isolatedMarkup = (string) ($isolatedInstances['serialized_blocks'] ?? '');
preg_match_all('/<div class="blocks-engine-authored-carousel[^>]+>/', $isolatedMarkup, $isolatedRoots);
$scopeBindings = array();
foreach ($isolatedRoots[0] as $isolatedRoot) {
    if (preg_match('/data-be-source-scope="([^"]+)"/', $isolatedRoot, $scopeMatch)) {
        $scopeBindings[] = $scopeMatch[1];
    }
}
$assert(
    2 === count($scopeBindings) && $scopeBindings[0] !== $scopeBindings[1]
        && str_contains($isolatedMarkup, 'data-be-source-scope="' . $scopeBindings[0] . '"')
        && str_contains($isolatedMarkup, 'data-be-source-scope="' . $scopeBindings[1] . '"'),
    'same-page carousel instances receive distinct source-scope bindings'
);

foreach (array(array('Previous', 'Next'), array('<span class="source-arrow">←</span>', '<span class="source-arrow">→</span>')) as $controlLabels) {
    $textControls = (new HtmlTransformer())->transform(
        '<div class="text-carousel slideshow"><ul><li class="slide"><h2>First</h2></li><li class="slide"><h2>Second</h2></li></ul>'
        . '<div class="source-actions"><button aria-label="Previous slide">' . $controlLabels[0] . '</button>'
        . '<button aria-label="Next slide">' . $controlLabels[1] . '</button></div></div>'
    )->toArray();
    $textDocument = new DOMDocument('1.0', 'UTF-8');
    @$textDocument->loadHTML('<?xml encoding="utf-8" ?>' . (string) $textControls['serialized_blocks']);
    $buttons = $textDocument->getElementsByTagName('button');
    $assert(
        2 === $buttons->length
            && strip_tags($controlLabels[0]) === $buttons->item(0)->textContent
            && strip_tags($controlLabels[1]) === $buttons->item(1)->textContent,
        'captured source control groups preserve both plain labels and safe inline arrow spans'
    );
}

$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$editor = (string) ($definition['assets']['index.js'] ?? '');
$view = (string) ($definition['view_js'] ?? '');
$style = (string) ($definition['assets']['style.css'] ?? '');
$assert('file:./view.js' === ($definition['block_json']['viewScriptModule'] ?? null) && true === ($definition['block_json']['supports']['interactivity'] ?? null) && ! isset($definition['block_json']['viewScript']) && str_contains($editor, 'InnerBlocks.Content'), 'the companion carries one editable parent block and declares its behavior through the Interactivity API');
$assert(str_contains($view, "from '@wordpress/interactivity'") && str_contains($view, "store( 'blocks-engine/carousel'"), 'frontend behavior is a script module built on the WordPress Interactivity API');
$assert(str_contains($view, "'ArrowLeft'") && str_contains($view, "'ArrowRight'") && str_contains($view, 'requested > maximum ? 0'), 'frontend behavior supports keyboard navigation and deterministic wrapping');
$assert(str_contains($style, 'grid-auto-flow:column') && str_contains($style, '@media(max-width:600px)') && str_contains($style, 'prefers-reduced-motion:reduce'), 'carousel layout is bounded and responsive with reduced-motion handling');
$assert(str_contains($style, '.blocks-engine-authored-carousel{--blocks-engine-carousel-gap:1rem;position:relative;') && ! str_contains($style, '--slideshow{position:static'), 'every carousel presentation keeps a positioned root so a source background layer cannot paint over the rebuilt rail');
$assert(str_contains($style, 'pointer-events:auto'), 'slideshow controls and viewport remain interactive inside source layers that disable pointer events');
$assert(
    ! str_contains($style, 'mobile-arrows') && ! str_contains($style, 'desktop-arrows') && ! str_contains($style, 'arrows-bottom')
        && ! str_contains($style, '@media(max-width:600px){.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__controls'),
    'the generic renderer does not prescribe source control classes or responsive boundaries'
);
$assert(str_contains($style, 'visibility:hidden!important') && str_contains($style, 'visibility:visible!important'), 'slideshow state overrides captured responsive visibility on borrowed slides');
$assert(str_contains($style, 'height:var(--blocks-engine-carousel-height,auto)') && str_contains($style, 'slide--active{position:relative!important') && str_contains($style, 'height:auto!important') && str_contains($style, '__track>:first-child'), 'slideshow overlay geometry wins over captured ID positioning so the active slide can size the track');

$shell = (new AuthoredCarouselBlockGenerator())->shell(array('ariaLabel' => 'Care & <support>', 'itemsPerView' => 99, 'wrap' => false));
$shellMarkup = $shell['opening'] . $shell['closing'];
$assert(str_contains($shellMarkup, 'aria-label="Care &amp; &lt;support&gt;"') && str_contains($shellMarkup, '--items-6') && str_contains($shellMarkup, 'data-wrap="false"'), 'shell attributes are escaped and bounded');
$assert(str_contains($shellMarkup, 'data-wp-interactive="blocks-engine/carousel"') && str_contains($shellMarkup, '&quot;presentation&quot;:&quot;track&quot;') && str_contains($shellMarkup, 'data-wp-init="callbacks.init"') && str_contains($shellMarkup, 'data-wp-on--click="actions.next"') && str_contains($shellMarkup, 'data-wp-bind--disabled="state.atEnd"'), 'the shell declares its behavior through Interactivity API directives');
$authoredTopology = (new AuthoredCarouselBlockGenerator())->shell(array(
    'sourceControlTopology' => '<section class="source-group"><div class="source-depth"><button type="button" class="source-prev" data-carousel-previous="true" data-wp-on--click="actions.previous" data-wp-bind--disabled="state.atStart" aria-label="Previous slide"><svg viewBox="0 0 10 10"><path d="M1 5H9"></path></svg></button><button type="button" class="source-next" data-carousel-next="true" data-wp-on--click="actions.next" data-wp-bind--disabled="state.atEnd" aria-label="Next slide"><svg viewBox="0 0 10 10"><path d="M1 5H9"></path></svg></button></div></section>',
));
$authoredTopologyMarkup = $authoredTopology['opening'] . $authoredTopology['closing'];
$assert(
    str_contains($authoredTopologyMarkup, 'source-group') && str_contains($authoredTopologyMarkup, 'source-depth')
        && str_contains($authoredTopologyMarkup, 'data-wp-on--click="actions.previous"')
        && str_contains($authoredTopologyMarkup, 'data-wp-on--click="actions.next"')
        && str_contains($authoredTopologyMarkup, '<svg viewbox="0 0 10 10">'),
    'the shell retains sanitized source wrapper depth and binds both native controls to carousel actions'
);

$payload = (new CompanionPluginPayload())->fromBlockTypes(array(), array(), array(), array($definition));
$payloadBlock = $payload['blocks'][0] ?? array();
$assert(CompanionPluginPayload::SCHEMA === ($payload['schema'] ?? null) && 'authored-carousel' === ($payloadBlock['name'] ?? null), 'the generated carousel uses the established companion-plugin payload');
$assert(array('@wordpress/interactivity') === ($payloadBlock['script_dependencies']['view.js'] ?? null), 'the view module declares the Interactivity API import so the generated asset manifest resolves it');
$assert(isset($payloadBlock['assets']['index.js'], $payloadBlock['assets']['style.css']) && str_contains((string) ($payloadBlock['view_js'] ?? ''), "store( 'blocks-engine/carousel'"), 'the companion payload carries editor, style, and frontend behavior assets');
$assert(!isset($payloadBlock['render'], $payloadBlock['renderer'], $payloadBlock['block_json']['render']), 'the carousel needs no executable PHP renderer');

$customHost = (new HtmlTransformer())->transform('<vendor-carousel><button>Previous</button><div role="list"><div role="listitem"><img src="one.jpg"></div><div role="listitem"><img src="two.jpg"></div></div><button>Next</button></vendor-carousel>')->toArray();
$assert('custom/authored-carousel' === ($customHost['blocks'][0]['blockName'] ?? null), 'custom-element carousel hosts use the same generic block before generated HTML fallback');

$slideshowSource = '<div class="heroSlider" style="width:100vw;left:-120px"><ul class="hero-slideshow" style="height:720px"><li data-slideshow-slide="img" aria-hidden="true" style="animation-duration:500ms"><div style="animation-duration:12000ms"></div><img src="one.jpg"></li><li data-slideshow-slide="img" aria-hidden="false" style="animation-duration:500ms"><div style="animation-duration:12000ms"></div><img src="two.jpg"></li></ul><button class="previous">Previous</button><button class="next">Next</button><ol><li data-slideshow-item="0"></li><li data-slideshow-item="1"></li></ol></div>';
$slideshowResult = (new HtmlTransformer())->transform($slideshowSource)->toArray();
$slideshow = $slideshowResult['blocks'][0] ?? array();
$slideshowMarkup = (string) ($slideshowResult['serialized_blocks'] ?? '');
$assert('custom/authored-carousel' === ($slideshow['blockName'] ?? null) && 'slideshow' === ($slideshow['attrs']['presentation'] ?? null) && 1 === ($slideshow['attrs']['itemsPerView'] ?? null), 'a one-at-a-time authored slideshow uses the same parameterized carousel primitive');
$assert(720 === ($slideshow['attrs']['viewportHeight'] ?? null) && 500 === ($slideshow['attrs']['transitionDuration'] ?? null) && 12000 === ($slideshow['attrs']['autoplayInterval'] ?? null), 'slideshow timing and captured viewport height are recovered from source declarations');
$assert(1 === ($slideshow['attrs']['initialSlide'] ?? null) && true === ($slideshow['attrs']['showDots'] ?? null) && true === ($slideshow['attrs']['fullBleed'] ?? null), 'active slide, dot navigation, and viewport breakout survive conversion');
$assert(str_contains($slideshowMarkup, '--slideshow') && 2 === substr_count($slideshowMarkup, 'data-carousel-index=') && str_contains($slideshowMarkup, '--blocks-engine-carousel-height:720px'), 'slideshow markup carries stacked presentation, indexed dots, and source height');

$wixCapture = (new HtmlTransformer())->transform('<div class="wixui-slideshow"><button data-testid="prevButton" aria-label="Zurück"></button><button data-testid="nextButton" aria-label="Weiter"></button><div data-testid="slidesWrapper" role="list"><article role="listitem" data-dla-captured-slide="0"><h2>First testimonial</h2><p>First complete testimonial.</p><img src="first.jpg" alt="First"></article><article role="listitem" data-dla-captured-slide="1"><h2>Second testimonial</h2><p>Second complete testimonial.</p><img src="second.jpg" alt="Second"></article></div></div>')->toArray();
$wixBlock = $wixCapture['blocks'][0] ?? array();
$wixMarkup = (string) ($wixCapture['serialized_blocks'] ?? '');
$assert(
    'custom/authored-carousel' === ($wixBlock['blockName'] ?? null)
        && 'slideshow' === ($wixBlock['attrs']['presentation'] ?? null)
        && 2 === count($wixBlock['innerBlocks'] ?? array())
        && str_contains($wixMarkup, 'First complete testimonial.')
        && str_contains($wixMarkup, 'Second complete testimonial.'),
    'captured slideshow states with test-id controls become an editable authored carousel without losing slide copy'
);

$boundaryItems = '';
for ( $index = 1; $index <= 6; $index++ ) {
    $boundaryItems .= '<div data-hook="group-view" aria-hidden="false"><div data-idx="' . ($index - 1) . '" data-hook="item-container"><img src="award-' . $index . '.jpg" alt="Award ' . $index . '"></div></div>';
}
$boundaryRailSource = '<div class="pro-gallery slider"><div role="region"><div class="gallery-horizontal-scroll"><div class="gallery-horizontal-scroll-inner">' . $boundaryItems . '</div></div><button data-hook="nav-arrow-next" aria-label="Next Item"></button></div></div>';
$boundaryRailResult = (new HtmlTransformer())->transform($boundaryRailSource)->toArray();
$boundaryRail = $boundaryRailResult['blocks'][0] ?? array();
$assert(
    'custom/authored-carousel' === ($boundaryRail['blockName'] ?? null)
        && 6 === count($boundaryRail['innerBlocks'] ?? array())
        && 'track' === ($boundaryRail['attrs']['presentation'] ?? null)
        && 0 === ($boundaryRail['attrs']['initialSlide'] ?? null)
        && false === ($boundaryRail['attrs']['wrap'] ?? null),
    'a repeated scroll rail with one boundary-state direction lowers to an editable track carousel'
);

$unrelatedGallery = (new HtmlTransformer())->transform('<div class="photo-gallery"><div class="product-scroll"><div class="item"><img src="one.jpg"></div><div class="item"><img src="two.jpg"></div></div><a href="/next">Next collection</a></div>')->toArray();
$assert(
    'custom/authored-carousel' !== ($unrelatedGallery['blocks'][0]['blockName'] ?? null),
    'an unrelated gallery link does not turn a repeated scroll collection into a carousel'
);

$responsiveTestimonials = '<style>.desktop,.mobile,.testimonialSlideshow{display:grid}.desktop .testimonialSlideshow{height:403px}.mobile .testimonialSlideshow{height:309px}[data-testid="slidesWrapper"]{height:100%}</style><div class="desktop"><div id="testimonials" class="testimonialSlideshow" role="region"><button data-testid="prevButton"><svg></svg></button><button data-testid="nextButton"><svg></svg></button><div data-testid="slidesWrapper"><div id="quote-one"><p>First person</p><blockquote>First testimonial.</blockquote></div></div><nav><ol><li><a aria-label="Slide item 1"></a></li><li><a aria-label="Slide item 2"></a></li></ol></nav></div></div>'
    . '<div class="mobile"><div id="testimonials" class="testimonialSlideshow" role="region"><div data-testid="slidesWrapper"><div id="quote-one"><p>First person</p><blockquote>First testimonial.</blockquote></div><div id="quote-two"><p>Second person</p><blockquote>Second testimonial.</blockquote></div></div><nav><ol><li><a aria-label="Slide item 1"></a></li><li><a aria-label="Slide item 2"></a></li></ol></nav></div></div>';
$responsiveTestimonialResult = (new HtmlTransformer())->transform($responsiveTestimonials)->toArray();
$responsiveTestimonialBlocks = array();
$collectCarousels = static function (array $blocks) use (&$collectCarousels, &$responsiveTestimonialBlocks): void {
    foreach ( $blocks as $candidate ) {
        if ( 'custom/authored-carousel' === ($candidate['blockName'] ?? null) ) {
            $responsiveTestimonialBlocks[] = $candidate;
        }
        $collectCarousels($candidate['innerBlocks'] ?? array());
    }
};
$collectCarousels($responsiveTestimonialResult['blocks'] ?? array());
$responsiveTestimonialMarkup = (string) ($responsiveTestimonialResult['serialized_blocks'] ?? '');
$assert(2 === count($responsiveTestimonialBlocks) && 2 === count($responsiveTestimonialBlocks[0]['innerBlocks'] ?? array()) && 2 === count($responsiveTestimonialBlocks[1]['innerBlocks'] ?? array()), 'responsive carousel counterparts reconcile controls and complete text-rich slide collections through stable root identity');
$assert(403 === ($responsiveTestimonialBlocks[0]['attrs']['viewportHeight'] ?? null) && 309 === ($responsiveTestimonialBlocks[1]['attrs']['viewportHeight'] ?? null), 'slideshow viewport geometry falls back to each authored root when its slide list uses percentage height');
$assert(2 === substr_count($responsiveTestimonialMarkup, 'Second testimonial.') && 2 === substr_count($responsiveTestimonialMarkup, 'data-wp-interactive=') && 4 === substr_count($responsiveTestimonialMarkup, 'data-carousel-index='), 'responsive text slides remain editable and each variant receives functional carousel controls and pagination');

$thumbnailRailSource = '<div id="gallery-showcase" class="photo-slideshow"><div class="stage"><div class="slides">'
    . '<div class="slide"><img src="one-large.jpg" alt="One"></div>'
    . '<div class="slide" style="display:none"><img src="two-large.jpg" alt="Two"></div>'
    . '<div class="slide" style="display:none"><img src="three-large.jpg" alt="Three"></div>'
    . '</div></div><div class="picker" style="width:75px;height:100%"><div class="picker-inner">'
    . '<a><img src="one-thumb.jpg" alt=""></a><a><img src="two-thumb.jpg" alt=""></a><a><img src="three-thumb.jpg" alt=""></a>'
    . '</div></div></div>';
$thumbnailRailResult = (new HtmlTransformer())->transform($thumbnailRailSource)->toArray();
$thumbnailRail = $thumbnailRailResult['blocks'][0] ?? array();
$thumbnailRailMarkup = (string) ($thumbnailRailResult['serialized_blocks'] ?? '');
$assert(
    'custom/authored-carousel' === ($thumbnailRail['blockName'] ?? null)
        && 3 === count($thumbnailRail['innerBlocks'] ?? array())
        && 'slideshow' === ($thumbnailRail['attrs']['presentation'] ?? null)
        && 1 === ($thumbnailRail['attrs']['itemsPerView'] ?? null),
    'an image-only selector beside a stage is pagination, so the stage lowers to a one-at-a-time carousel'
);
$assert(
    array(
        array('url' => 'one-thumb.jpg', 'alt' => ''),
        array('url' => 'two-thumb.jpg', 'alt' => ''),
        array('url' => 'three-thumb.jpg', 'alt' => ''),
    ) === ($thumbnailRail['attrs']['thumbnails'] ?? null)
        && 'right' === ($thumbnailRail['attrs']['thumbnailPosition'] ?? null)
        && false === ($thumbnailRail['attrs']['showDots'] ?? null),
    'the source thumbnails become the carousel pager and replace generic dots'
);
$assert(
    str_contains($thumbnailRailMarkup, 'blocks-engine-authored-carousel--thumbnails-right')
        && 3 === substr_count($thumbnailRailMarkup, 'blocks-engine-authored-carousel__thumbnail"')
        && str_contains($thumbnailRailMarkup, 'src="two-thumb.jpg"')
        && str_contains($thumbnailRailMarkup, 'data-carousel-index="2" data-wp-on--click="actions.goTo"')
        && str_contains($thumbnailRailMarkup, 'loading="lazy"'),
    'the rendered rail keeps the source thumbnails as indexed, lazily loaded slide controls'
);
$assert(
    array() === ($thumbnailRailResult['fallbacks'] ?? array())
        && 'pass' === ($thumbnailRailResult['source_reports']['wp_block_validity']['status'] ?? null),
    'the stage and its thumbnail rail convert without fallbacks and stay editor-valid'
);

$railDefinition = $thumbnailRailResult['source_reports']['generated_blocks'][0] ?? array();
$railStyle = (string) ($railDefinition['assets']['style.css'] ?? '');
$railView = (string) ($railDefinition['view_js'] ?? '');
$assert(
    str_contains($railStyle, '--thumbnails-right{display:grid;position:relative;grid-template-columns:minmax(0,1fr) var(--blocks-engine-carousel-thumbnail-size)')
        && str_contains($railStyle, 'overflow-y:auto')
        && str_contains($railStyle, '@media(max-width:600px)'),
    'the side rail is a bounded scrollable column that collapses to a strip on small screens'
);
$assert(
    str_contains($railStyle, '--stage-aspect.blocks-engine-authored-carousel--thumbnails-right{align-items:stretch}')
        && str_contains($railStyle, '--stage-aspect.blocks-engine-authored-carousel--thumbnails-right .blocks-engine-authored-carousel__thumbnails{height:0;min-height:100%;max-height:none}'),
    'a rail beside a ratio-sized stage scrolls inside that stage instead of running past it'
);
$assert(
    str_contains($railView, "[ 'dot', 'thumbnail' ]") && str_contains($railView, 'scrollIntoView'),
    'the active slide is reflected on both pager shapes and keeps the selected thumbnail in view'
);

$shortPagerSource = '<div class="photo-slideshow"><div class="slides">'
    . '<div class="slide"><img src="a.jpg"></div><div class="slide" style="display:none"><img src="b.jpg"></div><div class="slide" style="display:none"><img src="c.jpg"></div>'
    . '</div><div class="picker" style="width:75px"><a><img src="a-t.jpg"></a><a><img src="b-t.jpg"></a></div></div>';
$shortPagerResult = (new HtmlTransformer())->transform($shortPagerSource)->toArray();
$shortPager = $shortPagerResult['blocks'][0] ?? array();
$assert(
    'custom/authored-carousel' === ($shortPager['blockName'] ?? null) && array() === ($shortPager['attrs']['thumbnails'] ?? null),
    'a selector that does not cover every captured slide is not published as a thumbnail pager'
);

$wideePagerSource = '<div class="hero-slideshow"><div class="slides"><div class="slide"><img src="a.jpg"></div><div class="slide" style="display:none"><img src="b.jpg"></div></div>'
    . '<div class="picker" style="width:900px"><a><img src="a-t.jpg"></a><a><img src="b-t.jpg"></a></div></div>';
$widePagerResult = (new HtmlTransformer())->transform($wideePagerSource)->toArray();
$widePager = $widePagerResult['blocks'][0] ?? array();
$assert(
    'bottom' === ($widePager['attrs']['thumbnailPosition'] ?? null) && 2 === count($widePager['attrs']['thumbnails'] ?? array()),
    'a full-width thumbnail strip stays underneath the stage instead of becoming a side rail'
);

$overflowCropSource = '<div id="portrait-slideshow" class="photo-slideshow"><div class="stage"><div class="slides">'
    . '<div class="slide"><div class="crop" style="width:400px;left:-200px;top:-300px"><img src="tall-one.jpg" style="width:100%"></div></div>'
    . '<div class="slide" style="display:none"><div class="crop" style="width:900px;left:-450px;top:-296px"><img src="wide-two.jpg" style="width:100%"></div></div>'
    . '<div class="slide" style="display:none"><div class="crop" style="width:900px;left:-450px;top:-300px"><img src="wide-three.jpg" style="width:100%"></div></div>'
    . '</div></div><div class="picker" style="width:75px"><a><img src="one-t.jpg"></a><a><img src="two-t.jpg"></a><a><img src="three-t.jpg"></a></div></div>';
$overflowCropResult = (new HtmlTransformer())->transform($overflowCropSource)->toArray();
$overflowCrop = $overflowCropResult['blocks'][0] ?? array();
$overflowCropMarkup = (string) ($overflowCropResult['serialized_blocks'] ?? '');
$assert(
    'custom/authored-carousel' === ($overflowCrop['blockName'] ?? null)
        && 0 === ($overflowCrop['attrs']['viewportHeight'] ?? null)
        && '900/600' === ($overflowCrop['attrs']['stageAspectRatio'] ?? null)
        && 900 === ($overflowCrop['attrs']['stageMaxWidth'] ?? null),
    'a stage sized by a runtime script is recovered from the largest centered layer on each axis'
);
$assert(
    str_contains($overflowCropMarkup, 'blocks-engine-authored-carousel--stage-aspect')
        && str_contains($overflowCropMarkup, '--blocks-engine-carousel-stage-aspect:900/600')
        && str_contains($overflowCropMarkup, '--blocks-engine-carousel-stage-width:900px'),
    'the recovered stage box ships as a responsive ratio bounded by its authored width'
);
$overflowCropStyle = (string) ($overflowCropResult['source_reports']['generated_blocks'][0]['assets']['style.css'] ?? '');
$assert(
    str_contains($overflowCropStyle, '--stage-aspect .blocks-engine-authored-carousel__track{height:auto;aspect-ratio:var(--blocks-engine-carousel-stage-aspect)}')
        && str_contains($overflowCropStyle, '--stage-aspect .blocks-engine-authored-carousel__track>.wp-block-image img{width:100%;height:100%;aspect-ratio:auto;object-fit:contain;object-position:center}'),
    'the recovered stage fits every slide into one steady frame instead of growing to the tallest image'
);
$assert(
    720 === ($slideshow['attrs']['viewportHeight'] ?? null)
        && '' === ($slideshow['attrs']['stageAspectRatio'] ?? null)
        && 0 === ($slideshow['attrs']['stageMaxWidth'] ?? null),
    'a slideshow that declares its own pixel height keeps that height instead of a recovered box'
);

$uncenteredLayerSource = '<div class="photo-slideshow"><div class="slides">'
    . '<div class="slide"><div class="crop" style="width:400px;left:-40px;top:-30px"><img src="a.jpg" style="width:100%"></div></div>'
    . '<div class="slide" style="display:none"><div class="crop" style="width:400px;left:-40px;top:-30px"><img src="b.jpg" style="width:100%"></div></div>'
    . '</div><div class="picker" style="width:75px"><a><img src="a-t.jpg"></a><a><img src="b-t.jpg"></a></div></div>';
$uncenteredLayer = (new HtmlTransformer())->transform($uncenteredLayerSource)->toArray()['blocks'][0] ?? array();
$assert(
    'custom/authored-carousel' === ($uncenteredLayer['blockName'] ?? null) && '' === ($uncenteredLayer['attrs']['stageAspectRatio'] ?? null),
    'an offset that does not centre its own layer is not read as a fitted stage'
);

$playbackSource = '<div id="gallery-showcase" class="photo-slideshow"><div class="stage"><div class="slides">'
    . '<div class="slide"><img src="one.jpg"></div>'
    . '<div class="slide" style="display:none"><img src="two.jpg"></div>'
    . '</div></div>'
    . '<div class="overlay"><span class="play-button">Play</span><span class="pause-button" style="display:none">Pause</span></div>'
    . '<div class="picker" style="width:75px"><a><img src="one-t.jpg"></a><a><img src="two-t.jpg"></a></div></div>';
$playbackResult = (new HtmlTransformer())->transform($playbackSource)->toArray();
$playback = $playbackResult['blocks'][0] ?? array();
$playbackMarkup = (string) ($playbackResult['serialized_blocks'] ?? '');
$assert(
    true === ($playback['attrs']['showPlayControl'] ?? null)
        && 0 === ($playback['attrs']['autoplayInterval'] ?? null)
        && 'fade' === ($playback['attrs']['transitionStyle'] ?? null),
    'a source that offers start and stop keeps that affordance without inventing autoplay it never declared'
);
$assert(
    str_contains($playbackMarkup, 'class="blocks-engine-authored-carousel__playback blocks-engine-authored-carousel__playback--')
        && str_contains($playbackMarkup, 'data-wp-on--click="actions.toggleAutoplay"')
        && str_contains($playbackMarkup, 'data-wp-text="state.playbackLabel"')
        && str_contains($playbackMarkup, '>Play</button>'),
    'the playback control is a real toggle whose label follows the running state'
);

$noPlaybackSource = str_replace('<span class="play-button">Play</span><span class="pause-button" style="display:none">Pause</span>', '', $playbackSource);
$noPlayback = (new HtmlTransformer())->transform($noPlaybackSource)->toArray()['blocks'][0] ?? array();
$assert(
    'custom/authored-carousel' === ($noPlayback['blockName'] ?? null)
        && false === ($noPlayback['attrs']['showPlayControl'] ?? null),
    'a slideshow with no playback affordance does not gain one'
);

$slideTransitionSource = '<div class="hero-slideshow"><div class="slides">'
    . '<div class="slide" style="transition:transform 400ms ease"><img src="a.jpg"></div>'
    . '<div class="slide" style="display:none;transition:transform 400ms ease"><img src="b.jpg"></div>'
    . '</div><div class="picker" style="width:75px"><a><img src="a-t.jpg"></a><a><img src="b-t.jpg"></a></div></div>';
$slideTransitionResult = (new HtmlTransformer())->transform($slideTransitionSource)->toArray();
$slideTransition = $slideTransitionResult['blocks'][0] ?? array();
$assert(
    'slide' === ($slideTransition['attrs']['transitionStyle'] ?? null)
        && str_contains((string) ($slideTransitionResult['serialized_blocks'] ?? ''), 'blocks-engine-authored-carousel--transition-slide'),
    'a source that moves its slides on the moving axis is carried as a slide transition, not a cross-fade'
);

$playbackStyle = (string) ($playbackResult['source_reports']['generated_blocks'][0]['assets']['style.css'] ?? '');
$playbackEditor = (string) ($playbackResult['source_reports']['generated_blocks'][0]['assets']['index.js'] ?? '');
$playbackView = (string) ($playbackResult['source_reports']['generated_blocks'][0]['view_js'] ?? '');
$assert(
    str_contains($playbackStyle, '--transition-slide .blocks-engine-authored-carousel__track>:not(.blocks-engine-authored-carousel__slide--active){transform:translateX(100%)}')
        && str_contains($playbackStyle, '[data-direction="backward"]')
        && str_contains($playbackStyle, '@media(prefers-reduced-motion:reduce){.blocks-engine-authored-carousel--transition-slide'),
    'the slide transition is direction aware and yields to a reduced-motion preference'
);
$assert(
    str_contains($playbackEditor, 'InspectorControls')
        && str_contains($playbackEditor, "label: 'Transition'")
        && str_contains($playbackEditor, "label: 'Autoplay interval (ms, 0 to hold)'")
        && str_contains($playbackEditor, "label: 'Show play control'"),
    'the carried block hands playback and transition back to the editor as ordinary controls'
);
$assert(
    str_contains($playbackView, 'toggleAutoplay()') && str_contains($playbackView, 'DEFAULT_AUTOPLAY_INTERVAL'),
    'pressing play starts playback even when the source declared no interval of its own'
);

$placementCss = '.control-overlay{position:absolute;z-index:2;top:10px;left:10px}';
$placementSource = '<style>' . $placementCss . '</style><div class="photo-slideshow"><div class="slides">'
    . '<div class="slide"><img src="one.jpg"></div><div class="slide" style="display:none"><img src="two.jpg"></div>'
    . '</div><div class="control-overlay"><span>Play</span><span>Pause</span></div>'
    . '<div class="picker" style="width:75px"><a><img src="one-t.jpg"></a><a><img src="two-t.jpg"></a></div></div>';
$placementResult = (new HtmlTransformer())->transform($placementSource)->toArray();
$placement = $placementResult['blocks'][0] ?? array();
$placementMarkup = (string) ($placementResult['serialized_blocks'] ?? '');
$assert(
    'top-left' === ($placement['attrs']['playControlPosition'] ?? null)
        && 10 === ($placement['attrs']['playControlInset'] ?? null),
    'the corner a source pins its playback control to is read from the declared insets of its positioned box'
);
$assert(
    str_contains($placementMarkup, 'blocks-engine-authored-carousel__playback--top-left')
        && str_contains($placementMarkup, '--blocks-engine-carousel-control-inset:10px'),
    'the recovered corner and its distance ship on the control instead of a fixed corner'
);

$oppositeCornerSource = str_replace('top:10px;left:10px', 'bottom:20px;right:20px', $placementSource);
$oppositeCorner = (new HtmlTransformer())->transform($oppositeCornerSource)->toArray()['blocks'][0] ?? array();
$assert(
    'bottom-right' === ($oppositeCorner['attrs']['playControlPosition'] ?? null)
        && 20 === ($oppositeCorner['attrs']['playControlInset'] ?? null),
    'the opposite corner is recovered from the same declarations rather than assumed'
);

$unpinnedSource = str_replace($placementCss, '.control-overlay{z-index:2}', $placementSource);
$unpinned = (new HtmlTransformer())->transform($unpinnedSource)->toArray()['blocks'][0] ?? array();
$assert(
    true === ($unpinned['attrs']['showPlayControl'] ?? null)
        && 'bottom-left' === ($unpinned['attrs']['playControlPosition'] ?? null)
        && 0 === ($unpinned['attrs']['playControlInset'] ?? null),
    'a control the source never pinned keeps the block default instead of inventing an offset'
);

$placementStyle = (string) ($placementResult['source_reports']['generated_blocks'][0]['assets']['style.css'] ?? '');
$placementEditor = (string) ($placementResult['source_reports']['generated_blocks'][0]['assets']['index.js'] ?? '');
$assert(
    str_contains($placementStyle, '__playback--top-left{top:var(--blocks-engine-carousel-control-inset,0);left:var(--blocks-engine-carousel-control-inset,0)}')
        && str_contains($placementStyle, '__playback--bottom-right{bottom:var(--blocks-engine-carousel-control-inset,0);right:var(--blocks-engine-carousel-control-inset,0)}'),
    'every corner is expressed through the same inset property so the placement stays one parameter'
);
$assert(
    str_contains($placementEditor, "label: 'Play control position'"),
    'the corner is handed back to the editor as an ordinary control'
);

fwrite(STDOUT, "Authored carousel companion tests passed\n");
