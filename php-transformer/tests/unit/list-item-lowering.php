<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$native = (new HtmlTransformer())->transform('<ul><li>Plain <strong>copy</strong><ul><li>Nested</li></ul></li></ul>')->toArray();
if ('core/list' !== ($native['blocks'][0]['blockName'] ?? null)) throw new RuntimeException('RichText lists must remain native core/list output.');
if (2 !== substr_count((string) ($native['serialized_blocks'] ?? ''), '<!-- wp:list-item')) throw new RuntimeException('Direct nested lists must remain native list-item children.');
if (str_contains((string) ($native['serialized_blocks'] ?? ''), '<!-- wp:html')) throw new RuntimeException('Representable RichText lists must not use HTML fallback.');

$source = '<ul class="cards"><li><div><h3>Card title</h3><p>Card copy</p><img src="card.jpg" alt=""></div></li></ul>';
$first = (new HtmlTransformer())->transform($source)->toArray();
$second = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($first['serialized_blocks'] ?? '');

if ('core/group' !== ($first['blocks'][0]['blockName'] ?? null) || 'ul' !== ($first['blocks'][0]['attrs']['tagName'] ?? null) || 'li' !== ($first['blocks'][0]['innerBlocks'][0]['attrs']['tagName'] ?? null)) throw new RuntimeException('Structural lists must lower to semantic native Group collections.');
if (str_contains($markup, '<!-- wp:list-item') || str_contains($markup, '<!-- wp:html') || array() !== ($first['fallbacks'] ?? array())) throw new RuntimeException('Structural lists must avoid invalid RichText and HTML fallback.');
if (!str_contains($markup, '<ul class="wp-block-group cards ') || !str_contains($markup, '<li class="wp-block-group ') || !str_contains($markup, '>Card title</h3>') || !str_contains($markup, '>Card copy</p>') || !str_contains($markup, '<img src="card.jpg" alt=""')) throw new RuntimeException('Structural list decomposition must preserve semantic list wrappers and editable content blocks.');
if ('pass' !== ($first['source_reports']['wp_block_validity']['status'] ?? null)) throw new RuntimeException('Structural list decomposition must remain Gutenberg-valid.');
if (($first['serialized_blocks'] ?? null) !== ($second['serialized_blocks'] ?? null) || ($first['fallbacks'] ?? null) !== ($second['fallbacks'] ?? null)) throw new RuntimeException('Structural list lowering and diagnostics must be deterministic.');

$styled = (new HtmlTransformer())->transform('<style>.stage-output span{display:block;font-size:.62rem}.stage-output strong{display:block}</style><ol><li><div class="stage-output"><span>Feeds back</span><strong>Findings</strong></div></li></ol>')->toArray();
$styledMarkup = (string) ($styled['serialized_blocks'] ?? '');
$styledCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $styled['assets'] ?? array()));
if (!str_contains($styledMarkup, 'tagName":"ol"') || !str_contains($styledMarkup, 'tagName":"li"') || !str_contains($styledMarkup, 'blocks-engine-inline-layout-carrier') || !str_contains($styledCss, '.stage-output p.blocks-engine-inline-layout-carrier')) throw new RuntimeException('Structural native Groups must project author selectors through native carriers.');
if (str_contains($styledMarkup, '<!-- wp:html')) throw new RuntimeException('Projected structural lists must stay fully native.');

$inlineFlow = (new HtmlTransformer())->transform('<ol><li><span>1</span><div><strong>Observe</strong><p>Copy</p></div></li></ol>')->toArray();
$inlineFlowMarkup = (string) ($inlineFlow['serialized_blocks'] ?? '');
if (!str_contains($inlineFlowMarkup, '<p class="blocks-engine-synthetic-paragraph"><strong>Observe</strong></p>')) throw new RuntimeException('Standalone inline content in a structural item must use a margin-neutral synthetic paragraph.');
if ('pass' !== ($inlineFlow['source_reports']['wp_block_validity']['status'] ?? null)) throw new RuntimeException('Structural item inline carriers must remain Gutenberg-valid.');

