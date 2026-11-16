<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A landmark must retain its block identity even inside an addressable wrapper.
$html = '<style>.menu-item{display:flex;gap:var(--menu-gap)}</style>'
    . '<div id="menu-content"><nav class="navbar"><ul><li class="menu-item"><div></div></li></ul></nav></div>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (str_contains($markup, '/layout-shell') || !str_contains($markup, '"tagName":"nav"') || !str_contains($markup, 'id="menu-content"')) {
    throw new RuntimeException('Navigation landmarks and their ancestors must remain native Groups: ' . $markup);
}
echo "native navigation wrapper boundary passed\n";
