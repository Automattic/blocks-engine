<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// CSS-addressable wrapper boundaries must survive, but do not need a
// generated block when they already have a native Group representation.
$html = '<style>.frame .paint{background-color:rgba(var(--bg,var(--color_11,color_11)),var(--alpha-bg,1))}</style>'
    . '<div id="root"><div id="master"><div id="pages"><div class="frame"><div class="paint"></div></div></div></div></div>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (str_contains($markup, '/layout-shell') || str_contains($markup, 'wp:html') || substr_count($markup, '<!-- wp:group') < 4) {
    throw new RuntimeException('CSS-addressable wrapper chains must remain independently editable core Groups: ' . $markup);
}
foreach (array('id="root"', 'id="master"', 'id="pages"', 'frame', 'paint') as $identity) {
    if (!str_contains($markup, $identity)) {
        throw new RuntimeException('Lost source wrapper identity: ' . $identity);
    }
}
// Builder exports nest wrapper chains dozens deep, separated by styled
// boxes. Keeping them as native Groups must stay linear in depth: each
// chain segment used to normalize everything below it once per level.
$segments = 12;
$deep = '';
for ($segment = 0; $segment < $segments; ++$segment) {
    $deep .= '<div id="a' . $segment . '"><div id="b' . $segment . '"><div id="c' . $segment . '"><div style="padding:' . ($segment + 1) . 'px">';
}
$deep .= '<p>Deep</p><p>Content</p>' . str_repeat('</div>', 4 * $segments);
$started = microtime(true);
$markup = (string) ((new HtmlTransformer())->transform($deep)->toArray()['serialized_blocks'] ?? '');
if (microtime(true) - $started > 5.0 || !str_contains($markup, 'id="c' . ($segments - 1) . '"') || !str_contains($markup, 'Content')) {
    throw new RuntimeException('Deep native wrapper chains must normalize in linear time.');
}
echo "native wrapper chain editability passed\n";
