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

fwrite(STDOUT, "list item lowering contract passed\n");
