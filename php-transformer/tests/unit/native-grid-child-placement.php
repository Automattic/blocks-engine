<?php
declare(strict_types=1);

/**
 * Source-authored grid-item placement becomes WordPress 7.1 native child
 * layout data (`style.layout.columnStart/columnSpan/rowStart/rowSpan`) when
 * the parent is emitted as a core grid layout container, and stays on
 * carrier/author CSS with a typed diagnostic otherwise
 * (Automattic/blocks-engine#2139 step 1).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

/** @return list<string> */
$gridDiagnosticReasons = static function (array $result): array {
    $reasons = array();
    array_walk_recursive($result, static function ($value, $key) use (&$reasons): void {
        if ( 'reason_code' === $key && is_string($value) && str_starts_with($value, 'grid_placement_') ) {
            $reasons[] = $value;
        }
    });

    return array_values(array_unique($reasons));
};

$engineCss = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'engine-support' === ( $asset['source'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }

    return $css;
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

$compile = static function (string $css, string $body): array {
    $result = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><main>' . $body . '</main></body></html>',
            's.css' => $css,
        ),
    ))->toArray();

    return array(
        'result' => $result,
        'markup' => (string) ( $result['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? '' ),
    );
};

// 1. Fixed equal tracks + inline placement: native parent columnCount and
//    native child placement, with no duplicated carrier CSS.
$fixed = $transform(
    '<div style="display:grid;grid-template-columns:repeat(12,1fr)">'
    . '<h3 style="grid-column:2 / span 4;grid-row:1 / 3">Heading</h3>'
    . '<p>Body</p>'
    . '</div>'
);
$group = $fixed['blocks'][0] ?? array();
$heading = $group['innerBlocks'][0] ?? array();
$assert(
    array( 'type' => 'grid', 'columnCount' => 12 ) === ( $group['attrs']['layout'] ?? null ),
    'repeat(12,1fr) parent emits native columnCount grid layout',
    json_encode($group['attrs'] ?? null) ?: ''
);
$assert(
    array( 'columnStart' => 2, 'columnSpan' => 4, 'rowStart' => 1, 'rowSpan' => 2 ) === ( $heading['attrs']['style']['layout'] ?? null ),
    'inline grid-column/grid-row become native child layout values (line pair -> span)',
    json_encode($heading['attrs'] ?? null) ?: ''
);
$assert(
    ! str_contains($engineCss($fixed), 'grid-column:2 / span 4') && ! str_contains($engineCss($fixed), 'grid-row:1 / 3'),
    'converted placement is not duplicated on a carrier',
    $engineCss($fixed)
);
$assert(array() === $gridDiagnosticReasons($fixed), 'fully converted placement records no carrier diagnostic', implode(',', $gridDiagnosticReasons($fixed)));

// 2. Numeric grid-area converts atomically.
$area = $transform(
    '<div style="display:grid;grid-template-columns:1fr 1fr 1fr">'
    . '<p style="grid-area:2 / 1 / span 1 / span 3">Wide</p>'
    . '</div>'
);
$assert(
    array( 'type' => 'grid', 'columnCount' => 3 ) === ( $area['blocks'][0]['attrs']['layout'] ?? null ),
    'three literal 1fr tracks emit columnCount 3',
    json_encode($area['blocks'][0]['attrs'] ?? null) ?: ''
);
$assert(
    array( 'columnStart' => 1, 'columnSpan' => 3, 'rowStart' => 2, 'rowSpan' => 1 ) === ( $area['blocks'][0]['innerBlocks'][0]['attrs']['style']['layout'] ?? null ),
    'numeric grid-area converts to native child layout values',
    json_encode($area['blocks'][0]['innerBlocks'][0]['attrs'] ?? null) ?: ''
);

// 3. Named lines stay on the carrier with a typed diagnostic.
$named = $transform(
    '<div style="display:grid;grid-template-columns:repeat(4,1fr)">'
    . '<p style="grid-column:content-start / content-end">Named</p>'
    . '</div>'
);
$assert(
    ! isset($named['blocks'][0]['innerBlocks'][0]['attrs']['style']['layout']),
    'named-line placement does not become native data',
    json_encode($named['blocks'][0]['innerBlocks'][0]['attrs'] ?? null) ?: ''
);
$assert(
    in_array('grid_placement_named_lines', $gridDiagnosticReasons($named), true),
    'named-line placement records grid_placement_named_lines',
    implode(',', $gridDiagnosticReasons($named))
);

// 4. Mixed track sizes keep the parent CSS-owned; inline child placement
//    stays on its carrier with a typed diagnostic.
$mixed = $transform(
    '<div style="display:grid;grid-template-columns:200px 1fr">'
    . '<p style="grid-column:2">Mixed</p>'
    . '</div>'
);
$assert(
    ! isset($mixed['blocks'][0]['attrs']['layout']) && str_contains((string) ( $mixed['blocks'][0]['attrs']['className'] ?? '' ), 'blocks-engine-css-owned-grid'),
    'mixed track list stays CSS-owned',
    json_encode($mixed['blocks'][0]['attrs'] ?? null) ?: ''
);
$assert(
    in_array('grid_placement_parent_not_core_grid', $gridDiagnosticReasons($mixed), true),
    'placement under a CSS-owned grid records grid_placement_parent_not_core_grid',
    implode(',', $gridDiagnosticReasons($mixed))
);
$assert(str_contains($engineCss($mixed), 'grid-column:2'), 'placement under a CSS-owned grid stays on the carrier', $engineCss($mixed));

// 5. Stylesheet-authored parent and child placement (the artifact path the
//    importer uses) convert; the author rule remains and agrees.
$sheet = $compile(
    '.g{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem}.a{grid-column:2 / span 2}',
    '<section class="g"><h3 class="a">A</h3><p>B</p></section>'
);
$assert(
    str_contains($sheet['markup'], '"layout":{"type":"grid","columnCount":3}'),
    'class-owned repeat(3,1fr) grid emits native columnCount',
    $sheet['markup']
);
$assert(
    str_contains($sheet['markup'], '"style":{"layout":{"columnStart":2,"columnSpan":2}}'),
    'class-authored grid-column becomes native child layout values',
    $sheet['markup']
);

// 6. A media-query placement variant keeps the whole placement author-owned
//    and records grid_placement_responsive_unmapped.
$responsive = $compile(
    '.g{display:grid;grid-template-columns:repeat(3,1fr)}.a{grid-column:2 / span 2}@media (max-width:600px){.a{grid-column:1 / span 3}}',
    '<section class="g"><h3 class="a">A</h3><p>B</p></section>'
);
$assert(
    ! str_contains($responsive['markup'], '"columnStart"'),
    'responsive placement is not emitted as unconditional native data',
    $responsive['markup']
);
$assert(
    in_array('grid_placement_responsive_unmapped', $gridDiagnosticReasons($responsive['result']), true),
    'responsive placement records grid_placement_responsive_unmapped',
    implode(',', $gridDiagnosticReasons($responsive['result']))
);

// 7. A media-query track-list variant keeps the parent CSS-owned.
$responsiveTracks = $compile(
    '.g{display:grid;grid-template-columns:repeat(3,1fr)}@media (max-width:600px){.g{grid-template-columns:1fr}}',
    '<section class="g"><p>A</p><p>B</p></section>'
);
$assert(
    ! str_contains($responsiveTracks['markup'], '"columnCount"'),
    'responsive track list does not become native columnCount',
    $responsiveTracks['markup']
);

// 8. Native placement merges with existing child layout values.
$merged = $transform(
    '<div style="display:grid;grid-template-columns:repeat(2,1fr)">'
    . '<div style="display:flex;grid-column:1 / span 2"><p>One</p><p>Two</p></div>'
    . '</div>'
);
$mergedLayout = $merged['blocks'][0]['innerBlocks'][0]['attrs']['style']['layout'] ?? array();
$assert(
    1 === ( $mergedLayout['columnStart'] ?? null ) && 2 === ( $mergedLayout['columnSpan'] ?? null ),
    'native placement is present alongside other attributes on a layout-bearing child',
    json_encode($merged['blocks'][0]['innerBlocks'][0]['attrs'] ?? null) ?: ''
);

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf('native grid child placement FAILED: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

echo sprintf('Native grid child placement passed: %d assertions%s', $passes, PHP_EOL);
