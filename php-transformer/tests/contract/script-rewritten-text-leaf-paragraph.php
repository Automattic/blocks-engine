<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/**
 * Contract coverage for inline text a script rewrites.
 *
 * A live result count (`<span data-count aria-live>`) whose text the page's
 * script replaces is still just text. It converts to an editable paragraph
 * whose rich text saves the span with its hooks, so the script finds the same
 * node. An inline element that holds other elements keeps its runtime island.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$html = '<!doctype html><html><head><title>Listing</title><style>.bar{display:flex;justify-content:space-between}.toolbar .total{color:#556;font-weight:600}</style></head><body><main>'
    . '<div class="bar"><span class="count" data-count aria-live="polite">12 lamps</span><div class="sort"><label for="sort">Sort</label><select id="sort"><option>Newest</option></select></div></div>'
    . '<div class="bar toolbar"><span class="total" data-total aria-live="polite">7 shades</span><div class="sort"><p>Sorted</p></div></div>'
    . '<div class="bar"><span class="badge" data-badge><b>3</b> new</span><div class="sort"><p>More</p></div></div>'
    . '</main><script>var c = document.querySelector("[data-count]"); c.textContent = 4 + " lamps"; var t = document.querySelector("[data-total]"); t.textContent = 2 + " shades"; var b = document.querySelector("[data-badge]"); b.innerHTML = "<b>1</b> new";</script></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$page = (string) (current($result['source_reports']['wordpress_site_plan']['pages'] ?? array())['canonical_block_markup'] ?? '');

$owner = static function (array $blocks, string $needle) use (&$owner): ?string {
    foreach ($blocks as $block) {
        $inner = $owner($block['innerBlocks'] ?? array(), $needle);
        if (null !== $inner) {
            return $inner;
        }
        if (str_contains((string) ($block['innerHTML'] ?? ''), $needle)) {
            return (string) $block['blockName'];
        }
    }
    return null;
};
$blocks = (new Runtime())->parseBlocks($page);

$assert('core/paragraph' === $owner($blocks, 'data-count'), 'Script-rewritten inline text converts to an editable paragraph: ' . substr($page, 0, 1500));
$assert(1 === preg_match('#<p\b[^>]*><span class="count[^"]*" data-count aria-live="polite"[^>]*>12 lamps</span></p>#', $page), 'The paragraph saves the span with its script hook and live region.');
$assert('core/paragraph' === $owner($blocks, 'data-total') && 1 === preg_match('#<span class="total\b[^"]*" data-total aria-live="polite"[^>]*>7 shades</span>#', $page), 'Styled inline text keeps its element, class and hook rather than a restyled mark.');
$assert('core/html' === $owner($blocks, 'data-badge'), 'An inline element holding other elements keeps its runtime island.');

echo "Script-rewritten text leaf paragraph contract passed.\n";
