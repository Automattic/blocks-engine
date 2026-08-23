<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

fwrite(STDOUT, "list item lowering contract passed\n");
