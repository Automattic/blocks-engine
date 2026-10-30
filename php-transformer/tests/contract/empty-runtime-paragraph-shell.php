<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/**
 * Contract coverage for an empty paragraph a script fills.
 *
 * A status line beside a form (`<p class="note" data-note hidden></p>`) is an
 * empty mount point the script reveals and writes after a submit. Like an
 * empty `div` mount, it keeps its exact source element on an editable layout
 * shell instead of a raw HTML island. A paragraph holding text stays a
 * paragraph.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$html = '<!doctype html><html><head><title>Signup</title><style>.note{color:#2a6;margin-top:8px}</style></head><body><main><div class="signup">'
    . '<h2>Join</h2><p class="note" data-note hidden></p><p class="lede">Monthly notes.</p>'
    . '</div></main><script>var n = document.querySelector("[data-note]"); document.querySelector("h2").addEventListener("click", function () { n.hidden = false; n.textContent = "Thanks!"; });</script></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$page   = (string) (current($result['source_reports']['wordpress_site_plan']['pages'] ?? array())['canonical_block_markup'] ?? '');
$blocks = (new Runtime())->parseBlocks($page);

$find = static function (array $blocks, string $needle) use (&$find): ?array {
    foreach ($blocks as $block) {
        $inner = $find($block['innerBlocks'] ?? array(), $needle);
        if (null !== $inner) {
            return $inner;
        }
        if (str_contains((string) ($block['innerHTML'] ?? ''), $needle)) {
            return $block;
        }
    }
    return null;
};

$note = $find($blocks, 'data-note');
$assert(! str_contains($page, '<!-- wp:html'), 'No raw HTML island remains.');
$assert(str_ends_with((string) ($note['blockName'] ?? ''), '/layout-shell'), 'The empty status paragraph is an editable layout shell.');
$assert('p' === ($note['attrs']['wrappers'][0]['tagName'] ?? null) && true === ($note['attrs']['wrappers'][0]['attributes']['hidden'] ?? null) && '' === ($note['attrs']['wrappers'][0]['attributes']['data-note'] ?? null), 'The shell records the paragraph, its hook and its hidden state.');
$assert(1 === preg_match('#<p class="note[^"]*" data-note="" hidden=""></p>#', (string) ($note['innerHTML'] ?? '')), 'The shell saves the source paragraph exactly.');
$assert('core/paragraph' === ($find($blocks, 'Monthly notes.')['blockName'] ?? null), 'A paragraph holding text stays a paragraph.');

echo "Empty runtime paragraph shell contract passed.\n";
