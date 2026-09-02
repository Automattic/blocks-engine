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

$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$editor = (string) ($definition['assets']['index.js'] ?? '');
$view = (string) ($definition['view_js'] ?? '');
$style = (string) ($definition['assets']['style.css'] ?? '');
$assert('file:./view.js' === ($definition['block_json']['viewScriptModule'] ?? null) && true === ($definition['block_json']['supports']['interactivity'] ?? null) && ! isset($definition['block_json']['viewScript']) && str_contains($editor, 'InnerBlocks.Content'), 'the companion carries one editable parent block and declares its behavior through the Interactivity API');
$assert(str_contains($view, "from '@wordpress/interactivity'") && str_contains($view, "store( 'blocks-engine/carousel'"), 'frontend behavior is a script module built on the WordPress Interactivity API');
$assert(str_contains($view, "'ArrowLeft'") && str_contains($view, "'ArrowRight'") && str_contains($view, 'requested > maximum ? 0'), 'frontend behavior supports keyboard navigation and deterministic wrapping');
$assert(str_contains($style, 'grid-auto-flow:column') && str_contains($style, '@media(max-width:600px)') && str_contains($style, 'prefers-reduced-motion:reduce'), 'carousel layout is bounded and responsive with reduced-motion handling');

$shell = (new AuthoredCarouselBlockGenerator())->shell(array('ariaLabel' => 'Care & <support>', 'itemsPerView' => 99, 'wrap' => false));
$shellMarkup = $shell['opening'] . $shell['closing'];
$assert(str_contains($shellMarkup, 'aria-label="Care &amp; &lt;support&gt;"') && str_contains($shellMarkup, '--items-6') && str_contains($shellMarkup, 'data-wrap="false"'), 'shell attributes are escaped and bounded');
$assert(str_contains($shellMarkup, 'data-wp-interactive="blocks-engine/carousel"') && str_contains($shellMarkup, '&quot;presentation&quot;:&quot;track&quot;') && str_contains($shellMarkup, 'data-wp-init="callbacks.init"') && str_contains($shellMarkup, 'data-wp-on--click="actions.next"') && str_contains($shellMarkup, 'data-wp-bind--disabled="state.atEnd"'), 'the shell declares its behavior through Interactivity API directives');

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

fwrite(STDOUT, "Authored carousel companion tests passed\n");
