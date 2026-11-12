<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Synthetic inline fragments do not supply independent source boxes. Keeping
// ordinary authored branching content native must not change their transport.
$result = (new HtmlTransformer())->transform('<div class="band"><div class="track"><span class="item">First</span><span class="item">Second</span></div></div>')->toArray();
$block = $result['blocks'][0] ?? array();
if ('custom/layout-shell' !== ($block['blockName'] ?? null)
    || 2 !== count($block['attrs']['wrappers'] ?? array())
    || 2 !== count($block['innerBlocks'] ?? array())
    || !str_contains((string) ($result['serialized_blocks'] ?? ''), 'wp-block-group track')) {
    throw new RuntimeException('Inline-only fragments retain their declared wrappers and separate editable paragraphs.');
}
echo "Inline fragment wrapper transport passed\n";
