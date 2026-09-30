<?php
/**
 * Toggle ownership asks "does this element convert to core/navigation?" of
 * every element in each candidate scope, for every toggle, every ancestor
 * scope and every navigation. Without a per-element memo the full
 * NavigationPattern recognizer re-runs hundreds of times per element on deep
 * documents with several menu toggles, which dominated compile time on real
 * captured pages.
 *
 * The contract: each source element is recognized at most once per
 * transform, and the memo leaves the block output unchanged.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressor;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$navigation = static fn (string $label): string => '<nav><ul>'
    . '<li><a href="/' . $label . '-a">' . $label . ' A</a></li>'
    . '<li><a href="/' . $label . '-b">' . $label . ' B</a></li>'
    . '<li><a href="/' . $label . '-c">' . $label . ' C</a></li>'
    . '</ul></nav>';
$toggle = '<button aria-expanded="false" aria-label="Menu"><span></span></button>';

// Three labelless menu toggles beside two navigations, nested under wrappers
// that each carry sibling content, so every ancestor scope holds both menus.
$inner = '<header>' . str_repeat($toggle, 3) . $navigation('primary') . $navigation('secondary') . '</header>';
for ($level = 0; $level < 8; ++$level) {
    $inner = '<div class="level-' . $level . '">' . str_repeat('<p>Level ' . $level . ' copy.</p>', 5) . $inner . '</div>';
}
$html = '<!doctype html><html><body>' . $inner . '</body></html>';

$source = new DOMDocument();
$source->loadHTML($html, LIBXML_NOERROR);
$sourceElements = $source->getElementsByTagName('*')->length;

$compilation = new HtmlCompilation();
$result = $compilation->transform($html)->toArray();
$suppressor = (new ReflectionProperty(HtmlCompilation::class, 'navigationToggleSuppressor'))->getValue($compilation);
$assert($suppressor instanceof NavigationToggleSuppressor, 'The compilation exposes its navigation toggle suppressor.');

// Output recorded from the unmemoized recognizer: the memo must not change
// which blocks are emitted or how the navigations collapse (the toggles are
// not unique owners of either menu, so both stay non-overlay navigations).
$signature = static function (array $blocks) use (&$signature): array {
    $flat = array();
    foreach ($blocks as $block) {
        $name = (string) ($block['blockName'] ?? '');
        if ('core/navigation' === $name) {
            $name .= ':' . (string) ($block['attrs']['overlayMenu'] ?? '');
        }
        $flat[] = $name;
        array_push($flat, ...$signature($block['innerBlocks'] ?? array()));
    }
    return $flat;
};
$actual = implode(',', $signature($result['blocks'] ?? array()));
$menu = 'core/navigation:never' . str_repeat(',core/navigation-link', 3);
$expected = str_repeat('core/group' . str_repeat(',core/paragraph', 5) . ',', 8) . 'core/group,' . $menu . ',' . $menu;
$assert($expected === $actual, "Memoized recognition preserves the block output.\nexpected: {$expected}\nactual:   {$actual}");

$recognitions = $suppressor->coreNavigationRecognitionExecutions ?? null;
$assert(
    is_int($recognitions) && $recognitions > 0 && $recognitions <= $sourceElements,
    sprintf('NavigationPattern recognitions stay bounded by the %d source elements (got %s).', $sourceElements, var_export($recognitions, true))
);

echo "navigation-toggle-recognition-memo: {$assertions} assertions passed ({$recognitions} recognitions for {$sourceElements} elements)\n";
