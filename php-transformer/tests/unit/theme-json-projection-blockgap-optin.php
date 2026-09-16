<?php
declare(strict_types=1);

/**
 * Unit tests for the theme.json blockGap support opt-in.
 *
 * Plain-PHP test script in the style of tests/unit/css-value-splitter.php — no
 * PHPUnit. WordPress core gates every layout gap style on
 * `isset( $this->theme_json['settings']['spacing']['blockGap'] )` (WP_Theme_JSON::get_layout_styles()
 * and wp_render_layout_support_flag()). Without that setting, core takes its
 * fallback branch, emits a 0.5em gap, and silently discards every per-block
 * style.spacing.blockGap value. The projection must therefore always declare
 * the settings opt-in alongside the styles.spacing.blockGap value it already
 * writes, whether that value came from authored CSS or the 0px default.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
) )['theme'];

// ---------------------------------------------------------------------------
// 1. An authored global gap projects onto styles.spacing.blockGap and the
//    settings opt-in rides along, so WordPress serializes the authored value.
// ---------------------------------------------------------------------------
$authoredGap = $project( 'body{color:#111;gap:2rem}p{margin:2rem}' );
$assert(
    true === ( $authoredGap['settings']['spacing']['blockGap'] ?? null ),
    '1: an authored global gap declares the settings.spacing.blockGap opt-in',
    json_encode( $authoredGap['settings'] ?? null )
);
$authoredGapValue = (string) ( $authoredGap['styles']['spacing']['blockGap'] ?? '' );
$assert(
    str_starts_with( $authoredGapValue, 'var:preset|spacing|' ) || '2rem' === $authoredGapValue,
    '1b: the authored global gap value is retained on styles.spacing.blockGap as a preset reference',
    $authoredGapValue
);

// ---------------------------------------------------------------------------
// 2. A source with no gap declarations still opts in: the plan asserts a
//    global gap of "no gap" (boolean false), so the theme must not leave
//    WordPress's 0.5em fallback-gap branch active for its flex and grid
//    containers while also not emitting 0-1-0 global margin/gap rules that
//    would clobber authored element-level child spacing.
// ---------------------------------------------------------------------------
$noGap = $project( 'body{color:#111}' );
$assert(
    true === ( $noGap['settings']['spacing']['blockGap'] ?? null ),
    '2: a plan without authored gaps still opts into blockGap support',
    json_encode( $noGap['settings'] ?? null )
);
$assert(
    false === ( $noGap['styles']['spacing']['blockGap'] ?? null ),
    '2b: the styles-side default is an explicit false that suppresses global gap rules',
    json_encode( $noGap['styles']['spacing'] ?? null )
);
$assert(
    true === ( $project( '' )['settings']['spacing']['blockGap'] ?? null ),
    '2c: an asset-free projection still opts into blockGap support'
);

// ---------------------------------------------------------------------------
// 3. The opt-in and the styles disable survive the theme.json JSON round trip
//    as booleans, the exact values WordPress isset()-tests after json_decode.
// ---------------------------------------------------------------------------
$encoded = json_decode( (string) json_encode( $noGap, JSON_UNESCAPED_SLASHES ), true );
$assert(
    true === ( $encoded['settings']['spacing']['blockGap'] ?? null ) && isset( $encoded['settings']['spacing']['blockGap'] ),
    '3: the settings opt-in round-trips through theme.json as a boolean true',
    (string) json_encode( $encoded['settings'] ?? null )
);
$assert(
    false === ( $encoded['styles']['spacing']['blockGap'] ?? null ),
    '3b: the styles-side gap disable round-trips as a boolean false',
    (string) json_encode( $encoded['styles']['spacing'] ?? null )
);

// ---------------------------------------------------------------------------
// 4. The opt-in coexists with every other projected setting without
//    clobbering the generated palette, typography, or layout values.
// ---------------------------------------------------------------------------
$rich = ( new ThemeJsonProjection() )->project( array(
    array( 'kind' => 'css', 'source_path' => 'style.css', 'content' => 'body{color:#123456;background-color:#fff;font-family:Georgia;font-size:1rem}main{max-width:72rem}' ),
) )['theme'];
$assert(
    true === ( $rich['settings']['spacing']['blockGap'] ?? null ),
    '4: the opt-in is present alongside other projected settings',
    json_encode( $rich['settings'] ?? null )
);
$assert(
    isset( $rich['settings']['color']['palette'] ) && isset( $rich['settings']['typography']['fontFamilies'] ) && isset( $rich['settings']['typography']['fontSizes'] ) && '72rem' === ( $rich['settings']['layout']['contentSize'] ?? null ),
    '4b: color, typography, and layout settings are unchanged by the opt-in',
    json_encode( $rich['settings'] ?? null )
);
$assert(
    3 === ( $rich['version'] ?? null ),
    '4c: the theme.json version stays 3'
);

if ( $failures > 0 ) {
    fwrite(STDERR, "ThemeJsonProjection blockGap opt-in unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "ThemeJsonProjection blockGap opt-in unit tests: {$passes} passed\n");
