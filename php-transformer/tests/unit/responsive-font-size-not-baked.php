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

if ( $failures > 0 ) {
    fwrite(STDERR, "Responsive font-size baking: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Responsive font-size baking passed: {$passes} assertions\n");
