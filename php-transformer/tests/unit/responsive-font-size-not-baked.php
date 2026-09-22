<?php
declare(strict_types=1);

/**
 * Unit tests for responsive authored `font-size` on text blocks.
 *
 * Plain-PHP test script — no PHPUnit. A layered authored `font-size` that no
 * unlayered rule contests is baked onto the block so the value survives the
 * WordPress cascade. A *responsive* authored `font-size` is not one value but a
 * set of viewport-specific ones, and baking freezes the reference viewport's
 * winner into an unconditional inline style that then wins at every width — a
 * phone renders the desktop size. Those breakpoints stay with the author
 * stylesheet, which resolves them per viewport.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$blocks = static fn (string $html): string => (string) ( ( new HtmlTransformer() )->transform($html)->toArray()['serialized_blocks'] ?? '' );

// Tailwind v4's shape: a preflight reset plus responsive size utilities, all
// layered. The heading must keep its author classes and carry no frozen size.
$responsive = $blocks(
    '<style>@layer base{h1,h2{font-size:inherit}}'
    . '@layer utilities{.text-5xl{font-size:3rem}@media (width>=64rem){.lg\:text-7xl{font-size:4.5rem}}}</style>'
    . '<main><h1 class="text-5xl lg:text-7xl">Responsive</h1></main>'
);
$assert(
    ! str_contains($responsive, '"fontSize":"4.5rem"') && ! str_contains($responsive, 'font-size:4.5rem'),
    'a responsive authored font-size does not bake the reference-viewport winner',
    $responsive
);
$assert(
    ! str_contains($responsive, '"fontSize":"3rem"'),
    'a responsive authored font-size does not bake the base breakpoint either',
    $responsive
);
$assert(
    str_contains($responsive, 'text-5xl') && str_contains($responsive, 'lg:text-7xl'),
    'the authored responsive classes survive so the stylesheet keeps resolving them',
    $responsive
);

// A single layered size has no breakpoints to lose, so it still bakes.
$fixed = $blocks(
    '<style>@layer base{h1,h2{font-size:inherit}}@layer utilities{.text-5xl{font-size:3rem}}</style>'
    . '<main><h1 class="text-5xl">Fixed</h1></main>'
);
$assert(
    str_contains($fixed, '3rem'),
    'a non-responsive layered authored font-size is still baked onto the block',
    $fixed
);

// An element whose own inline style declares the size keeps that value.
$inline = $blocks(
    '<style>@layer utilities{.text-5xl{font-size:3rem}@media (width>=64rem){.lg\:text-7xl{font-size:4.5rem}}}</style>'
    . '<main><h1 class="text-5xl lg:text-7xl" style="font-size:2rem">Inline</h1></main>'
);
$assert(
    str_contains($inline, '2rem'),
    'an inline authored font-size still wins over the responsive class set',
    $inline
);

// Regression evidence for the demonstrated three-width typography shape. The
// values must remain in the author stylesheet so the browser, rather than the
// reference-viewport carrier, selects 36px/48px/60px at 390/768/1440px.
$threeViewport = ( new HtmlTransformer() )->transform(
    '<style>@layer base{h1{font-size:inherit}}'
    . '@layer utilities{.conference-title{font-size:36px}'
    . '@media (min-width:768px){.conference-title{font-size:48px}}'
    . '@media (min-width:1440px){.conference-title{font-size:60px}}}</style>'
    . '<main><h1 class="conference-title">International NGO Conference - Canada</h1></main>'
)->toArray();
$threeViewportCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ( $asset['content'] ?? '' ),
    array_values(array_filter(
        is_array($threeViewport['assets'] ?? null) ? $threeViewport['assets'] : array(),
        static fn (array $asset): bool => 'author-css' === ( $asset['source'] ?? '' )
    ))
));
$threeViewportEngineCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ( $asset['content'] ?? '' ),
    array_values(array_filter(
        is_array($threeViewport['assets'] ?? null) ? $threeViewport['assets'] : array(),
        static fn (array $asset): bool => 'engine-support' === ( $asset['source'] ?? '' )
    ))
));
$assert(
    str_contains($threeViewportCss, 'font-size:36px')
        && str_contains($threeViewportCss, 'font-size:48px')
        && str_contains($threeViewportCss, 'font-size:60px')
        && str_contains($threeViewportCss, 'min-width:768px')
        && str_contains($threeViewportCss, 'min-width:1440px'),
    'the 390/768/1440 authored font-size set remains conditional in author CSS',
    $threeViewportCss
);
$assert(
    str_contains($threeViewportEngineCss, 'blocks-engine-responsive-typography-')
        && str_contains($threeViewportEngineCss, 'font-size:36px')
        && str_contains($threeViewportEngineCss, 'font-size:48px')
        && str_contains($threeViewportEngineCss, 'font-size:60px'),
    'the converted heading receives an unlayered responsive typography carrier',
    $threeViewportEngineCss
);
$assert(
    ! str_contains((string) ( $threeViewport['serialized_blocks'] ?? '' ), 'fontSize')
        && 'pass' === ( ( new BlockValidityValidator() )->validateBlocks($threeViewport['blocks'] ?? array())['status'] ?? '' ),
    'the three-viewport heading has no frozen typography attribute and remains Gutenberg-valid',
    (string) ( $threeViewport['serialized_blocks'] ?? '' )
);

// The artifact compiler's page path must not reintroduce the reference-width
// winner as an inline style after the HTML transformer has created its carrier.
$artifactResult = ( new ArtifactCompiler() )->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'files' => array(array(
        'path' => 'website/index.html',
        'content' => '<!doctype html><html><head><style>@layer base{h1{font-size:inherit}}'
            . '@layer utilities{.text-4xl{font-size:2.25rem}'
            . '@media (width>=640px){.sm\\:text-5xl{font-size:3rem}}'
            . '@media (width>=1024px){.lg\\:text-6xl{font-size:3.75rem}}}</style></head>'
            . '<body><main><h1 class="text-4xl sm:text-5xl lg:text-6xl">International NGO Conference - Canada</h1></main></body></html>',
    )),
))->toArray();
$artifactBlocks = (string) ( $artifactResult['serialized_blocks'] ?? '' );
$artifactEngineCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ( $asset['content'] ?? '' ),
    array_values(array_filter(
        is_array($artifactResult['assets'] ?? null) ? $artifactResult['assets'] : array(),
        static fn (array $asset): bool => 'engine-support' === ( $asset['source'] ?? '' )
    ))
));
$assert(
    ! preg_match('/<h1\b[^>]*style="[^"]*font-size:/i', $artifactBlocks)
        && str_contains($artifactBlocks, 'blocks-engine-responsive-typography-')
        && str_contains($artifactEngineCss, 'font-size:3.75rem'),
    'the artifact compiler keeps the responsive carrier and drops its frozen inline winner',
    $artifactBlocks
);
$assert(
    'pass' === ( ( new BlockValidityValidator() )->validateBlocks($artifactResult['blocks'] ?? array())['status'] ?? '' ),
    'the artifact-path responsive heading remains Gutenberg-valid',
    $artifactBlocks
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Responsive font-size baking: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Responsive font-size baking passed: {$passes} assertions\n");
