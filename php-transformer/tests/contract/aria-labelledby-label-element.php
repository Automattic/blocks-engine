<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/**
 * Contract coverage for a label that names a group through `aria-labelledby`.
 *
 * A `<label>` captioning a button group has no `for` and holds no control, yet
 * the group points at it by id. It stays a `<label>` with that id, as an
 * editable label block, so `aria-labelledby` still resolves and author rules
 * such as `.group > label` keep matching it. A label nothing points at keeps
 * its existing paragraph conversion.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$html = '<!doctype html><html><head><title>Filters</title><style>.group > label { display: block; font-weight: 700; }</style></head><body><main>'
    . '<div class="group"><label id="kind-label" class="caption">Kind</label><div class="chips" role="group" aria-labelledby="kind-label"><button type="button">All</button><button type="button">Lamps</button></div></div>'
    . '<div class="group"><label id="loose-label">Loose caption</label><p>Nothing names this label.</p></div>'
    . '</main></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$page = (string) (current($result['source_reports']['wordpress_site_plan']['pages'] ?? array())['canonical_block_markup'] ?? '');

$found = null;
$walk = static function (array $blocks) use (&$walk, &$found): void {
    foreach ($blocks as $block) {
        if (str_ends_with((string) ($block['blockName'] ?? ''), '/authored-label') && 'kind-label' === ($block['attrs']['id'] ?? null)) {
            $found = $block;
        }
        $walk($block['innerBlocks'] ?? array());
    }
};
$walk((new Runtime())->parseBlocks($page));

$assert(is_array($found) && 'Kind' === ($found['attrs']['content'] ?? null) && ! isset($found['attrs']['htmlFor']), 'The group caption stays an editable label block with its id: ' . substr($page, 0, 1200));
$assert(1 === preg_match_all('/<label id="kind-label" class="caption [^"]*">Kind<\/label>/', $page), 'The group caption renders as the source label element, so aria-labelledby resolves.');
$assert(! str_contains($page, '<label id="loose-label"') && 1 === preg_match('/<p id="loose-label" class="[^"]*blocks-engine-source-label-[^"]*">Loose caption<\/p>/', $page), 'A label nothing names keeps its paragraph conversion and its source label marker.');

echo "aria-labelledby label element contract passed.\n";
