<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Shared rows and field help/error siblings are native editable containers,
// not a reason to require the consumer's layout-shell block.
foreach (array('', ' class="field"') as $attributes) {
    $html = '<form><div class="row"><div' . $attributes . '><input name="name"><div>Help</div></div>'
        . '<div class="field"><input name="email"><div>Error</div></div></div></form>';
    $result = (new HtmlTransformer())->transform($html, array())->toArray();
    $runtime = new Runtime();
    $readable = $result['fallbacks'][0]['readable_blocks'];
    $markup = $runtime->serializeBlocks($readable);
    if (str_contains($markup, '/layout-shell') || str_contains($markup, 'wp:html')) {
        throw new RuntimeException('Ordinary branching form containers must stay native: ' . $markup);
    }
    $row = $readable[0]; // The page keeps the form wrapper; the provider replaces the controls row.
    if ('core/group' !== $row['blockName']
        || !in_array('row', explode(' ', $row['attrs']['className'] ?? ''), true)
        || 2 !== count($row['innerBlocks'])) {
        throw new RuntimeException('Shared form rows must retain source identity and both editable fields.');
    }
    $field = $row['innerBlocks'][0];
    if (!str_contains($field['attrs']['className'] ?? '', 'blocks-engine-css-owned-flow')
        || isset($field['attrs']['layout'])) {
        throw new RuntimeException('A native form frame must not acquire WordPress flow spacing.');
    }
    if ('' !== $attributes && !in_array('field', explode(' ', $field['attrs']['className'] ?? ''), true)) {
        throw new RuntimeException('Native field frames must retain authored classes for stylesheet selectors.');
    }
    $saved = $runtime->serializeBlocks($runtime->parseBlocks($markup));
    $document = new DOMDocument();
    @$document->loadHTML($saved);
    $xpath = new DOMXPath($document);
    if (2 !== $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " row ")]/div/p[1]')->length
        || !str_contains($saved, 'Help') || !str_contains($saved, 'Error')) {
        throw new RuntimeException('Native form Groups must preserve readable field ancestry and authored siblings.');
    }
}
// Authored layouts (including responsive-only ones) already own their child
// geometry. Adding a flow reset would override grid gaps and margins.
foreach (array(
    '.row{display:grid;gap:2rem}',
    '@media(min-width:600px){.row{display:grid;gap:2rem}}',
) as $css) {
    $result = (new HtmlTransformer())->transform(
        '<form><div class="row"><input name="name"><div>Help</div></div></form>',
        array('static_css' => $css)
    )->toArray();
    $row = $result['fallbacks'][0]['readable_blocks'][0]['innerBlocks'][0];
    if ('core/group' !== $row['blockName']
        || str_contains($row['attrs']['className'] ?? '', 'blocks-engine-css-owned-flow')
        || '0' === ($row['attrs']['style']['spacing']['blockGap'] ?? null)) {
        throw new RuntimeException('Authored form layouts must not acquire a synthetic flow reset: ' . json_encode($row));
    }
}
echo "Native branching form containers passed\n";