$repeater = (new HtmlTransformer())->transform('<style>.fluid-columns-repeater{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}@media(max-width:600px){.fluid-columns-repeater{grid-template-columns:repeat(2,minmax(0,1fr))}}</style><fluid-columns-repeater class="fluid-columns-repeater" role="list" horizontal-gap="0" vertical-gap="10" justify-content="space-between" container-id="cards" items="8" direction="ltr"><div id="card-one" data-testid="card" role="listitem"><a href="/one"><img src="one.jpg" alt="One"><h3>One</h3></a></div><repeater-card role="listitem"><a href="/two"><img src="two.jpg" alt="Two"><h3>Two</h3></a></repeater-card><repeater-card role="listitem"><a href="/three"><img src="three.jpg" alt="Three"><h3>Three</h3></a></repeater-card><repeater-card role="listitem"><a href="/four"><img src="four.jpg" alt="Four"><h3>Four</h3></a></repeater-card><repeater-card role="listitem"><a href="/five"><img src="five.jpg" alt="Five"><h3>Five</h3></a></repeater-card><repeater-card role="listitem"><a href="/six"><img src="six.jpg" alt="Six"><h3>Six</h3></a></repeater-card><repeater-card role="listitem"><a href="/seven"><img src="seven.jpg" alt="Seven"><h3>Seven</h3></a></repeater-card><repeater-card role="listitem"><a href="/eight"><img src="eight.jpg" alt="Eight"><h3>Eight</h3></a></repeater-card></fluid-columns-repeater>')->toArray();
$repeaterMarkup = (string) ($repeater['serialized_blocks'] ?? '');
$repeaterCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $repeater['assets'] ?? array()));
if ('core/group' !== ($repeater['blocks'][0]['blockName'] ?? null) || 'ul' !== ($repeater['blocks'][0]['attrs']['tagName'] ?? null) || 8 !== count($repeater['blocks'][0]['innerBlocks'] ?? array()) || 'li' !== ($repeater['blocks'][0]['innerBlocks'][7]['attrs']['tagName'] ?? null)) throw new RuntimeException('Safe custom repeaters must lower eight ordered list items to editable semantic Groups.');
if (!str_contains($repeaterMarkup, 'one.jpg') || !str_contains($repeaterMarkup, 'eight.jpg') || strpos($repeaterMarkup, '>One</h3>') > strpos($repeaterMarkup, '>Eight</h3>') || !str_contains($repeaterMarkup, 'href="/one"') || !str_contains($repeaterCss, 'grid-template-columns')) throw new RuntimeException('Custom repeater lowering must preserve card content, links, images, ordering, and responsive CSS.');
$repeaterFallbackTags = array_column($repeater['fallbacks'] ?? array(), 'tag');
if (in_array('fluid-columns-repeater', $repeaterFallbackTags, true) || in_array('repeater-card', $repeaterFallbackTags, true) || 'pass' !== ($repeater['source_reports']['wp_block_validity']['status'] ?? null)) throw new RuntimeException('Custom repeater Groups must be reorderable/editable Gutenberg-valid blocks without custom-element fallbacks.');

$transparent = (new HtmlTransformer())->transform('<content-shell class="shell"><p>Editable wrapper content</p></content-shell>')->toArray();
if ('core/group' !== ($transparent['blocks'][0]['blockName'] ?? null) || 'core/paragraph' !== ($transparent['blocks'][0]['innerBlocks'][0]['blockName'] ?? null) || array() !== ($transparent['fallbacks'] ?? array())) throw new RuntimeException('Static custom wrappers must be transparent when their contents are independently convertible.');

$runtimeCustom = (new HtmlTransformer())->transform('<runtime-repeater role="list" onclick="refreshCards()"><repeater-card role="listitem"><p>Unsafe runtime card</p></repeater-card></runtime-repeater>')->toArray();
if ('runtime-repeater' !== ($runtimeCustom['fallbacks'][0]['tag'] ?? null) || 'html_unsupported_element' !== ($runtimeCustom['fallbacks'][0]['diagnostic_code'] ?? null)) throw new RuntimeException('Runtime-owned custom elements must remain diagnosed fallbacks.');

