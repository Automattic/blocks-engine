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
$headingStyles = static fn (array $projected, string $level = 'h1'): array => $projected['theme']['styles']['elements'][$level]['typography'] ?? array();

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

// A representable global declaration must not outrank a later, more specific
// source declaration that theme.json cannot express (for example, calc()).
$specificCalculation = $project(array($cssAsset(
    'h2{font-family:Georgia;line-height:1.4em}'
    . 'h2{line-height:calc(1.2 * (1 + (1 - var(--heading-size))/25))}'
)));
$assert(
    ! isset($headingStyles($specificCalculation, 'h2')['lineHeight']),
    'a non-representable later heading line-height keeps the property source-owned',
    json_encode($headingStyles($specificCalculation, 'h2'))
);

$conditionalCalculation = $project(array($cssAsset(
    'h2{line-height:1.4em}'
    . '@media (min-width:1px){h2{line-height:calc(1.2 * (1 + (1 - var(--heading-size))/25))}}'
)));
$assert(
    ! isset($headingStyles($conditionalCalculation, 'h2')['lineHeight']),
    'a conditional non-representable heading line-height keeps the property source-owned',
    json_encode($headingStyles($conditionalCalculation, 'h2'))
);

// Live Tailwind repro: promoting either base declaration would override the
// utilities on h3 and change 36px/400 back to 30px/800 at every viewport.
$css = '@layer base{h1,h2,h3{line-height:1;font-weight:800}}'
    . '@layer utilities{.text-3xl{font-size:30px;line-height:1.2}.font-normal{font-weight:400}}';
$reduced = $project(array($cssAsset($css)));
foreach (array('h1', 'h2', 'h3') as $tag) {
    $assert(!isset($headingStyles($reduced, $tag)['lineHeight']), $tag . ' contested line-height stays source-owned');
    $assert(!isset($headingStyles($reduced, $tag)['fontWeight']), $tag . ' contested weight stays source-owned');
}
$assert($reduced['assets'][0]['content'] === $css, 'the complete layered source remains intact');

// Each property written by projection participates, independent of value
// representability, selector specificity, or conditional activation.
foreach (array(
    'line-height' => '1', 'font-weight' => '800', 'letter-spacing' => '-.02em',
    'font-family' => 'Georgia', 'font-size' => '32px', 'font-style' => 'italic',
    'text-transform' => 'uppercase', 'color' => 'red', 'background-color' => 'red',
    'padding' => '10px', 'margin' => '10px', 'gap' => '10px', 'max-width' => '960px',
) as $property => $value) {
    $tag = 'max-width' === $property ? 'main' : 'body';
    $base = '@layer base{' . $tag . '{' . $property . ':' . $value . '}}';
    $plain = $project(array($cssAsset($base)));
    $assert(1 === count($plain['provenance']), $property . ' uncontested default projects');
    $contested = $project(array($cssAsset($base . '@layer utilities{@media(min-width:390px){.utility{' . $property . ':var(--other)}}}')));
    $assert(array() === $contested['provenance'], $property . ' conditional utility contests the default');
}

foreach (array('font', 'all') as $shorthand) {
    $result = $project(array($cssAsset('@layer base{h3{line-height:1;font-weight:800;font-family:Georgia}}@layer utilities{.utility{' . $shorthand . ':initial}}')));
    $assert(array() === $result['provenance'], $shorthand . ' contests its projected longhands');
}
$background = $project(array($cssAsset('@layer base{h3{background-color:red}}@layer utilities{.utility{background:blue}}')));
$assert(array() === $background['provenance'], 'background shorthand contests background-color');

// Registration, not block order, determines rank; unrelated subject types
// should not suppress an editable heading default.
foreach (array(
    '@layer utilities,base;@layer base{h3{line-height:1}}@layer utilities{.utility{line-height:2}}',
    '@layer base{h3{line-height:1}}@layer utilities{p.utility{line-height:2}}',
    '@layer base{h3{line-height:1}}@layer utilities{h3 p{line-height:2}}',
) as $uncontestedCss) {
    $result = $project(array($cssAsset($uncontestedCss)));
    $assert('1' === ($headingStyles($result, 'h3')['lineHeight'] ?? null), 'lower layers and unrelated subjects leave heading editable');
}
foreach (array('h3.utility', 'p h3', ':is(h3,.utility)', '[data-heading]', '*') as $selector) {
    $result = $project(array($cssAsset('@layer base,utilities;@layer utilities{' . $selector . '{line-height:2}}@layer base{h3{line-height:1}}')));
    $assert(!isset($headingStyles($result, 'h3')['lineHeight']), $selector . ' may contest a heading');
}
$crossAsset = $project(array($cssAsset('@layer base,utilities;@layer base{h3{line-height:1}}'), $cssAsset('@layer utilities{.utility{line-height:2}}')));
$assert(!isset($headingStyles($crossAsset, 'h3')['lineHeight']), 'contenders are indexed across stylesheets');

if ( $failures > 0 ) {
    fwrite(STDERR, "theme.json layered deferral projection: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "theme.json layered deferral projection passed: {$passes} assertions\n");
