<?php
declare(strict_types=1);

/**
 * Unit tests for theme.json typography-family projection.
 *
 * Plain-PHP test script in the style of tests/unit/theme-json-projection-blockgap-optin.php —
 * no PHPUnit. Source typography is routinely declared through custom properties
 * (`body{font-family:var(--font-sans)}`, `h1,h2,h3,h4,h5,h6{font-family:var(--font-serif)}`
 * defined by `:root{--font-sans:…;--font-serif:…}`) and, in Tailwind v4 output,
 * nested inside `@layer base`. The projection must resolve those references,
 * register the captured families as settings.typography.fontFamilies, and set
 * body versus heading styles.typography.fontFamily from the same capture
 * evidence — otherwise the generated theme cannot represent the source
 * typefaces in the block editor even though the frontend renders them through
 * the copied author CSS.
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

$project = static fn( array $assets ): array => ( new ThemeJsonProjection() )->project( $assets );

$cssAsset = static fn( string $css, string $path = 'assets/styles.css' ): array => array( 'kind' => 'css', 'source_path' => $path, 'content' => $css );

// ---------------------------------------------------------------------------
// 1. Body and heading families declared through custom properties inside a
//    cascade layer project into settings.typography.fontFamilies and the
//    matching styles.typography.fontFamily values.
// ---------------------------------------------------------------------------
$projection = $project( array( $cssAsset(
    '@layer base{:root{--font-sans:"Inter",system-ui,sans-serif;--font-serif:"Cormorant Garamond",Georgia,serif}'
    . 'body{color:#111;font-family:var(--font-sans)}'
    . 'h1,h2,h3,h4,h5,h6{font-family:var(--font-serif);letter-spacing:-.02em;font-weight:500}}'
) ) );
$theme = $projection['theme'];
$families = $theme['settings']['typography']['fontFamilies'] ?? array();
$assert(
    2 === count( $families ) && array( 'Cormorant Garamond', 'Inter' ) === array_column( $families, 'name' ),
    '1: both captured families register as settings.typography.fontFamilies with readable names',
    json_encode( $families )
);
$stackByPrimaryFamily = array();
foreach ( $families as $family ) {
    $stackByPrimaryFamily[ str_contains( (string) $family['fontFamily'], 'Inter' ) ? 'body' : 'heading' ] = (string) $family['fontFamily'];
}
$assert(
    '"Inter",system-ui,sans-serif' === ( $stackByPrimaryFamily['body'] ?? '' ) && '"Cormorant Garamond",Georgia,serif' === ( $stackByPrimaryFamily['heading'] ?? '' ),
    '1b: the registered fontFamily stacks are the resolved source stacks, not var() tokens',
    json_encode( $stackByPrimaryFamily )
);
$bodyFamily = (string) ( $theme['styles']['typography']['fontFamily'] ?? '' );
$assert(
    str_starts_with( $bodyFamily, 'var:preset|font-family|' ) && str_contains( $projection['presets']['font-family'][ $stackByPrimaryFamily['body'] ] ?? '', preg_replace( '/^var:preset\|font-family\|/', '', $bodyFamily ) ),
    '1c: the body style references the Inter family preset',
    $bodyFamily
);
foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $element ) {
    $headingFamily = (string) ( $theme['styles']['elements'][ $element ]['typography']['fontFamily'] ?? '' );
    $expectedSlug  = (string) ( $projection['presets']['font-family'][ $stackByPrimaryFamily['heading'] ] ?? '' );
    $assert(
        'var:preset|font-family|' . $expectedSlug === $headingFamily && 'var:preset|font-family|' . $expectedSlug !== $bodyFamily,
        "1d: $element styles reference the heading family preset",
        $headingFamily
    );
}

// ---------------------------------------------------------------------------
// 2. The projected typography survives the theme.json JSON round trip without
//    leaking an unresolved var() token anywhere.
// ---------------------------------------------------------------------------
$encoded = json_decode( (string) json_encode( $theme, JSON_UNESCAPED_SLASHES ), true );
$assert(
    is_array( $encoded['settings']['typography']['fontFamilies'] ?? null ) && is_string( $encoded['styles']['typography']['fontFamily'] ?? null ),
    '2: the projected typography round-trips through theme.json JSON'
);
$assert(
    ! str_contains( (string) json_encode( $encoded ), 'var(--' ),
    '2b: no unresolved var() reference reaches the generated theme.json',
    (string) json_encode( $encoded )
);

// ---------------------------------------------------------------------------
// 3. A font-family that stays unresolved after variable expansion is never
//    projected, not even as a literal var() token.
// ---------------------------------------------------------------------------
$unresolved = $project( array( $cssAsset( 'body{font-family:var(--missing-font)}' ) ) )['theme'];
$assert(
    ! isset( $unresolved['settings']['typography'] ) && ! isset( $unresolved['styles']['typography']['fontFamily'] ),
    '3: an unresolvable font var() projects nothing',
    json_encode( $unresolved['styles'] ?? null )
);

// ---------------------------------------------------------------------------
// 4. A conditional (media) font-family override keeps the property source-owned
//    while the unconditional body family still projects.
// ---------------------------------------------------------------------------
$conditional = $project( array( $cssAsset(
    ':root{--font-body:"Lora",serif}body{font-family:var(--font-body)}@media (max-width:600px){body{font-family:Georgia,serif}}'
) ) )['theme'];
$assert(
    ! isset( $conditional['styles']['typography']['fontFamily'] ) && ! isset( $conditional['settings']['typography']['fontFamilies'] ),
    '4: a media-conditioned family override blocks projection of that property',
    json_encode( $conditional['styles']['typography'] ?? null )
);

// ---------------------------------------------------------------------------
// 5. The same target and property declared under two different cascade layers
//    stays source-owned because layer priority decides the winner.
// ---------------------------------------------------------------------------
$layered = $project( array( $cssAsset(
    'body{font-family:Georgia,serif}@layer base{body{font-family:var(--font-body)}}:root{--font-body:"Lora",serif}'
) ) )['theme'];
$assert(
    ! isset( $layered['styles']['typography']['fontFamily'] ),
    '5: cross-layer font-family conflicts stay authored-CSS owned',
    json_encode( $layered['styles']['typography'] ?? null )
);

// ---------------------------------------------------------------------------
// 6. CSS-wide keywords carry style values but never register as presets.
// ---------------------------------------------------------------------------
$keywords = $project( array( $cssAsset(
    'h1{font-size:inherit}h2{font-size:inherit}a{color:inherit}button{color:inherit}'
) ) )['theme'];
$assert(
    array() === ( $keywords['settings']['typography']['fontSizes'] ?? array() ) && array() === ( $keywords['settings']['color']['palette'] ?? array() ),
    '6: CSS-wide keywords never become editor-facing presets',
    json_encode( array( $keywords['settings']['typography']['fontSizes'] ?? null, $keywords['settings']['color']['palette'] ?? null ) )
);
$assert(
    'inherit' === ( $keywords['styles']['elements']['h1']['typography']['fontSize'] ?? null ),
    '6b: the keyword still projects as the literal style value it resolves to'
);

// ---------------------------------------------------------------------------
// 7. @font-face rules backed by an already-materialized woff2 asset attach as
//    fontFace records on the family preset that names the typeface; remote
//    sources stay authored-CSS owned.
// ---------------------------------------------------------------------------
$facedProjection = $project( array(
    $cssAsset( '@font-face{font-family:"Lora";font-style:normal;font-weight:400;src:url(fonts/lora.woff2) format("woff2")}@font-face{font-family:Lora;font-style:italic;font-weight:400;src:url(fonts/lora-italic.woff2)}@font-face{font-family:"Remote";src:url(https://fonts.example.test/remote.woff2)}body{font-family:Lora,serif}h1{font-family:"Remote",serif}' ),
    array( 'kind' => 'font', 'source_path' => 'assets/fonts/lora.woff2', 'target_path' => 'assets/fonts/lora.woff2', 'mime_type' => 'font/woff2' ),
    array( 'kind' => 'font', 'source_path' => 'assets/fonts/lora-italic.woff2', 'target_path' => 'assets/fonts/lora-italic.woff2', 'mime_type' => 'font/woff2' ),
) );
$faced    = $facedProjection['theme'];
$families = $faced['settings']['typography']['fontFamilies'] ?? array();
$lora = null;
foreach ( $families as $family ) {
    if ( 'Lora' === ( $family['name'] ?? '' ) ) {
        $lora = $family;
    }
}
$assert(
    is_array( $lora ) && array(
        array( 'family' => 'Lora', 'src' => 'file:./assets/fonts/lora.woff2', 'fontStyle' => 'normal', 'fontWeight' => '400' ),
        array( 'family' => 'Lora', 'src' => 'file:./assets/fonts/lora-italic.woff2', 'fontStyle' => 'italic', 'fontWeight' => '400' ),
    ) === array_map( static fn( array $face ): array => array_intersect_key( $face, array_flip( array( 'family', 'src', 'fontStyle', 'fontWeight' ) ) ), $lora['fontFace'] ?? array() ),
    '7: materialized woff2 faces attach to the Lora family preset',
    json_encode( $lora ?? null )
);
$remote = null;
foreach ( $families as $family ) {
    if ( str_contains( (string) ( $family['fontFamily'] ?? '' ), 'Remote' ) ) {
        $remote = $family;
    }
}
$assert(
    is_array( $remote ) && ! isset( $remote['fontFace'] ),
    '7b: a remote font source never becomes a fontFace record',
    json_encode( $remote ?? null )
);
$assert(
    'var:preset|font-family|' . ( $facedProjection['presets']['font-family']['Lora,serif'] ?? '' ) === ( $faced['styles']['typography']['fontFamily'] ?? null ),
    '7c: the body style still references the family preset that owns the faces',
    json_encode( $faced['styles']['typography'] ?? null )
);

// ---------------------------------------------------------------------------
// 8. Typography projection is absent for sources without global type rules.
// ---------------------------------------------------------------------------
$bare = $project( array( $cssAsset( '.card{font-family:Georgia,serif}' ) ) )['theme'];
$assert(
    ! isset( $bare['settings']['typography'] ) && ! isset( $bare['styles']['typography'] ),
    '8: class-scoped typography never projects into theme.json',
    json_encode( $bare['settings'] ?? null )
);

if ( $failures > 0 ) {
    fwrite(STDERR, "ThemeJsonProjection typography-family unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "ThemeJsonProjection typography-family unit tests: {$passes} passed\n");
