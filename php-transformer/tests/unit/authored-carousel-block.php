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
$assert(str_contains($shellMarkup, 'data-wp-interactive="blocks-engine/carousel"') && str_contains($shellMarkup, 'data-wp-context="{&quot;index&quot;:0,&quot;wrap&quot;:false,&quot;count&quot;:0,&quot;visible&quot;:6}"') && str_contains($shellMarkup, 'data-wp-init="callbacks.init"') && str_contains($shellMarkup, 'data-wp-on--click="actions.next"') && str_contains($shellMarkup, 'data-wp-bind--disabled="state.atEnd"'), 'the shell declares its behavior through Interactivity API directives');

$payload = (new CompanionPluginPayload())->fromBlockTypes(array(), array(), array(), array($definition));
$payloadBlock = $payload['blocks'][0] ?? array();
$assert(CompanionPluginPayload::SCHEMA === ($payload['schema'] ?? null) && 'authored-carousel' === ($payloadBlock['name'] ?? null), 'the generated carousel uses the established companion-plugin payload');
$assert(array('@wordpress/interactivity') === ($payloadBlock['script_dependencies']['view.js'] ?? null), 'the view module declares the Interactivity API import so the generated asset manifest resolves it');
$assert(isset($payloadBlock['assets']['index.js'], $payloadBlock['assets']['style.css']) && str_contains((string) ($payloadBlock['view_js'] ?? ''), "store( 'blocks-engine/carousel'"), 'the companion payload carries editor, style, and frontend behavior assets');
$assert(!isset($payloadBlock['render'], $payloadBlock['renderer'], $payloadBlock['block_json']['render']), 'the carousel needs no executable PHP renderer');

$customHost = (new HtmlTransformer())->transform('<vendor-carousel><button>Previous</button><div role="list"><div role="listitem"><img src="one.jpg"></div><div role="listitem"><img src="two.jpg"></div></div><button>Next</button></vendor-carousel>')->toArray();
$assert('custom/authored-carousel' === ($customHost['blocks'][0]['blockName'] ?? null), 'custom-element carousel hosts use the same generic block before generated HTML fallback');

fwrite(STDOUT, "Authored carousel companion tests passed\n");
