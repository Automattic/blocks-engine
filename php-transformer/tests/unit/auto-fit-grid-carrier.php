<?php
declare(strict_types=1);

/**
 * Contract for `repeat(auto-fit, …)` track lists.
 *
 * `wp-includes/block-supports/layout.php` hardcodes `auto-fill` in every branch
 * that renders `minimumColumnWidth`, so a native grid layout attribute can only
 * ever express `auto-fill`. Converting an authored `auto-fit` track list to that
 * attribute changes the rendered geometry: `auto-fit` collapses empty tracks,
 * `auto-fill` retains them, so the content is crammed into part of the measure.
 * `auto-fit` must therefore stay under CSS ownership like every other track list
 * WordPress cannot express, while `auto-fill` keeps its native conversion.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$cssFor = static function (array $result, string $source): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(array_filter(
            is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            static fn (array $asset): bool => $source === ($asset['source'] ?? '')
        ))
    ));
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

$cards = '<div><p>One</p></div><div><p>Two</p></div><div><p>Three</p></div>';

// -- Defect: an inline auto-fit track list is converted to a native grid layout.
$inlineAutoFit = $transform(
    '<section class="cards" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:24px">' . $cards . '</section>'
);
$inlineAutoFitMarkup = (string) ($inlineAutoFit['serialized_blocks'] ?? '');
$inlineAutoFitEngineCss = $cssFor($inlineAutoFit, 'engine-support');

$assert(
    ! str_contains($inlineAutoFitMarkup, 'minimumColumnWidth'),
    'inline auto-fit: no native grid layout attribute, which WordPress would render as auto-fill',
    $inlineAutoFitMarkup
);
$assert(
    str_contains($inlineAutoFitMarkup, 'blocks-engine-css-owned-grid'),
    'inline auto-fit: container is marked css-owned-grid like every other non-expressible track list',
    $inlineAutoFitMarkup
);
$assert(
    str_contains($inlineAutoFitEngineCss, 'grid-template-columns:repeat(auto-fit, minmax(280px, 1fr))'),
    'inline auto-fit: the authored track list rides to the generated stylesheet verbatim',
    $inlineAutoFitEngineCss
);
$assert(
    str_contains($inlineAutoFitEngineCss, 'display:grid'),
    'inline auto-fit: the carrier keeps the container a grid',
    $inlineAutoFitEngineCss
);

// -- Same defect through a class-owned track list, which is the shape the
// site-builder corpus authors. The author stylesheet already retains the rule;
// the native attribute is what overrides it.
$classAutoFit = $transform(
    '<style>.cards{display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:24px}</style>'
    . '<section class="cards">' . $cards . '</section>'
);
$classAutoFitMarkup = (string) ($classAutoFit['serialized_blocks'] ?? '');
$classAutoFitAuthorCss = $cssFor($classAutoFit, 'author-css');

$assert(
    ! str_contains($classAutoFitMarkup, 'minimumColumnWidth'),
    'class-owned auto-fit: no native grid layout attribute',
    $classAutoFitMarkup
);
$assert(
    ! str_contains($classAutoFitMarkup, 'is-layout-grid'),
    'class-owned auto-fit: no core grid layout classes competing with the author rule',
    $classAutoFitMarkup
);
$assert(
    str_contains($classAutoFitAuthorCss, '.cards{display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:24px}'),
    'class-owned auto-fit: the author rule stays the single owner of the track geometry',
    $classAutoFitAuthorCss
);

// -- Control: auto-fill IS natively expressible and must keep converting.
$inlineAutoFill = $transform(
    '<section class="cards" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:24px">' . $cards . '</section>'
);
$inlineAutoFillMarkup = (string) ($inlineAutoFill['serialized_blocks'] ?? '');
$inlineAutoFillEngineCss = $cssFor($inlineAutoFill, 'engine-support');

$assert(
    str_contains($inlineAutoFillMarkup, '"layout":{"type":"grid","minimumColumnWidth":"280px"}'),
    'auto-fill control: still converts to the native grid layout attribute',
    $inlineAutoFillMarkup
);
$assert(
    ! str_contains($inlineAutoFillMarkup, 'blocks-engine-css-owned-grid'),
    'auto-fill control: does not fall back to the css-owned-grid carrier',
    $inlineAutoFillMarkup
);
$assert(
    ! str_contains($inlineAutoFillEngineCss, 'grid-template-columns'),
    'auto-fill control: WordPress still owns the track geometry',
    $inlineAutoFillEngineCss
);

$classAutoFill = $transform(
    '<style>.cards{display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:24px}</style>'
    . '<section class="cards">' . $cards . '</section>'
);
$classAutoFillMarkup = (string) ($classAutoFill['serialized_blocks'] ?? '');

$assert(
    str_contains($classAutoFillMarkup, '"layout":{"type":"grid","minimumColumnWidth":"280px"}'),
    'class-owned auto-fill control: still converts to the native grid layout attribute',
    $classAutoFillMarkup
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Auto-fit grid carrier contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Auto-fit grid carrier contract passed: {$passes} assertions\n");
