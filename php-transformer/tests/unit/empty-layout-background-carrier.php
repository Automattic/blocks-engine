<?php
declare(strict_types=1);

/**
 * An author-owned empty container that paints an inline background image must
 * render that background on the frontend, with the same size and position.
 *
 * Two lowering paths keep an empty painted container as its own visual
 * boundary group (marked blocks-engine-empty-visual-group), and both
 * previously dropped the inline background declarations entirely:
 *
 * - a childless direct child of an author-owned layout lowers through
 *   HtmlCompilation::authorLayoutBlockFromElement(), and
 * - a childless box the source itself positions (absolute/fixed with an
 *   inset) preserves through the empty-visual spacer group.
 *
 * The standard inline geometry carrier declines background properties for
 * childless elements because the flow-container path lowers those to a
 * background image block; these boundaries keep the source box instead, so
 * the background must ride on a dedicated generated carrier. Requires author
 * CSS: without the authored flex context the container is not author-owned
 * and lowers differently.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$transform = static function (string $html): array {
    return ( new HtmlTransformer() )->transform($html)->toArray();
};

$carrierClassOnEmptyGroup = static function (string $serialized): ?string {
    if ( 1 !== preg_match('/blocks-engine-empty-visual-group\s+(be-inline-geometry-[0-9a-f]+)/', $serialized, $matches) ) {
        return null;
    }

    return $matches[1];
};

$cssAssets = static function (array $result): string {
    return implode("\n", array_map(
        static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
        $result['assets'] ?? array()
    ));
};

$assertBackground = static function (string $css, string $carrier, string $label) use ($assert): void {
    $assert(
        1 === preg_match(
            '/\.' . $carrier . '\{background-image:url\((["\']?)\/media\/wave\.jpg\1\) !important;'
            . 'background-size:cover !important;'
            . 'background-position:center center !important\}/',
            $css
        ),
        $label . ': the empty boundary group carries the inline background image at its authored size and position.'
    );
};

// Door one: a positioned background layer beside content in an authored flex
// section — the captured-home hero shape.
$positioned = $transform(
    '<style>.hero{display:flex;position:relative}.layer{position:absolute;inset:0;opacity:.2}</style>'
    . '<main><section class="hero">'
    . '<div class="layer" style="background-image:url(/media/wave.jpg);background-size:cover;background-position:center center"></div>'
    . '<div class="content"><h1>Hero</h1><p>Copy</p></div>'
    . '</section></main>'
);
$positionedMarkup = (string) ( $positioned['serialized_blocks'] ?? '' );
$carrier = $carrierClassOnEmptyGroup($positionedMarkup);
$assert(null !== $carrier, 'A positioned empty background layer keeps its group and gains a background carrier.');
$assert('pass' === ( $positioned['source_reports']['wp_block_validity']['status'] ?? '' ), 'The positioned background layer stays editor-valid.');
if ( null !== $carrier ) {
    $assert(str_contains($positionedMarkup, 'class="layer ' . $carrier . '"') || 1 === preg_match('/class="[^"]*' . $carrier . '[^"]*"/', $positionedMarkup), 'The carrier class lands on the saved group wrapper.');
    $assertBackground($cssAssets($positioned), $carrier, 'Positioned layer');
}
$assert(str_contains($positionedMarkup, '<h1') && str_contains($positionedMarkup, 'Hero'), 'The positioned background layer does not displace its sibling content.');

// Door two: a childless direct child of an author-owned layout whose only
// styling is the inline background paint — no positioning, so the layer
// lowers through the author layout path instead of the spacer path.
$bare = $transform(
    '<style>.hero{display:flex}</style>'
    . '<main><section class="hero">'
    . '<div style="background-image:url(/media/wave.jpg);background-size:cover;background-position:center center"></div>'
    . '<div><h1>Hero</h1><p>Copy</p></div>'
    . '</section></main>'
);
$bareMarkup = (string) ( $bare['serialized_blocks'] ?? '' );
$bareCarrier = $carrierClassOnEmptyGroup($bareMarkup);
$assert(null !== $bareCarrier, 'A bare inline-painted empty layout child keeps its group and gains a background carrier.');
$assert('pass' === ( $bare['source_reports']['wp_block_validity']['status'] ?? '' ), 'The bare painted layer stays editor-valid.');
if ( null !== $bareCarrier ) {
    $assertBackground($cssAssets($bare), $bareCarrier, 'Bare layer');
}
$assert(str_contains($bareMarkup, '<h1') && str_contains($bareMarkup, 'Hero'), 'The bare painted layer does not displace its sibling content.');

// A childless empty container without an inline background paint still gets
// no geometry carrier: the projection is scoped to real background paints.
$unpainted = $transform(
    '<style>.hero{display:flex;position:relative}.layer{position:absolute;inset:0}</style>'
    . '<main><section class="hero">'
    . '<div class="layer"></div>'
    . '<div><h1>Hero</h1><p>Copy</p></div>'
    . '</section></main>'
);
$unpaintedMarkup = (string) ( $unpainted['serialized_blocks'] ?? '' );
$assert(str_contains($unpaintedMarkup, 'blocks-engine-empty-visual-group'), 'An unpainted empty boundary is still classified as an empty visual group.');
$assert(null === $carrierClassOnEmptyGroup($unpaintedMarkup), 'An unpainted empty boundary mints no background carrier.');

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf('empty layout background carrier: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('empty layout background carrier tests: %d passed%s', $passes, PHP_EOL));
