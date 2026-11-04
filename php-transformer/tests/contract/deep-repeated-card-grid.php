<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/deep-repeated-card-grid.html');
$result = $transform($html);
$markup = $result['serialized_blocks'];
$assert(! preg_match('/collection-[0-9a-f]{8,}/', $markup), 'Native cards must not freeze as a collection.');
$assert(! str_contains($markup, '<!-- wp:html'), 'Cards must not contain core/html.');
foreach ($result['source_reports']['generated_blocks'] ?? array() as $definition) {
    $assert(! str_contains(json_encode($definition), 'raw_html'), 'No generated raw_html editing surface remains.');
}
foreach (array('Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta') as $index => $name) {
    $assert(1 === preg_match('/<!-- wp:heading[^>]*-->\s*<h4[^>]*>' . $name . '<\/h4>/', $markup), 'Card heading is native: ' . $name);
    foreach (array('SN ' . (11 + $index), 'Owner ' . ($index + 1)) as $text) {
        $assert(1 === preg_match('/<!-- wp:paragraph[^>]*-->\s*<p[^>]*>' . $text . '<\/p>/', $markup), 'Card paragraph is native: ' . $text);
    }
    $assert(1 === preg_match('/<!-- wp:(?:image|cover)[^>]*-->.*?src="' . chr(97 + $index) . '\.jpg"/s', $markup), 'Thumbnail is native: ' . $name);
}
$assert(6 === preg_match_all('/<!-- wp:(?:image|cover)\b/', $markup), 'Six native thumbnail blocks.');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'Native output is editor-valid.');

// The same repeated structure with an unsupported runtime surface must stay
// frozen; native conversion is not permission to discard part of an item.
$runtime = str_replace('<h4>', '<iframe src="https://example.org/widget"></iframe><h4>', $html);
$frozen = $transform($runtime);
$assert(1 === preg_match('/collection-[0-9a-f]{8,}/', $frozen['serialized_blocks']), 'A runtime/raw HTML item still freezes.');
$assert(str_contains(json_encode($frozen['source_reports']['generated_blocks']), 'raw_html'), 'Fallback retains the raw_html editing surface.');
$assert(str_contains(json_encode($frozen['diagnostics']), 'generated_component_non_native_items'), 'Fallback explains native representability failure.');

foreach ($frozen['diagnostics'] as $diagnostic) {
    if ('generated_component' === ($diagnostic['type'] ?? '')) {
        $assert('editable_approximation' === ($diagnostic['conversion_classification'] ?? ''), 'Preserved generated content must not become unsupported loss.');
    }
}

// Real padded boxes, unlike inert attribute-bearing wrappers, consume depth.
$pressured = $html;
for ($depth = 0; $depth < 24; ++$depth) {
    // Fourteen independent padded groups leave room for the generated leaf;
    // the remaining id wrappers are packed by layout-shell normalization.
    $padding = $depth < 14 ? ' style="padding:1px"' : '';
    $pressured = str_replace('<body>', '<body><div id="depth-' . $depth . '"' . $padding . '>', $pressured);
    $pressured = str_replace('</body>', '</div></body>', $pressured);
}
$bounded = $transform($pressured);
$assert(str_contains(json_encode($bounded['diagnostics']), 'generated_component_depth_budget'), 'Depth pressure retains an explained bounded fallback.');
$assert(20 >= ($bounded['source_reports']['editability_report']['metrics']['max_nesting_depth'] ?? PHP_INT_MAX), 'Depth fallback protects the editability cap.');

echo "deep repeated card grid contract passed\n";
