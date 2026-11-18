<?php
declare(strict_types=1);

/**
 * A form control matched by one complex selector longer than the provenance
 * selector bound — a long `:is(...)` list, say — cannot be evidenced without
 * rewriting the selector. The builder omits that declaration's style and
 * provenance together, reports `provenance_selector_limit`, and keeps the graph
 * valid instead of aborting the import. Short-selector rules on the same
 * control keep their styles and provenance.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$classes = array();
for ( $i = 1; $i <= 60; ++$i ) $classes[] = '.unrelated-class-name-' . $i;
$longSelector = ':is(' . implode(',', $classes) . ',button)';
$assert(strlen($longSelector) > 1024, 'the fixture selector exceeds the provenance selector bound', (string) strlen($longSelector));

$css = $longSelector . ' { font-size: 12px; letter-spacing: .2em; text-transform: uppercase; } button { color: #123456; padding: 8px; }';
$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body><form class="f" method="post"><label for=e>Email</label><input id=e type=email><button type=submit>Join</button></form></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$form = $document->getElementsByTagName('form')->item(0);

$graph = null;
try {
    $graph = (new FormPresentationGraphBuilder())->build($form, array(), $css);
    $assert(true, 'build accepts a control matched by an over-long selector');
} catch ( Throwable $error ) {
    $assert(false, 'build accepts a control matched by an over-long selector', $error->getMessage());
}

if ( is_array($graph) ) {
    try {
        FormPresentationGraphBuilder::assertValid($graph);
        $assert(true, 'the built graph passes its own validator');
    } catch ( Throwable $error ) {
        $assert(false, 'the built graph passes its own validator', $error->getMessage());
    }

    $selectors = array();
    array_walk_recursive($graph, static function (mixed $value, int|string $key) use (&$selectors): void {
        if ( 'selector' === $key && is_string($value) ) $selectors[] = $value;
    });
    $assert(array() !== $selectors && array() === array_filter($selectors, static fn (string $selector): bool => strlen($selector) > 1024), 'every provenance selector is within the bound', json_encode(array_map('strlen', $selectors)));
    $assert(! in_array($longSelector, $selectors, true), 'the over-long selector is not emitted as provenance');
    $assert(in_array('provenance_selector_limit', $graph['diagnostics'] ?? array(), true), 'the omitted declaration is reported as a provenance selector bound', json_encode($graph['diagnostics'] ?? null));

    $encoded = json_encode($graph);
    $assert(str_contains($encoded, '#123456') && str_contains($encoded, '"selector":"button"'), 'the short-selector rule on the same button keeps its styles and provenance', $encoded);
    $assert(! str_contains($encoded, '"12px"') && ! str_contains($encoded, '.2em'), 'the over-long selector declarations are omitted rather than kept without evidence', $encoded);
}

if ( 0 < $failures ) {
    fwrite(STDERR, "form long selector provenance FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form long selector provenance passed: {$passes} assertions\n";