$markedStructural = (new HtmlTransformer())->transform('<style>.quote span{color:#123}</style><div class="quote"><span><div><h3>Quote <em>heading</em></h3><p>Body <a href="/source">link</a></p><img src="quote.jpg" alt="Quote"></div></span></div>')->toArray();
$markedBlocks = $markedStructural['blocks'] ?? array();
$markedMarkup = (string) ($markedStructural['serialized_blocks'] ?? '');
$markedReport = $markedStructural['source_reports']['editability_report'] ?? array();
if ('core/group' !== ($markedBlocks[0]['innerBlocks'][0]['blockName'] ?? null) || 'core/group' !== ($markedBlocks[0]['innerBlocks'][0]['innerBlocks'][0]['blockName'] ?? null) || 'core/heading' !== ($markedBlocks[0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['blockName'] ?? null) || 'core/paragraph' !== ($markedBlocks[0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][1]['blockName'] ?? null) || 'core/image' !== ($markedBlocks[0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][2]['blockName'] ?? null) || 0 !== ($markedReport['metrics']['structural_rich_text_attribute_count'] ?? -1)) throw new RuntimeException('Selector-addressed inline containers lower structural children to native editable blocks instead of RichText attributes.');
if (!str_contains($markedMarkup, '<em>heading</em>') || !str_contains($markedMarkup, '<a href="/source">link</a>') || 'pass' !== ((new BlockValidityValidator())->validateBlocks($markedBlocks)['status'] ?? '')) throw new RuntimeException('Structural lowering retains genuine inline formatting and Gutenberg block validity.');
$runtime = new Runtime();
$persisted = $runtime->parseBlocks($runtime->serializeBlocks($markedBlocks));
$persisted[0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['innerHTML'] = '<h3 class="wp-block-heading">Edited <em>heading</em></h3>';
$persisted[0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['innerContent'] = array('<h3 class="wp-block-heading">Edited <em>heading</em></h3>');
$persisted[0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][1]['innerHTML'] = '<p>Edited <a href="/updated">link</a></p>';
$persisted[0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][1]['innerContent'] = array('<p>Edited <a href="/updated">link</a></p>');
$edited = $runtime->serializeBlocks($persisted);
if (!str_contains($edited, 'Edited <em>heading</em>') || !str_contains($edited, '<a href="/updated">link</a>') || 'pass' !== ((new BlockValidityValidator())->validateBlocks($runtime->parseBlocks($edited))['status'] ?? '')) throw new RuntimeException('Native structural children persist text and link edits through parse and serialize.');

$structuralQuote = (new HtmlTransformer())->transform('<style>.quote span{color:#123}</style><blockquote class="quote"><span><div><p>Quoted <strong>copy</strong> <a href="/quote">link</a></p></div></span></blockquote>')->toArray();
if (0 !== ($structuralQuote['source_reports']['editability_report']['metrics']['structural_rich_text_attribute_count'] ?? -1) || !str_contains((string) ($structuralQuote['serialized_blocks'] ?? ''), '<strong>copy</strong>') || !str_contains((string) ($structuralQuote['serialized_blocks'] ?? ''), '<a href="/quote">link</a>') || 'pass' !== ((new BlockValidityValidator())->validateBlocks($structuralQuote['blocks'] ?? array())['status'] ?? '')) throw new RuntimeException('Structural blockquotes lower nested layout to native children while retaining valid inline RichText formats.');

$standaloneStructuralInline = (new HtmlTransformer())->transform('<style>.meta p{color:#123}</style><span class="meta"><p>Blog</p></span>')->toArray();
if (0 !== ($standaloneStructuralInline['source_reports']['editability_report']['metrics']['structural_rich_text_attribute_count'] ?? -1) || !str_contains((string) ($standaloneStructuralInline['serialized_blocks'] ?? ''), '>Blog</p>') || str_contains((string) ($standaloneStructuralInline['serialized_blocks'] ?? ''), '<mark class="meta"><p>') || 'pass' !== ((new BlockValidityValidator())->validateBlocks($standaloneStructuralInline['blocks'] ?? array())['status'] ?? '')) throw new RuntimeException('Standalone inline wrappers with block descendants lower to native child blocks instead of structural RichText.');

$structuralCardFragment = (new HtmlTransformer())->transform('<ul class="cards"><li><a class="title" href="/story">Story title</a><span class="meta"><p>Blog</p></span></li></ul>')->toArray();
$structuralCardMarkup = (string) ($structuralCardFragment['serialized_blocks'] ?? '');
if (0 !== ($structuralCardFragment['source_reports']['editability_report']['metrics']['structural_rich_text_attribute_count'] ?? -1) || !str_contains($structuralCardMarkup, '<a class="title" href="/story">Story title</a>') || !str_contains($structuralCardMarkup, '<div class="wp-block-group meta"><!-- wp:paragraph --><p>Blog</p>') || str_contains($structuralCardMarkup, '<!-- wp:html') || 'pass' !== ((new BlockValidityValidator())->validateBlocks($structuralCardFragment['blocks'] ?? array())['status'] ?? '')) throw new RuntimeException('Structured-card candidates with block descendants use native structural list lowering without losing links or storing structural RichText.');

fwrite(STDOUT, "list item lowering contract passed\n");
