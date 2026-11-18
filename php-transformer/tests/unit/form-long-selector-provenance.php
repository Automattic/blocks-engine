<?php
declare(strict_types=1);

/**
 * A form control matched by one complex selector longer than the provenance
 * selector bound — a long `:is(...)` list, say — keeps that rule's styles,
 * precedence and provenance. Only the provenance selector is bounded: it is
 * recorded as a token-boundary prefix plus `selector_sha256` of the full
 * selector, `provenance_selector_limit` is reported, and the graph stays valid
 * instead of aborting the import. Short-selector rules are unaffected.
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
    $assert(! in_array($longSelector, $selectors, true), 'the over-long selector is not emitted verbatim as provenance');
    $assert(in_array('provenance_selector_limit', $graph['diagnostics'] ?? array(), true), 'the bounded selector is reported as a provenance selector bound', json_encode($graph['diagnostics'] ?? null));

    $digest = hash('sha256', $longSelector);
    $bounded = array();
    array_walk_recursive($graph, static function (mixed $value, int|string $key) use (&$bounded, $digest): void {
        if ( 'selector_sha256' === $key && $digest === $value ) $bounded[] = $value;
    });
    $assert(array() !== $bounded, 'the over-long rule keeps provenance attributable through the full selector digest');
    $encoded = json_encode($graph);
    $prefixes = array_filter($selectors, static fn (string $selector): bool => 'button' !== $selector);
    $assert(array() !== $prefixes && array() === array_filter($prefixes, static fn (string $selector): bool => ! str_starts_with($longSelector, $selector) || str_ends_with($selector, ',')), 'the bounded selector is a token-boundary prefix of the source selector', json_encode($prefixes));

    $assert(str_contains($encoded, '#123456') && str_contains($encoded, '"selector":"button"'), 'the short-selector rule on the same button keeps its styles and provenance', $encoded);
    $assert(str_contains($encoded, '"12px"') && str_contains($encoded, '.2em') && str_contains($encoded, 'uppercase'), 'the over-long selector declarations survive in styles', $encoded);
}

if ( 0 < $failures ) {
    fwrite(STDERR, "form long selector provenance FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form long selector provenance passed: {$passes} assertions\n";
