<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Exercise the public transformer: the panel shell must not require a
// companion block, even when it owns state replay and a generated closer.
$html = '<body><button id="opener" aria-haspopup="dialog">Open</button>'
    . '<dialog id="panel" class="drawer" aria-label="Notes" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener" data-blocks-engine-add-close="true">'
    . '<h2>Notes</h2><p>Edit this content.</p></dialog></body>';
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$walk = static function (array $blocks) use (&$walk): ?array {
    foreach ($blocks as $block) {
        if (isset($block['attrs']['metadata']['blocksEngineDialog'])) return $block;
        $found = $walk($block['innerBlocks'] ?? array());
        if (null !== $found) return $found;
    }
    return null;
};
$panel = $walk($result['blocks'] ?? array());
if (null === $panel || 'core/group' !== $panel['blockName']) {
    throw new RuntimeException('Captured panels must be editable core Groups, not companion blocks.');
}
$shell = $panel['attrs']['metadata']['blocksEngineDialog'];
if ('dialog' !== $shell['tagName'] || 'opener' !== $shell['attributes']['data-blocks-engine-triggers']
    || 'Notes' !== $shell['attributes']['aria-label'] || !$shell['addCloseButton']
    || array('core/heading', 'core/paragraph') !== array_column($panel['innerBlocks'], 'blockName')
    || str_contains($result['serialized_blocks'], '/captured-dialog')) {
    throw new RuntimeException('The core Group must retain editable children and its native dialog binding.');
}
// The wrapper-chain pass must not fold away runtime metadata when the
// captured panel and its content are both otherwise foldable Groups.
$nested = (new HtmlTransformer())->transform('<div data-blocks-engine-captured-dialog="true" id="nested-panel" data-blocks-engine-triggers="opener"><div class="content"><h2>Editable title</h2><p>Editable body</p></div></div>')->toArray();
$nestedPanel = $walk($nested['blocks'] ?? array());
if (null === $nestedPanel || 'nested-panel' !== ($nestedPanel['attrs']['metadata']['blocksEngineDialog']['attributes']['id'] ?? null)) {
    throw new RuntimeException('Wrapper coalescing must retain the core Group dialog owner.');
}
echo "captured-dialog-core-group: ok\n";
