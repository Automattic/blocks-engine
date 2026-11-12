<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Several shallow branches share deep ancestry. Compress the shared chain,
// rather than generating a separate non-core shell around every leaf.
$html = '';
for ($i = 0; $i < 3; $i++) {
    $html .= '<div id="branch-' . $i . '"><div class="leaf"><p>Editable leaf ' . $i . '</p></div></div>';
}
for ($i = 0; $i < 18; $i++) {
    $html = '<div id="ancestor-' . $i . '">' . $html . '</div>';
}
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (1 !== substr_count($markup, '/layout-shell {') || 7 !== substr_count($markup, '<!-- wp:group ')) {
    throw new RuntimeException('The shared ancestor chain must pay the depth budget once, leaving all three leaf chains native: ' . $markup);
}
for ($i = 0; $i < 3; $i++) {
    if (!str_contains($markup, 'id="branch-' . $i . '"') || !str_contains($markup, 'Editable leaf ' . $i)) {
        throw new RuntimeException('Native leaf wrappers must retain their identity and content.');
    }
}
echo "Shared ancestor wrapper budget passed\n";
