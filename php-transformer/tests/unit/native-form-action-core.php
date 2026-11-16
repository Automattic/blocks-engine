<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Public interface: relative form actions must not acquire synthetic button
// wrappers or redirect a shared source selector onto unrelated core links.
$result = (new HtmlTransformer())->transform(
    '<style>.action{width:100%;padding:12px;background:#123;color:white}</style>'
    . '<form><input name="email"><textarea name="message"></textarea><button type="submit" class="action">Find &amp; choose</button></form>'
    . '<a class="action" href="/shop/">Shop</a>'
)->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '/authored-button')
    || !str_contains($markup, '"blocksEngineControlTagName":"button"')
    || !str_contains($markup, '"blocksEngineControlBox":{"type":"submit","class":"action"}')
    || !str_contains($markup, '"blocksEngineControlLabel":true')
    || !str_contains($markup, '<p>Find &amp; choose</p>')
    || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? null)) {
    throw new RuntimeException('Native form action must use editable core blocks without replacing its source box: ' . $markup);
}
echo "Native form action core: passed\n";
