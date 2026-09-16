<?php
declare(strict_types=1);

/**
 * Unit tests for cascade-layer deferrals in theme.json projection.
 *
 * Plain-PHP test script in the style of tests/unit/theme-json-projection-typography-families.php —
 * no PHPUnit. A reset layer declares a CSS-wide keyword so a later layer can
 * win: Tailwind v4 preflight ships `@layer base{h1,h2,…{font-size:inherit}}`
 * precisely so `@layer utilities{.text-5xl{…}}` owns heading size. Global
 * Styles is unlayered, so projecting that deferral would outrank every author
 * layer and invert the source cascade — the heading would render at the
 * inherited body size and the author's responsive type would never apply.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeJsonProjection;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$project = static fn (array $assets): array => ( new ThemeJsonProjection() )->project($assets);
$cssAsset = static fn (string $css): array => array( 'kind' => 'css', 'source_path' => 'assets/styles.css', 'content' => $css );
$headingStyles = static fn (array $projected): array => $projected['theme']['styles']['elements']['h1']['typography'] ?? array();

// A layered preflight deferral stays source-owned.
$preflight = $project(array($cssAsset(
    '@layer base{h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}}'
    . '@layer utilities{.text-5xl{font-size:3rem}}'
)));
$assert(
    ! isset($headingStyles($preflight)['fontSize']),
    'a layered font-size deferral is not projected onto heading elements'
);
$assert(
    ! isset($headingStyles($preflight)['fontWeight']),
    'a layered font-weight deferral is not projected onto heading elements'
);

// Every CSS-wide keyword defers, not just `inherit`.
foreach ( array('inherit', 'initial', 'revert', 'revert-layer', 'unset') as $keyword ) {
    $projected = $project(array($cssAsset('@layer base{h1{font-size:' . $keyword . '}}')));
    $assert(
        ! isset($headingStyles($projected)['fontSize']),
        'a layered `' . $keyword . '` font-size is not projected'
    );
}

// A concrete value inside a layer still projects: it states design intent that
// Global Styles can reproduce.
$concrete = $project(array($cssAsset('@layer base{h1{font-size:3rem}}')));
$assert(
    str_starts_with((string) ( $headingStyles($concrete)['fontSize'] ?? '' ), 'var:preset|font-size|'),
    'a layered concrete font-size still projects as a registered preset',
    json_encode($headingStyles($concrete))
);

// An unlayered deferral is the whole cascade's truth, so it still projects.
$unlayered = $project(array($cssAsset('h1{font-size:inherit}')));
$assert(
    'inherit' === ( $headingStyles($unlayered)['fontSize'] ?? '' ),
    'an unlayered font-size deferral still projects',
    json_encode($headingStyles($unlayered))
);

// The deferral must not suppress sibling properties declared in the same rule.
$mixed = $project(array($cssAsset('@layer base{h1{font-size:inherit;letter-spacing:-.02em}}')));
$assert(
    '-.02em' === ( $headingStyles($mixed)['letterSpacing'] ?? '' ),
    'a concrete sibling declaration still projects alongside a dropped deferral',
    json_encode($headingStyles($mixed))
);

if ( $failures > 0 ) {
    fwrite(STDERR, "theme.json layered deferral projection: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "theme.json layered deferral projection passed: {$passes} assertions\n");
