<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Capture node IDs bind box-tree evidence. They must not change the block tree
// (wrapper coalescing) or reach output markup (attribute-inlined labels).
$source = '<!doctype html><html><head><style>.c{display:flex;gap:10px}</style></head><body>'
    . '<nav><ul><li><a href="/about"><span class="w"><span class="label">About</span></span></a></li><li><a href="/contact">Contact</a></li></ul></nav>'
    . '<main><div class="c"><div class="w"><div class="w"><div class="w"><p>Hello world</p></div></div></div>'
    . '<div class="w"><div class="w"><img src="https://example.com/a.png" alt="x"></div></div></div></main></body></html>';
$index = 0;
$stamped = preg_replace_callback('/<([a-z]+)\b/', static function (array $match) use (&$index): string {
    return '<' . $match[1] . ' data-blocks-engine-node-id="n' . $index++ . '"';
}, $source);

$plain = (new HtmlTransformer())->transform($source)->toArray()['serialized_blocks'];
$withIds = (new HtmlTransformer())->transform((string) $stamped)->toArray()['serialized_blocks'];
if ($plain !== $withIds) {
    throw new RuntimeException("Node IDs changed the block output:\n--- plain\n{$plain}\n--- stamped\n{$withIds}");
}
echo "ok\n";
