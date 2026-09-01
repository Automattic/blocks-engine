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
$assert('manual' === ($block['attrs']['mode'] ?? 'manual') && false === ($block['attrs']['autoplay'] ?? false), 'controlled carousel remains manual by default');
$assert(2 === count($block['innerBlocks'] ?? array()) && 'core/image' === ($block['innerBlocks'][0]['blockName'] ?? null), 'carousel slides remain ordinary editable inner image blocks');
$assert(str_contains($markup, 'First description.') && !str_contains($markup, 'expanded-one.jpg'), 'the primary rail keeps captions and excludes expanded-state duplicates');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'carousel serialization is editor-valid');
$serialized = (new Runtime())->serializeBlocks(array($block));
$assert('custom/authored-carousel' === ((new Runtime())->parseBlocks($serialized)[0]['blockName'] ?? null), 'the carousel and its inner blocks persist through parse and serialize');

$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$editor = (string) ($definition['assets']['index.js'] ?? '');
$view = (string) ($definition['view_js'] ?? '');
$style = (string) ($definition['assets']['style.css'] ?? '');
$assert('file:./view.js' === ($definition['block_json']['viewScript'] ?? null) && str_contains($editor, 'InnerBlocks.Content'), 'the companion carries one editable parent block and a scoped frontend script');
$assert(str_contains($view, "'ArrowLeft'") && str_contains($view, "'ArrowRight'") && str_contains($view, 'requested > maximum ? 0'), 'frontend behavior supports keyboard navigation and deterministic wrapping');
$assert(str_contains($style, 'grid-auto-flow:column') && str_contains($style, '@media(max-width:600px)') && str_contains($style, 'prefers-reduced-motion:reduce'), 'carousel layout is bounded and responsive with reduced-motion handling');

$shell = (new AuthoredCarouselBlockGenerator())->shell(array('ariaLabel' => 'Care & <support>', 'itemsPerView' => 99, 'wrap' => false));
$shellMarkup = $shell['opening'] . $shell['closing'];
$assert(str_contains($shellMarkup, 'aria-label="Care &amp; &lt;support&gt;"') && str_contains($shellMarkup, '--items-6') && str_contains($shellMarkup, 'data-wrap="false"'), 'shell attributes are escaped and bounded');

$payload = (new CompanionPluginPayload())->fromBlockTypes(array(), array(), array(), array($definition));
$payloadBlock = $payload['blocks'][0] ?? array();
$assert(CompanionPluginPayload::SCHEMA === ($payload['schema'] ?? null) && 'authored-carousel' === ($payloadBlock['name'] ?? null), 'the generated carousel uses the established companion-plugin payload');
$assert(isset($payloadBlock['assets']['index.js'], $payloadBlock['assets']['style.css']) && str_contains((string) ($payloadBlock['view_js'] ?? ''), 'data-carousel-next'), 'the companion payload carries editor, style, and frontend behavior assets');
$assert(!isset($payloadBlock['render'], $payloadBlock['renderer'], $payloadBlock['block_json']['render']), 'the carousel needs no executable PHP renderer');

$customHost = (new HtmlTransformer())->transform('<vendor-carousel><button>Previous</button><div role="list"><div role="listitem"><img src="one.jpg"></div><div role="listitem"><img src="two.jpg"></div></div><button>Next</button></vendor-carousel>')->toArray();
$assert('custom/authored-carousel' === ($customHost['blocks'][0]['blockName'] ?? null), 'custom-element carousel hosts use the same generic block before generated HTML fallback');

$slideshowSource = '<div data-testid="slideshow" role="group" style="--transitionDuration:1"><div class="repeater" role="group"><div role="list" aria-live="off"><div role="listitem"><img src="one.jpg" width="538" height="402"></div><div role="listitem"><img src="two.jpg" width="538" height="402"></div><div role="listitem"><img src="three.jpg" width="538" height="402"></div><div role="listitem"><img src="four.jpg" width="538" height="402"></div></div></div></div>';
$slideshowResult = (new HtmlTransformer())->transform($slideshowSource)->toArray();
$slideshow = $slideshowResult['blocks'][0] ?? array();
$slideshowMarkup = (string) ($slideshowResult['serialized_blocks'] ?? '');
$slideshowDefinition = $slideshowResult['source_reports']['generated_blocks'][0] ?? array();
$slideshowView = (string) ($slideshowDefinition['view_js'] ?? '');
$slideshowStyle = (string) ($slideshowDefinition['assets']['style.css'] ?? '');
$assert('custom/authored-carousel' === ($slideshow['blockName'] ?? null) && 4 === count($slideshow['innerBlocks'] ?? array()), 'an explicit slideshow host with a bounded image list is recognized');
$assert('slideshow' === ($slideshow['attrs']['mode'] ?? null) && 1 === ($slideshow['attrs']['itemsPerView'] ?? null) && true === ($slideshow['attrs']['autoplay'] ?? null) && 4.0 === ($slideshow['attrs']['interval'] ?? null) && 1.0 === ($slideshow['attrs']['transitionDuration'] ?? null) && false === ($slideshow['attrs']['navigation'] ?? null), 'slideshow attributes preserve one-up bounded autoplay without visible navigation');
$assert(!str_contains($slideshowMarkup, 'data-carousel-next') && str_contains($slideshowMarkup, 'data-carousel-pause') && 'pass' === ($slideshowResult['source_reports']['wp_block_validity']['status'] ?? null), 'slideshow markup has focus-revealed motion control and remains block-valid');
$assert('custom/authored-carousel' === ((new Runtime())->parseBlocks($slideshowMarkup)[0]['blockName'] ?? null), 'slideshow markup round-trips through the block parser');
$assert(str_contains($slideshowView, 'window.setInterval') && str_contains($slideshowView, 'mouseenter') && str_contains($slideshowView, 'focusin') && str_contains($slideshowView, 'visibilitychange') && str_contains($slideshowView, 'prefers-reduced-motion: reduce') && str_contains($slideshowView, 'requested > maximum ? 0') && str_contains($slideshowView, "show( index + 1, false )"), 'slideshow runtime bounds timers, pauses for interaction, respects reduced motion, wraps, and does not announce automatic updates');
$assert(str_contains($slideshowStyle, 'position:absolute;inset:0') && str_contains($slideshowStyle, '.blocks-engine-authored-carousel--slideshow .blocks-engine-authored-carousel__track>.wp-block-image img{aspect-ratio:auto}') && str_contains($slideshowStyle, 'transition:none'), 'slideshow CSS overlays one active slide without portrait forcing and suppresses reduced-motion fades');

$genericRepeater = (new HtmlTransformer())->transform('<div class="repeater"><div role="list"><div role="listitem"><img src="one.jpg"></div><div role="listitem"><img src="two.jpg"></div></div></div>')->toArray();
$assert('custom/authored-carousel' !== ($genericRepeater['blocks'][0]['blockName'] ?? null), 'generic repeaters and static image lists are not promoted without explicit slideshow identity');

fwrite(STDOUT, "Authored carousel companion tests passed\n");
