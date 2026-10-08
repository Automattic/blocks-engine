<?php
declare(strict_types=1);

/**
 * Unit tests for the source's dominant responsive breakpoints in theme.json.
 *
 * Plain-PHP test script in the style of tests/unit/theme-json-projection-blockgap-optin.php — no
 * PHPUnit. WordPress 7.1 reads `settings.viewport.mobile` and `settings.viewport.tablet`
 * (WP_Theme_JSON::get_viewport_media_queries()) and turns them into `@mobile` =
 * `(width <= mobile)` and `@tablet` = `(mobile < width <= tablet)` media queries for
 * per-breakpoint block child layout and viewport-scoped block styles. The projection must emit
 * the source's dominant layout-switch boundaries — scored by how many layout-relevant
 * declarations change there — and keep WordPress's 480px/782px defaults whenever no boundary
 * dominates, so the emitted viewport never invents a structure the source does not have.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeJsonProjection;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$project = static fn( string $css ): array => ( new ThemeJsonProjection() )->project( array(
    array( 'kind' => 'css', 'source_path' => 'style.css', 'content' => $css ),
) );

// ---------------------------------------------------------------------------
// 1. A grid collapsing to one column plus a flex-direction change at 900px and
//    another layout switch at 560px emit the source's own viewport breakpoints
//    and the typed report of chosen values and scored candidates.
// ---------------------------------------------------------------------------
$acceptance = $project( '.cards{display:grid;grid-template-columns:repeat(3,1fr)}.menu{display:flex}@media (max-width:900px){.cards{grid-template-columns:1fr}.menu{flex-direction:column}}@media (max-width:560px){.list{flex-wrap:wrap}.sidebar{width:100%}}' );
$assert(
    array( 'tablet' => '900px', 'mobile' => '560px' ) === ( $acceptance['theme']['settings']['viewport'] ?? null ),
    '1: the dominant source boundaries project as settings.viewport tablet 900px and mobile 560px',
    json_encode( $acceptance['theme']['settings'] ?? null )
);
$report = $acceptance['responsive_breakpoints'];
$assert(
    is_array( $report ) && 'blocks-engine/responsive-breakpoints/v1' === ( $report['schema'] ?? null ),
    '1b: the typed responsive-breakpoints report carries its versioned schema',
    json_encode( $report )
);
$assert(
    array( 'tablet' => '900px', 'mobile' => '560px' ) === ( $report['chosen'] ?? null ),
    '1c: the report records the chosen breakpoints',
    json_encode( $report['chosen'] ?? null )
);
$assert(
    array( array( 'width' => 560, 'score' => 2 ), array( 'width' => 900, 'score' => 2 ) ) === ( $report['candidates'] ?? null ),
    '1d: the report scores every observed layout-switch boundary in width order',
    json_encode( $report['candidates'] ?? null )
);
$assert(
    is_string( $report['reason'] ?? null ) && '' !== $report['reason'],
    '1e: the report explains the selection'
);
$encoded = json_decode( (string) json_encode( $acceptance['theme'], JSON_UNESCAPED_SLASHES ), true );
$assert(
    array( 'tablet' => '900px', 'mobile' => '560px' ) === ( $encoded['settings']['viewport'] ?? null ),
    '1f: settings.viewport round-trips through theme.json as string lengths'
);

// ---------------------------------------------------------------------------
// 2. A media query that only changes color is not a layout switch: no viewport
//    setting and no report, so the source output stays on WordPress defaults.
// ---------------------------------------------------------------------------
$colorOnly = $project( 'body{color:#111}@media (max-width:600px){body{color:#222}}' );
$assert(
    ! isset( $colorOnly['theme']['settings']['viewport'] ) && null === $colorOnly['responsive_breakpoints'],
    '2: a source whose only media query changes color keeps the WordPress viewport defaults',
    json_encode( array( $colorOnly['theme']['settings'] ?? null, $colorOnly['responsive_breakpoints'] ) )
);

// ---------------------------------------------------------------------------
// 3. min-width normalizes to the equivalent max-width boundary one pixel down,
//    so a desktop-first stack switching at 1024px becomes tablet 1023px.
// ---------------------------------------------------------------------------
$minWidth = $project( '@media (min-width:1024px){.cards{grid-template-columns:1fr}.menu{flex-direction:row}}' );
$assert(
    array( 'tablet' => '1023px' ) === ( $minWidth['theme']['settings']['viewport'] ?? null ),
    '3: min-width 1024px normalizes to the 1023px max-width boundary',
    json_encode( $minWidth['theme']['settings'] ?? null )
);
$assert(
    array( 'tablet' => '1023px' ) === ( $minWidth['responsive_breakpoints']['chosen'] ?? null ),
    '3b: without a qualifying mobile boundary only the tablet key is set'
);

// ---------------------------------------------------------------------------
// 4. Media Query Level 4 range syntax is understood.
// ---------------------------------------------------------------------------
$range = $project( '@media (width <= 768px){.cards{grid-template-columns:1fr}.menu{flex-direction:column}}' );
$assert(
    array( 'tablet' => '768px' ) === ( $range['theme']['settings']['viewport'] ?? null ),
    '4: the range syntax (width <= 768px) selects the 768px boundary',
    json_encode( $range['theme']['settings'] ?? null )
);
$twoSided = $project( '@media (400px <= width <= 900px){.cards{grid-template-columns:1fr}.menu{flex-direction:column}}' );
$assert(
    array( 'tablet' => '900px', 'mobile' => '399px' ) === ( $twoSided['theme']['settings']['viewport'] ?? null ),
    '4b: a two-sided range yields its inclusive upper boundary and its exclusive lower boundary',
    json_encode( $twoSided['theme']['settings'] ?? null )
);

// ---------------------------------------------------------------------------
// 5. em and rem media queries use the 16px media-query base.
// ---------------------------------------------------------------------------
$em = $project( '@media (min-width:48em){.cards{grid-template-columns:1fr}.menu{flex-direction:row}}' );
$assert(
    array( 'tablet' => '767px' ) === ( $em['theme']['settings']['viewport'] ?? null ),
    '5: min-width 48em normalizes to the 767px boundary',
    json_encode( $em['theme']['settings'] ?? null )
);

// ---------------------------------------------------------------------------
// 6. Score ties break toward the WordPress default so the stock behavior wins
//    whenever the source is indifferent.
// ---------------------------------------------------------------------------
$tie = $project( '@media (max-width:700px){.a{display:grid}.b{display:flex}}@media (max-width:860px){.c{display:grid}.d{display:flex}}' );
$assert(
    array( 'tablet' => '860px', 'mobile' => '700px' ) === ( $tie['theme']['settings']['viewport'] ?? null ),
    '6: a tie between 700px and 860px breaks toward the 782px WordPress default tablet',
    json_encode( $tie['theme']['settings'] ?? null )
);

// ---------------------------------------------------------------------------
// 7. A single layout declaration is below the dominance threshold: the viewport
//    stays on the defaults while the report records the boundary left out.
// ---------------------------------------------------------------------------
$single = $project( '@media (max-width:900px){.hero{display:grid}}' );
$assert(
    ! isset( $single['theme']['settings']['viewport'] ),
    '7: one layout declaration never moves the viewport',
    json_encode( $single['theme']['settings'] ?? null )
);
$assert(
    array() === ( $single['responsive_breakpoints']['chosen'] ?? null ) && array( array( 'width' => 900, 'score' => 1 ) ) === ( $single['responsive_breakpoints']['candidates'] ?? null ),
    '7b: the report keeps the sub-threshold boundary as evidence without choosing it',
    json_encode( $single['responsive_breakpoints'] )
);

// ---------------------------------------------------------------------------
// 8. A source without any width media query emits neither viewport nor report,
//    byte-identical to the projection before the breakpoint analysis.
// ---------------------------------------------------------------------------
$quiet = $project( 'body{color:#111;gap:2rem}p{margin:2rem}' );
$quietBaseline = ( new ThemeJsonProjection() )->project( array( array( 'kind' => 'css', 'source_path' => 'style.css', 'content' => 'body{color:#111;gap:2rem}p{margin:2rem}' ) ) );
$assert(
    ! isset( $quiet['theme']['settings']['viewport'] ) && null === $quiet['responsive_breakpoints'] && $quiet === $quietBaseline,
    '8: a source without width media queries is unchanged, so no boundary means no viewport'
);

// ---------------------------------------------------------------------------
// 9. Through the full site-plan compile, the chosen breakpoints land in the
//    generated theme.json write and the typed report is mounted under the
//    plan's theme source reports; a quiet source mounts neither.
// ---------------------------------------------------------------------------
$responsivePlan = ( new ArtifactCompiler() )->compile( array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="assets/site.css"></head><body><main><div class="cards"><p>One</p><p>Two</p><p>Three</p></div><nav class="menu"><a href="/">Home</a></nav></main></body></html>',
        'assets/site.css' => '.cards{display:grid;grid-template-columns:repeat(3,1fr)}@media (max-width:900px){.cards{grid-template-columns:1fr}.menu{flex-direction:column}}@media (max-width:560px){.list{flex-wrap:wrap}.sidebar{width:100%}}',
    ),
) )->toArray();
$responsiveWrites = array();
foreach ( ( $responsivePlan['source_reports']['wordpress_site_plan']['writes'] ?? array() ) as $write ) {
    $responsiveWrites[ (string) ( $write['target_path'] ?? '' ) ] = $write;
}
$responsiveTheme = json_decode( (string) ( $responsiveWrites['theme.json']['payload']['data'] ?? '' ), true );
$assert(
    array( 'tablet' => '900px', 'mobile' => '560px' ) === ( $responsiveTheme['settings']['viewport'] ?? null ),
    '9: the generated theme.json carries the source viewport breakpoints',
    (string) ( $responsiveWrites['theme.json']['payload']['data'] ?? '' )
);
$responsiveReport = $responsivePlan['source_reports']['wordpress_site_plan']['theme']['responsive_breakpoints'] ?? null;
$assert(
    is_array( $responsiveReport ) && 'blocks-engine/responsive-breakpoints/v1' === ( $responsiveReport['schema'] ?? null ) && array( 'tablet' => '900px', 'mobile' => '560px' ) === ( $responsiveReport['chosen'] ?? null ),
    '9b: the plan mounts the typed responsive-breakpoints report under its theme',
    json_encode( $responsivePlan['source_reports']['wordpress_site_plan']['theme'] ?? null )
);
$quietPlan = ( new ArtifactCompiler() )->compile( array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="assets/site.css"></head><body><main><p>Plain</p></main></body></html>',
        'assets/site.css' => 'body{color:#111}@media (max-width:600px){body{color:#222}}',
    ),
) )->toArray();
$quietTheme = $quietPlan['source_reports']['wordpress_site_plan']['theme'] ?? array();
$quietPlanWrites = array();
foreach ( ( $quietPlan['source_reports']['wordpress_site_plan']['writes'] ?? array() ) as $write ) {
    $quietPlanWrites[ (string) ( $write['target_path'] ?? '' ) ] = $write;
}
$quietGeneratedTheme = json_decode( (string) ( $quietPlanWrites['theme.json']['payload']['data'] ?? '' ), true );
$assert(
    ! isset( $quietGeneratedTheme['settings']['viewport'] ) && ! isset( $quietTheme['responsive_breakpoints'] ),
    '9c: a source without layout media queries generates a plan and theme.json without viewport keys',
    json_encode( array( $quietGeneratedTheme['settings'] ?? null, array_keys( $quietTheme ) ) )
);

// 10: `max-width: 600px` (600) and `min-width: 600px` (599) are one switch; merged, they
// outrank a nearby single-convention boundary with fewer declarations.
$mergedResult = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ResponsiveBreakpoints::fromAssets( array(
    array( 'path' => 'assets/merge.css', 'kind' => 'css', 'content' => '.a{display:grid}@media (max-width:600px){.a{display:block}.b{flex-direction:column}}@media (min-width:600px){.c{display:flex}.d{width:50%}}@media (max-width:768px){.e{display:block}.f{float:none}.g{position:static}}' ),
) );
$assert(
    array( 599, 768 ) === array_column( $mergedResult['report']['candidates'] ?? array(), 'width' ) && array( 4, 3 ) === array_column( $mergedResult['report']['candidates'] ?? array(), 'score' ) && '599px' === ( $mergedResult['viewport']['mobile'] ?? null ) && '768px' === ( $mergedResult['viewport']['tablet'] ?? null ),
    '10: boundaries within 1px merge into one candidate that sums their scores',
    json_encode( $mergedResult )
);

if ( $failures > 0 ) {
    fwrite(STDERR, "ThemeJsonProjection responsive breakpoint unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "ThemeJsonProjection responsive breakpoint unit tests: {$passes} passed\n");
