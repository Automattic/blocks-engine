<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A native button whose save wrapper stays a box (block flow, or a grid item
// behind a display:contents source wrapper) keeps its percentage width on the
// wrapper chain. The link's containing block is then that wrapper, so the
// same percentage must not be restated on the link a second time.
$pill = '.pill{display:flex;width:20%;padding:8px 16px;border:4px solid #000;border-radius:30px;background:#fff}';
$cases = array(
    'block flow' => array('<div class="wrap"><button class="pill" type="button">Events</button></div>', '.wrap{width:1000px}' . $pill),
    'contents parent in grid' => array('<section class="grid"><div class="pw"><button class="pill" type="button">Events</button></div></section>', '.grid{display:grid;grid-template-columns:1fr;width:1000px}.pw{display:contents}' . $pill),
    'variable width' => array('<div class="wrap"><button class="pill" type="button">Events</button></div>', '.wrap{width:1000px;--w:20%}' . str_replace('width:20%', 'width:var(--w)', $pill)),
);
foreach ($cases as $name => [$html, $css]) {
    $result = (new HtmlTransformer())->transform('<style>' . $css . '</style>' . $html)->toArray();
    $markup = $result['serialized_blocks'];
    if (!str_contains($markup, 'wp-block-button__link') || str_contains($markup, 'blocks-engine-layout-child-button')) {
        throw new RuntimeException($name . ': fixture must keep the core/button save wrapper.');
    }
    $assets = stripslashes(json_encode($result['assets']));
    if (1 !== preg_match('/:where\(\.wp-block-buttons\)\{[^{}]*width:(?:20%|var\(--w\))/', $assets)) {
        throw new RuntimeException($name . ': the kept wrapper must carry the source percentage width.');
    }
    preg_match_all('/[^{}]*wp-block-button__link[^{}]*\{[^{}]*(?<![\w-])width:(?:20%|var\(--w\))[^{}]*\}/', $assets, $linkWidths);
    if (array() !== $linkWidths[0]) {
        throw new RuntimeException($name . ': percentage width resolves a second time against the kept wrapper: ' . $linkWidths[0][0]);
    }
}

// A flattened wrapper still routes the percentage to the link, its only box.
$flattened = (new HtmlTransformer())->transform('<style>.grid{display:grid;width:1000px}' . $pill . '</style><section class="grid"><button class="pill" type="button">Events</button></section>')->toArray();
preg_match_all('/[^{}]*wp-block-button__link[^{}]*\{[^{}]*(?<![\w-])width:20%[^{}]*\}/', stripslashes(json_encode($flattened['assets'])), $flattenedWidths);
if (array() === $flattenedWidths[0] || array() !== array_filter($flattenedWidths[0], static fn (string $rule): bool => !str_contains($rule, '.blocks-engine-layout-child-button'))) {
    throw new RuntimeException('A flattened button wrapper must keep its percentage width on the link.');
}

echo "Kept button wrappers own their percentage width once.\n";
