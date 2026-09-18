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
 * register the captured families as settings.typography.fontFamilies — including
 * class-scoped stacks that never project as Global Styles — and set body versus
 * heading styles.typography.fontFamily from global-element capture evidence.
 * Duplicate resolved stacks collapse to one preset; unused custom properties
 * never register. Otherwise the generated theme cannot represent the source
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
//    as a style while the used stacks still register as editor-facing families.
// ---------------------------------------------------------------------------
$conditional = $project( array( $cssAsset(
    ':root{--font-body:"Lora",serif}body{font-family:var(--font-body)}@media (max-width:600px){body{font-family:Georgia,serif}}'
) ) )['theme'];
$assert(
    ! isset( $conditional['styles']['typography']['fontFamily'] ),
    '4: a media-conditioned family override blocks style projection of that property',
    json_encode( $conditional['styles']['typography'] ?? null )
);
$assert(
    array( 'Lora', 'Georgia' ) === array_column( $conditional['settings']['typography']['fontFamilies'] ?? array(), 'name' ),
    '4b: used stacks still register as editor-facing families',
    json_encode( $conditional['settings']['typography']['fontFamilies'] ?? null )
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
// 8. Class-scoped typography never projects as Global Styles. The used stack
//    still registers as an editor-facing family so it is selectable.
// ---------------------------------------------------------------------------
$bare = $project( array( $cssAsset( '.card{font-family:Georgia,serif}' ) ) )['theme'];
$assert(
    ! isset( $bare['styles']['typography'] ),
    '8: class-scoped typography never projects as styles.typography',
    json_encode( $bare['styles'] ?? null )
);
$assert(
    array( 'Georgia' ) === array_column( $bare['settings']['typography']['fontFamilies'] ?? array(), 'name' )
        && 'Georgia,serif' === ( $bare['settings']['typography']['fontFamilies'][0]['fontFamily'] ?? null ),
    '8b: a class-scoped used stack still registers as a fontFamilies preset',
    json_encode( $bare['settings']['typography']['fontFamilies'] ?? null )
);

// ---------------------------------------------------------------------------
// 9. Three source families with one duplicate resolved stack produce two
//    deduplicated entries. An unused custom property does not register. Slugs
//    are deterministic. Global Styles stay limited to the body capture.
// ---------------------------------------------------------------------------
$multiCss = ':root{--font-body:"Inter", ui-sans-serif, system-ui, sans-serif;--font-display:"Inter Tight", ui-sans-serif, system-ui, sans-serif;--font-heading:"Inter Tight", ui-sans-serif, system-ui, sans-serif;--font-unused:"Never Used", serif}'
    . 'body{font-family:var(--font-body)}'
    . '.font-display{font-family:var(--font-display)}'
    . '.font-heading{font-family:var(--font-heading)}';
$multiProjection = $project( array( $cssAsset( $multiCss ) ) );
$multi = $multiProjection['theme'];
$multiFamilies = $multi['settings']['typography']['fontFamilies'] ?? array();
$assert(
    2 === count( $multiFamilies ) && array( 'Inter Tight', 'Inter' ) === array_column( $multiFamilies, 'name' ),
    '9: three used families with one duplicate stack register two presets',
    json_encode( $multiFamilies )
);
$assert(
    '"Inter Tight", ui-sans-serif, system-ui, sans-serif' === ( $multiFamilies[0]['fontFamily'] ?? null )
        && '"Inter", ui-sans-serif, system-ui, sans-serif' === ( $multiFamilies[1]['fontFamily'] ?? null ),
    '9b: registered stacks are the resolved source stacks, not var() tokens',
    json_encode( $multiFamilies )
);
$assert(
    ! in_array( 'Never Used', array_column( $multiFamilies, 'name' ), true ),
    '9c: an unused custom property does not register as a font family'
);
$bodyFamily = (string) ( $multi['styles']['typography']['fontFamily'] ?? '' );
$assert(
    'var:preset|font-family|' . ( $multiProjection['presets']['font-family']['"Inter", ui-sans-serif, system-ui, sans-serif'] ?? '' ) === $bodyFamily,
    '9d: the body style still references the Inter family preset',
    $bodyFamily
);
$assert(
    ! isset( $multi['styles']['elements'] ),
    '9e: class-scoped families do not project as element styles',
    json_encode( $multi['styles'] ?? null )
);
$repeat = $project( array( $cssAsset( $multiCss ) ) );
$assert(
    array_column( $multiFamilies, 'slug' ) === array_column( $repeat['theme']['settings']['typography']['fontFamilies'] ?? array(), 'slug' )
        && json_encode( $multiFamilies ) === json_encode( $repeat['theme']['settings']['typography']['fontFamilies'] ?? array() ),
    '9f: registered family slugs are deterministic across repeated projection',
    json_encode( array_column( $repeat['theme']['settings']['typography']['fontFamilies'] ?? array(), 'slug' ) )
);

// ---------------------------------------------------------------------------
// 10. A third distinct used stack (mono) registers beside body and display.
// ---------------------------------------------------------------------------
$withMono = $project( array( $cssAsset(
    $multiCss . '.font-mono{font-family:var(--font-mono)}:root{--font-mono:"JetBrains Mono", ui-monospace, SFMono-Regular, monospace}'
) ) )['theme'];
$withMonoFamilies = $withMono['settings']['typography']['fontFamilies'] ?? array();
$assert(
    3 === count( $withMonoFamilies ) && array( 'Inter Tight', 'Inter', 'JetBrains Mono' ) === array_column( $withMonoFamilies, 'name' ),
    '10: a third distinct used stack registers beside the deduplicated pair',
    json_encode( $withMonoFamilies )
);
$assert(
    ! isset( $withMono['styles']['elements'] )
        && str_starts_with( (string) ( $withMono['styles']['typography']['fontFamily'] ?? '' ), 'var:preset|font-family|' ),
    '10b: registering the extra family does not add competing element styles',
    json_encode( $withMono['styles'] ?? null )
);

if ( $failures > 0 ) {
    fwrite(STDERR, "ThemeJsonProjection typography-family unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "ThemeJsonProjection typography-family unit tests: {$passes} passed\n");
