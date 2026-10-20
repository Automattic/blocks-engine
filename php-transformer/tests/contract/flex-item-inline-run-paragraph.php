<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/**
 * Contract coverage for a run of text inside a flex or grid item.
 *
 * A footer item `<div>© <span data-year>2026</span> Studio</div>` laid out by
 * its parent's flex is one box holding one line of text, just as it is in
 * flow. It converts to one paragraph whose rich text keeps the inline span,
 * rather than splitting the sentence into a block per text node and element.
 * An item that is itself a layout keeps its children as layout items.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$html = '<!doctype html><html><head><title>Footer</title><style>.foot{display:flex;justify-content:space-between}.chips{display:flex;gap:8px}</style></head><body>'
    . '<footer class="foot"><div>© <span data-year>2026</span> Fern Studio. All rights reserved.</div><div class="chips"><span>Tea</span> <span>Ink</span></div><nav><a href="about.html">About</a></nav></footer>'
    . '<script>document.querySelectorAll("[data-year]").forEach(function (e) { e.textContent = new Date().getFullYear(); });</script></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$page   = (string) ($result['serialized_blocks'] ?? '');
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

$run = $find($blocks, 'data-year');
$assert('core/paragraph' === ($run['blockName'] ?? null), 'The flex item\'s run of text converts to one paragraph.');
$assert(1 === preg_match('#>© <span data-year>2026</span> Fern Studio\. All rights reserved\.</p>#', (string) ($run['innerHTML'] ?? '')), 'The paragraph keeps the whole sentence with its inline hook span.');
$assert(! str_contains($page, '<!-- wp:html -->'), 'The run leaves no raw HTML island.');

$chips = $find($blocks, 'chips');
$assert('core/group' === ($chips['blockName'] ?? null) && 2 === count($chips['innerBlocks'] ?? array()), 'An item that is itself a flex layout keeps each child as its own layout item.');

echo "Flex item inline run paragraph contract passed.\n";
