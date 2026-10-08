<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/layout-media-tracks.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = $result['serialized_blocks'];
$failures = array();
$walk = static function (array $blocks) use (&$walk, &$failures): void {
    foreach ($blocks as $block) {
        if ('core/columns' === $block['blockName'] && false !== ($block['attrs']['isStackedOnMobile'] ?? null)) {
            $failures[] = 'Layout tables must not acquire Core mobile stacking.';
        }
        if ('core/html' === $block['blockName']) {
            $failures[] = 'Layout fixture must remain natively editable.';
        }
        $walk($block['innerBlocks'] ?? array());
    }
};
$walk($result['blocks']);
if (!str_contains($markup, 'is-not-stacked-on-mobile')) {
    $failures[] = 'Columns save shape must serialize the native stacking opt-out.';
}
if (!str_contains($markup, 'wp:table') || !str_contains($markup, '<thead><tr><th>Sample</th>') || !str_contains($markup, 'Measurements')) {
    $failures[] = 'Semantic data table must retain its native table, header section and caption.';
}
$validity = (new Runtime())->validateBlockSerialization($markup);
if (array() !== ($validity['findings'] ?? array())) {
    $failures[] = json_encode($validity['findings']);
}
$semantic = (new HtmlTransformer())->transform('<table role="table" aria-label="Records"><tr><td><img src="record.jpg" alt="Record"></td><td>42</td></tr></table>')->toArray();
if ('core/table' !== ($semantic['blocks'][0]['blockName'] ?? '')) {
    $failures[] = 'Explicit accessible table semantics must take precedence over media-layout heuristics.';
}
$unjustified = (new Runtime())->validateBlockSerialization('<!-- wp:columns --><div class="wp-block-columns is-not-stacked-on-mobile"></div><!-- /wp:columns -->');
if (array() === ($unjustified['findings'] ?? array())) {
    $failures[] = 'Native stacking class is valid only when justified by its attribute.';
}
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "layout media tracks native contract passed\n";
