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
$fallback = $first['fallbacks'][0] ?? array();

if ('core/html' !== ($first['blocks'][0]['blockName'] ?? null) || str_contains($markup, '<!-- wp:list-item')) throw new RuntimeException('Structural list items must use one coherent explicit fallback rather than opaque list-item RichText.');
if (!str_contains($markup, '<ul class="cards"><li><div><h3>Card title</h3>') || !str_contains($markup, '<img src="card.jpg" alt="">')) throw new RuntimeException('Structural list fallback must preserve the sanitized source region.');
if ('html_list_item_block_grammar_fallback' !== ($fallback['diagnostic_code'] ?? null) || 'block_grammar' !== ($fallback['reason'] ?? null) || 'structural_list' !== ($fallback['pattern_family'] ?? null)) throw new RuntimeException('Structural list fallback must carry typed block-grammar evidence.');
if (($first['serialized_blocks'] ?? null) !== ($second['serialized_blocks'] ?? null) || ($first['fallbacks'] ?? null) !== ($second['fallbacks'] ?? null)) throw new RuntimeException('Structural list lowering and diagnostics must be deterministic.');

fwrite(STDOUT, "list item lowering contract passed\n");
