<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Exercise the public transformer. Styling alone must not force a plain
// standalone label into a companion; form actions have their own lowering.
foreach (array('submit', 'reset', 'button') as $type) {
    $result = (new HtmlTransformer())->transform(
        '<style>.action{background:#111;color:white;padding:12px 24px}</style>'
        . '<button type="' . $type . '" class="action">Choose &amp; continue</button>'
    )->toArray();
    $markup = (string) $result['serialized_blocks'];
    if (str_contains($markup, '/authored-button') || !str_contains($markup, '<!-- wp:button ')
        || !str_contains($markup, '<button type="' . $type . '"')
        || !str_contains($markup, 'Choose &amp; continue')
        || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '')) {
        throw new RuntimeException('Plain styled ' . $type . ' must remain a valid native core button: ' . $markup);
    }
}
// The same label in a form must still reach the form-owned lowering,
// retaining its native source class rather than the synthetic Core link.
$result = (new HtmlTransformer())->transform(
    '<style>.action{background:#111;color:white;padding:12px 24px}</style>'
    . '<form><input name="email"><button type="submit" class="action">Send</button></form>'
)->toArray();
$markup = $result['serialized_blocks'];
if (!str_contains($markup, '/authored-button') || !str_contains($markup, '<button type="submit" class="action">Send</button>')) {
    throw new RuntimeException('Form actions must retain form-owned submission semantics: ' . $markup);
}
echo "Plain native button core: 4 cases passed\n";
